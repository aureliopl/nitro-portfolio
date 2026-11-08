<?php
// ============================================================
//  FASE 4.1 — FERRAMENTA DE LINHA DE COMANDO da camada de historico.
//
//  Por que CLI e nao endpoint: o backfill baixa ~44 arquivos da B3 (um por
//  pregao) e leva minutos. Isso nao cabe num request web (timeout do PHP-FPM,
//  e o usuario ficaria olhando a tela travar). No uso do dia a dia a memoria
//  se mantem sozinha: o quote_preview grava o fechamento em apl_cotacoes toda
//  vez que o usuario clica em "Buscar cotacoes".
//
//  USO (no servidor, dentro da pasta do app):
//    php series_cli.php backfill [de] [ate] [--refazer]   baixa o COTAHIST dia a dia
//    php series_cli.php indices  [de] [ate]               CDI/IPCA (BCB) + ETFs-proxy
//    php series_cli.php rebuild                           materializa a serie de todas as carteiras
//    php series_cli.php cotacoes                           atualiza `recebido` das ABERTAS (todas as
//                                                          carteiras + Nitro) com o ultimo pregao + rebuild
//    php series_cli.php tudo     [de] [ate]               os tres, na ordem certa
//    php series_cli.php status                            o que ja existe em cada tabela
//    php series_cli.php aceite                            criterios de aceite da secao 10 do plano
//
//  O comando `cotacoes` foi feito para rodar por CRON (sem ninguem logado): baixa
//  o COTAHIST do ultimo pregao disponivel, grava o fechamento na coluna `recebido`
//  de TODA posicao aberta (Carteira rica de todos os usuarios/carteiras + Nitro),
//  memoriza os precos em apl_cotacoes e reconstroi as series. E' idempotente:
//  rodar duas vezes no mesmo pregao apenas regrava os mesmos valores.
//
//  Sem argumento de data, a janela vai da 1a operacao da carteira ate hoje.
//  Todos os comandos sao IDEMPOTENTES: rodar de novo nao duplica nada.
// ============================================================

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Esta ferramenta so roda por linha de comando.\n");
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/series.php';

$pdo = getDB();
$cmd = $argv[1] ?? 'status';
$arg = array_values(array_filter(array_slice($argv, 2), fn($a) => substr($a, 0, 2) !== '--'));
$flag = fn($f) => in_array('--' . $f, $argv, true);
$log = function ($m) { echo $m . "\n"; };

// Janela padrao: da 1a operacao da Carteira ate hoje.
function janelaPadrao($pdo, $arg) {
    $de  = $arg[0] ?? null;
    $ate = $arg[1] ?? null;
    if (!$de) {
        $de = $pdo->query("SELECT MIN(data_inicio) FROM apl_carteira")->fetchColumn();
        if (!$de) $de = date('Y-m-d', strtotime('-90 days'));
    }
    if (!$ate) $ate = date('Y-m-d');
    return [$de, $ate];
}

// Todas as carteiras ricas do banco (a serie e' por usuario E carteira).
function todasCarteiras($pdo) {
    return $pdo->query("SELECT id, user_id, nome FROM apl_carteiras ORDER BY user_id, id")->fetchAll();
}

