<?php
// ============================================================
//  FASE 4.1 — MEMORIA DE PRECOS, SERIE DIARIA DO PATRIMONIO E INDICES.
//
//  Este arquivo e' a "camada de historico" do projeto. Ele existe porque o
//  sistema NAO tinha memoria: a coluna `recebido` das abertas e' sobrescrita
//  a cada busca de cotacao, entao sabiamos quanto a carteira vale HOJE e nunca
//  quanto valia em 12/06. Sem serie historica nao ha grafico de evolucao nem
//  "resultado do mes incluindo posicoes vivas".  (ADR 015)
//
//  TRES CAMADAS, tres tabelas:
//    apl_cotacoes     (GLOBAL)  — fechamento por (codigo, data), vindo do COTAHIST
//    apl_serie_diaria (usuario+carteira) — patrimonio reconstruido pregao a pregao
//    apl_indices      (GLOBAL)  — CDI/IPCA (Banco Central) e ETFs-proxy (B3)
//
//  ORGANIZACAO DO ARQUIVO — de proposito em duas metades:
//    1) NUCLEO PURO: so contas, sem banco e sem rede (series_build, series_twr,
//       quotes_carry, indices_normalize...). E' onde mora toda a regra dificil,
//       e' testavel isoladamente e e' IDENTICO em dev e producao.
//    2) I/O: leitura/gravacao no banco e download das fontes. E' a unica parte
//       que muda entre ambientes (producao ainda nao tem user_id/carteira_id).
//
//  Nomes de funcao em ingles (convencao do wallets.php), comentarios em PT-BR.
//  Arquivo de biblioteca: SEM efeitos colaterais no include. Bloqueado no .htaccess.
// ============================================================

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/vencimentos.php';   // MESES_OPCOES + vencimento_get() (calendario da Fase 2)

// ETFs usados como PROXY dos indices de bolsa. Decisao do ADR 015: reaproveita o
// COTAHIST que ja funciona — sem token, sem custo, e o S&P ja vem em reais.
// Em troca ha descolamento em relacao ao indice puro (taxa de administracao,
// tracking difference), por isso a UI SEMPRE rotula como proxy.
const INDICES_ETF = [
    'ibov'  => 'BOVA11',   // Ibovespa
    'sp500' => 'IVVB11',   // S&P 500 (em reais)
    'ifix'  => 'XFIX11',   // IFIX
];

// Series do Banco Central (SGS). CDI = % AO DIA; IPCA = % NO MES.
const INDICES_SGS = ['cdi' => 12, 'ipca' => 433];


// ════════════════════════════════════════════════════════════════════════════
//  1) NUCLEO PURO — so contas. Nao toca banco, nao toca rede.
// ════════════════════════════════════════════════════════════════════════════

// Mes da serie de uma opcao pela LETRA (5o caractere do codigo).
// CALL A-L = jan..dez | PUT M-X = jan..dez (mesmo mapa do vencimentos.php).
// Devolve null quando nao e' opcao (ETF/acao) ou a letra e' desconhecida.
function opcao_mes_serie($codigo) {
    $c = strtoupper(trim((string)$codigo));
    if (strlen($c) < 5) return null;
    $L = $c[4];
    foreach (MESES_OPCOES as $mes => $info) {
        if ($L === $info[1] || $L === $info[2]) return $mes;
    }
    return null;
}

// Data de vencimento de uma opcao ('YYYY-MM-DD') ou null se nao for opcao.
//
// O codigo da B3 diz o MES da serie mas NAO diz o ANO — por isso precisamos da
// data de inicio da posicao: o vencimento e' a primeira ocorrencia daquele mes
// a partir dela (ninguem compra uma opcao ja vencida). A data efetiva sai do
// calendario da Fase 2 (3a sexta-feira, com os ajustes manuais de feriado).
function opcao_vencimento($codigo, $dataInicio) {
    $mes = opcao_mes_serie($codigo);
    if ($mes === null || !$dataInicio) return null;
    $ano = (int)substr($dataInicio, 0, 4);
    $v   = vencimento_get($ano, $mes);
    if ($v < $dataInicio) $v = vencimento_get($ano + 1, $mes);   // serie do ano seguinte
    return $v;
}

// Carry-forward: preenche os dias SEM negocio com o ultimo fechamento conhecido.
//
// Opcao iliquida simplesmente nao aparece no COTAHIST do dia — o preco nao
// "sumiu", so nao houve negocio. Repetir o ultimo fechamento e' a leitura certa
// (e e' o que a propria corretora faz). Antes do 1o fechamento conhecido nao ha
// o que repetir: o dia fica sem valor e quem chama decide o fallback.
//
//   $bruto   = [CODIGO][DATA] => preco   (so os dias com negocio)
//   $pregoes = lista de datas 'YYYY-MM-DD' em ordem crescente
//   retorna  = [CODIGO][DATA] => ['preco'=>float, 'origem'=>'b3'|'estimado']
function quotes_carry($bruto, $pregoes) {
    $out = [];
    foreach ($bruto as $cod => $porData) {
        $ultimo = null;
        foreach ($pregoes as $d) {
            if (isset($porData[$d])) {
                $ultimo = (float)$porData[$d];
                $out[$cod][$d] = ['preco' => $ultimo, 'origem' => 'b3'];
            } elseif ($ultimo !== null) {
                $out[$cod][$d] = ['preco' => $ultimo, 'origem' => 'estimado'];
            }
        }
    }
    return $out;
}

// Preco de UMA posicao num pregao. Concentra as tres regras de borda:
//   1. Depois do vencimento a opcao vale R$ 0,00 — nao o ultimo preco negociado.
//      (Uma opcao que virou po nao continua "valendo" o que valia na vespera.)
//   2. Dia sem negocio: carry-forward, ja resolvido em quotes_carry().
//   3. Sem nenhum fechamento conhecido ainda (posicao nova, opcao que nunca
//      negociou): cai no preco medio de entrada — a melhor estimativa possivel,
//      e a que faz o P&L do dia comecar em zero em vez de inventar lucro.
function series_preco($pos, $data, $precos) {
    $cod = strtoupper(trim($pos['codigo']));
    $venc = opcao_vencimento($cod, $pos['data_inicio']);
    if ($venc !== null && $data > $venc) return 0.0;              // regra 1
    if (isset($precos[$cod][$data])) return (float)$precos[$cod][$data]['preco'];   // regras 1-2
    return (float)$pos['preco_medio'];                            // regra 3
}

// Data efetiva de um fluxo dentro da serie: o primeiro pregao >= a data do fluxo.
// Um aporte feito no sabado (ou antes do 1o pregao da serie) precisa cair em
// ALGUM pregao, senao ele vira "rentabilidade" no TWR — que e' exatamente o
// artefato que o TWR existe para evitar.
function series_pregao_de($data, $pregoes) {
    foreach ($pregoes as $d) {
        if ($d >= $data) return $d;
    }
    return null;   // fluxo posterior ao ultimo pregao: fica de fora da serie
}

