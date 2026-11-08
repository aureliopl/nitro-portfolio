<?php
// ============================================================
//  Autenticacao por USUARIO/E-MAIL + senha (tabela apl_usuarios).
//  1o acesso (tabela vazia): tela "Criar acesso" cadastra o usuario.
//  Compartilhado por todas as paginas internas.
// ============================================================
require_once __DIR__ . '/sessao.php';   // bootstrap único da sessão (não expira sozinha — 08/08/2026)
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/papeis.php';   // papeis/capacidades + iniciar_sessao()

// Logout
if (($_POST['auth_acao'] ?? '') === 'logout') { nitro_require_csrf(); $_SESSION = []; session_destroy(); header('Location: /'); exit; }
if (!empty($_SESSION['user_id'])) nitro_revalidate_session(getDB());

// Conta usuarios; -1 = tabela ainda nao existe (migracao apl_ nao rodada)
function _usuarios_count($pdo) {
    try { return (int)$pdo->query("SELECT COUNT(*) FROM apl_usuarios")->fetchColumn(); }
    catch (Throwable $e) { return -1; }
}

// ── Processa POST (criar 1o acesso OU login) ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['auth_acao'])) {
    nitro_require_csrf();
    if (!nitro_allow_login(__DIR__ . '/storage/login-attempts', $_SERVER['REMOTE_ADDR'] ?? 'unknown')) { http_response_code(429); exit('Muitas tentativas. Aguarde 15 minutos.'); }
    $pdo  = getDB();
    $acao = $_POST['auth_acao'];
    $n    = _usuarios_count($pdo);

    if ($acao === 'criar' && $n === 0) {
        $nome    = trim($_POST['nome'] ?? '');
        $usuario = strtolower(trim($_POST['usuario'] ?? ''));
        $email   = strtolower(trim($_POST['email'] ?? ''));
        $senha   = (string)($_POST['senha'] ?? '');
        $erros = [];
        if (mb_strlen($nome) < 2) $erros[] = 'Informe seu nome.';
        if (!preg_match('/^[a-z0-9._-]{3,40}$/', $usuario)) $erros[] = 'Usuario invalido (3+ letras/numeros).';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $erros[] = 'E-mail invalido.';
        if (strlen($senha) < 12) $erros[] = 'A senha precisa de ao menos 12 caracteres.';
        if (!$erros) {
            // 1o usuario do sistema = fundador → nasce super_admin (bootstrap do admin).
            $hash = password_hash($senha, PASSWORD_DEFAULT);
            $st = $pdo->prepare("INSERT INTO apl_usuarios (nome,usuario,email,senha_hash,papel) VALUES (?,?,?,?,'super_admin')");
            $st->execute([$nome, $usuario, $email, $hash]);
            iniciar_sessao(['id'=>$pdo->lastInsertId(),'nome'=>$nome,'email'=>$email,'usuario'=>$usuario,'papel'=>'super_admin','senha_hash'=>$hash]);
            header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?')); exit;
        }
        $GLOBALS['auth_erro'] = implode(' ', $erros);
    } elseif ($acao === 'login') {
        $login = strtolower(trim($_POST['login'] ?? ''));
        $senha = (string)($_POST['senha'] ?? '');
        $st = $pdo->prepare("SELECT * FROM apl_usuarios WHERE usuario=? OR email=? LIMIT 1");
        $st->execute([$login, $login]);
        $u = $st->fetch();
        if ($u && password_verify($senha, $u['senha_hash'])) {
            if ((int)($u['ativo'] ?? 1) !== 1) {
                $GLOBALS['auth_erro'] = 'Conta desativada. Procure um administrador.';
            } else {
                iniciar_sessao($u);
                header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?')); exit;
            }
        } else {
            $GLOBALS['auth_erro'] = 'Usuario/e-mail ou senha incorretos.';
        }
    }
}

function exigir_login() {
    if (empty($_SESSION['user_id'])) { render_login(); exit; }
}

function render_login() {
    $erro     = $GLOBALS['auth_erro'] ?? '';
    $n        = _usuarios_count(getDB());
    $primeiro = ($n === 0);
    $semTab   = ($n === -1);
    ?><!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $primeiro ? 'Criar acesso' : 'Acesso' ?> &middot; Nitro</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
<link rel="stylesheet" href="/assets/css/login.css?v=<?= @filemtime(__DIR__.'/assets/css/login.css') ?>">
<?php require __DIR__ . '/security-head.php'; ?>
</head>
<body>
  <div class="card">
    <div class="brand"><span class="dot">&#9889;</span> Nitro</div>
    <?php if ($semTab): ?>
      <h1>Configuração pendente</h1>
      <p class="sub">A tabela de usuários ainda não existe.</p>
      <div class="err">Rode a migração <strong>setup_prefixo_apl.sql</strong> no phpMyAdmin (ela cria <code>apl_usuarios</code> e renomeia as tabelas) e recarregue esta página.</div>
    <?php elseif ($primeiro): ?>
      <h1>Criar acesso</h1>
      <p class="sub">Primeiro acesso &mdash; cadastre seu usuário. Depois é só entrar com ele.</p>
      <?php if ($erro) echo '<div class="err">'.htmlspecialchars($erro).'</div>'; ?>
      <form method="POST">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars(nitro_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="auth_acao" value="criar">
        <div class="field"><label>Nome completo</label><input type="text" name="nome" value="<?= htmlspecialchars($_POST['nome'] ?? '') ?>" autofocus></div>
        <div class="field"><label>Usuário (login)</label><input type="text" name="usuario" value="<?= htmlspecialchars($_POST['usuario'] ?? '') ?>" placeholder="ex: aurelio" autocomplete="username"></div>
        <div class="field"><label>E-mail</label><input type="email" name="email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" placeholder="voce@email.com" autocomplete="email"></div>
        <div class="field"><label>Senha (mín. 12)</label><input type="password" name="senha" autocomplete="new-password"></div>
        <button type="submit">Criar e entrar</button>
      </form>
    <?php else: ?>
      <h1>Entrar</h1>
      <p class="sub">Acesse com seu usuário ou e-mail.</p>
      <?php if ($erro) echo '<div class="err">'.htmlspecialchars($erro).'</div>'; ?>
      <form method="POST">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars(nitro_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="auth_acao" value="login">
        <div class="field"><label>Usuário ou e-mail</label><input type="text" name="login" value="<?= htmlspecialchars($_POST['login'] ?? '') ?>" autocomplete="username" autofocus></div>
        <div class="field"><label>Senha</label><input type="password" name="senha" autocomplete="current-password"></div>
        <button type="submit">Entrar</button>
      </form>
    <?php endif; ?>
  </div>
</body>
</html><?php
}
