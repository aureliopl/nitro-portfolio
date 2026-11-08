<?php
// Controles compartilhados da versão de portfólio.
function nitro_csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function nitro_request_status(string $method, $sent, string $expected): int {
    if ($method !== 'POST') return 405;
    return is_string($sent) && $expected !== '' && hash_equals($expected, $sent) ? 0 : 403;
}
function nitro_require_csrf(): void {
    $status = nitro_request_status($_SERVER['REQUEST_METHOD'] ?? 'GET', $_SERVER['HTTP_X_CSRF'] ?? ($_POST['_csrf'] ?? ''), nitro_csrf_token());
    if ($status) {
        http_response_code($status);
        if ($status === 405) header('Allow: POST');
        exit(json_encode(['error' => 'Requisicao de seguranca invalida. Recarregue a pagina.']));
    }
}
function nitro_session_expired(array $session, int $now): bool {
    return !isset($session['authenticated_at'], $session['last_activity'])
        || $now - (int)$session['authenticated_at'] >= 28800
        || $now - (int)$session['last_activity'] >= 1800;
}
function nitro_user_is_current($user, array $session): bool {
    return is_array($user) && (int)($user['ativo'] ?? 0) === 1
        && isset($session['auth_version'])
        && hash_equals($session['auth_version'], hash('sha256', $user['senha_hash']));
}
function nitro_revalidate_session($pdo): bool {
    if (empty($_SESSION['user_id'])) return false;
    $stmt = $pdo->prepare('SELECT id,nome,usuario,email,papel,ativo,senha_hash,avatar FROM apl_usuarios WHERE id=?');
    $stmt->execute([(int)$_SESSION['user_id']]);
    $user = $stmt->fetch();
    if (!nitro_user_is_current($user, $_SESSION)) {
        $_SESSION = [];
        session_regenerate_id(true);
        return false;
    }
    $_SESSION['user_papel'] = $user['papel'];
    return true;
}
function nitro_allow_login(string $directory, string $remote, ?int $now = null): bool {
    $now ??= time();
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) return false;
    $handle = fopen($directory . '/' . hash('sha256', $remote) . '.json', 'c+');
    if (!$handle || !flock($handle, LOCK_EX)) return false;
    $attempts = json_decode(stream_get_contents($handle), true);
    $attempts = array_values(array_filter(is_array($attempts) ? $attempts : [], fn($t) => is_int($t) && $t > $now - 900));
    $allowed = count($attempts) < 10;
    if ($allowed) $attempts[] = $now;
    ftruncate($handle, 0); rewind($handle); fwrite($handle, json_encode($attempts));
    flock($handle, LOCK_UN); fclose($handle);
    return $allowed;
}