// ── RECONSTRUCAO DA SERIE (funcao central da Fase 4.1) ───────────────────────
//
// Monta, para CADA pregao, a fotografia da carteira naquele dia. Funcao PURA:
// recebe os dados ja carregados e devolve as linhas — sem banco, sem rede.
//
//   $posicoes = linhas de apl_carteira (codigo, direcao, data_inicio, data_final,
//               preco_medio, lote, recebido, status)
//   $notas    = [{data_pregao, total_taxas, liquido, fluxo_caixa}]
//   $movs     = [{tipo:'aporte'|'saque', data, valor}]
//   $aporte   = aporte inicial da carteira (float) e $aporteData ('YYYY-MM-DD'|null)
//   $pregoes  = datas em ordem crescente
//   $precos   = saida de quotes_carry()
//
// CONVENCOES QUE NAO PODEM SER VIOLADAS:
//  - Vendida entra NEGATIVA no capital e no valor a mercado (mesma logica de
//    invNet()/posNet() do index.php) — e' o que bate com a corretora.
//  - "Aberta em D" = data_inicio <= D E (data_final nula OU data_final > D).
//    A posicao encerrada em D ja conta como realizado em D; contar nos dois
//    lugares seria dobrar o resultado.
//  - caixa segue a formula do extrato.php ("Disponivel para uso"), sem D+1.
//  - fluxo_externo e' SO aporte/saque. Nota de corretagem NAO e' fluxo externo:
//    ela troca caixa por posicao (e' interna). Marcar nota como aporte
//    contaminaria toda a rentabilidade.
function series_build($posicoes, $notas, $movs, $aporte, $aporteData, $pregoes, $precos) {
    if (!$pregoes) return [];

    // ── fluxo externo por pregao (aporte inicial + movimentacoes do Extrato) ──
    $fluxo = array_fill_keys($pregoes, 0.0);
    if ($aporte > 0) {
        $d = series_pregao_de($aporteData ?: $pregoes[0], $pregoes);
        if ($d !== null) $fluxo[$d] += (float)$aporte;
    }
    foreach ($movs as $m) {
        $v = ($m['tipo'] === 'saque') ? -abs((float)$m['valor']) : abs((float)$m['valor']);
        $d = series_pregao_de($m['data'], $pregoes);
        if ($d !== null) $fluxo[$d] += $v;
    }

    // ── vencimento de cada posicao, calculado uma vez so ─────────────────────
    foreach ($posicoes as &$p) {
        $p['_venc']  = opcao_vencimento($p['codigo'], $p['data_inicio']);
        $p['_sinal'] = ($p['direcao'] ?? 'C') === 'V' ? -1.0 : 1.0;
        $p['_lote']  = (int)$p['lote'];
        $p['_pm']    = (float)$p['preco_medio'];
    } unset($p);

    $linhas = [];
    foreach ($pregoes as $D) {
        $capital = 0.0; $mercado = 0.0; $realizado = 0.0;

        foreach ($posicoes as $p) {
            if ($p['data_inicio'] > $D) continue;                       // ainda nao existia

            $encerradaAte = !empty($p['data_final']) && $p['data_final'] <= $D;
            if ($encerradaAte) {
                // ja saiu da carteira: entra no REALIZADO acumulado, nunca na posicao
                if ($p['recebido'] !== null) {
                    $realizado += $p['_sinal'] * ((float)$p['recebido'] - $p['_pm']) * $p['_lote'];
                }
                continue;
            }

            // viva em D: capital ao preco de entrada, posicao ao preco de mercado
            $capital += $p['_sinal'] * $p['_pm'] * $p['_lote'];
            $mercado += $p['_sinal'] * series_preco($p, $D, $precos) * $p['_lote'];
        }

        // ── caixa: MESMA formula do extrato.php ("Disponivel para uso") ──────
        $caixa = 0.0;
        if ($aporte > 0 && ($aporteData === null || $aporteData <= $D)) $caixa += (float)$aporte;
        foreach ($movs as $m) {
            if ($m['data'] > $D) continue;
            $caixa += ($m['tipo'] === 'saque') ? -abs((float)$m['valor']) : abs((float)$m['valor']);
        }
        $custos = 0.0;
        foreach ($notas as $n) {
            if ($n['data_pregao'] > $D) continue;
            // nota sem fluxo_caixa informado = debito presumido (mesma regra do Extrato)
            $caixa  += ($n['fluxo_caixa'] === null) ? -abs((float)$n['liquido']) : (float)$n['fluxo_caixa'];
            $custos += (float)$n['total_taxas'];
        }

        $linhas[] = [
            'data'             => $D,
            'capital_aplicado' => round($capital, 2),
            'valor_mercado'    => round($mercado, 2),
            'caixa'            => round($caixa, 2),
            'patrimonio'       => round($caixa + $mercado, 2),
            'fluxo_externo'    => round($fluxo[$D], 2),
            'realizado_acum'   => round($realizado, 2),
            'custos_acum'      => round($custos, 2),
        ];
    }
    return $linhas;
}

// ── TWR — rentabilidade ponderada pelo TEMPO ─────────────────────────────────
//
//   r_D       = (patrimonio_D - fluxo_externo_D) / patrimonio_(D-1) - 1
//   acumulado = PRODUTO (1 + r_D) - 1
//
// Por que TWR e nao "quanto rendeu sobre o que pus": porque o TWR neutraliza
// aportes e saques e mede a qualidade das DECISOES — a unica metrica comparavel
// com CDI/Ibovespa na mesma linha. E' o que evita o -99,92% que a propria Rico
// exibe quando o investidor saca quase tudo (artefato de fluxo, nao performance).
// O resultado em R$ continua no card "Liquido total", que responde outra
// pergunta (o bolso).
//
// Bordas, definidas uma vez:
//  - patrimonio_(D-1) <= 0  -> r_D = 0 (nao existe base sobre a qual render)
//  - primeiro dia da janela -> r = 0 (a base e' ele mesmo; base 100)
//
// $baseAnterior = patrimonio do pregao ANTERIOR a janela (para uma janela
// recortada nao perder o retorno do 1o dia). null = comeco absoluto da serie.
function series_twr($linhas, $baseAnterior = null) {
    $acum = 1.0;
    $prev = $baseAnterior;
    foreach ($linhas as &$l) {
        $r = 0.0;
        if ($prev !== null && $prev > 0) {
            $r = (((float)$l['patrimonio'] - (float)$l['fluxo_externo']) / $prev) - 1.0;
        }
        $acum *= (1.0 + $r);
        $l['retorno_dia'] = round($r * 100, 6);       // % do dia
        $l['twr_acum']    = round(($acum - 1.0) * 100, 6);   // % acumulado na janela
        $prev = (float)$l['patrimonio'];
    } unset($l);
    return $linhas;
}

