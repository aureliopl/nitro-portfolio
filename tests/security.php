<?php
require __DIR__ . '/../security.php';
function check($ok, $message) { if (!$ok) throw new RuntimeException($message); }
check(nitro_request_status('GET', 'valid', 'valid') === 405, 'GET de mutacao');
check(nitro_request_status('POST', '', 'valid') === 403, 'Token ausente');
check(nitro_request_status('POST', 'wrong', 'valid') === 403, 'Token incorreto');
check(nitro_request_status('POST', ['valid'], 'valid') === 403, 'Token nao textual');
check(nitro_request_status('POST', 'valid', 'valid') === 0, 'POST autenticado');
$s = ['authenticated_at'=>1000, 'last_activity'=>1000];
check(!nitro_session_expired($s, 1100), 'Sessao nova');
check(nitro_session_expired($s, 2800), 'Expiracao por inatividade');
check(nitro_session_expired(['authenticated_at'=>1000,'last_activity'=>30000],30000), 'Expiracao absoluta');
$u = ['ativo'=>1,'senha_hash'=>'hash-a']; $s = ['auth_version'=>hash('sha256', 'hash-a')];
check(nitro_user_is_current($u,$s), 'Usuario ativo');
check(!nitro_user_is_current(['ativo'=>0,'senha_hash'=>'hash-a'],$s), 'Usuario desativado');
check(!nitro_user_is_current(['ativo'=>1,'senha_hash'=>'hash-b'],$s), 'Senha revogada');
check(!nitro_user_is_current(false,$s), 'Usuario removido');
$dir=sys_get_temp_dir().'/nitro-limit-'.bin2hex(random_bytes(8));
try {
 for($i=0;$i<10;$i++) check(nitro_allow_login($dir,'test-client',1000), 'Tentativa permitida');
 check(!nitro_allow_login($dir,'test-client',1000),'Limite por cliente');
 check(nitro_allow_login($dir,'other-client',1000),'Clientes independentes');
 check(nitro_allow_login($dir,'test-client',1901),'Janela expirada');
} finally { foreach(glob($dir.'/*') as $file) unlink($file); rmdir($dir); }
echo "Nitro: testes de CSRF, revogacao, expiracao e limite passaram.\n";
