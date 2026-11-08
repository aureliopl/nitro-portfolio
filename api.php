<?php
require_once __DIR__ . '/sessao.php';   // bootstrap único da sessão (não expira sozinha — 08/08/2026)
require_once 'config.php';
require_once 'config_geral.php';            // configuracoes gerais (store global, so leitura)
require_once 'avatars.php';                 // upload/processamento/rendering de avatar
require_once 'wallets.php';                 // multi-carteira: resolucao da carteira ativa (Fase 3)
require_once 'papeis.php';                  // papeis/capacidades (gate da Nitro)
require_once 'series.php';                  // historico: cotacoes, serie diaria e indices (Fase 4.1)
                                            // (traz tambem b3Cotahist/b3UltimoPregao, que moraram aqui ate a Fase 4.1)
date_default_timezone_set(config_fuso());   // fuso da plataforma (mesmo das paginas do app)
header('Content-Type: application/json; charset=utf-8');

// Toda a API exige sessao autenticada (usuario logado).
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Nao autorizado']);
    exit;
}

// Qualquer erro vira JSON legivel (em vez de 500 em branco) — facilita o diagnostico.
set_exception_handler(function ($e) {
    http_response_code(500);
    error_log('[nitro] ' . $e->getMessage());
    echo json_encode(['error' => 'Erro interno.']);
});
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_COMPILE_ERROR, E_CORE_ERROR], true)) {
        http_response_code(500);
        error_log('[nitro] ' . $e['message']);
        echo json_encode(['error' => 'Erro interno.']);
    }
});

nitro_require_csrf();
if (!nitro_revalidate_session(getDB())) { http_response_code(401); exit(json_encode(['error' => 'Nao autorizado'])); }
$action = $_GET['action'] ?? '';
$input  = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$pdo    = getDB();

// ── MULTI-TENANCY: toda query e' escopada pelo usuario logado ─────────────────
// $uid vem da sessao (nunca do cliente). Todo SELECT/UPDATE/DELETE filtra por
// user_id=$uid; todo INSERT grava user_id=$uid. Sem isto um usuario veria/mexeria
// nos dados de outro.
$uid = (int)$_SESSION['user_id'];

// ── Qual tabela? carteira (Pessoal, default), posicoes (Nitro) ou betha ────────
// A Betha e' uma 3a carteira SIMPLES (mesmo modelo pago/recebido da Nitro, sem
// carteira_id, sem taxas), tambem pessoal do super_admin. Os ramos "simples" do
// api.php ja usam $T generico, entao ela reaproveita quase tudo.
$TABELAS = ['carteira', 'posicoes', 'betha'];
$tabela  = in_array($_GET['tabela'] ?? '', $TABELAS, true) ? $_GET['tabela'] : 'carteira';
$T       = 'apl_' . $tabela;   // nome FISICO da tabela ativa (prefixo apl_). $tabela continua logico p/ comparacoes.

// ── MULTI-CARTEIRA (Fase 3) ──────────────────────────────────────────────────
// A Carteira rica e' multi-carteira: alem de user_id, toda query e' escopada
// pela CARTEIRA ATIVA ($cid, vinda da sessao e validada server-side). A Nitro
// (apl_posicoes) NAO tem carteira_id (continua unica) e e' pessoal do super_admin.
wallet_ensure_default($pdo, $uid);          // rede de seguranca: todo usuario tem ao menos a "Principal"
$cid        = wallet_active_id($pdo, $uid); // resolve a carteira ativa (sessao -> default)
$isCarteira = ($tabela === 'carteira');
$cartSql    = $isCarteira ? ' AND carteira_id=?' : '';   // fragmento p/ SELECT/UPDATE/DELETE em $T
$cartParam  = $isCarteira ? [$cid] : [];                 // param posicional correspondente

