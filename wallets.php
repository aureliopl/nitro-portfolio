<?php
// ============================================================
//  Biblioteca de CARTEIRAS (multi-carteira, Fase 3) — apl_carteiras.
//  So leitura/resolucao; SEM efeitos colaterais (nao escreve na sessao
//  nem no banco) — pode ser incluido por api.php, partials/head.php e
//  pela pagina carteiras.php sem conflito. Bloqueado no .htaccess.
//
//  Nomes em ingles (codigo), comentarios em PT-BR (convencao do projeto).
// ============================================================

// Lista as carteiras do usuario, ordenadas (ordem, depois id).
// Cache estatico por usuario — head.php e wallet_active_id() consultam no
// mesmo request sem repetir o SELECT.
function wallets_list($pdo, $uid, $reset = false) {
    static $cache = [];
    $uid = (int)$uid;
    if ($reset) unset($cache[$uid]);              // invalida apos criar/alterar carteiras
    if (array_key_exists($uid, $cache)) return $cache[$uid];
    $st = $pdo->prepare("SELECT * FROM apl_carteiras WHERE user_id=? ORDER BY ordem ASC, id ASC");
    $st->execute([$uid]);
    return $cache[$uid] = $st->fetchAll();
}

// Garante que o usuario tenha ao menos UMA carteira (a "Principal" padrao) e
// devolve o id da carteira padrao. Rede de seguranca p/ usuarios criados depois
// da migracao (cadastro novo) ou sem carteira por qualquer motivo. E' a UNICA
// funcao deste arquivo que ESCREVE — sempre por chamada explicita do caller.
function wallet_ensure_default($pdo, $uid) {
    $uid = (int)$uid;
    $st = $pdo->prepare("SELECT id FROM apl_carteiras WHERE user_id=? ORDER BY is_default DESC, ordem ASC, id ASC LIMIT 1");
    $st->execute([$uid]);
    $id = (int)$st->fetchColumn();
    if ($id) return $id;
    $pdo->prepare("INSERT INTO apl_carteiras (user_id,nome,ordem,is_default) VALUES (?, 'Principal', 0, 1)")->execute([$uid]);
    wallets_list($pdo, $uid, true);               // refresca o cache estatico
    return (int)$pdo->lastInsertId();
}

// Uma carteira do usuario pelo id (ou null se nao for dele / nao existir).
function wallet_get($pdo, $uid, $id) {
    foreach (wallets_list($pdo, $uid) as $w) {
        if ((int)$w['id'] === (int)$id) return $w;
    }
    return null;
}

// Resolve a carteira ATIVA do usuario a partir da sessao, validando posse.
// Se a sessao nao tiver carteira valida, cai na is_default (ou na de menor id).
// Devolve o id (int) ou 0 se o usuario nao tiver nenhuma carteira (nao deveria
// ocorrer apos a migracao/criacao do 1o usuario). NAO escreve na sessao —
// quem troca a carteira ativa (wallet_switch) e' que grava $_SESSION.
function wallet_active_id($pdo, $uid) {
    $wallets = wallets_list($pdo, $uid);
    if (!$wallets) return 0;

    $sid = (int)($_SESSION['carteira_id'] ?? 0);
    if ($sid) {
        foreach ($wallets as $w) {
            if ((int)$w['id'] === $sid) return $sid;   // sessao aponta p/ carteira valida
        }
    }
    // fallback: a marcada como padrao, senao a primeira (menor ordem/id)
    foreach ($wallets as $w) {
        if ((int)$w['is_default'] === 1) return (int)$w['id'];
    }
    return (int)$wallets[0]['id'];
}
