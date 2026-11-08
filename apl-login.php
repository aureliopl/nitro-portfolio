<?php
// ============================================================
//  Login do PAINEL ADMINISTRATIVO (/apl-login).
//  Mesma sessao/cookie do app, mas com BARREIRA DE PAPEL: so entra
//  quem tem papel admin (super_admin/administrador). Em sucesso
//  redireciona para /apl-admin/. Quem nao e admin e' recusado aqui
//  (mas continua entrando no app normalmente por /).
// ============================================================
require_once __DIR__ . '/sessao.php';   // bootstrap único da sessão (não expira sozinha — 08/08/2026)
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/papeis.php';

// Logout do admin -> volta para esta tela.
if (($_POST['acao'] ?? '') === 'logout') { nitro_require_csrf(); $_SESSION = []; session_destroy(); header('Location: /apl-login'); exit; }
if (!empty($_SESSION['user_id'])) nitro_revalidate_session(getDB());

// Ja logado e com permissao? vai direto pro painel.
if (!empty($_SESSION['user_id']) && pode_admin($_SESSION['user_papel'] ?? '')) {
    header('Location: /apl-admin/'); exit;
}

$erro = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'login') {
    nitro_require_csrf();
    if (!nitro_allow_login(__DIR__ . '/storage/login-attempts', $_SERVER['REMOTE_ADDR'] ?? 'unknown')) { http_response_code(429); exit('Muitas tentativas. Aguarde 15 minutos.'); }
    $login = strtolower(trim($_POST['login'] ?? ''));
    $senha = (string)($_POST['senha'] ?? '');
    $st = getDB()->prepare("SELECT * FROM apl_usuarios WHERE usuario=? OR email=? LIMIT 1");
    $st->execute([$login, $login]);
    $u = $st->fetch();
    if ($u && password_verify($senha, $u['senha_hash'])) {
        if ((int)($u['ativo'] ?? 1) !== 1) {
            $erro = 'Conta desativada. Procure um administrador.';
        } elseif (!pode_admin($u['papel'] ?? '')) {
            $erro = 'Esta conta nao tem acesso ao painel administrativo.';
        } else {
            iniciar_sessao($u);
            header('Location: /apl-admin/'); exit;
        }
    } else {
        $erro = 'Usuario/e-mail ou senha incorretos.';
    }
}
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Painel administrativo &middot; Nitro</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600&display=swap">
<link rel="stylesheet" href="/assets/css/apl-admin.css?v=<?= @filemtime(__DIR__.'/assets/css/apl-admin.css') ?>">
<?php require __DIR__ . '/security-head.php'; ?>
</head>
<body>
  <div class="login-stage">
    <div class="login-card">
      <div class="login-brand"><span class="bolt">&#9889;</span><span class="wm">Nitro</span><span class="tag">Admin</span></div>
      <h1>Mesa de operações</h1>
      <p class="sub">Acesso restrito. Entre com seu usuário ou e-mail.</p>
      <?php if ($erro) echo '<div class="msg err" style="display:block">'.htmlspecialchars($erro).'</div>'; ?>
      <form method="POST">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars(nitro_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="acao" value="login">
        <div class="field"><label>Usuário ou e-mail</label><input type="text" class="mono" name="login" value="<?= htmlspecialchars($_POST['login'] ?? '') ?>" autocomplete="username" autofocus></div>
        <div class="field"><label>Senha</label><input type="password" name="senha" autocomplete="current-password"></div>
        <button type="submit" class="btn btn-primary">Entrar no painel</button>
      </form>
      <a class="login-back" href="/">&larr; voltar ao app</a>
    </div>
  </div>
</body>
</html>