// ── CONSOLIDADO POR PERIODO (Fase 4.3) ──────────────────────────────────────
//
// A lente "A mercado" do bloco Ano -> Mes -> Dia. Uma posicao ABERTA nao
// pertence a um periodo — ela esta viva —, entao somar realizado com nao
// realizado por data produz numero que nao significa nada. A resposta certa e'
// a mesma da corretora: o resultado de um periodo e' a VARIACAO DO PATRIMONIO
// menos os APORTES do periodo. As abertas entram sozinhas, pela marcacao a
// mercado, sem precisar "pertencer" a mes nenhum.  (ADR 015 §7)
//
// Agrega UM periodo (lista de pregoes consecutivos):
//   $ls       = linhas da serie do periodo, ja passadas pelo series_twr()
//   $anterior = linha do pregao IMEDIATAMENTE anterior (null = comeco da serie)
//
// A rentabilidade do periodo e' a composicao dos retornos diarios — nunca
// "rendimento / patrimonio inicial", que aportes no meio do caminho falseiam.
// Como o retorno do 1o dia do periodo ja foi medido contra o fechamento do
// periodo ANTERIOR (o series_twr faz isso ao varrer a serie inteira), os meses
// compoem exatamente para o ano e para o acumulado. Foi justamente esse elo que
// faltava no `aceite` da 4.1 e fez os meses nao fecharem com o total (ADR 017).
function series_periodo_agrega($ls, $anterior) {
    $fim     = $ls[count($ls) - 1];
    $patrIni = $anterior ? (float)$anterior['patrimonio']     : 0.0;
    $realIni = $anterior ? (float)$anterior['realizado_acum'] : 0.0;
    $custIni = $anterior ? (float)$anterior['custos_acum']    : 0.0;

    $fluxo = 0.0; $fator = 1.0;
    foreach ($ls as $l) {
        $fluxo += (float)$l['fluxo_externo'];
        $fator *= 1.0 + ((float)($l['retorno_dia'] ?? 0)) / 100.0;
    }
    return [
        'de'         => $ls[0]['data'],
        'ate'        => $fim['data'],
        'pregoes'    => count($ls),
        'patr_ini'   => round($patrIni, 2),
        'patr_fim'   => round((float)$fim['patrimonio'], 2),
        'fluxo'      => round($fluxo, 2),
        // variacao do patrimonio DESCONTADOS aportes e saques — senao um deposito
        // de R$ 5.000 apareceria como "ganho" de R$ 5.000
        'rendimento' => round((float)$fim['patrimonio'] - $patrIni - $fluxo, 2),
        'twr'        => round(($fator - 1.0) * 100, 4),
        // de onde veio o rendimento: o que ja virou dinheiro e o que ainda e' preco
        'realizado'  => round((float)$fim['realizado_acum'] - $realIni, 2),
        'custos'     => round((float)$fim['custos_acum'] - $custIni, 2),
    ];
}

// Arvore Ano -> Mes -> Dia da serie inteira, em ordem DECRESCENTE (mais recente
// primeiro, como o bloco ja exibe hoje). Funcao PURA: recebe a serie ja com TWR.
function series_periodos($linhas) {
    if (!$linhas) return [];

    $arv = [];
    foreach ($linhas as $i => $l) {
        $y = substr($l['data'], 0, 4); $m = substr($l['data'], 5, 2); $d = substr($l['data'], 8, 2);
        $arv[$y]['i'][]           = $i;
        $arv[$y]['m'][$m]['i'][]  = $i;
        $arv[$y]['m'][$m]['d'][$d][] = $i;
    }
    // agrega pelos INDICES: o pregao anterior ao periodo e' simplesmente o de
    // indice-1 na serie — nao ha o que procurar no banco.
    $agg = function ($idx) use ($linhas) {
        return series_periodo_agrega(
            array_map(fn($i) => $linhas[$i], $idx),
            $idx[0] > 0 ? $linhas[$idx[0] - 1] : null
        );
    };

    // PHP transforma chave numerica em int ('2026' vira 2026, '10' vira 10, mas
    // '07' continua string) — por isso o (string) explicito na ordenacao e na
    // saida: o JSON precisa sair com tipo estavel para o front nao ter surpresa.
    $desc = fn($a, $b) => strcmp((string)$b, (string)$a);
    $out = [];
    $anos = array_keys($arv); usort($anos, $desc);
    foreach ($anos as $y) {
        $mesesOut = [];
        $meses = array_keys($arv[$y]['m']); usort($meses, $desc);
        foreach ($meses as $m) {
            $diasOut = [];
            $dias = array_keys($arv[$y]['m'][$m]['d']); usort($dias, $desc);
            foreach ($dias as $d) {
                $diasOut[] = ['dia' => (string)$d] + $agg($arv[$y]['m'][$m]['d'][$d]);
            }
            $mesesOut[] = ['mes' => (string)$m, 'dias' => $diasOut] + $agg($arv[$y]['m'][$m]['i']);
        }
        $out[] = ['ano' => (string)$y, 'meses' => $mesesOut] + $agg($arv[$y]['i']);
    }
    return $out;
}

// ── PAINEL DE SINAIS (Nitro) — nucleo PURO (ADR 021) ─────────────────────────
//
// Toda a matematica do gatilho de entrada mora AQUI, num lugar so, testavel por
// numero. A camada de I/O (o case 'sinais' do api.php) carrega posicoes+cotacoes
// e delega. A regra e' a mesma do resto do series.php: nada de reimplementar o
// preco de uma opcao — reusa opcao_vencimento()/series_preco()/quotes_carry().

// Mediana pura de uma lista de numeros (null se vazia).
function mediana($xs) {
    $xs = array_values(array_filter($xs, fn($v) => $v !== null));
    if (!$xs) return null;
    sort($xs);
    $n = count($xs); $m = intdiv($n, 2);
    return ($n % 2) ? (float)$xs[$m] : ($xs[$m - 1] + $xs[$m]) / 2.0;
}

// Trajetoria de rentabilidade de UMA posicao comprada do Nitro ao longo da vida.
//
//   $pos     = ['codigo','data_inicio','data_final'(|null),'pago','recebido'(|null)]
//   $pregoes = todos os pregoes conhecidos, ordem crescente
//   $precos  = saida de quotes_carry() (ja com carry-forward)
//   $margem  = quantos pregoes ANTES do vencimento a janela deve parar
//              (o usuario NUNCA segura ate vencer — ADR 021).
//
// Devolve:
//   'cobertura' => true se ha preco conhecido em/antes da data_inicio (base do estudo);
//                  false = a posicao caiu na regra 3 (fallback ao pago) no 1o dia,
//                  logo a trajetoria e' inventada e nao entra no estudo.
//   'rent'      => [DATA => rent%]  ((preco-pago)/pago*100) ate o fim da janela
//   'pico'      => maior rent% da janela (null se sem pontos)
//   'venc'      => vencimento efetivo (ou null)
//   'fim'       => ultima DATA considerada (venc-margem, capado no ultimo pregao)
//
// ⚠️ A janela NAO e' cortada na data_final DE PROPOSITO. A tese da ADR 021 e' que
// as recomendacoes "encerram cedo" e teriam ido mais alto SE SEGURADAS — o pico de
// referencia e o "do fecho ate 140%" so existem se olharmos a vida da opcao ALEM do
// fecho da recomendacao. Quem precisa saber ate quando a recomendacao esteve viva
// (o CRUZAMENTO do gatilho de entrada) e' o sinais_eventos(), que recebe a data_final
// a' parte. Aqui a janela vai ate venc-margem (o usuario nunca segura ate vencer).
//
// pago==0 => trajetoria vazia (rentabilidade indefinida).
function sinais_trajetoria($pos, $pregoes, $precos, $margem = 0) {
    $pago = (float)$pos['pago'];
    $cod  = strtoupper(trim($pos['codigo']));
    $ini  = $pos['data_inicio'];
    // series_preco espera 'preco_medio' como fallback (regra 3); no Nitro e' o pago.
    $pp = ['codigo' => $cod, 'data_inicio' => $ini, 'preco_medio' => $pago];

    // ha fechamento conhecido em/antes do inicio? (define "cobertura total")
    $cobertura = false;
    if (isset($precos[$cod])) {
        foreach ($precos[$cod] as $d => $_) { if ($d <= $ini) { $cobertura = true; break; } }
    }

    // fim da janela: venc-margem (a margem recua $margem pregoes a partir do 1o
    // pregao >= vencimento — mas SO quando o vencimento cai DENTRO do historico de
    // precos; opcao longa cujo vencimento esta no futuro corre ate o ultimo pregao).
    $venc = opcao_vencimento($cod, $ini);
    $fim = null;
    if ($venc !== null) {
        $idxVenc = null;
        foreach ($pregoes as $i => $d) { if ($d >= $venc) { $idxVenc = $i; break; } }
        if ($idxVenc !== null) {                          // vencimento dentro dos dados
            $k = $idxVenc - (int)$margem;
            $fim = $k > 0 ? $pregoes[$k] : $pregoes[0];
        }
        // idxVenc null = vencimento alem dos dados => sem corte (fim fica null)
    }

    $rent = []; $pico = null;
    if ($pago > 0) {
        foreach ($pregoes as $d) {
            if ($d < $ini) continue;
            if ($fim !== null && $d > $fim) break;
            $preco = series_preco($pp, $d, $precos);
            $r = ($preco - $pago) / $pago * 100.0;
            $rent[$d] = $r;
            if ($pico === null || $r > $pico) $pico = $r;
        }
    }
    return ['cobertura' => $cobertura, 'rent' => $rent, 'pico' => $pico, 'venc' => $venc, 'fim' => $fim];
}

