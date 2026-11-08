<?php
// ============================================================
//  Bootstrap do painel administrativo (/apl-admin/). (Fase 2)
//  TODO arquivo do painel (paginas e api) comeca com:
//      require __DIR__ . '/_admin.php';
//  Ele inicia a sessao, carrega config + papeis e RODA O GUARD
//  (so papel admin entra; senao redireciona/403). Tambem prepara o CSRF.
//
//  Modo JSON: a API define `const ADMIN_JSON = true;` ANTES de incluir
//  este arquivo — assim o guard responde 401/403 em JSON em vez de HTML.
// ============================================================
require_once __DIR__ . '/../sessao.php';   // bootstrap único da sessão (não expira sozinha — 08/08/2026)
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../papeis.php';
require_once __DIR__ . '/../config_geral.php';   // store global (Configuracoes Gerais)
require_once __DIR__ . '/../avatars.php';        // upload/processamento/rendering de avatar

$ADMIN_JSON = defined('ADMIN_JSON') && ADMIN_JSON;

// ── Resolve o usuario logado e seu papel ─────────────────────────────────────
if (!empty($_SESSION['user_id'])) nitro_revalidate_session(getDB());
$uid   = (int)($_SESSION['user_id'] ?? 0);
$papel = $_SESSION['user_papel'] ?? null;

// Sessao antiga (anterior a Fase 2) pode nao ter o papel: busca no banco.
if ($uid && $papel === null) {
    try {
        $st = getDB()->prepare("SELECT papel FROM apl_usuarios WHERE id=?");
        $st->execute([$uid]);
        $r = $st->fetch();
        if ($r) { $papel = $_SESSION['user_papel'] = $r['papel']; }
    } catch (Throwable $e) { /* cai no guard abaixo */ }
}

// Pagina 403 simples (sem permissao). On-brand, sem dependencias externas.
function _admin_403_html() {
    $app = '/';
    echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8">'
       . '<meta name="viewport" content="width=device-width,initial-scale=1"><title>Acesso negado</title>'
       . '<style>body{margin:0;font-family:system-ui,Segoe UI,Roboto,Inter,sans-serif;background:#edf0f8;'
       . 'color:#1b2b5e;display:flex;min-height:100vh;align-items:center;justify-content:center}'
       . '.box{background:#fff;border:1px solid #d8def0;border-radius:14px;padding:34px 38px;max-width:420px;'
       . 'box-shadow:0 8px 30px rgba(27,43,94,.08);text-align:center}h1{margin:0 0 6px;font-size:20px}'
       . 'p{color:#566;line-height:1.5;font-size:14px}a{color:#5b21b6;text-decoration:none;font-weight:600}'
       . '.row{margin-top:18px;display:flex;gap:14px;justify-content:center}</style></head><body><div class="box">'
       . '<h1>&#128683; Acesso negado</h1>'
       . '<p>Sua conta nao tem permissao para o painel administrativo.</p>'
       . '<div class="row"><a href="' . $app . '">&#8592; Voltar ao app</a>'
       . '<a href="/apl-login">Trocar de conta</a></div></div></body></html>';
}

// ── GUARD ────────────────────────────────────────────────────────────────────
if (!$uid) {                                   // nao logado
    if ($ADMIN_JSON) { http_response_code(401); header('Content-Type: application/json; charset=utf-8'); echo json_encode(['error' => 'Nao autorizado']); }
    else { header('Location: /apl-login'); }
    exit;
}
if (!pode_admin($papel)) {                      // logado, sem permissao
    http_response_code(403);
    if ($ADMIN_JSON) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['error' => 'Sem permissao para o painel']); }
    else { _admin_403_html(); }
    exit;
}

// ── CSRF (token por sessao; exigido nas acoes que alteram estado) ────────────
if (empty($_SESSION['admin_csrf'])) {
    $_SESSION['admin_csrf'] = bin2hex(random_bytes(16));
}
function admin_csrf() { return $_SESSION['admin_csrf']; }
function admin_csrf_ok() {
    $sent = $_SERVER['HTTP_X_CSRF'] ?? ($_POST['_csrf'] ?? '');
    return is_string($sent) && $sent !== '' && hash_equals($_SESSION['admin_csrf'], $sent);
}

// Usuario atual, pronto para as paginas/handlers.
$ADMIN = [
    'id'      => $uid,
    'nome'    => $_SESSION['user_nome'] ?? '',
    'email'   => $_SESSION['user_email'] ?? '',
    'usuario' => $_SESSION['user_usuario'] ?? '',
    'papel'   => $papel,
    'avatar'  => $_SESSION['user_avatar'] ?? null,
];