switch ($cmd) {

case 'backfill':
    [$de, $ate] = janelaPadrao($pdo, $arg);
    $log("Backfill do COTAHIST diario: $de -> $ate" . ($flag('refazer') ? '  (refazendo dias ja baixados)' : ''));
    $log(count(quotes_codigos($pdo)) . " codigos relevantes (carteira + Nitro + ETFs-proxy)");
    $t = microtime(true);
    $r = quotes_backfill($pdo, $de, $ate, $flag('refazer'), $log);
    $log(sprintf("OK: %d pregoes baixados, %d pulados, %d linhas gravadas, %.0fs",
        $r['pregoes'], $r['pulados'], $r['linhas'], microtime(true) - $t));
    if ($r['sem_arquivo']) $log("Sem arquivo (feriado/sem pregao): " . implode(', ', $r['sem_arquivo']));
    $falt = quotes_faltantes($pdo);
    if ($falt) $log("AVISO: sem nenhuma cotacao ainda -> " . implode(' ', $falt) . "\n  (rode de novo com --refazer se forem codigos que ja negociaram)");
    break;

case 'indices':
    [$de, $ate] = janelaPadrao($pdo, $arg);
    $log("Indices: $de -> $ate");
    foreach (indices_import_bcb($pdo, $de, $ate) as $k => $v) $log("  BCB  $k: " . json_encode($v));
    foreach (indices_import_etfs($pdo, $de, $ate) as $k => $v) $log("  ETF  $k ({$v['etf']}): {$v['linhas']} linhas");
    break;

case 'rebuild':
    foreach (todasCarteiras($pdo) as $w) {
        $r = series_rebuild($pdo, $w['user_id'], $w['id']);
        $log(sprintf("carteira #%d (user %d) %-20s -> %d pregoes  %s..%s %s",
            $w['id'], $w['user_id'], $w['nome'], $r['linhas'], $r['de'] ?? '-', $r['ate'] ?? '-', $r['aviso'] ?? ''));
    }
    break;

case 'cotacoes':
    // Atualizacao automatica da coluna `recebido` das posicoes ABERTAS, feita
    // para rodar por CRON depois que o COTAHIST do dia sai (ver cabecalho).
    $ts  = date('Y-m-d H:i:s');
    $spo = (new DateTime('now', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d H:i:s');

    // Trava contra execucoes sobrepostas (dois crons colidindo).
    $lockfile = sys_get_temp_dir() . '/nitro_cotacoes.lock';
    $lock = fopen($lockfile, 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { $log("[$spo] outra execucao de 'cotacoes' em andamento — saindo."); break; }

    $res = b3UltimoPregao();  // recua ate achar arquivo; pula fim de semana
    if ($res === null) {
        $log("[$spo] COTAHIST indisponivel agora (o boletim do pregao ainda nao foi publicado?) — nada alterado.");
        break;
    }
    $ref = $res['ref'];
    $U = [];   // codigo (maiusculo) => fechamento > 0
    foreach ($res['map'] as $c => $p) if ($p > 0) $U[strtoupper(trim($c))] = (float)$p;
    $log("[$spo] pregao $ref — " . count($U) . " instrumentos com fechamento.");

    // 1) memoria de precos (e' o que alimenta a serie historica).
    $mem = quotes_memorizar($pdo, $ref, $res['map']);
    $log("  apl_cotacoes: $mem fechamentos memorizados.");

    // 2) `recebido` das abertas — so onde HOUVE negocio no dia (senao mantem o valor).
    //    Carteira rica (todas as carteiras de todos os usuarios) + Nitro.
    $updC = 0; $semC = 0;
    $stC = $pdo->prepare("UPDATE apl_carteira SET recebido=? WHERE id=?");
    foreach ($pdo->query("SELECT id, codigo FROM apl_carteira WHERE status='Aberta'") as $r) {
        $p = $U[strtoupper(trim($r['codigo']))] ?? null;
        if ($p === null) { $semC++; continue; }
        $stC->execute([$p, $r['id']]); $updC++;
    }
    $updN = 0; $semN = 0;
    $stN = $pdo->prepare("UPDATE apl_posicoes SET recebido=? WHERE id=?");
    foreach ($pdo->query("SELECT id, codigo FROM apl_posicoes WHERE status='Aberta'") as $r) {
        $p = $U[strtoupper(trim($r['codigo']))] ?? null;
        if ($p === null) { $semN++; continue; }
        $stN->execute([$p, $r['id']]); $updN++;
    }
    // Betha (carteira de simulacao, ADR 022) — mesmo tratamento da Nitro.
    $updB = 0; $semB = 0;
    $stB = $pdo->prepare("UPDATE apl_betha SET recebido=? WHERE id=?");
    foreach ($pdo->query("SELECT id, codigo FROM apl_betha WHERE status='Aberta'") as $r) {
        $p = $U[strtoupper(trim($r['codigo']))] ?? null;
        if ($p === null) { $semB++; continue; }
        $stB->execute([$p, $r['id']]); $updB++;
    }
    $log("  recebido: Carteira rica $updC atualizadas ($semC sem negocio no dia) | Nitro $updN ($semN sem negocio) | Betha $updB ($semB sem negocio).");

    // 3) reconstroi a serie de TODAS as carteiras (o novo pregao entra na historia).
    foreach (todasCarteiras($pdo) as $w) {
        $rr = series_rebuild($pdo, $w['user_id'], $w['id']);
        $log(sprintf("  serie #%d (user %d) %s -> %d pregoes.", $w['id'], $w['user_id'], $w['nome'], $rr['linhas']));
    }
    $log("[$spo] concluido (pregao $ref).");
    break;

case 'tudo':
    [$de, $ate] = janelaPadrao($pdo, $arg);
    foreach (['backfill', 'indices', 'rebuild'] as $c) {
        $log("\n=== $c ===");
        passthru(PHP_BINARY . ' ' . escapeshellarg(__FILE__) . " $c " . escapeshellarg($de) . ' ' . escapeshellarg($ate)
                 . ($flag('refazer') && $c === 'backfill' ? ' --refazer' : ''));
    }
    break;

case 'status':
    $n = fn($sql) => $pdo->query($sql)->fetch(PDO::FETCH_ASSOC);
    $c = $n("SELECT COUNT(*) linhas, COUNT(DISTINCT codigo) codigos, COUNT(DISTINCT data) pregoes, MIN(data) de, MAX(data) ate FROM apl_cotacoes");
    $log("apl_cotacoes     : {$c['linhas']} linhas | {$c['codigos']} codigos | {$c['pregoes']} pregoes | {$c['de']} .. {$c['ate']}");
    foreach ($pdo->query("SELECT indice, COUNT(*) n, MIN(data) de, MAX(data) ate FROM apl_indices GROUP BY indice") as $r) {
        $log("apl_indices      : {$r['indice']}  {$r['n']} linhas  {$r['de']} .. {$r['ate']}");
    }
    foreach ($pdo->query("SELECT user_id, carteira_id, COUNT(*) n, MIN(data) de, MAX(data) ate FROM apl_serie_diaria GROUP BY user_id, carteira_id") as $r) {
        $log("apl_serie_diaria : user {$r['user_id']} carteira {$r['carteira_id']}  {$r['n']} pregoes  {$r['de']} .. {$r['ate']}");
    }
    $falt = quotes_faltantes($pdo);
    if ($falt) $log("codigos SEM cotacao: " . implode(' ', $falt));
    break;

// ── CRITERIOS DE ACEITE (secao 10 do plano) ──────────────────────────────────
// "Sem isso, nao sobe." Compara a serie materializada com o que as telas do app
// ja mostram hoje. Se um numero nao bater, o problema esta na serie — as telas
// sao a referencia, porque elas e' que estao conferidas com a corretora.
case 'aceite':
    $falhas = 0;
    $checa = function ($rotulo, $obtido, $esperado, $tol = 0.01) use (&$falhas, $log) {
        $ok = abs((float)$obtido - (float)$esperado) <= $tol;
        if (!$ok) $falhas++;
        $log(sprintf("  [%s] %-34s serie=%12.2f   telas=%12.2f", $ok ? 'OK ' : 'X  ', $rotulo, $obtido, $esperado));
    };

    foreach (todasCarteiras($pdo) as $w) {
        $uid = (int)$w['user_id']; $cid = (int)$w['id'];
        $serie = series_load($pdo, $uid, $cid);
        if (!$serie) continue;
        $ult = $serie[count($serie) - 1];
        $log("\ncarteira #$cid (user $uid) \"{$w['nome']}\" — ultimo pregao {$ult['data']} (" . count($serie) . " pregoes)");

        // 1 e 2 — capital aplicado e valor a mercado das abertas (card Resultados).
        //
        // O card le a coluna `recebido` (o ultimo preco buscado); a serie le a
        // memoria de precos e aplica as regras de borda do plano. Quando os dois
        // divergem, o teste RECONCILIA posicao por posicao em vez de so reprovar —
        // divergencias legitimas conhecidas:
        //   a) opcao ja VENCIDA e ainda aberta  -> a serie zera (criterio 5) e o
        //      card segue exibindo o preco velho. A serie e' que esta certa.
        //   b) aberta com `recebido` NULL       -> o card ignora; a serie usa o
        //      preco medio (P&L zero) para nao abrir buraco na linha.
        //   c) codigo sem fechamento na memoria -> idem (b): a serie cai no
        //      preco medio. Em producao isso some depois de um backfill completo.
        // Se sobrar diferenca sem explicacao, o criterio FALHA.
        $pregoesJan = quotes_pregoes($pdo, $serie[0]['data'], $ult['data']);
        $st = $pdo->prepare("SELECT codigo, direcao, data_inicio, preco_medio, lote, recebido
                             FROM apl_carteira WHERE user_id=? AND carteira_id=? AND status='Aberta'");
        $st->execute([$uid, $cid]);
        $abertas = $st->fetchAll();
        $precosJan = $abertas
            ? quotes_carry(quotes_load($pdo, array_column($abertas, 'codigo'), $pregoesJan[0], end($pregoesJan)), $pregoesJan)
            : [];

        $capital = 0.0; $mercadoCard = 0.0; $mercadoSerie = 0.0; $ajustes = [];
        foreach ($abertas as $p) {
            $sinal = $p['direcao'] === 'V' ? -1.0 : 1.0;
            $lote  = (int)$p['lote'];
            $capital += $sinal * (float)$p['preco_medio'] * $lote;

            $vs = $sinal * series_preco($p, $ult['data'], $precosJan) * $lote;              // o que a serie usa
            $vc = $p['recebido'] !== null ? $sinal * (float)$p['recebido'] * $lote : 0.0;   // o que o card mostra
            $mercadoSerie += $vs; $mercadoCard += $vc;

            if (abs($vs - $vc) > 0.005) {
                $venc = opcao_vencimento($p['codigo'], $p['data_inicio']);
                $cod  = strtoupper(trim($p['codigo']));
                $ajustes[] = [$cod, $vs - $vc,
                    ($venc && $venc < $ult['data'])            ? "vencida em $venc — a serie zera"
                    : ($p['recebido'] === null                 ? 'sem cotacao no card — a serie usa o preco medio'
                    : (!isset($precosJan[$cod][$ult['data']])  ? 'sem fechamento na memoria — a serie usa o preco medio'
                    :                                            'preco do card diferente do fechamento memorizado'))];
            }
        }
        $checa('1. valor_mercado (a mercado)', $ult['valor_mercado'], round($mercadoSerie, 2));
        if ($ajustes) {
            $log(sprintf("        card Resultados = %.2f; diferenca de %+.2f explicada por %d posicao(oes):",
                 $mercadoCard, $mercadoSerie - $mercadoCard, count($ajustes)));
            foreach ($ajustes as [$cod, $d, $motivo]) $log(sprintf("          %-10s %+9.2f   %s", $cod, $d, $motivo));
        }
        $checa('2. capital_aplicado (aplicado)', $ult['capital_aplicado'], round($capital, 2));

        // 3 — caixa = "Disponivel para uso" do Extrato
        $st = $pdo->prepare("SELECT aporte_inicial FROM apl_carteiras WHERE id=?"); $st->execute([$cid]);
        $disp = (float)($st->fetchColumn() ?: 0);
        $st = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN tipo='saque' THEN -valor ELSE valor END),0) FROM apl_movimentacoes WHERE user_id=? AND carteira_id=?");
        $st->execute([$uid, $cid]); $disp += (float)$st->fetchColumn();
        $st = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN fluxo_caixa IS NULL THEN -ABS(liquido) ELSE fluxo_caixa END),0) FROM apl_notas WHERE user_id=? AND carteira_id=?");
        $st->execute([$uid, $cid]); $disp += (float)$st->fetchColumn();
        $checa('3. caixa (Disponivel p/ uso)', $ult['caixa'], $disp);

        // 4 — realizado e custos acumulados
        $st = $pdo->prepare("SELECT ROUND(SUM(CASE WHEN direcao='V' THEN -1 ELSE 1 END * (recebido-preco_medio)*lote),2) FROM apl_carteira WHERE user_id=? AND carteira_id=? AND status='Encerrada'");
        $st->execute([$uid, $cid]); $pnl = (float)$st->fetchColumn();
        $st = $pdo->prepare("SELECT ROUND(COALESCE(SUM(total_taxas),0),2) FROM apl_notas WHERE user_id=? AND carteira_id=?");
        $st->execute([$uid, $cid]); $taxas = (float)$st->fetchColumn();
        $checa('4a. realizado_acum (P&L encerrado)', $ult['realizado_acum'], $pnl);
        $checa('4b. custos_acum (taxas)',            $ult['custos_acum'],    $taxas);

        // 5 — serie continua: nenhum pregao faltando e nenhuma vencida "valendo"
        $checa('5a. pregoes na serie', count($serie), count($pregoesJan), 0);
        $vivasVencidas = 0;
        foreach ($abertas as $p) {
            $v = opcao_vencimento($p['codigo'], $p['data_inicio']);
            if ($v && $v < $ult['data']) $vivasVencidas++;
        }
        $log(sprintf("  [i  ] %-34s %d aberta(s) ja vencida(s) — valem R$ 0,00 na serie (regra do plano)", '5b. opcoes vencidas', $vivasVencidas));

        // 6 — TWR: mostra o acumulado e o de cada mes fechado, para conferencia na mao
        $log(sprintf("  [i  ] %-34s %+.2f%% no periodo todo", '6. TWR acumulado', $ult['twr_acum']));
        $meses = [];
        foreach ($serie as $l) $meses[substr($l['data'], 0, 7)][] = $l;
        // cada mes parte do patrimonio de fechamento do mes ANTERIOR: sem isso o
        // 1o dia de cada mes nao rende e os meses nao compoem para o acumulado.
        $baseMes = null;
        foreach ($meses as $m => $ls) {
            $sub = series_twr($ls, $baseMes);
            $log(sprintf("        %s  TWR %+7.2f%%   patrimonio %10.2f -> %10.2f   aportes/saques %+8.2f",
                $m, $sub[count($sub) - 1]['twr_acum'], $ls[0]['patrimonio'], $ls[count($ls) - 1]['patrimonio'],
                array_sum(array_column($ls, 'fluxo_externo'))));
            $baseMes = (float)$ls[count($ls) - 1]['patrimonio'];
        }

        // 8 — CONSOLIDADO (Fase 4.3): os periodos precisam COMPOR.
        //
        // A lente "A mercado" so vale alguma coisa se as partes fecharem com o
        // todo: a composicao dos meses tem que dar o TWR acumulado e a soma dos
        // rendimentos mensais tem que dar o rendimento do periodo inteiro. Foi
        // exatamente esse elo que faltou no aceite da 4.1 (ADR 017), entao ele
        // agora e' criterio, nao confianca.
        $anos = series_periodos($serie);
        $fator = 1.0; $somaRend = 0.0; $somaFluxo = 0.0; $nMeses = 0;
        foreach ($anos as $A) {
            foreach ($A['meses'] as $M) {
                $fator    *= 1.0 + $M['twr'] / 100.0;
                $somaRend += $M['rendimento'];
                $somaFluxo += $M['fluxo'];
                $nMeses++;
                // dentro do mes, os DIAS tambem precisam compor
                $fd = 1.0; foreach ($M['dias'] as $D) $fd *= 1.0 + $D['twr'] / 100.0;
                $checa("8. dias compoem {$A['ano']}-{$M['mes']}", ($fd - 1) * 100, $M['twr'], 0.01);
            }
        }
        $checa('8a. meses compoem p/ o TWR total', ($fator - 1) * 100, $ult['twr_acum'], 0.01);
        $checa('8b. soma dos rendimentos mensais', $somaRend, $ult['patrimonio'] - $somaFluxo, 0.02);
        $log(sprintf("        %d mes(es) na arvore; aportes/saques somados %+.2f", $nMeses, $somaFluxo));

        // 9 — "Patrimonio hoje": as partes do card tem que fechar entre si.
        //
        // Identidade contabil do periodo inteiro:
        //   rendimento = realizado - custos + nao realizado
        //
        // Ela so fecha quando TODO movimento de posicao teve o seu movimento de
        // caixa. Duas quebras sao LEGITIMAS neste app e precisam ser nomeadas,
        // nao "consertadas":
        //   a) posicao lancada/encerrada NA MAO, sem nota — o capital aparece na
        //      carteira sem nunca ter saido do caixa (a bolinha amarela existe
        //      justamente para dizer que aquilo e' provisorio);
        //   b) nota sem `fluxo_caixa` informado — entra como debito presumido.
        // Falha so se sobrar residuo que nenhuma das duas explique.
        $checa('9a. caixa + a mercado = patrimonio', $ult['caixa'] + $ult['valor_mercado'], $ult['patrimonio']);
        $naoReal = $ult['valor_mercado'] - $ult['capital_aplicado'];
        $rendTot = $ult['patrimonio'] - $somaFluxo;
        $residuo = $rendTot - ($ult['realizado_acum'] - $ult['custos_acum'] + $naoReal);

        // ids de posicao que alguma nota tocou (log `acoes` de cada importacao)
        $st = $pdo->prepare("SELECT acoes FROM apl_notas WHERE user_id=? AND carteira_id=? AND acoes IS NOT NULL");
        $st->execute([$uid, $cid]);
        $comNota = [];
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $js) {
            foreach ((json_decode($js, true) ?: []) as $a) {
                foreach (['id', 'slice_id', 'open_id', 'reopen_id'] as $k) {
                    if (!empty($a[$k])) $comNota[(int)$a[$k]] = true;
                }
            }
        }
        $st = $pdo->prepare("SELECT id,direcao,status,preco_medio,lote,recebido FROM apl_carteira WHERE user_id=? AND carteira_id=?");
        $st->execute([$uid, $cid]);
        $capSemNota = 0.0; $realSemNota = 0.0; $nSemNota = 0;
        foreach ($st->fetchAll() as $p) {
            if (isset($comNota[(int)$p['id']])) continue;
            $nSemNota++;
            $s = $p['direcao'] === 'V' ? -1.0 : 1.0;
            if ($p['status'] === 'Aberta') $capSemNota += $s * (float)$p['preco_medio'] * (int)$p['lote'];
            elseif ($p['recebido'] !== null) $realSemNota += $s * ((float)$p['recebido'] - (float)$p['preco_medio']) * (int)$p['lote'];
        }
        $st = $pdo->prepare("SELECT COUNT(*) FROM apl_notas WHERE user_id=? AND carteira_id=? AND fluxo_caixa IS NULL");
        $st->execute([$uid, $cid]); $presumidas = (int)$st->fetchColumn();

        // 9b — O GUARDA DA RENTABILIDADE: fluxo externo e' SO aporte/saque.
        //
        // Se uma nota de corretagem vazar para o fluxo_externo, ou se um aporte
        // datado fora de pregao sumir da serie, o TWR passa a chamar dinheiro
        // depositado de "rentabilidade" — o erro mais caro possivel aqui, e o
        // unico que nenhum outro criterio pega (caixa e patrimonio continuariam
        // certos). Compara a serie com a FONTE (apl_movimentacoes + a carteira).
        $st = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN tipo='saque' THEN -valor ELSE valor END),0)
                             FROM apl_movimentacoes WHERE user_id=? AND carteira_id=?");
        $st->execute([$uid, $cid]);
        $st2 = $pdo->prepare("SELECT COALESCE(aporte_inicial,0) FROM apl_carteiras WHERE id=? AND user_id=?");
        $st2->execute([$cid, $uid]);
        $checa('9b. fluxo externo = aportes/saques', $somaFluxo, (float)$st->fetchColumn() + (float)$st2->fetchColumn());

        // 9c — de onde veio o rendimento (informativo).
        //
        // A identidade "rendimento = realizado - custos + nao realizado" so
        // fecha quando todo movimento de posicao teve o seu movimento de caixa.
        // Duas quebras sao LEGITIMAS neste app — posicao lancada na mao (sem
        // nota, o capital aparece sem sair do caixa) e `fluxo_caixa` acertado a
        // mao que nao corresponde as operacoes. Por isso isto INFORMA e nomeia
        // o residuo, em vez de reprovar: seria reprovar o dado do usuario, nao
        // o codigo. O que valida o codigo sao os criterios 1-4, 8 e 9a-b.
        $sobra = $residuo - ($capSemNota - $realSemNota);
        $log(sprintf("  [i  ] %-34s %+.2f = realizado %+.2f - custos %.2f + nao realizado %+.2f%s",
             '9c. decomposicao do rendimento', $rendTot, $ult['realizado_acum'], $ult['custos_acum'], $naoReal,
             abs($residuo) <= 0.02 ? '' : sprintf("   (residuo %+.2f)", $residuo)));
        if (abs($residuo) > 0.02) {
            // "sem nota" = nenhum log `acoes` de nota referencia a posicao — ou ela
            // foi lancada na mao, ou veio de uma importacao anterior ao log.
            if ($nSemNota) $log(sprintf("        %d posicao(oes) que nenhuma nota tocou explicam %+.2f (capital %+.2f, realizado %+.2f)",
                                $nSemNota, $capSemNota - $realSemNota, $capSemNota, $realSemNota));
            if (abs($sobra) > 0.02) $log(sprintf("        sobram %+.2f — caixa das notas nao corresponde as operacoes%s",
                                $sobra, $presumidas ? " ($presumidas nota(s) sem fluxo_caixa informado)" : ''));
        }

        // 7 — CDI acumulado do mesmo periodo (conferir na calculadora do Banco Central)
        $idx = indices_load($pdo, array_column($serie, 'data'), ['cdi', 'ipca', 'ibov', 'sp500', 'ifix']);
        foreach ($idx as $nome => $i) {
            $pts = $i['pontos'];
            if (!$pts) { $log(sprintf("  [!  ] %-34s sem dados", "7. $nome")); continue; }
            $log(sprintf("  [i  ] %-34s %+7.2f%% no periodo%s", "7. $nome acumulado", end($pts) - 100,
                $i['proxy'] ? "  (proxy {$i['proxy']})" : ''));
        }
    }
    $log($falhas === 0 ? "\nTODOS os criterios numericos bateram." : "\n$falhas criterio(s) FALHARAM — nao subir.");
    exit($falhas === 0 ? 0 : 1);

default:
    $log("Comando desconhecido. Use: backfill | indices | rebuild | tudo | status | aceite");
    exit(1);
}