// A partir de uma trajetoria (saida de sinais_trajetoria) + a data_final + limiares,
// resume os eventos do estudo. PURA — sem banco, sem datas de hoje.
//
// DUAS JANELAS, de proposito (ADR 021):
//  - CRUZAMENTO do gatilho (40/50%) = so enquanto a recomendacao esteve VIVA
//    (ate a $dataFinal). E' o momento em que o Nitro te daria o sinal de ENTRADA.
//    $dataFinal null (posicao ainda aberta) => a vida toda conta.
//  - ALVO (>=140%) e o tempo ate ele = na vida TODA da opcao (potencial se segurada,
//    inclusive DEPOIS do fecho) — e' a tese "teriam ido mais alto se seguradas".
//
//   'atingiu_alvo'  => bool (pico >= alvo, vida toda)
//   'cruzou_alerta' => bool · 'cruzou_sinal' => bool  (dentro da recomendacao)
//   'recuperou'     => recuou abaixo do gatilho e VOLTOU a atingir o alvo depois
//                      (vida toda — a ambivalencia do "recuou").
//   'dias_ate_alvo' => dias corridos do FECHO da recomendacao ($dataFinal) ao 1o dia
//                      >= alvo (ADR: "do fecho da recomendacao ate bater 140%"). Se
//                      o alvo foi batido ANTES do fecho, 0. Sem fecho: do inicio.
function sinais_eventos($traj, $dataFinal, $gAlerta, $gSinal, $alvo) {
    $rent = $traj['rent'];
    $pico = $traj['pico'];
    $out = [
        'atingiu_alvo' => ($pico !== null && $pico >= $alvo),
        'cruzou_alerta' => false, 'cruzou_sinal' => false,
        'recuperou' => false, 'dias_ate_alvo' => null,
    ];
    if (!$rent) return $out;

    $dAlvo = null; $jaCruzou = false; $recuou = false;
    foreach ($rent as $d => $r) {
        // cruzamento do gatilho de entrada: so vale com a recomendacao viva
        if ($dataFinal === null || $d <= $dataFinal) {
            if ($r >= $gAlerta) $out['cruzou_alerta'] = true;
            if ($r >= $gSinal)  $out['cruzou_sinal']  = true;
        }
        // ambivalencia do "recuou": cruzou o gatilho, caiu abaixo e voltou ao alvo (vida toda)
        if ($r >= $gAlerta) $jaCruzou = true;
        elseif ($jaCruzou)  $recuou = true;
        if ($r >= $alvo && $dAlvo === null) $dAlvo = $d;          // 1o dia no alvo (vida toda)
        if ($r >= $alvo && $recuou) $out['recuperou'] = true;
    }
    if ($dAlvo !== null) {
        $ref = $dataFinal ?: array_key_first($rent);             // do fecho (ou do inicio, se aberta)
        $out['dias_ate_alvo'] = max(0, (int)round((strtotime($dAlvo) - strtotime($ref)) / 86400));
    }
    return $out;
}

// ── RENTABILIDADE DE UMA CARTEIRA SIMPLES (Nitro/Betha) — ADR 022 ─────────────
//
// A Nitro e a Betha nao tem caixa (sem aportes/notas), entao nao ha TWR "natural"
// como na Carteira rica. A metrica comparavel e honesta e' o ROI ACUMULADO
// PONDERADO PELO CAPITAL (regra de ouro nº1): a cada pregao, o retorno medio das
// operacoes ponderado pelo capital investido (`pago`). Funcao PURA.
//
//   $posicoes = linhas de apl_posicoes/apl_betha (codigo,direcao,data_inicio,
//               data_final,pago,recebido,status)
//   $pregoes  = datas em ordem crescente
//   $precos   = saida de quotes_carry()  ([cod][data] => ['preco'=>..])
//
// Marca de cada posicao em D:
//  - encerrada em/antes de D (com preco de saida): o `recebido` de saida, congelado;
//  - senao: series_preco() (as 3 regras de borda — vencida vira pó, carry-forward,
//    fallback = pago => 0%). Vendida inverte o sinal (lucra quando o premio cai).
//
// Devolve ['pontos'=>[DATA=>roi%], 'cobertura'=>fracao do capital cujos codigos
// tem cotacao conhecida na janela (0..1)]. Cobertura baixa = "sem dados" honesto
// (o chamador decide nao desenhar uma linha achatada enganosa).
function sim_rentabilidade($posicoes, $pregoes, $precos) {
    $ps = []; $capTotal = 0.0; $capComCota = 0.0;
    foreach ($posicoes as $p) {
        $pago = (float)$p['pago'];
        if ($pago <= 0) continue;
        $cod = strtoupper(trim($p['codigo']));
        $ps[] = [
            'ini'  => $p['data_inicio'],
            'fim'  => $p['data_final'],                                  // null se aberta
            'pago' => $pago,
            'sign' => (($p['direcao'] ?? 'C') === 'V') ? -1.0 : 1.0,     // vendida inverte
            'exit' => ($p['recebido'] !== null ? (float)$p['recebido'] : null),
            'pp'   => ['codigo' => $cod, 'data_inicio' => $p['data_inicio'], 'preco_medio' => $pago],
        ];
        $capTotal += $pago;
        if (isset($precos[$cod])) $capComCota += $pago;
    }
    $pontos = [];
    foreach ($pregoes as $d) {
        $num = 0.0; $den = 0.0;
        foreach ($ps as $q) {
            if ($q['ini'] > $d) continue;                               // ainda nao comecou
            if ($q['fim'] !== null && $q['fim'] <= $d && $q['exit'] !== null) {
                $mark = $q['exit'];                                     // saida congelada
            } else {
                $mark = series_preco($q['pp'], $d, $precos);            // 3 regras de borda
            }
            $ret = ($mark - $q['pago']) / $q['pago'] * $q['sign'];
            $num += $ret * $q['pago'];
            $den += $q['pago'];
        }
        $pontos[$d] = $den > 0 ? $num / $den * 100.0 : 0.0;
    }
    return ['pontos' => $pontos, 'cobertura' => $capTotal > 0 ? $capComCota / $capTotal : 0.0];
}