// Gate da Nitro/Betha: so o super_admin acessa ?tabela=posicoes|betha (nav/pagina/ranking ja escondem).
if (($tabela === 'posicoes' && !pode_ver_nitro($_SESSION['user_papel'] ?? ''))
 || ($tabela === 'betha'    && !pode_ver_betha($_SESSION['user_papel'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['error' => 'Acesso negado.']);
    exit;
}

// ── INVALIDACAO DA SERIE HISTORICA (Fase 4.1) ────────────────────────────────
// apl_serie_diaria e' CACHE: se uma posicao, nota, movimentacao ou cotacao muda,
// a serie envelhece na hora. Em vez de espalhar chamadas de rebuild por ~15
// handlers (e esquecer de uma), a invalidacao acontece num lugar so: as acoes
// que mexem nos dados agendam o recalculo para DEPOIS da resposta ser enviada.
//
// Por que no shutdown e nao inline: o usuario nao espera pelo recalculo, e uma
// falha aqui nunca pode derrubar a acao que ele pediu (importar nota, encerrar
// posicao). O rebuild e' idempotente, entao rodar "a mais" nao faz mal.
$ACOES_QUE_MUDAM_A_SERIE = [
    'create', 'update_field', 'bulk_update', 'close', 'reopen', 'delete',
    'trash_restore', 'trash_purge', 'trash_empty',
    'apply_quotes', 'import_nota', 'undo_nota', 'delete_nota', 'set_nota_fluxo',
    'add_mov', 'delete_mov', 'checkpoint_restore', 'wallet_set_aporte',
];
if (in_array($_GET['action'] ?? '', $ACOES_QUE_MUDAM_A_SERIE, true)) {
    register_shutdown_function(function () use ($pdo, $uid) {
        try {
            // recalcula so a carteira ATIVA — as outras seguem validas
            $cid = wallet_active_id($pdo, $uid);
            if ($cid) series_rebuild($pdo, $uid, $cid);
        } catch (Throwable $e) {
            // serie desatualizada e' um incomodo; derrubar a acao do usuario nao.
            error_log('[serie] falha ao recalcular: ' . $e->getMessage());
        }
    });
}

// Campos numericos editaveis por tabela
$NUM_FIELDS = [
    'carteira' => ['preco_medio', 'lote', 'recebido'],
    'posicoes' => ['pago', 'recebido'],
    'betha'    => ['pago', 'recebido'],
];
$DATE_FIELDS = ['data_inicio', 'data_final'];

// ── Detecta CALL/PUT pela letra de serie do codigo da opcao ──────────────────
//   5o caractere: A–L = CALL (jan..dez)  |  M–X = PUT (jan..dez)
function detectarTipo($cod) {
    $cod = strtoupper(trim($cod));
    if (strlen($cod) < 5) return null;
    $L = $cod[4];
    if ($L >= 'A' && $L <= 'L') return 'CALL';
    if ($L >= 'M' && $L <= 'X') return 'PUT';
    return null;
}

// ── Ativo-objeto a partir do codigo da opcao (ex.: PETRS437 -> PETR4) ────────
function tickerUnderlying($cod) {
    static $MAP = [
        'MGLU'=>'MGLU3','COGN'=>'COGN3','RENT'=>'RENT3','USIM'=>'USIM5','BRAV'=>'BRAV3',
        'LREN'=>'LREN3','BEEF'=>'BEEF3','CSNA'=>'CSNA3','CSAN'=>'CSAN3','MBRF'=>'MBRF3',
        'PRIO'=>'PRIO3','CYRE'=>'CYRE3','PETR'=>'PETR4','EMBJ'=>'EMBJ3','MRVE'=>'MRVE3',
        'WEGE'=>'WEGE3','BRKM'=>'BRKM3','CMIN'=>'CMIN3','BRAP'=>'BRAP4','BOVA'=>'BOVA11',
    ];
    $root = strtoupper(substr($cod, 0, 4));
    return $MAP[$root] ?? ($root . '3');
}

// ── Chamada GET autenticada na API da OpLab ──────────────────────────────────
function oplabGet($path, $token) {
    throw new RuntimeException('Integracoes externas desativadas nesta versao de portfolio.');
    $ch = curl_init('https://api.oplab.com.br/v3' . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => ['Access-Token: ' . $token, 'Accept: application/json'],
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return ['code' => $code, 'body' => $body, 'err' => $err];
}

// ── Le um valor da config_app DO USUARIO ─────────────────────────────────────
function cfgGet($pdo, $uid, $chave) {
    $s = $pdo->prepare("SELECT valor FROM apl_config_app WHERE user_id=? AND chave=?");
    $s->execute([$uid, $chave]);
    $r = $s->fetch();
    return $r ? $r['valor'] : null;
}

// ── Extrai um preco numerico de um item de opcao da OpLab (defensivo) ────────
function oplabPreco($opt) {
    foreach (['close', 'last', 'last_price', 'premium', 'previous_close', 'price'] as $k) {
        if (isset($opt[$k]) && is_numeric($opt[$k]) && $opt[$k] > 0) return (float)$opt[$k];
    }
    return null;
}

// b3Cotahist() e b3UltimoPregao() moraram aqui ate a Fase 4.1. Foram para o
// series.php (a "camada de historico"), que precisa das mesmas funcoes para o
// backfill — e la o parser passou a ler tambem TPMERC 010 (ETFs-proxy dos
// indices), sem perder as opcoes 070/080. Continuam disponiveis por require.

// ── Casta os tipos de uma linha lida do banco ────────────────────────────────
function castRow(&$r) {
    foreach (['preco_medio', 'pago', 'recebido'] as $f) {
        if (array_key_exists($f, $r)) $r[$f] = $r[$f] !== null ? (float)$r[$f] : null;
    }
    foreach (['lote', 'dias', 'fecho_manual'] as $f) {
        if (array_key_exists($f, $r)) $r[$f] = $r[$f] !== null ? (int)$r[$f] : null;
    }
}

// ── Recalcula 'dias' de uma posicao (diferenca entre inicio e final) ─────────
function recalcDias($pdo, $tbl, $id, $uid) {
    $q = $pdo->prepare("SELECT data_inicio, data_final FROM `$tbl` WHERE id=? AND user_id=?");
    $q->execute([$id, $uid]);
    $row = $q->fetch();
    if (!$row) return;
    $dias = null;
    if (!empty($row['data_inicio']) && !empty($row['data_final'])) {
        $di = new DateTime($row['data_inicio']);
        $df = new DateTime($row['data_final']);
        $dias = (int)$df->diff($di)->days;
    }
    $pdo->prepare("UPDATE `$tbl` SET dias=? WHERE id=? AND user_id=?")->execute([$dias, $id, $uid]);
}

// ── CUSTO DE CORRETAGEM POR OPERACAO (rateio das notas) ──────────────────────
// Problema: as taxas (liquidacao + registro + emolumentos + custos) sao cobradas por
// NOTA, nunca por operacao. Para dizer o resultado LIQUIDO de uma opcao encerrada
// precisamos saber quanto daquela nota pertence a ela.
//
// Solucao: dentro de cada nota, ratear o `total_taxas` entre as PERNAS que a nota
// executou, proporcionalmente ao volume financeiro de cada perna. Isso nao e' um chute:
// emolumentos e taxa de liquidacao da B3 sao percentuais sobre o volume negociado, entao
// o rateio por volume reconstroi praticamente o que a corretora cobrou em cada negocio —
// e, por construcao, a soma das fatias de uma nota e' EXATAMENTE o total_taxas dela.
//
// Cada operacao acumula duas pernas: a de ENTRADA (nota que abriu/aumentou/confirmou) e a
// de SAIDA (nota que encerrou). O custo de uma operacao encerrada e' a soma das duas —
// o custo de ida e volta, que e' o que sai do bolso naquele trade.
//
// O log `acoes` de cada nota (gravado pelo import_nota) diz quais posicoes ela tocou:
//   open/average/reconcile      -> perna de ENTRADA da posicao `id`
//   close/reconcile_close       -> perna de SAIDA da posicao `id`
//   partial_close               -> perna de SAIDA da fatia encerrada (`slice_id`)
//   split_reopen                -> perna de SAIDA da linha que ficou encerrada (`id`)
//
// Retorna: custos por id de posicao + a reconciliacao com o total pago em taxas
// (o que sobra sao taxas de posicoes ainda ABERTAS — custo pago mas nao realizado).
function custosPorOperacao($pdo, $uid, $cid) {
    // volume financeiro de cada perna de cada posicao da carteira ativa
    $ops = [];
    $q = $pdo->prepare("SELECT id, preco_medio, lote, recebido FROM apl_carteira WHERE user_id=? AND carteira_id=?");
    $q->execute([$uid, $cid]);
    foreach ($q as $r) {
        $lote = (int)$r['lote'];
        $ops[(int)$r['id']] = [
            'entrada' => (float)$r['preco_medio'] * $lote,
            'saida'   => $r['recebido'] !== null ? (float)$r['recebido'] * $lote : 0.0,
        ];
    }

    $custos = [];              // id da posicao => custo atribuido (R$)
    $totalTaxas   = 0.0;       // tudo que as notas cobraram
    $totalRateado = 0.0;       // tudo que foi possivel atribuir a alguma posicao

    $n = $pdo->prepare("SELECT total_taxas, acoes FROM apl_notas WHERE user_id=? AND carteira_id=?");
    $n->execute([$uid, $cid]);
    foreach ($n as $nota) {
        $taxas = (float)$nota['total_taxas'];
        $totalTaxas += $taxas;
        if ($taxas <= 0 || empty($nota['acoes'])) continue;
        $acoes = json_decode($nota['acoes'], true);
        if (!is_array($acoes)) continue;

        // pernas tocadas por esta nota
        $pernas = [];
        foreach ($acoes as $a) {
            $t = $a['t'] ?? '';
            if     ($t === 'open' || $t === 'average' || $t === 'reconcile') $pernas[] = [(int)($a['id'] ?? 0), 'entrada'];
            elseif ($t === 'close' || $t === 'reconcile_close')              $pernas[] = [(int)($a['id'] ?? 0), 'saida'];
            elseif ($t === 'partial_close')                                  $pernas[] = [(int)($a['slice_id'] ?? 0), 'saida'];
            elseif ($t === 'split_reopen')                                   $pernas[] = [(int)($a['id'] ?? 0), 'saida'];
        }
        // volume de cada perna (posicao apagada depois = volume 0, fica de fora do rateio)
        $vols = []; $soma = 0.0;
        foreach ($pernas as $i => $p) {
            $v = isset($ops[$p[0]]) ? (float)$ops[$p[0]][$p[1]] : 0.0;
            $vols[$i] = $v;
            $soma += $v;
        }
        if ($soma <= 0) continue;   // nada em que ratear (ex.: posicoes excluidas)
        foreach ($pernas as $i => $p) {
            if ($vols[$i] <= 0) continue;
            $fatia = $taxas * $vols[$i] / $soma;
            $custos[$p[0]] = ($custos[$p[0]] ?? 0) + $fatia;
            $totalRateado += $fatia;
        }
    }
    foreach ($custos as $k => $v) $custos[$k] = round($v, 4);
    return [
        'custos'        => (object)$custos,
        'total_taxas'   => round($totalTaxas, 2),
        'total_rateado' => round($totalRateado, 2),
    ];
}

// ── Janela de datas do gráfico de evolução (Fase 4.1) ────────────────────────
// Traduz o atalho de período dos botões (1M · 3M · 6M · 12M · YTD · Máx) para
// um par [de, ate]. Datas explícitas (de/ate) mandam sobre o atalho; 'max' (ou
// nada) devolve [null, null] = a série inteira.
function serieJanela($periodo, $de = null, $ate = null) {
    $ok = fn($d) => (is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) ? $d : null;
    $de = $ok($de); $ate = $ok($ate);
    if ($de || $ate) return [$de, $ate];

    $hoje = date('Y-m-d');
    switch (strtolower(trim((string)$periodo))) {
        case '1m':  return [date('Y-m-d', strtotime('-1 month')), $hoje];
        case '3m':  return [date('Y-m-d', strtotime('-3 months')), $hoje];
        case '6m':  return [date('Y-m-d', strtotime('-6 months')), $hoje];
        case '12m': return [date('Y-m-d', strtotime('-12 months')), $hoje];
        case 'ytd': return [date('Y') . '-01-01', $hoje];
        default:    return [null, null];   // 'max' / vazio = série inteira
    }
}

// ── Pontos de restauração (snapshot completo) ────────────────────────────────
// Tabelas do snapshot da Carteira rica (Fase 3): a Nitro (apl_posicoes) saiu —
// checkpoints sao POR CARTEIRA (conta isolada), e a Nitro nao e' multi-carteira.
function snapTables() { return ['apl_carteira', 'apl_notas', 'apl_movimentacoes', 'apl_lixeira']; }
// Snapshot e restore sao por (usuario, carteira) — um checkpoint guarda/restaura
// apenas os dados de $uid NAQUELA carteira ($cid); nunca toca em outra carteira
// nem em outro usuario. Na lixeira, so as linhas da carteira (carteira_id=$cid);
// a lixeira da Nitro (carteira_id NULL) fica de fora.
function fazerSnapshot($pdo, $uid, $cid) {
    $s = [];
    foreach (snapTables() as $t) {
        $st = $pdo->prepare("SELECT * FROM `$t` WHERE user_id=? AND carteira_id=?");
        $st->execute([$uid, $cid]);
        $s[$t] = $st->fetchAll();
    }
    return $s;
}
function criarCheckpoint($pdo, $uid, $cid, $rotulo, $tipo = 'manual') {
    $pdo->prepare("INSERT INTO apl_checkpoints (user_id,carteira_id,rotulo,tipo,dados) VALUES (?,?,?,?,?)")
        ->execute([$uid, $cid, $rotulo, $tipo, json_encode(fazerSnapshot($pdo, $uid, $cid))]);
    // mantém só os 25 pontos mais recentes DESTA carteira
    $pdo->prepare("DELETE FROM apl_checkpoints WHERE user_id=? AND carteira_id=? AND id NOT IN (SELECT id FROM (SELECT id FROM apl_checkpoints WHERE user_id=? AND carteira_id=? ORDER BY id DESC LIMIT 25) x)")
        ->execute([$uid, $cid, $uid, $cid]);
}
function restaurarTabela($pdo, $uid, $cid, $t, $rows) {
    // apaga SO as linhas desta carteira deste usuario antes de reinserir o snapshot
    $pdo->prepare("DELETE FROM `$t` WHERE user_id=? AND carteira_id=?")->execute([$uid, $cid]);
    if (!$rows) return;
    // força dono+carteira em cada linha (defesa; e snapshots antigos podem nao ter carteira_id)
    foreach ($rows as &$r) { $r['user_id'] = $uid; $r['carteira_id'] = $cid; } unset($r);
    $cols = array_keys($rows[0]);
    $sql = "INSERT INTO `$t` (`" . implode('`,`', $cols) . "`) VALUES (" . implode(',', array_fill(0, count($cols), '?')) . ")";
    $st = $pdo->prepare($sql);
    foreach ($rows as $r) { $st->execute(array_values($r)); }
}

switch ($action) {

    // ── LISTAR ───────────────────────────────────────────────────────────────
    case 'list':
        $stmt = $pdo->prepare("SELECT * FROM `$T` WHERE user_id=?$cartSql ORDER BY data_inicio DESC, id DESC");
        $stmt->execute([$uid, ...$cartParam]);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) castRow($r);
        echo json_encode($rows);
        break;

    // ── NOVA POSICAO ─────────────────────────────────────────────────────────
    case 'create':
        $codigo    = strtoupper(trim($input['codigo'] ?? ''));
        $estrutura = strtoupper(trim($input['estrutura'] ?? ''));
        $data_ini  = $input['data_inicio'] ?? '';
        $recebido  = isset($input['recebido']) && $input['recebido'] !== ''
                        ? (float)str_replace(',', '.', $input['recebido']) : null;

        // Auto-detecta o tipo se nao veio (ou veio invalido)
        if (!in_array($estrutura, ['PUT', 'CALL'], true)) {
            $estrutura = detectarTipo($codigo);
        }

        // Sentido: C = comprada (padrao) | V = vendida (lancada/descoberta)
        $direcao = strtoupper(trim($input['direcao'] ?? 'C'));
        if (!in_array($direcao, ['C', 'V'], true)) $direcao = 'C';

        if (!$codigo || !in_array($estrutura, ['PUT', 'CALL'], true) || !$data_ini) {
            http_response_code(400);
            echo json_encode(['error' => 'Campos obrigatorios: codigo, tipo (CALL/PUT), data de inicio']);
            exit;
        }

        // Unicidade SO entre posicoes ABERTAS — o mesmo codigo pode voltar em anos diferentes
        // (a B3 recicla os codigos). A data_inicio distingue cada operacao.
        $chk = $pdo->prepare("SELECT id FROM `$T` WHERE codigo=? AND status='Aberta' AND user_id=?$cartSql");
        $chk->execute([$codigo, $uid, ...$cartParam]);
        if ($chk->fetch()) {
            http_response_code(409);
            echo json_encode(['error' => "Codigo $codigo ja tem uma posicao aberta. Encerre-a antes de abrir outra igual."]);
            exit;
        }

        if ($tabela === 'carteira') {
            $preco = (float)str_replace(',', '.', $input['preco_medio'] ?? 0);
            $lote  = (int)($input['lote'] ?? 0);
            if (!$preco || !$lote) {
                http_response_code(400);
                echo json_encode(['error' => 'Carteira exige preco_medio e lote.']);
                exit;
            }
            // origem='manual': lançamento provisório (mostra ✎). A nota o sobrescreve depois.
            $s = $pdo->prepare("INSERT INTO apl_carteira (user_id,carteira_id,codigo,estrutura,direcao,data_inicio,preco_medio,lote,recebido,status,origem) VALUES (?,?,?,?,?,?,?,?,?,'Aberta','manual')");
            $s->execute([$uid, $cid, $codigo, $estrutura, $direcao, $data_ini, $preco, $lote, $recebido]);
        } else {
            $pago = (float)str_replace(',', '.', $input['pago'] ?? 0);
            if (!$pago) {
                http_response_code(400);
                echo json_encode(['error' => 'Nitro exige o valor pago.']);
                exit;
            }
            // carteira simples (Nitro ou Betha): mesma estrutura, tabela vem de $T
            $s = $pdo->prepare("INSERT INTO `$T` (user_id,codigo,estrutura,direcao,data_inicio,pago,recebido,status) VALUES (?,?,?,?,?,?,?,'Aberta')");
            $s->execute([$uid, $codigo, $estrutura, $direcao, $data_ini, $pago, $recebido]);
        }
        echo json_encode(['success' => true, 'id' => $pdo->lastInsertId(), 'estrutura' => $estrutura]);
        break;

    // ── ATUALIZACAO EM MASSA (precos do dia) ─────────────────────────────────
    case 'bulk_update':
        $texto   = $input['prices'] ?? '';
        $linhas  = explode("\n", trim($texto));
        $updated = 0;
        $nao_enc = [];
        $s = $pdo->prepare("UPDATE `$T` SET recebido=? WHERE codigo=? AND status='Aberta' AND user_id=?$cartSql");
        foreach ($linhas as $linha) {
            $linha = trim($linha);
            if (!$linha) continue;
            $partes = preg_split('/[;\t]+/', $linha, 2);
            if (count($partes) < 2) continue;
            $cod   = strtoupper(trim($partes[0]));
            $preco = (float)str_replace(',', '.', trim($partes[1]));
            if ($cod && $preco > 0) {
                $s->execute([$preco, $cod, $uid, ...$cartParam]);
                if ($s->rowCount() > 0) $updated++;
                else $nao_enc[] = $cod;
            }
        }
        echo json_encode(['success' => true, 'updated' => $updated, 'nao_encontrados' => $nao_enc]);
        break;

    // ── ATUALIZAR UM CAMPO (numerico ou data, qualquer status) ───────────────
    case 'update_field':
        $id    = (int)($input['id'] ?? 0);
        $campo = $input['campo'] ?? '';
        if (!$id) { http_response_code(400); echo json_encode(['error' => 'ID invalido']); exit; }

        $isNum  = in_array($campo, $NUM_FIELDS[$tabela], true);
        $isDate = in_array($campo, $DATE_FIELDS, true);
        if (!$isNum && !$isDate) {
            http_response_code(400);
            echo json_encode(['error' => "Campo invalido para $tabela: $campo"]);
            exit;
        }

        if ($isNum) {
            $valor = $campo === 'lote'
                ? (int)($input['valor'] ?? 0)
                : (float)str_replace(',', '.', $input['valor'] ?? 0);
            $pdo->prepare("UPDATE `$T` SET `$campo`=? WHERE id=? AND user_id=?$cartSql")->execute([$valor, $id, $uid, ...$cartParam]);
        } else {
            $valor = trim($input['valor'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor)) {
                http_response_code(400);
                echo json_encode(['error' => 'Data invalida (use AAAA-MM-DD).']);
                exit;
            }
            $pdo->prepare("UPDATE `$T` SET `$campo`=? WHERE id=? AND user_id=?$cartSql")->execute([$valor, $id, $uid, ...$cartParam]);
            recalcDias($pdo, $T, $id, $uid);  // datas mudaram -> recalcula dias
        }
        echo json_encode(['success' => true]);
        break;

    // ── ENCERRAR ─────────────────────────────────────────────────────────────
    case 'close':
        $id         = (int)($input['id'] ?? 0);
        $recebido   = (float)str_replace(',', '.', $input['recebido'] ?? 0);
        $data_final = $input['data_final'] ?? date('Y-m-d');
        if (!$id || !$recebido) {
            http_response_code(400);
            echo json_encode(['error' => 'id e recebido sao obrigatorios']);
            exit;
        }
        $pos = $pdo->prepare("SELECT * FROM `$T` WHERE id=? AND user_id=?$cartSql");
        $pos->execute([$id, $uid, ...$cartParam]);
        $row = $pos->fetch();
        if (!$row) { http_response_code(404); echo json_encode(['error' => 'Posicao nao encontrada']); exit; }
        $di   = new DateTime($row['data_inicio']);
        $df   = new DateTime($data_final);
        $dias = (int)$df->diff($di)->days;

        // ENCERRAMENTO PARCIAL (só Carteira, que tem lote): fecha 'qtd' numa nova linha e mantém o restante aberto
        $qtd      = ($tabela === 'carteira' && isset($input['lote']) && $input['lote'] !== '') ? (int)$input['lote'] : null;
        $loteAtual = (int)($row['lote'] ?? 0);
        if ($qtd !== null && $qtd > 0 && $qtd < $loteAtual) {
            $pdo->prepare("INSERT INTO apl_carteira (user_id,carteira_id,codigo,estrutura,direcao,data_inicio,data_final,dias,preco_medio,lote,recebido,status,origem,fecho_manual) VALUES (?,?,?,?,?,?,?,?,?,?,?,'Encerrada',?,1)")
                ->execute([$uid, $cid, $row['codigo'], $row['estrutura'], ($row['direcao'] ?: 'C'), $row['data_inicio'], $data_final, $dias, $row['preco_medio'], $qtd, $recebido, ($row['origem'] ?? 'nota')]);
            $pdo->prepare("UPDATE apl_carteira SET lote=? WHERE id=? AND user_id=? AND carteira_id=?")->execute([$loteAtual - $qtd, $id, $uid, $cid]);
            echo json_encode(['success' => true, 'dias' => $dias, 'parcial' => true, 'restante' => $loteAtual - $qtd]);
            break;
        }

        // encerramento TOTAL
        $pdo->prepare("UPDATE `$T` SET recebido=?,data_final=?,dias=?,status='Encerrada' WHERE id=? AND user_id=?$cartSql")
            ->execute([$recebido, $data_final, $dias, $id, $uid, ...$cartParam]);
        // encerramento na mão = provisório (bolinha amarela) até a nota confirmar
        if ($tabela === 'carteira') {
            $pdo->prepare("UPDATE apl_carteira SET fecho_manual=1 WHERE id=? AND user_id=? AND carteira_id=?")->execute([$id, $uid, $cid]);
        }
        echo json_encode(['success' => true, 'dias' => $dias]);
        break;

    // ── REABRIR ──────────────────────────────────────────────────────────────
    case 'reopen':
        $id = (int)($input['id'] ?? 0);
        if (!$id) { http_response_code(400); echo json_encode(['error' => 'ID invalido']); exit; }
        $pdo->prepare("UPDATE `$T` SET data_final=NULL,dias=NULL,status='Aberta' WHERE id=? AND user_id=?$cartSql")->execute([$id, $uid, ...$cartParam]);
        if ($tabela === 'carteira') {
            $pdo->prepare("UPDATE apl_carteira SET fecho_manual=0 WHERE id=? AND user_id=? AND carteira_id=?")->execute([$id, $uid, $cid]);
        }
        echo json_encode(['success' => true]);
        break;

    // ── EXCLUIR (soft-delete -> move para a LIXEIRA) ─────────────────────────
    case 'delete':
        $id = (int)($input['id'] ?? 0);
        if (!$id) { http_response_code(400); echo json_encode(['error' => 'ID invalido']); exit; }
        $q = $pdo->prepare("SELECT * FROM `$T` WHERE id=? AND user_id=?$cartSql");
        $q->execute([$id, $uid, ...$cartParam]);
        $row = $q->fetch();
        if (!$row) { http_response_code(404); echo json_encode(['error' => 'Posicao nao encontrada']); exit; }

        $ins = $pdo->prepare(
            "INSERT INTO apl_lixeira
             (user_id, carteira_id, origem, ref_id, codigo, estrutura, direcao, data_inicio, data_final, dias, preco_medio, lote, pago, recebido, status, payload)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
        );
        $ins->execute([
            $uid,
            // carteira_id so faz sentido na Carteira rica; na Nitro fica NULL
            $isCarteira ? $cid : null,
            $tabela,
            $row['id'],
            $row['codigo']      ?? null,
            $row['estrutura']   ?? null,
            $row['direcao']     ?? null,
            $row['data_inicio'] ?? null,
            $row['data_final']  ?? null,
            $row['dias']        ?? null,
            $row['preco_medio'] ?? null,
            $row['lote']        ?? null,
            $row['pago']        ?? null,
            $row['recebido']    ?? null,
            $row['status']      ?? null,
            json_encode($row, JSON_UNESCAPED_UNICODE),
        ]);
        $pdo->prepare("DELETE FROM `$T` WHERE id=? AND user_id=?$cartSql")->execute([$id, $uid, ...$cartParam]);
        echo json_encode(['success' => true]);
        break;

    // ── LIXEIRA: listar tudo (as duas origens) ───────────────────────────────
    case 'trash_list':
        // Carteira: so o lixo da carteira ATIVA (carteira_id=$cid). Nitro: itens com
        // carteira_id NULL (so existem p/ o super_admin, unico que exclui no Nitro).
        $stmt = $pdo->prepare("SELECT * FROM apl_lixeira WHERE user_id=? AND (carteira_id=? OR carteira_id IS NULL) ORDER BY excluido_em DESC, id DESC");
        $stmt->execute([$uid, $cid]);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) castRow($r);
        echo json_encode($rows);
        break;

    // ── LIXEIRA: restaurar para a tabela de origem ───────────────────────────
    case 'trash_restore':
        $id = (int)($input['id'] ?? 0);
        if (!$id) { http_response_code(400); echo json_encode(['error' => 'ID invalido']); exit; }
        $q = $pdo->prepare("SELECT * FROM apl_lixeira WHERE id=? AND user_id=?");
        $q->execute([$id, $uid]);
        $t = $q->fetch();
        if (!$t) { http_response_code(404); echo json_encode(['error' => 'Item nao encontrado']); exit; }
        $origem = $t['origem'];
        $status = $t['status'] ?: 'Aberta';
        try {
            if ($origem === 'carteira') {
                // restaura na carteira de ORIGEM do item (a coluna carteira_id da lixeira)
                $pdo->prepare("INSERT INTO apl_carteira (user_id,carteira_id,codigo,estrutura,direcao,data_inicio,data_final,dias,preco_medio,lote,recebido,status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
                    ->execute([$uid, $t['carteira_id'], $t['codigo'], $t['estrutura'], ($t['direcao'] ?: 'C'), $t['data_inicio'], $t['data_final'], $t['dias'], $t['preco_medio'], $t['lote'], $t['recebido'], $status]);
            } else {
                // carteira simples: a tabela de destino vem do proprio `origem` da lixeira
                // ('posicoes' -> apl_posicoes, 'betha' -> apl_betha).
                $destT = 'apl_' . $origem;
                $pdo->prepare("INSERT INTO `$destT` (user_id,codigo,estrutura,direcao,data_inicio,data_final,dias,pago,recebido,status) VALUES (?,?,?,?,?,?,?,?,?,?)")
                    ->execute([$uid, $t['codigo'], $t['estrutura'], ($t['direcao'] ?: 'C'), $t['data_inicio'], $t['data_final'], $t['dias'], $t['pago'], $t['recebido'], $status]);
            }
        } catch (PDOException $e) {
            http_response_code(409);
            echo json_encode(['error' => 'Nao foi possivel restaurar (codigo pode ja existir).']);
            exit;
        }
        $pdo->prepare("DELETE FROM apl_lixeira WHERE id=? AND user_id=?")->execute([$id, $uid]);
        echo json_encode(['success' => true]);
        break;

    // ── LIXEIRA: excluir definitivo (um ou varios ids) ───────────────────────
    case 'trash_purge':
        $ids = $input['ids'] ?? [];
        if (!is_array($ids) || !count($ids)) {
            http_response_code(400);
            echo json_encode(['error' => 'Nenhum item selecionado']);
            exit;
        }
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!count($ids)) { http_response_code(400); echo json_encode(['error' => 'IDs invalidos']); exit; }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $pdo->prepare("DELETE FROM apl_lixeira WHERE id IN ($ph) AND user_id=?")->execute([...$ids, $uid]);
        echo json_encode(['success' => true, 'removidos' => count($ids)]);
        break;

    // ── LIXEIRA: limpar tudo (ou so de uma origem) ───────────────────────────
    case 'trash_empty':
        $origem = $input['origem'] ?? '';
        if ($origem === 'carteira') {
            // limpa so o lixo da carteira ATIVA
            $pdo->prepare("DELETE FROM apl_lixeira WHERE user_id=? AND carteira_id=?")->execute([$uid, $cid]);
        } elseif ($origem === 'posicoes' || $origem === 'betha') {
            // limpa so o lixo da carteira simples pedida (Nitro/Betha; ambas com carteira_id NULL,
            // por isso o filtro e' pela coluna `origem`, nao por carteira_id).
            $pdo->prepare("DELETE FROM apl_lixeira WHERE user_id=? AND origem=?")->execute([$uid, $origem]);
        } else {
            // esvaziar tudo o que o usuario ve nesta carteira (carteira ativa + Nitro)
            $pdo->prepare("DELETE FROM apl_lixeira WHERE user_id=? AND (carteira_id=? OR carteira_id IS NULL)")->execute([$uid, $cid]);
        }
        echo json_encode(['success' => true]);
        break;

    // ── CONFIG: ler ──────────────────────────────────────────────────────────
    case 'get_config':
        $cfg = [];
        $cstmt = $pdo->prepare("SELECT chave,valor FROM apl_config_app WHERE user_id=?");
        $cstmt->execute([$uid]);
        foreach ($cstmt->fetchAll() as $r) {
            $cfg[$r['chave']] = $r['valor'];
        }
        echo json_encode(['success' => true, 'config' => $cfg]);
        break;

    // ── CONFIG: salvar ───────────────────────────────────────────────────────
    case 'save_config':
        // aporte_inicial/_data saíram daqui (Fase 3): agora moram na carteira (wallet_set_aporte)
        $permitidas = ['pdf_password', 'corretora', 'oplab_token', 'cotacao_fonte',
                       'sinal_gatilho_alerta', 'sinal_gatilho_sinal', 'sinal_alvo', 'sinal_margem_pregoes'];
        $up = $pdo->prepare("INSERT INTO apl_config_app (user_id,chave,valor) VALUES (?,?,?) ON DUPLICATE KEY UPDATE valor=VALUES(valor)");
        foreach ($permitidas as $k) {
            if (array_key_exists($k, $input)) $up->execute([$uid, $k, (string)$input[$k]]);
        }
        echo json_encode(['success' => true]);
        break;

    // ── COTAÇÕES: prévia (NÃO grava). Fonte: B3 (grátis) ou OpLab ─────────────
    case 'quote_preview':
        $fonte = cfgGet($pdo, $uid, 'cotacao_fonte') ?: 'b3';
        $cstmt = $pdo->prepare("SELECT codigo FROM `$T` WHERE status='Aberta' AND user_id=?$cartSql");
        $cstmt->execute([$uid, ...$cartParam]);
        $codigos = $cstmt->fetchAll(PDO::FETCH_COLUMN);
        if (!$codigos) { echo json_encode(['success' => true, 'itens' => [], 'ref' => null, 'fonte' => $fonte]); break; }

        $precoSimple = [];   // CODNEG => preco
        $ref = null; $debug = null;

        if ($fonte === 'oplab') {
            $tok = trim((string)cfgGet($pdo, $uid, 'oplab_token'));
            if (!$tok) { http_response_code(400); echo json_encode(['error' => 'Selecionou OpLab mas o token não está configurado em Config.']); exit; }
            $porAtivo = [];
            foreach ($codigos as $c) $porAtivo[tickerUnderlying($c)][] = $c;
            $maxTime = null;
            foreach ($porAtivo as $ativo => $cods) {
                $r = oplabGet('/market/options/' . $ativo, $tok);
                if ($r['code'] == 401 || $r['code'] == 403) { http_response_code(400); echo json_encode(['error' => 'Token da OpLab inválido/sem permissão (HTTP ' . $r['code'] . ').']); exit; }
                $data = json_decode($r['body'], true);
                if (isset($data['data']) && is_array($data['data'])) $data = $data['data'];
                if ($debug === null) $debug = ['fonte' => 'oplab', 'ativo' => $ativo, 'http' => $r['code'], 'amostra' => is_array($data) ? array_slice($data, 0, 2) : $r['body']];
                if (is_array($data)) foreach ($data as $opt) {
                    if (!is_array($opt)) continue;
                    $sym = strtoupper($opt['symbol'] ?? $opt['ticker'] ?? '');
                    if (!$sym) continue;
                    $precoSimple[$sym] = oplabPreco($opt);
                    $t = $opt['time'] ?? $opt['updated_at'] ?? $opt['date'] ?? null;
                    if ($t) $maxTime = $maxTime === null ? $t : max($maxTime, $t);
                }
            }
            $ref = $maxTime;
        } else {
            // B3 COTAHIST (gratuito) — ultimo pregao disponivel
            $res = b3UltimoPregao();
            if ($res === null) { http_response_code(502); echo json_encode(['error' => 'Não consegui baixar o boletim da B3 agora. Tente novamente em alguns minutos.']); exit; }
            $precoSimple = $res['map'];
            $ref = $res['ref'];
            $debug = ['fonte' => 'b3', 'pregao' => $ref, 'instrumentos' => count($precoSimple)];
            // MEMORIA DE PRECOS (Fase 4.1): ja que o boletim do dia esta baixado,
            // grava o fechamento dos codigos relevantes em apl_cotacoes. E' o que
            // mantem a serie historica viva no uso normal, sem cron nem backfill.
            // Falhar aqui nao pode derrubar a cotacao — e' um efeito colateral bom,
            // nao o objetivo da acao.
            try { quotes_memorizar($pdo, $ref, $precoSimple); } catch (Throwable $e) { /* silencioso de proposito */ }
        }

        $itens = [];
        foreach ($codigos as $c) {
            $p = $precoSimple[strtoupper($c)] ?? null;
            $itens[] = ['codigo' => $c, 'preco' => ($p !== null && $p > 0) ? (float)$p : null, 'underlying' => tickerUnderlying($c)];
        }
        echo json_encode(['success' => true, 'itens' => $itens, 'ref' => $ref, 'fonte' => $fonte, 'debug' => $debug]);
        break;

    // ── COTAÇÕES OpLab: aplicar (grava recebido das abertas) ─────────────────
    case 'apply_quotes':
        $precos = $input['precos'] ?? [];
        if (!is_array($precos) || !count($precos)) {
            http_response_code(400);
            echo json_encode(['error' => 'Nenhuma cotação para aplicar.']);
            exit;
        }
        $s = $pdo->prepare("UPDATE `$T` SET recebido=? WHERE codigo=? AND status='Aberta' AND user_id=?$cartSql");
        $n = 0;
        foreach ($precos as $cod => $preco) {
            $p = (float)str_replace(',', '.', $preco);
            if ($p <= 0) continue;
            $s->execute([$p, strtoupper($cod), $uid, ...$cartParam]);
            $n++;
        }
        echo json_encode(['success' => true, 'updated' => $n]);
        break;

    // ── NOTAS: listar (taxas) ─────────────────────────────────────────────────
    case 'list_notas':
        $st = $pdo->prepare("SELECT * FROM apl_notas WHERE user_id=? AND carteira_id=? ORDER BY data_pregao DESC, id DESC");
        $st->execute([$uid, $cid]);
        $rows = $st->fetchAll();
        echo json_encode($rows);
        break;

    // ── CUSTOS POR OPERACAO (rateio das taxas das notas) ─────────────────────
    // So faz sentido na Carteira rica (o Nitro nao tem notas nem taxas).
    case 'custos_ops':
        if (!$isCarteira) { echo json_encode(['custos' => new stdClass(), 'total_taxas' => 0, 'total_rateado' => 0]); break; }
        echo json_encode(custosPorOperacao($pdo, $uid, $cid));
        break;

    // ── NOTAS: excluir uma (NAO desfaz lancamentos na carteira) ───────────────
    case 'delete_nota':
        $id = (int)($input['id'] ?? 0);
        if (!$id) { http_response_code(400); echo json_encode(['error' => 'ID invalido']); exit; }
        $pdo->prepare("DELETE FROM apl_notas WHERE id=? AND user_id=? AND carteira_id=?")->execute([$id, $uid, $cid]);
        echo json_encode(['success' => true]);
        break;

    // ── IMPORTAR NOTA DE CORRETAGEM (sempre na CARTEIRA) ─────────────────────
    case 'import_nota':
        $nota  = $input['nota'] ?? [];
        $itens = $input['itens'] ?? [];
        $nr    = trim($nota['nr_nota'] ?? '');
        $dp    = $nota['data_pregao'] ?? '';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dp)) {
            http_response_code(400);
            echo json_encode(['error' => 'Data do pregão ausente ou inválida.']);
            exit;
        }
        // Evita reimportar a mesma nota
        if ($nr) {
            $c = $pdo->prepare("SELECT id FROM apl_notas WHERE nr_nota=? AND data_pregao=? AND user_id=? AND carteira_id=?");
            $c->execute([$nr, $dp, $uid, $cid]);
            if ($c->fetch()) {
                http_response_code(409);
                echo json_encode(['error' => "A nota $nr ($dp) já foi importada antes."]);
                exit;
            }
        }
        $abertas = 0; $encerradas = 0; $lancadas = 0; $reconciliadas = 0;
        $parciais = 0; $acumuladas = 0; $invertidas = 0;
        $avisos = []; $acoes = []; $itensSalvos = [];
        $val = function ($k) use ($nota) { return (float)str_replace(',', '.', $nota[$k] ?? 0); };
        $caixaOps = 0.0;   // soma com sinal dos negocios: +venda (credito) / -compra (debito)
        // ponto de restauração automático ANTES de mexer (permite reverter esta importação)
        try { criarCheckpoint($pdo, $uid, $cid, 'Antes da nota ' . ($nr ?: 's/n') . ' · ' . $dp, 'auto'); } catch (Throwable $e) {}
        try {
            $pdo->beginTransaction();
            foreach ($itens as $it) {
                if (empty($it['importar'])) continue;
                $cod = strtoupper(trim($it['codigo'] ?? ''));
                if (!$cod) continue;
                $est   = in_array(($it['estrutura'] ?? ''), ['PUT','CALL'], true) ? $it['estrutura'] : detectarTipo($cod);
                $preco = (float)str_replace(',', '.', $it['preco'] ?? 0);
                $lote  = (int)($it['lote'] ?? 0);
                $acao  = $it['acao'] ?? 'abrir';
                // efeito de caixa do negocio (independe da logica da carteira): venda entra, compra sai
                $caixaOps += ($acao === 'encerrar' ? 1 : -1) * $preco * $lote;
                // data da linha (default = data do pregao)
                $data  = preg_match('/^\d{4}-\d{2}-\d{2}$/', ($it['data'] ?? '')) ? $it['data'] : $dp;
                // guarda o negocio (p/ o modal "ver nota") — registra TODO item importado
                $itensSalvos[] = ['codigo' => $cod, 'estrutura' => $est, 'acao' => $acao, 'lote' => $lote, 'preco' => $preco];

                // posicao aberta atual deste codigo (se houver), com o sentido e a origem dela
                $q = $pdo->prepare("SELECT id,data_inicio,direcao,recebido,data_final,dias,status,estrutura,preco_medio,lote,origem FROM apl_carteira WHERE codigo=? AND status='Aberta' AND user_id=? AND carteira_id=? ORDER BY id DESC LIMIT 1");
                $q->execute([$cod, $uid, $cid]);
                $open = $q->fetch();
                $ehManual = $open && (($open['origem'] ?? 'nota') === 'manual');
                // posição ENCERRADA na mão (provisória) aguardando a nota confirmar o fechamento
                $qf = $pdo->prepare("SELECT id,data_inicio,direcao,estrutura,preco_medio,lote,recebido,data_final,dias,fecho_manual FROM apl_carteira WHERE codigo=? AND status='Encerrada' AND fecho_manual=1 AND user_id=? AND carteira_id=? ORDER BY id DESC LIMIT 1");
                $qf->execute([$cod, $uid, $cid]);
                $fechoManual = $qf->fetch();
                $encerra = function ($pos, $precoSaida) use ($pdo, $uid, $data, &$acoes) {
                    $acoes[] = ['t' => 'close', 'id' => (int)$pos['id'], 'prev' => ['recebido' => $pos['recebido'], 'data_final' => $pos['data_final'], 'dias' => $pos['dias'], 'status' => $pos['status']]];
                    $di = new DateTime($pos['data_inicio']); $df = new DateTime($data);
                    $dias = (int)$df->diff($di)->days;
                    $pdo->prepare("UPDATE apl_carteira SET recebido=?,data_final=?,dias=?,status='Encerrada' WHERE id=? AND user_id=?")
                        ->execute([$precoSaida, $data, $dias, $pos['id'], $uid]);
                };
                // a nota CONFIRMA um lançamento manual: sobrescreve os dados (preço/data/lote/tipo/sentido)
                // e marca como 'nota' (o sinal ✎ some). Mantém recebido (cotação) e o id da posição.
                $reconcilia = function ($pos, $estNova, $dataNova, $precoNovo, $loteNovo, $dirNova) use ($pdo, $uid, &$acoes) {
                    $acoes[] = ['t' => 'reconcile', 'id' => (int)$pos['id'], 'prev' => [
                        'estrutura' => $pos['estrutura'], 'data_inicio' => $pos['data_inicio'],
                        'preco_medio' => $pos['preco_medio'], 'lote' => $pos['lote'],
                        'direcao' => ($pos['direcao'] ?: 'C'), 'origem' => ($pos['origem'] ?? 'nota'),
                    ]];
                    $pdo->prepare("UPDATE apl_carteira SET estrutura=?,data_inicio=?,preco_medio=?,lote=?,direcao=?,origem='nota' WHERE id=? AND user_id=?")
                        ->execute([$estNova, $dataNova, $precoNovo, $loteNovo, $dirNova, (int)$pos['id'], $uid]);
                };
                // a nota CONFIRMA um encerramento manual: atualiza preço/data de saída e tira a bolinha (fecho_manual=0)
                $reconciliaFecho = function ($pos, $precoSaida, $dataSaida) use ($pdo, $uid, &$acoes) {
                    $di = new DateTime($pos['data_inicio']); $df = new DateTime($dataSaida);
                    $dias = (int)$df->diff($di)->days;
                    $acoes[] = ['t' => 'reconcile_close', 'id' => (int)$pos['id'], 'prev' => [
                        'recebido' => $pos['recebido'], 'data_final' => $pos['data_final'],
                        'dias' => $pos['dias'], 'fecho_manual' => ($pos['fecho_manual'] ?? 0),
                    ]];
                    $pdo->prepare("UPDATE apl_carteira SET recebido=?,data_final=?,dias=?,fecho_manual=0 WHERE id=? AND user_id=?")
                        ->execute([$precoSaida, $dataSaida, $dias, (int)$pos['id'], $uid]);
                };

                // ── MOTOR DE NETTING (helpers) ──────────────────────────────────
                // abre nova posição (C/V), origem nota
                $abrir = function ($dir, $estN, $dataN, $precoN, $loteN) use ($pdo, $uid, $cid, $cod, &$acoes) {
                    $pdo->prepare("INSERT INTO apl_carteira (user_id,carteira_id,codigo,estrutura,direcao,data_inicio,preco_medio,lote,status,origem) VALUES (?,?,?,?,?,?,?,?,'Aberta','nota')")
                        ->execute([$uid, $cid, $cod, $estN, $dir, $dataN, $precoN, $loteN]);
                    $id = (int)$pdo->lastInsertId();
                    $acoes[] = ['t' => 'open', 'id' => $id];
                    return $id;
                };
                // mesmo sentido → acumula no preço médio ponderado
                $mediar = function ($pos, $qtd, $precoN) use ($pdo, $uid, &$acoes) {
                    $novoLote = (int)$pos['lote'] + (int)$qtd;
                    $novoPM   = ($pos['preco_medio'] * $pos['lote'] + $precoN * $qtd) / $novoLote;
                    $acoes[] = ['t' => 'average', 'id' => (int)$pos['id'], 'prev' => ['lote' => (int)$pos['lote'], 'preco_medio' => $pos['preco_medio']]];
                    $pdo->prepare("UPDATE apl_carteira SET lote=?, preco_medio=? WHERE id=? AND user_id=?")
                        ->execute([$novoLote, round($novoPM, 3), (int)$pos['id'], $uid]);
                };
                // sentido oposto, qtd < lote → fatia 'qtd' numa nova linha Encerrada e reduz a aberta
                $encerraParcial = function ($pos, $qtd, $precoSaida, $dataSaida) use ($pdo, $uid, $cid, $cod, &$acoes) {
                    $di = new DateTime($pos['data_inicio']); $df = new DateTime($dataSaida);
                    $dias = (int)$df->diff($di)->days;
                    $pdo->prepare("INSERT INTO apl_carteira (user_id,carteira_id,codigo,estrutura,direcao,data_inicio,data_final,dias,preco_medio,lote,recebido,status,origem) VALUES (?,?,?,?,?,?,?,?,?,?,?,'Encerrada','nota')")
                        ->execute([$uid, $cid, $cod, $pos['estrutura'], ($pos['direcao'] ?: 'C'), $pos['data_inicio'], $dataSaida, $dias, $pos['preco_medio'], (int)$qtd, $precoSaida]);
                    $sliceId  = (int)$pdo->lastInsertId();
                    $novoLote = (int)$pos['lote'] - (int)$qtd;
                    $acoes[] = ['t' => 'partial_close', 'open_id' => (int)$pos['id'], 'prev_lote' => (int)$pos['lote'], 'prev_origem' => ($pos['origem'] ?? 'nota'), 'slice_id' => $sliceId];
                    $pdo->prepare("UPDATE apl_carteira SET lote=?, origem='nota' WHERE id=? AND user_id=?")->execute([$novoLote, (int)$pos['id'], $uid]);
                };
                // a nota confirma SÓ PARTE de um encerramento manual: ajusta a linha fechada p/ 'qtd' e REABRE o restante
                $splitReabreFecho = function ($pos, $qtd, $precoSaida, $dataSaida) use ($pdo, $uid, $cid, $cod, &$acoes) {
                    $di = new DateTime($pos['data_inicio']); $df = new DateTime($dataSaida);
                    $dias = (int)$df->diff($di)->days;
                    $restante = (int)$pos['lote'] - (int)$qtd;
                    $pdo->prepare("INSERT INTO apl_carteira (user_id,carteira_id,codigo,estrutura,direcao,data_inicio,preco_medio,lote,status,origem) VALUES (?,?,?,?,?,?,?,?,'Aberta','nota')")
                        ->execute([$uid, $cid, $cod, $pos['estrutura'], ($pos['direcao'] ?: 'C'), $pos['data_inicio'], $pos['preco_medio'], $restante]);
                    $reabertoId = (int)$pdo->lastInsertId();
                    $acoes[] = ['t' => 'split_reopen', 'id' => (int)$pos['id'], 'reopen_id' => $reabertoId, 'prev' => [
                        'recebido' => $pos['recebido'], 'data_final' => $pos['data_final'], 'dias' => $pos['dias'],
                        'lote' => (int)$pos['lote'], 'fecho_manual' => ($pos['fecho_manual'] ?? 0),
                    ]];
                    $pdo->prepare("UPDATE apl_carteira SET lote=?, recebido=?, data_final=?, dias=?, fecho_manual=0 WHERE id=? AND user_id=?")
                        ->execute([(int)$qtd, $precoSaida, $dataSaida, $dias, (int)$pos['id'], $uid]);
                };

                // ── DECISÃO POR NETTING (igual home broker) ─────────────────────
                $tradeDir = ($acao === 'encerrar') ? 'V' : 'C';   // nota: VENDA = sai (V), COMPRA = entra (C)

                if (!$open) {
                    // não há posição aberta deste código
                    if ($fechoManual && (($fechoManual['direcao'] ?: 'C') !== $tradeDir)) {
                        // a nota confirma um ENCERRAMENTO feito na mão (VENDA confirma saída de compra; COMPRA confirma recompra de venda)
                        $closedLote = (int)$fechoManual['lote'];
                        if ($lote >= $closedLote) {
                            $reconciliaFecho($fechoManual, $preco, $data);   // confirma o fechamento inteiro
                            $reconciliadas++;
                            $avisos[] = "$cod: encerramento manual confirmado pela nota — preço/data de saída atualizados.";
                            if ($lote > $closedLote && $est) {   // recomprou/vendeu mais do que estava fechado → excedente abre posição
                                $abrir($tradeDir, $est, $data, $preco, $lote - $closedLote);
                                if ($tradeDir === 'V') $lancadas++; else $abertas++;
                                $avisos[] = "$cod: excedente de " . ($lote - $closedLote) . " abriu nova posição (" . ($tradeDir === 'V' ? 'vendida' : 'comprada') . ").";
                            }
                        } else {
                            // a nota fechou só PARTE do que foi encerrado na mão → reabre o restante
                            $splitReabreFecho($fechoManual, $lote, $preco, $data);
                            $reconciliadas++;
                            $avisos[] = "$cod: a nota fechou só $lote de $closedLote — o restante (" . ($closedLote - $lote) . ") voltou para Abertas.";
                        }
                    } else {
                        // abre posição nova no sentido da nota
                        if (!$est) { $avisos[] = "$cod: tipo (CALL/PUT) não identificado — pulado."; continue; }
                        $abrir($tradeDir, $est, $data, $preco, $lote);
                        if ($tradeDir === 'V') {
                            $lancadas++;
                            $avisos[] = "$cod: venda sem posição comprada → registrada como posição VENDIDA (lançada/descoberta) — prêmio R$ " . number_format($preco, 3, ',', '.') . "/un.";
                        } else {
                            $abertas++;
                        }
                    }
                } elseif ($ehManual && (($open['direcao'] ?: 'C') === $tradeDir)) {
                    // posição lançada na MÃO, mesmo sentido → a nota CONFIRMA: sobrescreve preço, data, LOTE e tipo
                    if (!$est) { $avisos[] = "$cod: tipo (CALL/PUT) não identificado — pulado."; continue; }
                    $reconcilia($open, $est, $data, $preco, $lote, $tradeDir);
                    $reconciliadas++;
                    $avisos[] = "$cod: lançamento manual confirmado pela nota — preço e quantidade ($lote) atualizados.";
                } else {
                    $posDir = ($open['direcao'] ?: 'C');
                    $aberto = (int)$open['lote'];
                    if ($posDir === $tradeDir) {
                        // MESMO sentido → acumula (preço médio ponderado)
                        $mediar($open, $lote, $preco);
                        $acumuladas++;
                        $avisos[] = "$cod: posição aumentada para " . ($aberto + $lote) . " (preço médio recalculado).";
                    } elseif ($lote < $aberto) {
                        // OPOSTO, quantidade menor → encerramento PARCIAL (resto continua aberto)
                        $encerraParcial($open, $lote, $preco, $data);
                        $parciais++;
                        $avisos[] = "$cod: encerramento parcial de $lote — restam " . ($aberto - $lote) . " em aberto.";
                    } elseif ($lote == $aberto) {
                        // encerra a posição inteira
                        $encerra($open, $preco);
                        $encerradas++;
                    } else {
                        // OPOSTO, quantidade MAIOR → fecha tudo e INVERTE com o excedente
                        $encerra($open, $preco);
                        $encerradas++;
                        $excesso = $lote - $aberto;
                        $abrir($tradeDir, ($est ?: $open['estrutura']), $data, $preco, $excesso);
                        $invertidas++;
                        $avisos[] = "$cod: fechou $aberto e inverteu — abriu " . ($tradeDir === 'V' ? 'VENDIDA' : 'comprada') . " de $excesso (excedente).";
                    }
                }
            }
            $fluxoCaixa = round($caixaOps - $val('total_taxas'), 2);   // efeito de caixa COM SINAL
            $pdo->prepare(
                "INSERT INTO apl_notas (user_id,carteira_id,nr_nota,corretora,data_pregao,valor_operacoes,taxa_liquidacao,taxa_registro,emolumentos,outros,total_custos,total_taxas,liquido,fluxo_caixa,qtd_itens,acoes,itens)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
            )->execute([
                $uid, $cid, $nr ?: null, ($nota['corretora'] ?? 'RICO'), $dp,
                $val('valor_operacoes'), $val('taxa_liquidacao'), $val('taxa_registro'),
                $val('emolumentos'), $val('outros'), $val('total_custos'), $val('total_taxas'),
                $val('liquido'), $fluxoCaixa, $abertas + $encerradas + $lancadas + $reconciliadas + $parciais + $acumuladas + $invertidas, json_encode($acoes), json_encode($itensSalvos),
            ]);
            $pdo->commit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            http_response_code(500);
            echo json_encode(['error' => 'Falha ao importar: ' . $e->getMessage()]);
            exit;
        }
        echo json_encode(['success' => true, 'abertas' => $abertas, 'encerradas' => $encerradas, 'lancadas' => $lancadas, 'reconciliadas' => $reconciliadas, 'parciais' => $parciais, 'acumuladas' => $acumuladas, 'invertidas' => $invertidas, 'avisos' => $avisos]);
        break;

    // ── EXTRATO: dados para montar o razão (aporte inicial + movimentações + notas) ──
    case 'list_extrato':
        // aporte inicial agora vem da CARTEIRA ativa (Fase 3), nao mais do config_app
        $w = wallet_get($pdo, $uid, $cid);
        $mstmt = $pdo->prepare("SELECT id,tipo,data,descricao,valor FROM apl_movimentacoes WHERE user_id=? AND carteira_id=? ORDER BY data ASC, id ASC");
        $mstmt->execute([$uid, $cid]);
        $movs = $mstmt->fetchAll();
        foreach ($movs as &$m) { $m['valor'] = (float)$m['valor']; } unset($m);
        $nstmt = $pdo->prepare("SELECT id,nr_nota,data_pregao,valor_operacoes,total_taxas,liquido,fluxo_caixa FROM apl_notas WHERE user_id=? AND carteira_id=? ORDER BY data_pregao ASC, id ASC");
        $nstmt->execute([$uid, $cid]);
        $notas = $nstmt->fetchAll();
        foreach ($notas as &$n) {
            $n['valor_operacoes'] = (float)$n['valor_operacoes'];
            $n['total_taxas']     = (float)$n['total_taxas'];
            $n['liquido']         = (float)$n['liquido'];
            $n['fluxo_caixa']     = $n['fluxo_caixa'] === null ? null : (float)$n['fluxo_caixa'];
        } unset($n);
        echo json_encode([
            'success'        => true,
            'aporte_inicial' => ($w && $w['aporte_inicial'] !== null) ? (float)$w['aporte_inicial'] : 0,
            'aporte_data'    => $w['aporte_inicial_data'] ?? null,
            'movimentacoes'  => $movs,
            'notas'          => $notas,
        ]);
        break;

    // ── EXTRATO: novo depósito ou saque (simulado) ───────────────────────────
    case 'add_mov':
        $tipo = ($input['tipo'] ?? '') === 'saque' ? 'saque' : 'aporte';
        $data = $input['data'] ?? '';
        $valor = round((float)str_replace(',', '.', $input['valor'] ?? 0), 2);
        $desc = trim((string)($input['descricao'] ?? '')) ?: null;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) { http_response_code(400); echo json_encode(['error' => 'Data inválida.']); exit; }
        if ($valor <= 0) { http_response_code(400); echo json_encode(['error' => 'Informe um valor maior que zero.']); exit; }
        $pdo->prepare("INSERT INTO apl_movimentacoes (user_id,carteira_id,tipo,data,descricao,valor) VALUES (?,?,?,?,?,?)")
            ->execute([$uid, $cid, $tipo, $data, $desc, $valor]);
        echo json_encode(['success' => true, 'id' => (int)$pdo->lastInsertId()]);
        break;

    // ── EXTRATO: excluir movimentação manual ─────────────────────────────────
    case 'delete_mov':
        $id = (int)($input['id'] ?? 0);
        if (!$id) { http_response_code(400); echo json_encode(['error' => 'ID inválido']); exit; }
        $pdo->prepare("DELETE FROM apl_movimentacoes WHERE id=? AND user_id=? AND carteira_id=?")->execute([$id, $uid, $cid]);
        echo json_encode(['success' => true]);
        break;

    // ── EXTRATO: ajustar o efeito de caixa (com sinal) de uma nota ────────────
    case 'set_nota_fluxo':
        $id = (int)($input['id'] ?? 0);
        if (!$id) { http_response_code(400); echo json_encode(['error' => 'ID inválido']); exit; }
        $fluxo = round((float)str_replace(',', '.', $input['fluxo'] ?? 0), 2);
        $pdo->prepare("UPDATE apl_notas SET fluxo_caixa=? WHERE id=? AND user_id=? AND carteira_id=?")->execute([$fluxo, $id, $uid, $cid]);
        echo json_encode(['success' => true]);
        break;

    // ── PERFIL: dados do usuario logado ──────────────────────────────────────
    case 'me':
        $st = $pdo->prepare("SELECT id,nome,usuario,email,bio,avatar,criado_em FROM apl_usuarios WHERE id=?");
        $st->execute([$_SESSION['user_id']]);
        $u = $st->fetch();
        if (!$u) { http_response_code(404); echo json_encode(['error' => 'Usuario nao encontrado']); exit; }
        $u['avatar'] = avatar_url($u['avatar']);   // URL publica (com cache-bust) ou null = usar iniciais
        echo json_encode(['success' => true, 'user' => $u]);
        break;

    // ── PERFIL: enviar foto de avatar (multipart; $_FILES['avatar']) ─────────
    case 'avatar_upload':
        $res = avatar_processar_upload($_FILES['avatar'] ?? null, $uid);
        if (!$res['ok']) { http_response_code(400); echo json_encode(['error' => $res['error']]); exit; }
        // Apaga o avatar antigo (se houver) antes de gravar o novo.
        $ant = $pdo->prepare("SELECT avatar FROM apl_usuarios WHERE id=?");
        $ant->execute([$uid]);
        $antigo = $ant->fetchColumn();
        $pdo->prepare("UPDATE apl_usuarios SET avatar=? WHERE id=?")->execute([$res['path'], $uid]);
        if ($antigo && $antigo !== $res['path']) avatar_apagar_arquivo($antigo);
        $_SESSION['user_avatar'] = $res['path'];
        echo json_encode(['success' => true, 'avatar' => avatar_url($res['path'])]);
        break;

    // ── PERFIL: remover a foto (volta as iniciais) ───────────────────────────
    case 'avatar_remove':
        $ant = $pdo->prepare("SELECT avatar FROM apl_usuarios WHERE id=?");
        $ant->execute([$uid]);
        $antigo = $ant->fetchColumn();
        $pdo->prepare("UPDATE apl_usuarios SET avatar=NULL WHERE id=?")->execute([$uid]);
        avatar_apagar_arquivo($antigo);
        $_SESSION['user_avatar'] = null;
        echo json_encode(['success' => true]);
        break;

    // ── PERFIL: atualizar nome / usuario / email / bio ───────────────────────
    case 'update_profile':
        $nome    = trim($input['nome'] ?? '');
        $usuario = strtolower(trim($input['usuario'] ?? ''));
        $email   = strtolower(trim($input['email'] ?? ''));
        $bio     = trim($input['bio'] ?? '');
        $erros = [];
        if (mb_strlen($nome) < 2) $erros[] = 'Informe seu nome.';
        if (!preg_match('/^[a-z0-9._-]{3,40}$/', $usuario)) $erros[] = 'Usuario invalido (3+ caracteres: letras, numeros, . _ -).';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $erros[] = 'E-mail invalido.';
        if ($erros) { http_response_code(400); echo json_encode(['error' => implode(' ', $erros)]); exit; }
        // usuario/email nao podem pertencer a OUTRO usuario (mensagem especifica)
        $chk = $pdo->prepare("SELECT usuario,email FROM apl_usuarios WHERE (usuario=? OR email=?) AND id<>?");
        $chk->execute([$usuario, $email, $_SESSION['user_id']]);
        foreach ($chk->fetchAll() as $r) {
            if ($r['usuario'] === $usuario) { http_response_code(409); echo json_encode(['error' => 'Este nome de usuário já existe. Escolha outro.']); exit; }
            if ($r['email']   === $email)   { http_response_code(409); echo json_encode(['error' => 'Este e-mail já está em uso por outra conta.']); exit; }
        }
        $pdo->prepare("UPDATE apl_usuarios SET nome=?,usuario=?,email=?,bio=? WHERE id=?")
            ->execute([$nome, $usuario, $email, ($bio !== '' ? $bio : null), $_SESSION['user_id']]);
        $_SESSION['user_nome'] = $nome; $_SESSION['user_usuario'] = $usuario; $_SESSION['user_email'] = $email;
        echo json_encode(['success' => true]);
        break;

    // ── PERFIL: trocar senha (min 6) ─────────────────────────────────────────
    case 'change_password':
        $atual = (string)($input['atual'] ?? '');
        $nova  = (string)($input['nova'] ?? '');
        if (strlen($nova) < 12) { http_response_code(400); echo json_encode(['error' => 'A nova senha precisa de ao menos 12 caracteres.']); exit; }
        $st = $pdo->prepare("SELECT senha_hash FROM apl_usuarios WHERE id=?");
        $st->execute([$_SESSION['user_id']]);
        $row = $st->fetch();
        if (!$row || !password_verify($atual, $row['senha_hash'])) {
            http_response_code(400); echo json_encode(['error' => 'Senha atual incorreta.']); exit;
        }
        $pdo->prepare("UPDATE apl_usuarios SET senha_hash=? WHERE id=?")
            ->execute([$newHash = password_hash($nova, PASSWORD_DEFAULT), $_SESSION['user_id']]);
        $_SESSION['auth_version'] = hash('sha256', $newHash);
        session_regenerate_id(true);
        echo json_encode(['success' => true]);
        break;

    // ── DETALHE de uma nota (resumo + negociações) p/ o modal "ver nota" ─────
    case 'nota_detalhe':
        $id = (int)($input['id'] ?? $_GET['id'] ?? 0);
        if (!$id) { http_response_code(400); echo json_encode(['error' => 'ID inválido']); exit; }
        $st = $pdo->prepare("SELECT * FROM apl_notas WHERE id=? AND user_id=? AND carteira_id=?");
        $st->execute([$id, $uid, $cid]);
        $n = $st->fetch();
        if (!$n) { http_response_code(404); echo json_encode(['error' => 'Nota não encontrada']); exit; }
        foreach (['valor_operacoes', 'taxa_liquidacao', 'taxa_registro', 'emolumentos', 'outros', 'total_custos', 'total_taxas', 'liquido', 'fluxo_caixa'] as $f) {
            if (array_key_exists($f, $n)) $n[$f] = $n[$f] === null ? null : (float)$n[$f];
        }
        $negocios = []; $origem = 'nenhum';
        $itensJson = json_decode($n['itens'] ?? '', true);
        if (is_array($itensJson) && count($itensJson)) {
            $origem = 'itens';
            foreach ($itensJson as $it) {
                $negocios[] = [
                    'cv'        => (($it['acao'] ?? '') === 'encerrar') ? 'V' : 'C',
                    'codigo'    => $it['codigo'] ?? '',
                    'estrutura' => $it['estrutura'] ?? '',
                    'lote'      => (int)($it['lote'] ?? 0),
                    'preco'     => (float)($it['preco'] ?? 0),
                ];
            }
        } else {
            // reconstrói das ações (notas antigas): open=C/V conforme direcao; close inverte
            $acoesJson = json_decode($n['acoes'] ?? '', true);
            if (is_array($acoesJson) && count($acoesJson)) {
                $origem = 'acoes';
                $pst = $pdo->prepare("SELECT codigo,estrutura,direcao,preco_medio,lote,recebido FROM apl_carteira WHERE id=? AND user_id=? AND carteira_id=?");
                foreach ($acoesJson as $a) {
                    $pst->execute([(int)($a['id'] ?? 0), $uid, $cid]);
                    $p = $pst->fetch();
                    if (!$p) continue;
                    $isOpen = (($a['t'] ?? '') === 'open');
                    $negocios[] = [
                        'cv'        => $isOpen ? (($p['direcao'] === 'V') ? 'V' : 'C') : (($p['direcao'] === 'V') ? 'C' : 'V'),
                        'codigo'    => $p['codigo'],
                        'estrutura' => $p['estrutura'],
                        'lote'      => (int)$p['lote'],
                        'preco'     => (float)($isOpen ? $p['preco_medio'] : $p['recebido']),
                    ];
                }
            }
        }
        echo json_encode(['success' => true, 'nota' => $n, 'negocios' => $negocios, 'origem' => $origem]);
        break;

    // ── DESFAZER uma nota importada (reverte exatamente o que ela fez) ───────
    case 'undo_nota':
        $id = (int)($input['id'] ?? 0);
        if (!$id) { http_response_code(400); echo json_encode(['error' => 'ID inválido']); exit; }
        $st = $pdo->prepare("SELECT acoes FROM apl_notas WHERE id=? AND user_id=? AND carteira_id=?");
        $st->execute([$id, $uid, $cid]);
        $row = $st->fetch();
        if (!$row) { http_response_code(404); echo json_encode(['error' => 'Nota não encontrada']); exit; }
        $acoes = json_decode($row['acoes'] ?? '', true);
        $temLog = is_array($acoes) && count($acoes);
        try {
            $pdo->beginTransaction();
            if ($temLog) {
                foreach (array_reverse($acoes) as $a) {
                    if (($a['t'] ?? '') === 'open') {
                        $pdo->prepare("DELETE FROM apl_carteira WHERE id=? AND user_id=?")->execute([(int)$a['id'], $uid]);
                    } elseif (($a['t'] ?? '') === 'close') {
                        $p = $a['prev'] ?? [];
                        $pdo->prepare("UPDATE apl_carteira SET recebido=?,data_final=?,dias=?,status=? WHERE id=? AND user_id=?")
                            ->execute([$p['recebido'] ?? null, $p['data_final'] ?? null, $p['dias'] ?? null, $p['status'] ?? 'Aberta', (int)$a['id'], $uid]);
                    } elseif (($a['t'] ?? '') === 'reconcile') {
                        // restaura o lançamento manual como estava antes da nota confirmá-lo (a bolinha volta)
                        $p = $a['prev'] ?? [];
                        $pdo->prepare("UPDATE apl_carteira SET estrutura=?,data_inicio=?,preco_medio=?,lote=?,direcao=?,origem=? WHERE id=? AND user_id=?")
                            ->execute([$p['estrutura'] ?? null, $p['data_inicio'] ?? null, $p['preco_medio'] ?? null, $p['lote'] ?? null, $p['direcao'] ?? 'C', $p['origem'] ?? 'manual', (int)$a['id'], $uid]);
                    } elseif (($a['t'] ?? '') === 'reconcile_close') {
                        // restaura o encerramento manual como estava antes da nota confirmá-lo (a bolinha volta)
                        $p = $a['prev'] ?? [];
                        $pdo->prepare("UPDATE apl_carteira SET recebido=?,data_final=?,dias=?,fecho_manual=? WHERE id=? AND user_id=?")
                            ->execute([$p['recebido'] ?? null, $p['data_final'] ?? null, $p['dias'] ?? null, $p['fecho_manual'] ?? 1, (int)$a['id'], $uid]);
                    } elseif (($a['t'] ?? '') === 'average') {
                        // desfaz a acumulação: volta lote e preço médio anteriores
                        $p = $a['prev'] ?? [];
                        $pdo->prepare("UPDATE apl_carteira SET lote=?,preco_medio=? WHERE id=? AND user_id=?")
                            ->execute([$p['lote'] ?? null, $p['preco_medio'] ?? null, (int)$a['id'], $uid]);
                    } elseif (($a['t'] ?? '') === 'partial_close') {
                        // desfaz o encerramento parcial: apaga a fatia encerrada e devolve o lote/origem à posição aberta
                        $pdo->prepare("DELETE FROM apl_carteira WHERE id=? AND user_id=?")->execute([(int)($a['slice_id'] ?? 0), $uid]);
                        $pdo->prepare("UPDATE apl_carteira SET lote=?,origem=? WHERE id=? AND user_id=?")
                            ->execute([$a['prev_lote'] ?? null, $a['prev_origem'] ?? 'nota', (int)($a['open_id'] ?? 0), $uid]);
                    } elseif (($a['t'] ?? '') === 'split_reopen') {
                        // desfaz o split do encerramento manual: apaga a parte reaberta e restaura a linha fechada
                        $p = $a['prev'] ?? [];
                        $pdo->prepare("DELETE FROM apl_carteira WHERE id=? AND user_id=?")->execute([(int)($a['reopen_id'] ?? 0), $uid]);
                        $pdo->prepare("UPDATE apl_carteira SET lote=?,recebido=?,data_final=?,dias=?,fecho_manual=? WHERE id=? AND user_id=?")
                            ->execute([$p['lote'] ?? null, $p['recebido'] ?? null, $p['data_final'] ?? null, $p['dias'] ?? null, $p['fecho_manual'] ?? 1, (int)$a['id'], $uid]);
                    }
                }
            }
            $pdo->prepare("DELETE FROM apl_notas WHERE id=? AND user_id=? AND carteira_id=?")->execute([$id, $uid, $cid]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            http_response_code(500); echo json_encode(['error' => 'Falha ao desfazer: ' . $e->getMessage()]); exit;
        }
        echo json_encode(['success' => true, 'revertido' => $temLog,
            'msg' => $temLog ? 'Importação desfeita (posições revertidas) e nota removida.' : 'Nota removida. (Importada antes do registro de ações — posições não foram alteradas automaticamente.)']);
        break;

    // ── SÉRIE HISTÓRICA DO PATRIMÔNIO (Fase 4.1) ─────────────────────────────
    // Lê a série materializada da CARTEIRA ATIVA, já com o TWR acumulado.
    // Só faz sentido na Carteira rica: o Nitro não tem caixa nem lote.
    // Params: de / ate ('YYYY-MM-DD', opcionais) ou periodo=1m|3m|6m|12m|ytd|max.
    case 'serie_get':
        if (!$isCarteira) { echo json_encode(['success' => true, 'serie' => [], 'aviso' => 'A série é da Carteira rica.']); break; }
        [$de, $ate] = serieJanela($_GET['periodo'] ?? '', $_GET['de'] ?? null, $_GET['ate'] ?? null);
        $serie = series_load($pdo, $uid, $cid, $de, $ate);
        echo json_encode([
            'success' => true,
            'de'      => $serie ? $serie[0]['data'] : null,
            'ate'     => $serie ? $serie[count($serie) - 1]['data'] : null,
            'serie'   => $serie,
        ]);
        break;

    // ── ÍNDICES COMPARADORES (Fase 4.1) ──────────────────────────────────────
    // Devolve os índices já NORMALIZADOS em base 100 nos mesmos pregões da série
    // — é assim que CDI (% ao dia), IPCA (% ao mês) e ETFs (preço) viram linhas
    // comparáveis no mesmo gráfico. `proxy` != null avisa a UI para rotular
    // "Ibovespa · via BOVA11" (há descolamento em relação ao índice puro).
    // Params: os mesmos de serie_get + quais=cdi,ipca,ibov,sp500,ifix.
    case 'indices_get':
        [$de, $ate] = serieJanela($_GET['periodo'] ?? '', $_GET['de'] ?? null, $_GET['ate'] ?? null);
        // os pregões vêm da SÉRIE da carteira (quando existe), para as linhas
        // ficarem alinhadas ponto a ponto; senão, dos pregões conhecidos.
        $serie   = $isCarteira ? series_load($pdo, $uid, $cid, $de, $ate) : [];
        $pregoes = $serie ? array_column($serie, 'data') : quotes_pregoes($pdo, $de, $ate);
        $quais   = array_filter(array_map('trim', explode(',', (string)($_GET['quais'] ?? ''))));
        // numa janela recortada os comparadores partem do MESMO pregao-base que o
        // TWR da carteira usa — senao a carteira mede um dia a mais que eles.
        $antes   = ($isCarteira && $serie) ? series_pregao_anterior($pdo, $uid, $cid, $de) : null;
        echo json_encode([
            'success' => true,
            'de'      => $pregoes ? $pregoes[0] : null,
            'ate'     => $pregoes ? end($pregoes) : null,
            'indices' => indices_load($pdo, $pregoes, $quais ?: null, $antes),
        ]);
        break;

    // ── RENTABILIDADE DE UMA CARTEIRA SIMPLES p/ COMPARAR (Nitro/Betha) — ADR 022
    // Devolve o ROI acumulado ponderado pelo capital de uma carteira simples,
    // em base 100 na janela, para o gráfico da home (aba Rentabilidade) comparar
    // com a linha TWR da Carteira rica. Read-only. Gate: super_admin (o boot já
    // 403 p/ ?tabela=posicoes|betha de quem não pode). NÃO é TWR — a Nitro/Betha
    // não têm caixa; ver sim_rentabilidade() no núcleo puro do series.php.
    case 'serie_sim':
        if ($isCarteira) { echo json_encode(['success' => true, 'pontos' => new stdClass(), 'aviso' => 'serie_sim é das carteiras simples (Nitro/Betha).']); break; }
        [$de, $ate] = serieJanela($_GET['periodo'] ?? '', $_GET['de'] ?? null, $_GET['ate'] ?? null);
        $pregoes = quotes_pregoes($pdo, $de, $ate);
        if (!$pregoes) { echo json_encode(['success' => true, 'pontos' => new stdClass(), 'cobertura' => 0]); break; }
        $st = $pdo->prepare("SELECT codigo,direcao,data_inicio,data_final,pago,recebido,status FROM `$T` WHERE user_id=?");
        $st->execute([$uid]);
        $pos  = $st->fetchAll();
        $cods = array_values(array_unique(array_map(fn($r) => strtoupper($r['codigo']), $pos)));
        $precos = $cods ? quotes_carry(quotes_load($pdo, $cods, $pregoes[0], end($pregoes)), $pregoes) : [];
        $sim = sim_rentabilidade($pos, $pregoes, $precos);
        // cobertura baixa => "sem dados" honesto (não desenha linha achatada enganosa)
        if ($sim['cobertura'] < 0.5) { echo json_encode(['success' => true, 'pontos' => new stdClass(), 'cobertura' => round($sim['cobertura'], 3)]); break; }
        // rebase: base 100 no 1º pregão da janela (o comparador começa em 0, como os índices)
        $base = $sim['pontos'][$pregoes[0]] ?? 0.0;
        $out = [];
        foreach ($pregoes as $d) $out[$d] = 100.0 + (($sim['pontos'][$d] ?? $base) - $base);
        echo json_encode(['success' => true, 'pontos' => $out, 'cobertura' => round($sim['cobertura'], 3)]);
        break;

    // ── SOMA PURA DO % de uma carteira p/ o CONFRONTO (aba "Carteiras") ───────
    // Serve as TRÊS carteiras na MESMA base: a soma pura do % de cada operação
    // (o capital não pondera). ?tabela=carteira usa preco_medio+carteira ativa;
    // posicoes/betha usam pago (gate super_admin já no boot). O valor é o %
    // acumulado relativo ao 1º pregão da janela (começa em 0, como o TWR). Só
    // aqui a carteira rica troca de base — nos cards ela segue casada com a RICO.
    // Ver sim_soma_pct() no núcleo puro do series.php. (pedido 08/08/2026)
    case 'serie_pct_soma':
        [$de, $ate] = serieJanela($_GET['periodo'] ?? '', $_GET['de'] ?? null, $_GET['ate'] ?? null);
        $pregoes = quotes_pregoes($pdo, $de, $ate);
        if (!$pregoes) { echo json_encode(['success' => true, 'pontos' => new stdClass(), 'cobertura' => 0]); break; }
        if ($isCarteira) {
            $st = $pdo->prepare("SELECT codigo,direcao,data_inicio,data_final,preco_medio,recebido,status FROM `$T` WHERE user_id=? AND carteira_id=?");
            $st->execute([$uid, $cid]);
            $custoKey = 'preco_medio';
        } else {
            $st = $pdo->prepare("SELECT codigo,direcao,data_inicio,data_final,pago,recebido,status FROM `$T` WHERE user_id=?");
            $st->execute([$uid]);
            $custoKey = 'pago';
        }
        $pos  = $st->fetchAll();
        $cods = array_values(array_unique(array_map(fn($r) => strtoupper($r['codigo']), $pos)));
        $precos = $cods ? quotes_carry(quotes_load($pdo, $cods, $pregoes[0], end($pregoes)), $pregoes) : [];
        $soma = sim_soma_pct($pos, $pregoes, $precos, $custoKey);
        if ($soma['cobertura'] < 0.5) { echo json_encode(['success' => true, 'pontos' => new stdClass(), 'cobertura' => round($soma['cobertura'], 3)]); break; }
        $base = $soma['pontos'][$pregoes[0]] ?? 0.0;
        $out = [];
        foreach ($pregoes as $d) $out[$d] = ($soma['pontos'][$d] ?? $base) - $base;
        echo json_encode(['success' => true, 'pontos' => $out, 'cobertura' => round($soma['cobertura'], 3)]);
        break;

    // ── CONSOLIDADO: "patrimônio hoje" + resultado por período (Fase 4.3) ────
    // Uma leitura só da série serve as duas coisas — elas são a mesma pergunta
    // ("onde estou" e "como cheguei aqui") em recortes diferentes.
    //
    // `hoje` = fotografia do último pregão, com REALIZADO e NÃO REALIZADO
    //   separados: o primeiro já virou dinheiro, o segundo ainda é preço de tela.
    // `anos` = árvore Ano→Mês→Dia da lente "A mercado", onde o resultado de um
    //   período é a VARIAÇÃO DO PATRIMÔNIO menos os APORTES (as abertas entram
    //   pela marcação a mercado, sem precisar "pertencer" a um mês).
    //
    // ⚠️ Quando estes números divergirem dos cards do topo, a série é que está
    // certa (ex.: opção vencida que o card ainda mostra pelo preço velho) — ver
    // §10 do plano e o `php series_cli.php aceite`.
    case 'serie_consolidado':
        if (!$isCarteira) { echo json_encode(['success' => true, 'hoje' => null, 'anos' => []]); break; }
        $serie = series_load($pdo, $uid, $cid);            // histórico inteiro
        if (!$serie) { echo json_encode(['success' => true, 'hoje' => null, 'anos' => []]); break; }
        $u = $serie[count($serie) - 1];
        $ant = count($serie) > 1 ? $serie[count($serie) - 2] : null;
        echo json_encode([
            'success' => true,
            'hoje' => [
                'data'             => $u['data'],
                'patrimonio'       => $u['patrimonio'],
                'caixa'            => $u['caixa'],
                'valor_mercado'    => $u['valor_mercado'],
                'capital_aplicado' => $u['capital_aplicado'],
                'nao_realizado'    => round($u['valor_mercado'] - $u['capital_aplicado'], 2),
                'realizado'        => $u['realizado_acum'],
                'custos'           => $u['custos_acum'],
                'pregoes'          => count($serie),
                'twr_acum'         => $u['twr_acum'],
                // variação do dia: já descontado o aporte/saque do próprio dia
                'dia' => [
                    'anterior' => $ant['data'] ?? null,
                    'valor'    => $ant ? round($u['patrimonio'] - $ant['patrimonio'] - $u['fluxo_externo'], 2) : null,
                    'pct'      => $ant ? $u['retorno_dia'] : null,
                ],
            ],
            'anos' => series_periodos($serie),
        ]);
        break;

    // ── SÉRIE: recalcular (manutenção) ───────────────────────────────────────
    // A série é cache: quando posição/nota/movimentação muda, ela precisa ser
    // refeita. Idempotente (apaga e reescreve a janela da carteira ativa).
    case 'serie_rebuild':
        if (!$isCarteira) { http_response_code(400); echo json_encode(['error' => 'A série é da Carteira rica.']); exit; }
        echo json_encode(['success' => true] + series_rebuild($pdo, $uid, $cid));
        break;

    // ── PAINEL DE SINAIS (Nitro) — gatilho de entrada por histórico (ADR 021) ─
    // Read-only. Uma única leitura serve a coluna E o modal. Toda a matemática
    // vem do núcleo puro do series.php (sinais_trajetoria/sinais_eventos) — aqui
    // é só I/O + agregação. Gate: só o super_admin (a Nitro é pessoal dele).
    case 'sinais':
        // Sinais só fazem sentido nas carteiras SIMPLES (Nitro/Betha) — ambas
        // pessoais do super_admin, cujo gate já rodou no boot para ?tabela=posicoes|betha.
        // Os limiares (sinal_*) são por-usuário e compartilhados entre elas.
        if ($isCarteira) { http_response_code(400); echo json_encode(['error' => 'Sinais são das carteiras Nitro/Betha.']); exit; }

        // limiares (por-usuário; defaults da ADR 021)
        $gAlerta = (float)(cfgGet($pdo, $uid, 'sinal_gatilho_alerta') ?? 40);
        $gSinal  = (float)(cfgGet($pdo, $uid, 'sinal_gatilho_sinal')  ?? 50);
        $alvo    = (float)(cfgGet($pdo, $uid, 'sinal_alvo')           ?? 140);
        $margem  = (int)  (cfgGet($pdo, $uid, 'sinal_margem_pregoes') ?? 3);

        // todas as compradas do Nitro (base do estudo) + as abertas (a coluna)
        $st = $pdo->prepare("SELECT id,codigo,direcao,data_inicio,data_final,pago,recebido,status
                             FROM `$T` WHERE user_id=? AND direcao='C'");
        $st->execute([$uid]);
        $compradas = $st->fetchAll();

        // pregões e cotações (carry-forward) só dos códigos envolvidos
        $pregoes = quotes_pregoes($pdo);
        $cob = $pregoes ? ['de' => $pregoes[0], 'ate' => end($pregoes)] : ['de' => null, 'ate' => null];
        $codigos = array_values(array_unique(array_map(fn($r) => strtoupper($r['codigo']), $compradas)));
        $precos = ($pregoes && $codigos)
            ? quotes_carry(quotes_load($pdo, $codigos, $pregoes[0], end($pregoes)), $pregoes)
            : [];

        // ── estudo: percorre TODAS as compradas com cobertura total ──────────
        $baseTotal = 0; $foraDaBase = 0;
        // universo por gatilho: guarda os picos p/ mediana e os dias_ate_alvo
        $uni = [
            'alerta' => ['picos' => [], 'atingiram' => 0, 'dias' => []],
            'sinal'  => ['picos' => [], 'atingiram' => 0, 'dias' => []],
        ];
        $porAtivo = [];   // ticker => ['n','atingiram','picos','recup_elegiveis','recup_ok']
        foreach ($compradas as $r) {
            $traj = sinais_trajetoria($r, $pregoes, $precos, $margem);
            if (!$traj['cobertura'] || $traj['pico'] === null) { $foraDaBase++; continue; }
            $baseTotal++;
            $ev = sinais_eventos($traj, $r['data_final'], $gAlerta, $gSinal, $alvo);
            $tk = tickerUnderlying($r['codigo']);

            if ($ev['cruzou_alerta']) {
                $uni['alerta']['picos'][] = $traj['pico'];
                if ($ev['atingiu_alvo']) { $uni['alerta']['atingiram']++; if ($ev['dias_ate_alvo'] !== null) $uni['alerta']['dias'][] = $ev['dias_ate_alvo']; }
                // por-ativo: universo = cruzou o gatilho de alerta (a manchete usa 40%)
                if (!isset($porAtivo[$tk])) $porAtivo[$tk] = ['n' => 0, 'atingiram' => 0, 'picos' => [], 'recup_eleg' => 0, 'recup_ok' => 0];
                $porAtivo[$tk]['n']++;
                $porAtivo[$tk]['picos'][] = $traj['pico'];
                if ($ev['atingiu_alvo']) $porAtivo[$tk]['atingiram']++;
                // ambivalência do "recuou": entre as que cruzaram, quantas voltaram ao alvo após recuar
                $porAtivo[$tk]['recup_eleg']++;
                if ($ev['recuperou']) $porAtivo[$tk]['recup_ok']++;
            }
            if ($ev['cruzou_sinal']) {
                $uni['sinal']['picos'][] = $traj['pico'];
                if ($ev['atingiu_alvo']) { $uni['sinal']['atingiram']++; if ($ev['dias_ate_alvo'] !== null) $uni['sinal']['dias'][] = $ev['dias_ate_alvo']; }
            }
        }

        $manchete = function ($u) {
            $n = count($u['picos']);
            return [
                'n' => $n,
                'atingiram_alvo' => $u['atingiram'],
                'taxa' => $n ? round($u['atingiram'] / $n, 4) : null,
                'pico_mediano' => $n ? round(mediana($u['picos']), 1) : null,
                'pico_max' => $n ? round(max($u['picos']), 1) : null,
                'dias_ate_alvo' => [
                    'mediana' => $u['dias'] ? round(mediana($u['dias'])) : null,
                    'min' => $u['dias'] ? min($u['dias']) : null,
                    'max' => $u['dias'] ? max($u['dias']) : null,
                ],
            ];
        };

        $porAtivoOut = [];
        foreach ($porAtivo as $tk => $a) {
            $porAtivoOut[] = [
                'ticker' => $tk,
                'n' => $a['n'],
                'atingiram_alvo' => $a['atingiram'],
                'pico_mediano' => round(mediana($a['picos']), 1),
                // recupera: só afirma algo com base mínima; senão null (não inventa)
                'recupera' => $a['recup_eleg'] >= 3 ? ($a['recup_ok'] > 0) : null,
            ];
        }
        usort($porAtivoOut, fn($x, $y) => $y['atingiram_alvo'] <=> $x['atingiram_alvo'] ?: $y['n'] <=> $x['n']);

        // ── coluna: sinal de cada posição ABERTA comprada ────────────────────
        $abertasOut = [];
        foreach ($compradas as $r) {
            if ($r['status'] !== 'Aberta') continue;
            $traj = sinais_trajetoria($r, $pregoes, $precos, $margem);
            $rec  = $r['recebido'];
            $pago = (float)$r['pago'];
            $rentAtual = ($rec !== null && $pago > 0) ? round(((float)$rec - $pago) / $pago * 100.0, 1) : null;

            if ($rentAtual === null)            $estado = 'sem_cotacao';
            elseif ($rentAtual >= $gSinal)      $estado = 'sinal';
            elseif ($rentAtual >= $gAlerta)     $estado = 'alerta';
            elseif ($traj['pico'] !== null && $traj['pico'] >= $gAlerta) $estado = 'recuou';
            else                                $estado = 'nenhum';

            $abertasOut[] = [
                'id' => (int)$r['id'],
                'codigo' => $r['codigo'],
                'ticker' => tickerUnderlying($r['codigo']),
                'direcao' => $r['direcao'],
                'rent_atual' => $rentAtual,
                'pico_vida' => $traj['pico'] !== null ? round($traj['pico'], 1) : null,
                'estado' => $estado,
                'dias_vida' => (int)round((strtotime('today') - strtotime($r['data_inicio'])) / 86400),
                'vencimento' => $traj['venc'],
                'fim_janela' => $traj['fim'],
            ];
        }

        echo json_encode([
            'success' => true,
            'config' => ['gatilho_alerta' => $gAlerta, 'gatilho_sinal' => $gSinal, 'alvo' => $alvo, 'margem_pregoes' => $margem],
            'cobertura' => ['de' => $cob['de'], 'ate' => $cob['ate'], 'base_total' => $baseTotal, 'fora_da_base' => $foraDaBase],
            'agregado' => ['alerta' => $manchete($uni['alerta']), 'sinal' => $manchete($uni['sinal'])],
            'por_ativo' => $porAtivoOut,
            'abertas' => $abertasOut,
        ]);
        break;

    // ── PONTOS DE RESTAURAÇÃO ────────────────────────────────────────────────
    case 'checkpoint_create':
        $rot = trim((string)($input['rotulo'] ?? '')) ?: ('Ponto manual ' . fmt_data_hora());
        criarCheckpoint($pdo, $uid, $cid, $rot, 'manual');
        echo json_encode(['success' => true]);
        break;

    case 'checkpoint_list':
        $st = $pdo->prepare("SELECT id,rotulo,tipo,criado_em FROM apl_checkpoints WHERE user_id=? AND carteira_id=? ORDER BY id DESC");
        $st->execute([$uid, $cid]);
        $rows = $st->fetchAll();
        echo json_encode(['success' => true, 'pontos' => $rows]);
        break;

    case 'checkpoint_restore':
        $id = (int)($input['id'] ?? 0);
        if (!$id) { http_response_code(400); echo json_encode(['error' => 'ID inválido']); exit; }
        $st = $pdo->prepare("SELECT rotulo,dados FROM apl_checkpoints WHERE id=? AND user_id=? AND carteira_id=?");
        $st->execute([$id, $uid, $cid]);
        $cp = $st->fetch();
        if (!$cp) { http_response_code(404); echo json_encode(['error' => 'Ponto não encontrado']); exit; }
        $snap = json_decode($cp['dados'], true);
        if (!is_array($snap)) { http_response_code(500); echo json_encode(['error' => 'Snapshot corrompido']); exit; }
        // rede de segurança: snapshot do estado atual antes de sobrescrever
        try { criarCheckpoint($pdo, $uid, $cid, 'Antes de restaurar (' . $cp['rotulo'] . ')', 'auto'); } catch (Throwable $e) {}
        try {
            $pdo->beginTransaction();
            $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
            foreach (snapTables() as $t) { restaurarTabela($pdo, $uid, $cid, $t, $snap[$t] ?? []); }
            $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            http_response_code(500); echo json_encode(['error' => 'Falha ao restaurar: ' . $e->getMessage()]); exit;
        }
        echo json_encode(['success' => true]);
        break;

    case 'checkpoint_delete':
        $id = (int)($input['id'] ?? 0);
        if (!$id) { http_response_code(400); echo json_encode(['error' => 'ID inválido']); exit; }
        $pdo->prepare("DELETE FROM apl_checkpoints WHERE id=? AND user_id=? AND carteira_id=?")->execute([$id, $uid, $cid]);
        echo json_encode(['success' => true]);
        break;

    // ── CARTEIRAS (multi-carteira, Fase 3) ───────────────────────────────────
    // Endpoints por-usuario (sem CSRF, como o resto do app). A carteira ativa
    // ($cid) vem da sessao; wallet_switch e' quem a troca.

    // listar as carteiras do usuario (+ qual e' a ativa) — usado pelo seletor e pela pagina /carteiras
    case 'wallets_list':
        $st = $pdo->prepare(
            "SELECT w.id,w.nome,w.cor,w.ordem,w.is_default,w.aporte_inicial,w.aporte_inicial_data,
                    (SELECT COUNT(*) FROM apl_carteira c WHERE c.carteira_id=w.id AND c.user_id=w.user_id) AS posicoes
               FROM apl_carteiras w WHERE w.user_id=? ORDER BY w.ordem ASC, w.id ASC"
        );
        $st->execute([$uid]);
        $rows = $st->fetchAll();
        foreach ($rows as &$r) {
            $r['id']             = (int)$r['id'];
            $r['ordem']          = (int)$r['ordem'];
            $r['is_default']     = (int)$r['is_default'];
            $r['posicoes']       = (int)$r['posicoes'];
            $r['aporte_inicial'] = $r['aporte_inicial'] !== null ? (float)$r['aporte_inicial'] : null;
        } unset($r);
        echo json_encode(['success' => true, 'carteiras' => $rows, 'ativa' => $cid]);
        break;

    // criar nova carteira (a 1a do usuario nasce padrao)
    case 'wallet_create':
        $nome = trim((string)($input['nome'] ?? ''));
        if (mb_strlen($nome) < 1 || mb_strlen($nome) > 60) {
            http_response_code(400); echo json_encode(['error' => 'Informe um nome (até 60 caracteres).']); exit;
        }
        $ord = $pdo->prepare("SELECT COALESCE(MAX(ordem),-1)+1 FROM apl_carteiras WHERE user_id=?");
        $ord->execute([$uid]); $ordem = (int)$ord->fetchColumn();
        $cnt = $pdo->prepare("SELECT COUNT(*) FROM apl_carteiras WHERE user_id=?");
        $cnt->execute([$uid]); $isDef = ((int)$cnt->fetchColumn() === 0) ? 1 : 0;
        $pdo->prepare("INSERT INTO apl_carteiras (user_id,nome,ordem,is_default) VALUES (?,?,?,?)")
            ->execute([$uid, $nome, $ordem, $isDef]);
        echo json_encode(['success' => true, 'id' => (int)$pdo->lastInsertId()]);
        break;

    // renomear carteira
    case 'wallet_rename':
        $id   = (int)($input['id'] ?? 0);
        $nome = trim((string)($input['nome'] ?? ''));
        if (!$id || mb_strlen($nome) < 1 || mb_strlen($nome) > 60) {
            http_response_code(400); echo json_encode(['error' => 'Dados inválidos (id + nome até 60 caracteres).']); exit;
        }
        if (!wallet_get($pdo, $uid, $id)) { http_response_code(404); echo json_encode(['error' => 'Carteira não encontrada.']); exit; }
        $pdo->prepare("UPDATE apl_carteiras SET nome=? WHERE id=? AND user_id=?")->execute([$nome, $id, $uid]);
        echo json_encode(['success' => true]);
        break;

    // reordenar (recebe a lista de ids na ordem desejada)
    case 'wallet_reorder':
        $ids = $input['ids'] ?? [];
        if (!is_array($ids) || !count($ids)) { http_response_code(400); echo json_encode(['error' => 'Nenhuma ordem informada.']); exit; }
        $up = $pdo->prepare("UPDATE apl_carteiras SET ordem=? WHERE id=? AND user_id=?");
        $i = 0;
        foreach ($ids as $wid) { $up->execute([$i++, (int)$wid, $uid]); }
        echo json_encode(['success' => true]);
        break;

    // definir a carteira padrao (ativa no 1o acesso)
    case 'wallet_set_default':
        $id = (int)($input['id'] ?? 0);
        if (!$id || !wallet_get($pdo, $uid, $id)) { http_response_code(404); echo json_encode(['error' => 'Carteira não encontrada.']); exit; }
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE apl_carteiras SET is_default=0 WHERE user_id=?")->execute([$uid]);
        $pdo->prepare("UPDATE apl_carteiras SET is_default=1 WHERE id=? AND user_id=?")->execute([$id, $uid]);
        $pdo->commit();
        echo json_encode(['success' => true]);
        break;

    // aporte inicial da carteira (default = carteira ATIVA, p/ a tela de Config)
    case 'wallet_set_aporte':
        $id = (int)($input['id'] ?? $cid);
        if (!$id || !wallet_get($pdo, $uid, $id)) { http_response_code(404); echo json_encode(['error' => 'Carteira não encontrada.']); exit; }
        $apRaw = trim((string)($input['aporte_inicial'] ?? ''));
        $ap    = $apRaw === '' ? null : round((float)str_replace(',', '.', $apRaw), 2);
        $apd   = trim((string)($input['aporte_inicial_data'] ?? ''));
        $apd   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $apd) ? $apd : null;
        $pdo->prepare("UPDATE apl_carteiras SET aporte_inicial=?, aporte_inicial_data=? WHERE id=? AND user_id=?")
            ->execute([$ap, $apd, $id, $uid]);
        echo json_encode(['success' => true]);
        break;

    // trocar a carteira ATIVA (grava na sessao) — estilo "mudar de conta"
    case 'wallet_switch':
        $id = (int)($input['id'] ?? 0);
        if (!$id || !wallet_get($pdo, $uid, $id)) { http_response_code(404); echo json_encode(['error' => 'Carteira não encontrada.']); exit; }
        $_SESSION['carteira_id'] = $id;
        echo json_encode(['success' => true, 'ativa' => $id]);
        break;

    // excluir carteira (hard delete + limpeza dos dados dela em todas as tabelas)
    case 'wallet_delete':
        $id   = (int)($input['id'] ?? 0);
        $alvo = $id ? wallet_get($pdo, $uid, $id) : null;
        if (!$alvo) { http_response_code(404); echo json_encode(['error' => 'Carteira não encontrada.']); exit; }
        $cnt = $pdo->prepare("SELECT COUNT(*) FROM apl_carteiras WHERE user_id=?");
        $cnt->execute([$uid]);
        if ((int)$cnt->fetchColumn() <= 1) {
            http_response_code(409); echo json_encode(['error' => 'Não dá para excluir a sua única carteira. Crie outra antes.']); exit;
        }
        try {
            $pdo->beginTransaction();
            foreach (['apl_carteira', 'apl_notas', 'apl_movimentacoes', 'apl_checkpoints', 'apl_lixeira'] as $t) {
                $pdo->prepare("DELETE FROM `$t` WHERE user_id=? AND carteira_id=?")->execute([$uid, $id]);
            }
            $pdo->prepare("DELETE FROM apl_carteiras WHERE id=? AND user_id=?")->execute([$id, $uid]);
            // se a excluida era a padrao, promove a primeira restante a padrao
            if ((int)$alvo['is_default'] === 1) {
                $f = $pdo->prepare("SELECT id FROM apl_carteiras WHERE user_id=? ORDER BY ordem ASC, id ASC LIMIT 1");
                $f->execute([$uid]); $novaDefault = (int)$f->fetchColumn();
                if ($novaDefault) $pdo->prepare("UPDATE apl_carteiras SET is_default=1 WHERE id=? AND user_id=?")->execute([$novaDefault, $uid]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            http_response_code(500); echo json_encode(['error' => 'Falha ao excluir: ' . $e->getMessage()]); exit;
        }
        // se a excluida era a ATIVA, reaponta a sessao p/ a nova carteira ativa
        if ($id === $cid) {
            $f = $pdo->prepare("SELECT id FROM apl_carteiras WHERE user_id=? AND is_default=1 LIMIT 1");
            $f->execute([$uid]); $nova = (int)$f->fetchColumn();
            $_SESSION['carteira_id'] = $nova ?: null;
        }
        echo json_encode(['success' => true]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => "Acao invalida: $action"]);
}