// ── SOMA PURA DOS PERCENTUAIS de uma carteira ao longo do tempo ──────────────
//
// Para a aba "Carteiras" do grafico (confronto Minha carteira x Nitro x Betha):
// as tres linhas medidas na MESMA base, a SOMA PURA do % de cada operacao, sem
// ponderar pelo capital. E' o que torna as carteiras comparaveis lado a lado —
// nao da' para confrontar -686% do Nitro (soma pura) com -2,4% da carteira rica
// (ponderado pelos valores, casado com a corretora): base de calculo diferente.
// So' aqui a carteira rica troca de base. Funcao PURA.
//
// Em cada pregao D somamos o retorno % de cada posicao ja iniciada (ini <= D),
// marcada em D pela saida congelada (se encerrada ate D) ou pelas 3 regras de
// borda (series_preco). Vendida inverte o sinal. Diferente de sim_rentabilidade()
// (que e' a MEDIA ponderada pelo capital) — aqui e' a SOMA, para bater com os cards.
//
//   $custoKey = 'preco_medio' (carteira rica) | 'pago' (Nitro/Betha)
//
// Devolve ['pontos'=>[DATA=>soma%], 'cobertura'=>fracao das posicoes cujos codigos
// tem cotacao na janela] — mesma honestidade de "sem dados" do sim_rentabilidade.
function sim_soma_pct($posicoes, $pregoes, $precos, $custoKey) {
    $ps = []; $nTotal = 0; $nComCota = 0;
    foreach ($posicoes as $p) {
        $custo = (float)($p[$custoKey] ?? 0);
        if ($custo <= 0) continue;
        $cod = strtoupper(trim($p['codigo']));
        $ps[] = [
            'ini'   => $p['data_inicio'],
            'fim'   => $p['data_final'],                                 // null se aberta
            'custo' => $custo,
            'sign'  => (($p['direcao'] ?? 'C') === 'V') ? -1.0 : 1.0,    // vendida inverte
            'exit'  => ($p['recebido'] !== null ? (float)$p['recebido'] : null),
            'pp'    => ['codigo' => $cod, 'data_inicio' => $p['data_inicio'], 'preco_medio' => $custo],
        ];
        $nTotal++;
        if (isset($precos[$cod])) $nComCota++;
    }
    $pontos = [];
    foreach ($pregoes as $d) {
        $soma = 0.0;
        foreach ($ps as $q) {
            if ($q['ini'] > $d) continue;                               // ainda nao comecou
            if ($q['fim'] !== null && $q['fim'] <= $d && $q['exit'] !== null) {
                $mark = $q['exit'];                                     // saida congelada
            } else {
                $mark = series_preco($q['pp'], $d, $precos);            // 3 regras de borda
            }
            $soma += ($mark - $q['custo']) / $q['custo'] * $q['sign'] * 100.0;
        }
        $pontos[$d] = $soma;
    }
    return ['pontos' => $pontos, 'cobertura' => $nTotal > 0 ? $nComCota / $nTotal : 0.0];
}

// ── NORMALIZACAO DOS INDICES — tudo vira base 100 na janela ──────────────────
//
// Cada indice chega numa unidade diferente (CDI = % ao dia, IPCA = % ao mes,
// ETF = preco). Aqui todos viram a MESMA coisa: um fator acumulado com base 100
// no primeiro pregao da janela, que e' o que o grafico consegue desenhar junto
// com o TWR da carteira.
//
//   $valores = [DATA => valor] do indice (cru, como esta no banco)
//   $pregoes = janela, em ordem crescente
//   $tipo    = 'dia' (CDI: % ao dia) | 'mes' (IPCA: % ao mes) | 'preco' (ETF)
//
// $pregaoAnterior = pregao IMEDIATAMENTE ANTES da janela, quando ele existe
// (Fase 4.2). Numa janela RECORTADA ("ultimo mes") o TWR da carteira mede o
// retorno do 1o dia contra o fechamento anterior — se o indice comecasse
// achatado em 100 nesse mesmo dia, a carteira ganharia um dia de vantagem
// sobre TODO comparador. Com o pregao anterior como base os dois medem a mesma
// coisa. Sem ele (comeco do historico / "Max") o 1o dia nao rende, igual ao TWR.
function indices_normalize($valores, $pregoes, $tipo, $pregaoAnterior = null) {
    if (!$pregoes) return [];
    $out = [];

    if ($pregaoAnterior !== null && $pregaoAnterior < $pregoes[0]) {
        // normaliza a janela ESTENDIDA e depois rebaseia no pregao anterior
        $ext  = indices_normalize($valores, array_merge([$pregaoAnterior], $pregoes), $tipo);
        $base = $ext[$pregaoAnterior] ?? null;
        unset($ext[$pregaoAnterior]);
        if ($base === null || $base <= 0) return $ext;   // sem base utilizavel: fica como estava
        foreach ($ext as $d => $v) $out[$d] = ($v === null) ? null : round($v / $base * 100, 6);
        return $out;
    }

    if ($tipo === 'preco') {
        // ETF: fator = preco / preco_base. Dia sem negocio repete o ultimo preco.
        $base = null; $ultimo = null;
        foreach ($pregoes as $d) {
            if (isset($valores[$d])) $ultimo = (float)$valores[$d];
            if ($ultimo === null) continue;                 // ainda sem preco: sem ponto
            if ($base === null) $base = $ultimo;
            $out[$d] = $base > 0 ? round($ultimo / $base * 100, 6) : null;
        }
        return $out;
    }

    if ($tipo === 'mes') {
        // IPCA e' MENSAL e sai com ~2 semanas de atraso. Para nao virar uma
        // escada feia (e enganosa) no grafico, o percentual do mes e' diluido
        // proporcionalmente pelos dias do mes ja decorridos. Mes ainda nao
        // publicado = 0% (a linha fica plana ate o IBGE divulgar) — melhor
        // ficar plano do que extrapolar um numero que ainda nao existe.
        $fator = 1.0;
        $mesAnt = null;
        $bruto = [];
        foreach ($pregoes as $d) {
            $mesRef = substr($d, 0, 7) . '-01';
            if ($mesAnt !== null && $mesRef !== $mesAnt) {
                $fator *= 1.0 + ((float)($valores[$mesAnt] ?? 0)) / 100.0;   // fecha o mes anterior
            }
            $mesAnt = $mesRef;
            $pct    = (float)($valores[$mesRef] ?? 0);
            $dia    = (int)substr($d, 8, 2);
            $noMes  = (int)date('t', strtotime($d));
            $bruto[$d] = $fator * pow(1.0 + $pct / 100.0, $dia / $noMes);
        }
        // rebase: o 1o pregao da janela vale 100 (a janela pode comecar no meio do mes)
        $base = reset($bruto);
        foreach ($bruto as $d => $v) $out[$d] = round($base > 0 ? $v / $base * 100 : 100, 6);
        return $out;
    }

    // 'dia' (CDI): composicao diaria. Dia sem taxa publicada (feriado) nao rende.
    //
    // O 1o pregao da janela vale 100 e NAO rende: ele e' a base, igual ao 1o dia
    // do TWR da carteira (que tambem comeca em 0%). Compor o CDI ja no 1o dia
    // daria a ele um dia de juros de brinde e o comparador nasceria na frente.
    $fator = 1.0;
    $primeiro = true;
    foreach ($pregoes as $d) {
        if (!$primeiro) $fator *= 1.0 + ((float)($valores[$d] ?? 0)) / 100.0;
        $primeiro = false;
        $out[$d] = round($fator * 100, 6);
    }
    return $out;
}


// ════════════════════════════════════════════════════════════════════════════
//  2) I/O — banco e rede. Unica parte que muda entre dev e producao.
// ════════════════════════════════════════════════════════════════════════════

// ── B3 COTAHIST diario: baixa o ZIP oficial e devolve [CODNEG => ultimo preco] ─
//
// Fonte gratuita e oficial da B3. Le OPCOES (TPMERC 070/080) e, desde a Fase 4.1,
// tambem o MERCADO A VISTA (010) — e' de onde saem os ETFs-proxy dos indices.
// Aceitar 010 nao afeta as opcoes: os dois passam pelo mesmo laco e os codigos
// nunca colidem (PETR4 x PETRS437).
//
//   $codigos = null  -> devolve tudo que foi lido (comportamento antigo)
//   $codigos = [...] -> devolve so esses (usado no backfill; economiza memoria)
//
// Layout do registro tipo 01: CODNEG pos 13-24, TPMERC pos 25-27, PREULT pos
// 109-121 (2 casas implicitas).
function b3Cotahist($dateYmd, $codigos = null) {
    throw new RuntimeException('Integracoes externas desativadas nesta versao de portfolio.');
    $d = DateTime::createFromFormat('Y-m-d', $dateYmd);
    if (!$d) return null;
    $url = 'https://bvmf.bmfbovespa.com.br/InstDados/SerHist/COTAHIST_D' . $d->format('dmY') . '.ZIP';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 45,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; NitroCarteira/1.0)',
    ]);
    $bin  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($code != 200 || !$bin || strlen($bin) < 1000) return null;

    $tmp = tempnam(sys_get_temp_dir(), 'cot');
    file_put_contents($tmp, $bin);
    $zip = new ZipArchive();
    if ($zip->open($tmp) !== true) { @unlink($tmp); return null; }
    $txt = $zip->getFromIndex(0);
    $zip->close();
    @unlink($tmp);
    if (!$txt) return null;

    $filtro = $codigos === null ? null : array_flip(array_map('strtoupper', $codigos));

    $map = [];
    $tok = strtok($txt, "\n");
    while ($tok !== false) {
        if (substr($tok, 0, 2) === '01') {
            $tpmerc = substr($tok, 24, 3);
            // 070/080 = opcao de compra/venda  |  010 = mercado a vista (acoes e ETFs)
            if ($tpmerc === '070' || $tpmerc === '080' || $tpmerc === '010') {
                $cod = strtoupper(trim(substr($tok, 12, 12)));
                if ($cod !== '' && ($filtro === null || isset($filtro[$cod]))) {
                    $map[$cod] = (float)substr($tok, 108, 13) / 100.0;
                }
            }
        }
        $tok = strtok("\n");
    }
    return $map;
}

// ── Acha o mapa do ultimo pregao disponivel (recua ate achar arquivo) ────────
function b3UltimoPregao() {
    $hoje = new DateTime('today');
    for ($i = 0; $i < 8; $i++) {
        $try = (clone $hoje)->modify("-$i day");
        if ((int)$try->format('N') >= 6) continue;          // pula sab/dom
        $map = b3Cotahist($try->format('Y-m-d'));
        if ($map !== null && count($map)) return ['ref' => $try->format('Y-m-d'), 'map' => $map];
    }
    return null;
}

// Codigos que valem a pena guardar: os que o usuario ja teve (as duas carteiras)
// + os ETFs-proxy. Guardar o COTAHIST inteiro seriam ~10 mil linhas POR DIA;
// filtrando sao ~2.600 linhas no historico todo.
// A tabela e' GLOBAL, entao a lista tambem e' global (todos os usuarios).
function quotes_codigos($pdo) {
    $cods = [];
    foreach (['apl_carteira', 'apl_posicoes', 'apl_betha'] as $t) {
        foreach ($pdo->query("SELECT DISTINCT codigo FROM `$t`")->fetchAll(PDO::FETCH_COLUMN) as $c) {
            $c = strtoupper(trim($c));
            if ($c !== '') $cods[$c] = true;
        }
    }
    foreach (INDICES_ETF as $etf) $cods[$etf] = true;
    return array_keys($cods);
}

// ── BACKFILL: baixa pregao a pregao e grava em apl_cotacoes ──────────────────
//
// Sempre pelos arquivos DIARIOS (~450 KB cada), nunca pelo anual de 66 MB:
// ZipArchive::getFromIndex() carrega a entrada inteira em memoria e o anual
//
// $refazer=false pula os dias que ja tem alguma linha (rodadas incrementais
// ficam baratas). Quando entram CODIGOS NOVOS na carteira e' preciso rodar com
// $refazer=true na janela deles, senao os dias ja baixados ficam sem esses
// codigos. quotes_faltantes() abaixo aponta exatamente esse caso.
function quotes_backfill($pdo, $de, $ate, $refazer = false, $log = null) {
    $codigos = quotes_codigos($pdo);
    $jaTem   = [];
    foreach ($pdo->query("SELECT DISTINCT data FROM apl_cotacoes")->fetchAll(PDO::FETCH_COLUMN) as $d) $jaTem[$d] = true;

    $ins = $pdo->prepare("INSERT INTO apl_cotacoes (codigo,data,fechamento,origem) VALUES (?,?,?, 'b3')
                          ON DUPLICATE KEY UPDATE fechamento=VALUES(fechamento), origem=VALUES(origem)");

    $cur  = new DateTime($de);
    $fim  = new DateTime($ate);
    $res  = ['pregoes' => 0, 'pulados' => 0, 'sem_arquivo' => [], 'linhas' => 0];
    while ($cur <= $fim) {
        $d = $cur->format('Y-m-d');
        $cur->modify('+1 day');
        if ((int)(new DateTime($d))->format('N') >= 6) continue;       // sab/dom nao tem pregao
        if (!$refazer && isset($jaTem[$d])) { $res['pulados']++; continue; }

        $map = b3Cotahist($d, $codigos);
        if ($map === null) { $res['sem_arquivo'][] = $d; if ($log) $log("  $d  sem arquivo (feriado?)"); continue; }
        if (!count($map))  { $res['sem_arquivo'][] = $d; if ($log) $log("  $d  arquivo vazio p/ os codigos pedidos"); continue; }

        $pdo->beginTransaction();
        foreach ($map as $cod => $preco) {
            if ($preco <= 0) continue;          // sem negocio no dia: nao inventa linha
            $ins->execute([$cod, $d, $preco]);
            $res['linhas']++;
        }
        $pdo->commit();
        $res['pregoes']++;
        if ($log) $log("  $d  " . count($map) . " codigos");
    }
    return $res;
}

// Grava em apl_cotacoes os codigos RELEVANTES de um mapa ja baixado do COTAHIST.
// Usada pelo quote_preview: o boletim do dia ja esta na mao, entao memorizar o
// fechamento sai de graca — e' o que mantem a serie viva no uso normal, sem cron.
// Devolve quantas linhas foram gravadas.
function quotes_memorizar($pdo, $data, $map) {
    if (!$data || !is_array($map) || !$map) return 0;
    $alvo = array_flip(quotes_codigos($pdo));
    $ins  = $pdo->prepare("INSERT INTO apl_cotacoes (codigo,data,fechamento,origem) VALUES (?,?,?, 'b3')
                           ON DUPLICATE KEY UPDATE fechamento=VALUES(fechamento), origem=VALUES(origem)");
    $n = 0;
    foreach ($map as $cod => $preco) {
        $cod = strtoupper(trim($cod));
        if ($preco <= 0 || !isset($alvo[$cod])) continue;
        $ins->execute([$cod, $data, $preco]);
        $n++;
    }
    return $n;
}

// Codigos relevantes que NAO tem nenhuma linha em apl_cotacoes — normalmente
// posicoes novas cujos dias ja tinham sido baixados antes de elas existirem.
// Rodar quotes_backfill(..., $refazer=true) resolve.
function quotes_faltantes($pdo) {
    $tem = $pdo->query("SELECT DISTINCT codigo FROM apl_cotacoes")->fetchAll(PDO::FETCH_COLUMN);
    $tem = array_flip($tem);
    $out = [];
    foreach (quotes_codigos($pdo) as $c) if (!isset($tem[$c])) $out[] = $c;
    return $out;
}

// Pregoes conhecidos (as datas que existem em apl_cotacoes) dentro de uma janela.
// E' a nossa definicao operacional de "dia util da B3": se baixamos o COTAHIST
// daquele dia, houve pregao. Os ETFs-proxy garantem ao menos uma linha por dia
// (BOVA11 negocia todo pregao), entao nenhum dia util some da lista.
function quotes_pregoes($pdo, $de = null, $ate = null) {
    $sql = "SELECT DISTINCT data FROM apl_cotacoes";
    $w = []; $p = [];
    if ($de)  { $w[] = "data >= ?"; $p[] = $de; }
    if ($ate) { $w[] = "data <= ?"; $p[] = $ate; }
    if ($w) $sql .= " WHERE " . implode(' AND ', $w);
    $sql .= " ORDER BY data ASC";
    $st = $pdo->prepare($sql);
    $st->execute($p);
    return $st->fetchAll(PDO::FETCH_COLUMN);
}

// Precos brutos ([CODIGO][DATA] => preco) dos codigos pedidos numa janela.
function quotes_load($pdo, $codigos, $de, $ate) {
    if (!$codigos) return [];
    $in = implode(',', array_fill(0, count($codigos), '?'));
    $st = $pdo->prepare("SELECT codigo, data, fechamento FROM apl_cotacoes
                         WHERE codigo IN ($in) AND data BETWEEN ? AND ?");
    $st->execute([...array_map('strtoupper', $codigos), $de, $ate]);
    $out = [];
    foreach ($st as $r) $out[strtoupper($r['codigo'])][$r['data']] = (float)$r['fechamento'];
    return $out;
}

// ── SERIE: reconstroi e MATERIALIZA a serie de uma carteira ──────────────────
//
// A tabela apl_serie_diaria e' cache, nao fonte de verdade: apaga e reescreve
// a janela inteira. Idempotente por construcao — rodar duas vezes da o mesmo
// resultado. Escopado por user_id E carteira_id (Fase 3): cada carteira rica
// e' uma conta isolada e tem a sua propria serie.
function series_rebuild($pdo, $uid, $cid) {
    $uid = (int)$uid; $cid = (int)$cid;

    $st = $pdo->prepare("SELECT codigo,direcao,data_inicio,data_final,preco_medio,lote,recebido,status
                         FROM apl_carteira WHERE user_id=? AND carteira_id=?");
    $st->execute([$uid, $cid]);
    $posicoes = $st->fetchAll();
    if (!$posicoes) return ['linhas' => 0, 'de' => null, 'ate' => null];

    $st = $pdo->prepare("SELECT data_pregao,total_taxas,liquido,fluxo_caixa FROM apl_notas
                         WHERE user_id=? AND carteira_id=?");
    $st->execute([$uid, $cid]);
    $notas = $st->fetchAll();
    foreach ($notas as &$n) { $n['fluxo_caixa'] = $n['fluxo_caixa'] === null ? null : (float)$n['fluxo_caixa']; } unset($n);

    $st = $pdo->prepare("SELECT tipo,data,valor FROM apl_movimentacoes WHERE user_id=? AND carteira_id=?");
    $st->execute([$uid, $cid]);
    $movs = $st->fetchAll();

    $st = $pdo->prepare("SELECT aporte_inicial, aporte_inicial_data FROM apl_carteiras WHERE id=? AND user_id=?");
    $st->execute([$cid, $uid]);
    $w = $st->fetch();
    $aporte     = ($w && $w['aporte_inicial'] !== null) ? (float)$w['aporte_inicial'] : 0.0;
    $aporteData = $w['aporte_inicial_data'] ?? null;

    // janela: da 1a operacao ate o ultimo pregao que temos em memoria
    $de = min(array_column($posicoes, 'data_inicio'));
    $pregoes = quotes_pregoes($pdo, $de);
    if (!$pregoes) return ['linhas' => 0, 'de' => $de, 'ate' => null, 'aviso' => 'sem cotacoes: rode o backfill antes'];

    $codigos = array_values(array_unique(array_map(fn($p) => strtoupper($p['codigo']), $posicoes)));
    $precos  = quotes_carry(quotes_load($pdo, $codigos, $pregoes[0], end($pregoes)), $pregoes);

    $linhas = series_build($posicoes, $notas, $movs, $aporte, $aporteData, $pregoes, $precos);

    $pdo->beginTransaction();
    $pdo->prepare("DELETE FROM apl_serie_diaria WHERE user_id=? AND carteira_id=?")->execute([$uid, $cid]);
    $ins = $pdo->prepare("INSERT INTO apl_serie_diaria
        (user_id,carteira_id,data,capital_aplicado,valor_mercado,caixa,patrimonio,fluxo_externo,realizado_acum,custos_acum)
        VALUES (?,?,?,?,?,?,?,?,?,?)");
    foreach ($linhas as $l) {
        $ins->execute([$uid, $cid, $l['data'], $l['capital_aplicado'], $l['valor_mercado'], $l['caixa'],
                       $l['patrimonio'], $l['fluxo_externo'], $l['realizado_acum'], $l['custos_acum']]);
    }
    $pdo->commit();

    return ['linhas' => count($linhas), 'de' => $pregoes[0], 'ate' => end($pregoes)];
}

// Le a serie materializada de uma janela, ja com o TWR calculado.
// Busca tambem o patrimonio do pregao ANTERIOR a janela para servir de base —
// sem isso o 1o dia de um recorte (ex.: "ultimos 3 meses") perderia o retorno.
function series_load($pdo, $uid, $cid, $de = null, $ate = null) {
    $w = ["user_id=?", "carteira_id=?"]; $p = [(int)$uid, (int)$cid];
    if ($de)  { $w[] = "data >= ?"; $p[] = $de; }
    if ($ate) { $w[] = "data <= ?"; $p[] = $ate; }
    $st = $pdo->prepare("SELECT * FROM apl_serie_diaria WHERE " . implode(' AND ', $w) . " ORDER BY data ASC");
    $st->execute($p);
    $linhas = [];
    foreach ($st as $r) {
        $linhas[] = [
            'data'             => $r['data'],
            'capital_aplicado' => (float)$r['capital_aplicado'],
            'valor_mercado'    => (float)$r['valor_mercado'],
            'caixa'            => (float)$r['caixa'],
            'patrimonio'       => (float)$r['patrimonio'],
            'fluxo_externo'    => (float)$r['fluxo_externo'],
            'realizado_acum'   => (float)$r['realizado_acum'],
            'custos_acum'      => (float)$r['custos_acum'],
        ];
    }
    $base = null;
    if ($de && $linhas) {
        $b = $pdo->prepare("SELECT patrimonio FROM apl_serie_diaria WHERE user_id=? AND carteira_id=? AND data < ?
                            ORDER BY data DESC LIMIT 1");
        $b->execute([(int)$uid, (int)$cid, $de]);
        $v = $b->fetchColumn();
        if ($v !== false) $base = (float)$v;
    }
    return series_twr($linhas, $base);
}

// Data do pregao da serie IMEDIATAMENTE ANTERIOR a $de (ou null se a janela
// comeca no inicio da serie). E' exatamente a mesma condicao que o series_load
// usa para achar a base do TWR — os comparadores precisam da mesma referencia,
// senao a carteira mede um dia a mais que eles numa janela recortada (Fase 4.2).
function series_pregao_anterior($pdo, $uid, $cid, $de) {
    if (!$de) return null;
    $st = $pdo->prepare("SELECT data FROM apl_serie_diaria WHERE user_id=? AND carteira_id=? AND data < ?
                         ORDER BY data DESC LIMIT 1");
    $st->execute([(int)$uid, (int)$cid, $de]);
    $v = $st->fetchColumn();
    return $v === false ? null : $v;
}

// ── INDICES: importa CDI e IPCA da API do Banco Central (SGS) ────────────────
// CDI  = serie 12  (% ao dia, so em dia util)
// IPCA = serie 433 (% ao mes, data = 1o dia do mes, publicada com ~2 semanas de atraso)
function indices_import_bcb($pdo, $de, $ate) {
    throw new RuntimeException('Integracoes externas desativadas nesta versao de portfolio.');
    $ins = $pdo->prepare("INSERT INTO apl_indices (indice,data,valor) VALUES (?,?,?)
                          ON DUPLICATE KEY UPDATE valor=VALUES(valor)");
    $res = [];
    foreach (INDICES_SGS as $nome => $serie) {
        // O IPCA e' MENSAL e sai com ~2 semanas de atraso. Duas consequencias:
        //  - a janela precisa comecar antes (6 meses) para pegar o mes de
        //    abertura, senao a linha nasce torta;
        //  - se mesmo assim nao houver mes publicado no intervalo, a API do BCB
        //    responde 404. Isso e' "ainda nao divulgado", nao falha — a linha do
        //    IPCA so fica plana ate o IBGE publicar.
        $ini = ($nome === 'ipca') ? date('Y-m-01', strtotime($de . ' -6 months')) : $de;
        $url = sprintf(
            'https://api.bcb.gov.br/dados/serie/bcdata.sgs.%d/dados?formato=json&dataInicial=%s&dataFinal=%s',
            $serie, date('d/m/Y', strtotime($ini)), date('d/m/Y', strtotime($ate))
        );
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; NitroCarteira/1.0)',
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($code == 404) { $res[$nome] = ['linhas' => 0, 'nota' => 'sem divulgacao no periodo']; continue; }
        $dados = ($code == 200) ? json_decode($body, true) : null;
        if (!is_array($dados)) { $res[$nome] = ['erro' => 'HTTP ' . $code]; continue; }

        $n = 0;
        foreach ($dados as $d) {
            if (empty($d['data']) || !isset($d['valor'])) continue;
            $dt = DateTime::createFromFormat('d/m/Y', $d['data']);
            if (!$dt) continue;
            $ins->execute([$nome, $dt->format('Y-m-d'), (float)str_replace(',', '.', $d['valor'])]);
            $n++;
        }
        $res[$nome] = ['linhas' => $n];
    }
    return $res;
}

// ── INDICES: copia os ETFs-proxy de apl_cotacoes para apl_indices ────────────
// Nao ha download novo: o backfill ja trouxe BOVA11/IVVB11/XFIX11 junto com as
// opcoes (mesmo arquivo, mesmo passe). Aqui so damos a eles o nome do indice.
function indices_import_etfs($pdo, $de, $ate) {
    $ins = $pdo->prepare("INSERT INTO apl_indices (indice,data,valor)
                          SELECT ?, data, fechamento FROM apl_cotacoes
                          WHERE codigo=? AND data BETWEEN ? AND ?
                          ON DUPLICATE KEY UPDATE valor=VALUES(valor)");
    $res = [];
    foreach (INDICES_ETF as $nome => $etf) {
        $ins->execute([$nome, $etf, $de, $ate]);
        $res[$nome] = ['etf' => $etf, 'linhas' => $ins->rowCount()];
    }
    return $res;
}

// Le os indices pedidos numa janela, ja NORMALIZADOS em base 100 nos $pregoes.
// $quais = null -> todos. Devolve [indice => ['proxy'=>?, 'pontos'=>[data=>valor]]].
//
// $pregaoAnterior (Fase 4.2) = o pregao imediatamente antes da janela, quando a
// serie da carteira tambem tem base la (ver series_pregao_anterior). E' o que
// mantem carteira e comparadores medindo o MESMO intervalo numa janela recortada.
function indices_load($pdo, $pregoes, $quais = null, $pregaoAnterior = null) {
    if (!$pregoes) return [];
    $de = $pregoes[0]; $ate = end($pregoes);
    if ($pregaoAnterior !== null && $pregaoAnterior >= $de) $pregaoAnterior = null;
    $tipos = ['cdi' => 'dia', 'ipca' => 'mes', 'ibov' => 'preco', 'sp500' => 'preco', 'ifix' => 'preco'];
    $alvo  = $quais ? array_intersect(array_keys($tipos), $quais) : array_keys($tipos);

    $out = [];
    foreach ($alvo as $nome) {
        // o IPCA e' mensal: precisa do valor do mes de abertura, anterior a $de
        $base = $pregaoAnterior ?? $de;
        $ini  = ($tipos[$nome] === 'mes') ? substr($base, 0, 8) . '01' : $base;
        $st = $pdo->prepare("SELECT data, valor FROM apl_indices WHERE indice=? AND data BETWEEN ? AND ? ORDER BY data");
        $st->execute([$nome, $ini, $ate]);
        $vals = [];
        foreach ($st as $r) $vals[$r['data']] = (float)$r['valor'];
        $out[$nome] = [
            'proxy'  => INDICES_ETF[$nome] ?? null,   // != null -> a UI rotula "via BOVA11"
            'pontos' => indices_normalize($vals, $pregoes, $tipos[$nome], $pregaoAnterior),
        ];
    }
    return $out;
}
