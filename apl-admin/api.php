<?php
// ============================================================
//  API do PAINEL ADMINISTRATIVO (/apl-admin/api.php). (Fase 2)
//  Gestao de usuarios (CRUD). Gated pelo _admin.php (so papel admin)
//  + token CSRF nas acoes que alteram estado.
//  Padrao identico ao api.php do app: JSON + handlers de excecao/shutdown.
// ============================================================
define('ADMIN_JSON', true);            // faz o guard responder em JSON
require_once __DIR__ . '/_admin.php';  // sessao + guard + CSRF + $ADMIN
require_once __DIR__ . '/../vencimentos.php';  // calendario de vencimento (store global)

header('Content-Type: application/json; charset=utf-8');

set_exception_handler(function ($e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erro interno.']);
});
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_COMPILE_ERROR, E_CORE_ERROR], true)) {
        http_response_code(500);
        echo json_encode(['error' => 'Erro interno.']);
    }
});

$pdo    = getDB();
$action = $_GET['action'] ?? '';
$input  = json_decode(file_get_contents('php://input'), true) ?: $_POST;

$atorId    = (int)$ADMIN['id'];
$atorPapel = $ADMIN['papel'];

// Tabelas de dados do usuario (limpeza no hard-delete — "ON DELETE CASCADE em PHP").
const TABELAS_USUARIO = [
    'apl_carteira', 'apl_posicoes', 'apl_notas', 'apl_movimentacoes',
    'apl_checkpoints', 'apl_lixeira', 'apl_config_app',
];

// ── Helpers ──────────────────────────────────────────────────────────────────
function exigir_csrf() {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !admin_csrf_ok()) { http_response_code(403); echo json_encode(['error' => 'Token de seguranca invalido. Recarregue a pagina.']); exit; }
}
function carregar_usuario($pdo, $id) {
    $st = $pdo->prepare("SELECT * FROM apl_usuarios WHERE id=?");
    $st->execute([(int)$id]);
    return $st->fetch();
}
function total_super_admins($pdo) {
    return (int)$pdo->query("SELECT COUNT(*) FROM apl_usuarios WHERE papel='super_admin'")->fetchColumn();
}
function valida_dados_basicos($nome, $usuario, $email) {
    $e = [];
    if (mb_strlen(trim($nome)) < 2) $e[] = 'Informe o nome.';
    if (!preg_match('/^[a-z0-9._-]{3,40}$/', $usuario)) $e[] = 'Usuario invalido (3+ caracteres: letras, numeros, . _ -).';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $e[] = 'E-mail invalido.';
    return $e;
}
// Conflito de usuario/email com OUTRA conta. Devolve mensagem ESPECIFICA
// (usuario vs e-mail) ou null se estiver livre.
function conflito_unico($pdo, $usuario, $email, $excetoId = 0) {
    $st = $pdo->prepare("SELECT usuario, email FROM apl_usuarios WHERE (usuario=? OR email=?) AND id<>?");
    $st->execute([$usuario, $email, (int)$excetoId]);
    $usuarioEmUso = false; $emailEmUso = false;
    foreach ($st->fetchAll() as $r) {
        if ($r['usuario'] === $usuario) $usuarioEmUso = true;
        if ($r['email'] === $email)     $emailEmUso = true;
    }
    if ($usuarioEmUso) return 'Este nome de usuário já existe. Escolha outro.';
    if ($emailEmUso)   return 'Este e-mail já está em uso por outra conta.';
    return null;
}

switch ($action) {

    // ── LISTAR usuarios ──────────────────────────────────────────────────────
    case 'users_list':
        $rows = $pdo->query("SELECT id,nome,sobrenome,usuario,email,papel,avatar,ativo,criado_em FROM apl_usuarios ORDER BY id ASC")->fetchAll();
        foreach ($rows as &$r) {
            $r['id']         = (int)$r['id'];
            $r['ativo']      = (int)$r['ativo'];
            $r['papel_rotulo'] = papel_rotulo($r['papel']);
            $r['avatar']     = avatar_url($r['avatar']);   // URL da foto enviada ou null = usar iniciais
            $r['is_self']    = ($r['id'] === $atorId);
            $r['pode_gerir'] = pode_gerir($atorPapel, $r['papel']);   // o ator pode editar/excluir este?
        }
        unset($r);
        echo json_encode(['success' => true, 'usuarios' => $rows, 'ator' => ['id' => $atorId, 'papel' => $atorPapel]]);
        break;

    // ── UM usuario ───────────────────────────────────────────────────────────
    case 'user_get':
        $u = carregar_usuario($pdo, $input['id'] ?? $_GET['id'] ?? 0);
        if (!$u) { http_response_code(404); echo json_encode(['error' => 'Usuario nao encontrado']); exit; }
        unset($u['senha_hash']);
        $u['id'] = (int)$u['id']; $u['ativo'] = (int)$u['ativo'];
        $u['avatar'] = avatar_url($u['avatar']);
        echo json_encode(['success' => true, 'usuario' => $u]);
        break;

    // ── CRIAR usuario ────────────────────────────────────────────────────────
    case 'user_create':
        exigir_csrf();
        $nome      = trim($input['nome'] ?? '');
        $sobrenome = trim($input['sobrenome'] ?? '');
        $usuario   = strtolower(trim($input['usuario'] ?? ''));
        $email     = strtolower(trim($input['email'] ?? ''));
        $senha     = (string)($input['senha'] ?? '');
        $papel     = $input['papel'] ?? '';
        $ativo     = (int)!empty($input['ativo']);
        // bio NAO entra na criacao administrativa — e' campo do proprio perfil (perfil.php).

        $erros = valida_dados_basicos($nome, $usuario, $email);
        if (strlen($senha) < 12)            $erros[] = 'A senha precisa de ao menos 12 caracteres.';
        if ($papel === '')                 $erros[] = 'Selecione um papel.';
        elseif (!papel_valido($papel))     $erros[] = 'Papel invalido.';
        elseif (!pode_atribuir($atorPapel, $papel)) $erros[] = 'Voce nao pode atribuir o papel "' . papel_rotulo($papel) . '".';
        if ($erros) { http_response_code(400); echo json_encode(['error' => implode(' ', $erros)]); exit; }
        if ($c = conflito_unico($pdo, $usuario, $email)) { http_response_code(409); echo json_encode(['error' => $c]); exit; }

        $st = $pdo->prepare("INSERT INTO apl_usuarios (nome,sobrenome,usuario,email,senha_hash,papel,ativo) VALUES (?,?,?,?,?,?,?)");
        $st->execute([$nome, ($sobrenome ?: null), $usuario, $email, password_hash($senha, PASSWORD_DEFAULT), $papel, $ativo]);
        echo json_encode(['success' => true, 'id' => (int)$pdo->lastInsertId()]);
        break;

    // ── EDITAR usuario ───────────────────────────────────────────────────────
    case 'user_update':
        exigir_csrf();
        $id = (int)($input['id'] ?? 0);
        $alvo = carregar_usuario($pdo, $id);
        if (!$alvo) { http_response_code(404); echo json_encode(['error' => 'Usuario nao encontrado']); exit; }
        if (!pode_gerir($atorPapel, $alvo['papel'])) { http_response_code(403); echo json_encode(['error' => 'Voce nao pode editar este usuario.']); exit; }

        $nome      = trim($input['nome'] ?? '');
        $sobrenome = trim($input['sobrenome'] ?? '');
        $usuario   = strtolower(trim($input['usuario'] ?? ''));
        $email     = strtolower(trim($input['email'] ?? ''));
        $papel     = $input['papel'] ?? $alvo['papel'];
        $ativo     = array_key_exists('ativo', $input) ? (int)!empty($input['ativo']) : (int)$alvo['ativo'];
        // bio NAO e' tocada pelo admin — fica a cargo do proprio usuario (perfil.php).

        $erros = valida_dados_basicos($nome, $usuario, $email);
        if (!papel_valido($papel)) $erros[] = 'Papel invalido.';
        if ($erros) { http_response_code(400); echo json_encode(['error' => implode(' ', $erros)]); exit; }
        if ($c = conflito_unico($pdo, $usuario, $email, $id)) { http_response_code(409); echo json_encode(['error' => $c]); exit; }

        // Mudanca de papel: precisa poder atribuir o NOVO papel.
        $mudouPapel = ($papel !== $alvo['papel']);
        if ($mudouPapel && !pode_atribuir($atorPapel, $papel)) {
            http_response_code(403); echo json_encode(['error' => 'Voce nao pode atribuir o papel "' . papel_rotulo($papel) . '".']); exit;
        }
        // Nunca deixar o sistema sem super_admin (rebaixar o ultimo).
        if ($alvo['papel'] === 'super_admin' && $papel !== 'super_admin' && total_super_admins($pdo) <= 1) {
            http_response_code(409); echo json_encode(['error' => 'E o unico super administrador — promova outro antes de rebaixar este.']); exit;
        }
        // Auto-protecao: nao se rebaixe abaixo de admin nem se desative.
        if ($id === $atorId) {
            if (!pode_admin($papel)) { http_response_code(409); echo json_encode(['error' => 'Voce nao pode remover o proprio acesso ao painel.']); exit; }
            if ($ativo !== 1)        { http_response_code(409); echo json_encode(['error' => 'Voce nao pode desativar a propria conta.']); exit; }
        }
        // Desativar o ultimo super_admin tambem trava o sistema.
        if ($alvo['papel'] === 'super_admin' && $ativo !== 1 && total_super_admins($pdo) <= 1) {
            http_response_code(409); echo json_encode(['error' => 'Nao desative o unico super administrador.']); exit;
        }

        $st = $pdo->prepare("UPDATE apl_usuarios SET nome=?,sobrenome=?,usuario=?,email=?,papel=?,ativo=? WHERE id=?");
        $st->execute([$nome, ($sobrenome ?: null), $usuario, $email, $papel, $ativo, $id]);

        // Se editei a mim mesmo, atualiza a sessao (nome/usuario/email/papel).
        if ($id === $atorId) {
            $_SESSION['user_nome'] = $nome; $_SESSION['user_usuario'] = $usuario;
            $_SESSION['user_email'] = $email; $_SESSION['user_papel'] = $papel;
        }
        echo json_encode(['success' => true]);
        break;

    // ── REDEFINIR SENHA (administrativo; sem e-mail — isso e' Fase 6) ─────────
    case 'user_reset_password':
        exigir_csrf();
        $id   = (int)($input['id'] ?? 0);
        $nova = (string)($input['nova'] ?? '');
        $alvo = carregar_usuario($pdo, $id);
        if (!$alvo) { http_response_code(404); echo json_encode(['error' => 'Usuario nao encontrado']); exit; }
        if (!pode_gerir($atorPapel, $alvo['papel'])) { http_response_code(403); echo json_encode(['error' => 'Voce nao pode redefinir a senha deste usuario.']); exit; }
        if (strlen($nova) < 12) { http_response_code(400); echo json_encode(['error' => 'A nova senha precisa de ao menos 12 caracteres.']); exit; }
        $pdo->prepare("UPDATE apl_usuarios SET senha_hash=? WHERE id=?")->execute([password_hash($nova, PASSWORD_DEFAULT), $id]);
        echo json_encode(['success' => true]);
        break;

    // ── ATIVAR / DESATIVAR login ─────────────────────────────────────────────
    case 'user_toggle_active':
        exigir_csrf();
        $id    = (int)($input['id'] ?? 0);
        $ativo = (int)!empty($input['ativo']);
        $alvo = carregar_usuario($pdo, $id);
        if (!$alvo) { http_response_code(404); echo json_encode(['error' => 'Usuario nao encontrado']); exit; }
        if (!pode_gerir($atorPapel, $alvo['papel'])) { http_response_code(403); echo json_encode(['error' => 'Voce nao pode alterar este usuario.']); exit; }
        if ($id === $atorId && $ativo !== 1) { http_response_code(409); echo json_encode(['error' => 'Voce nao pode desativar a propria conta.']); exit; }
        if ($alvo['papel'] === 'super_admin' && $ativo !== 1 && total_super_admins($pdo) <= 1) {
            http_response_code(409); echo json_encode(['error' => 'Nao desative o unico super administrador.']); exit;
        }
        $pdo->prepare("UPDATE apl_usuarios SET ativo=? WHERE id=?")->execute([$ativo, $id]);
        echo json_encode(['success' => true]);
        break;

    // ── EXCLUIR usuario (HARD DELETE + limpeza de TODAS as tabelas dele) ──────
    case 'user_delete':
        exigir_csrf();
        $id = (int)($input['id'] ?? 0);
        $alvo = carregar_usuario($pdo, $id);
        if (!$alvo) { http_response_code(404); echo json_encode(['error' => 'Usuario nao encontrado']); exit; }
        if ($id === $atorId) { http_response_code(409); echo json_encode(['error' => 'Voce nao pode excluir a propria conta.']); exit; }
        if (!pode_gerir($atorPapel, $alvo['papel'])) { http_response_code(403); echo json_encode(['error' => 'Voce nao pode excluir este usuario.']); exit; }
        if ($alvo['papel'] === 'super_admin' && total_super_admins($pdo) <= 1) {
            http_response_code(409); echo json_encode(['error' => 'Nao exclua o unico super administrador.']); exit;
        }

        $pdo->beginTransaction();
        try {
            foreach (TABELAS_USUARIO as $t) {
                $pdo->prepare("DELETE FROM `$t` WHERE user_id=?")->execute([$id]);
            }
            $pdo->prepare("DELETE FROM apl_usuarios WHERE id=?")->execute([$id]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        avatar_apagar_arquivo($alvo['avatar'] ?? null);   // remove a foto do disco
        echo json_encode(['success' => true]);
        break;

    // ── AVATAR: enviar foto de um usuario (admin; multipart $_FILES['avatar']) ─
    case 'user_avatar_upload':
        exigir_csrf();
        $id   = (int)($input['id'] ?? $_POST['id'] ?? 0);
        $alvo = carregar_usuario($pdo, $id);
        if (!$alvo) { http_response_code(404); echo json_encode(['error' => 'Usuario nao encontrado']); exit; }
        if (!pode_gerir($atorPapel, $alvo['papel'])) { http_response_code(403); echo json_encode(['error' => 'Voce nao pode alterar este usuario.']); exit; }
        $res = avatar_processar_upload($_FILES['avatar'] ?? null, $id);
        if (!$res['ok']) { http_response_code(400); echo json_encode(['error' => $res['error']]); exit; }
        $pdo->prepare("UPDATE apl_usuarios SET avatar=? WHERE id=?")->execute([$res['path'], $id]);
        if (!empty($alvo['avatar']) && $alvo['avatar'] !== $res['path']) avatar_apagar_arquivo($alvo['avatar']);
        if ($id === $atorId) $_SESSION['user_avatar'] = $res['path'];   // editei a mim mesmo
        echo json_encode(['success' => true, 'avatar' => avatar_url($res['path'])]);
        break;

    // ── AVATAR: remover a foto de um usuario (volta as iniciais) ──────────────
    case 'user_avatar_remove':
        exigir_csrf();
        $id   = (int)($input['id'] ?? 0);
        $alvo = carregar_usuario($pdo, $id);
        if (!$alvo) { http_response_code(404); echo json_encode(['error' => 'Usuario nao encontrado']); exit; }
        if (!pode_gerir($atorPapel, $alvo['papel'])) { http_response_code(403); echo json_encode(['error' => 'Voce nao pode alterar este usuario.']); exit; }
        $pdo->prepare("UPDATE apl_usuarios SET avatar=NULL WHERE id=?")->execute([$id]);
        avatar_apagar_arquivo($alvo['avatar'] ?? null);
        if ($id === $atorId) $_SESSION['user_avatar'] = null;
        echo json_encode(['success' => true]);
        break;

    // ── CONFIGURACOES GERAIS — ler o store global ────────────────────────────
    case 'config_geral_get':
        echo json_encode(['success' => true, 'config' => config_geral_all()]);
        break;

    // ── CONFIGURACOES GERAIS — gravar o store global ─────────────────────────
    case 'config_geral_save':
        exigir_csrf();
        $titulo = trim((string)($input['site_titulo']    ?? ''));
        $desc   = trim((string)($input['site_descricao'] ?? ''));
        $icone  = trim((string)($input['site_icone']     ?? ''));
        $tz     = trim((string)($input['fuso_horario']   ?? ''));
        $fdata  = (string)($input['formato_data'] ?? '');
        $fhora  = (string)($input['formato_hora'] ?? '');

        $erros = [];
        if ($titulo === '')             $erros[] = 'Informe o título da plataforma.';
        if (mb_strlen($titulo) > 120)   $erros[] = 'Título muito longo (máx. 120 caracteres).';
        if (mb_strlen($desc) > 300)     $erros[] = 'Descrição muito longa (máx. 300 caracteres).';
        if ($icone !== '' && !preg_match('#^https?://#i', $icone) && $icone[0] !== '/')
            $erros[] = 'Ícone deve ser uma URL (http/https) ou um caminho começando com "/".';
        if (!in_array($tz, timezone_identifiers_list(), true)) $erros[] = 'Fuso horário inválido.';
        if (!in_array($fdata, FORMATOS_DATA, true))            $erros[] = 'Formato de data inválido.';
        if (!in_array($fhora, FORMATOS_HORA, true))            $erros[] = 'Formato de hora inválido.';
        if ($erros) { http_response_code(400); echo json_encode(['error' => implode(' ', $erros)]); exit; }

        $vals = [
            'site_titulo'    => $titulo,
            'site_descricao' => $desc,
            'site_icone'     => $icone,
            'fuso_horario'   => $tz,
            'formato_data'   => $fdata,
            'formato_hora'   => $fhora,
        ];
        $st = $pdo->prepare("INSERT INTO apl_config_geral (chave, valor) VALUES (?, ?)
                             ON DUPLICATE KEY UPDATE valor = VALUES(valor)");
        foreach ($vals as $k => $v) { $st->execute([$k, $v]); }
        echo json_encode(['success' => true]);
        break;

    // ── CALENDARIO DE VENCIMENTO — ler um ano (padrao + ajustes) ─────────────
    case 'vencimentos_get':
        $ano = (int)($input['ano'] ?? $_GET['ano'] ?? date('Y'));
        echo json_encode(['success' => true, 'ano' => $ano, 'linhas' => vencimentos_ano($ano)]);
        break;

    // ── CALENDARIO DE VENCIMENTO — gravar os ajustes de um ano ───────────────
    //  Recebe {ano, meses:{"1":"YYYY-MM-DD", ...}}. Para cada mes: se a data for
    //  diferente do padrao (3a sexta) grava o AJUSTE; se for igual ao padrao,
    //  remove o ajuste (volta ao automatico). Mantem a tabela enxuta.
    case 'vencimentos_save':
        exigir_csrf();
        $ano   = (int)($input['ano'] ?? 0);
        $meses = $input['meses'] ?? null;
        if ($ano < 2000 || $ano > 2100)   { http_response_code(400); echo json_encode(['error' => 'Ano inválido.']); exit; }
        if (!is_array($meses))             { http_response_code(400); echo json_encode(['error' => 'Dados do calendário ausentes.']); exit; }

        // Valida tudo antes de gravar (data real, dentro do ano/mes informado).
        $aGravar = [];   // mes => data (ajuste)  | $aLimpar = meses que voltam ao padrao
        $aLimpar = [];
        foreach (MESES_OPCOES as $mes => $_info) {
            if (!array_key_exists((string)$mes, $meses) && !array_key_exists($mes, $meses)) continue;
            $data   = trim((string)($meses[(string)$mes] ?? $meses[$mes] ?? ''));
            $padrao = vencimento_padrao($ano, $mes);
            if ($data === '' || $data === $padrao) { $aLimpar[] = $mes; continue; }
            $d = DateTime::createFromFormat('Y-m-d', $data);
            $valida = $d && $d->format('Y-m-d') === $data;
            if (!$valida || (int)$d->format('Y') !== $ano || (int)$d->format('n') !== $mes) {
                http_response_code(400);
                echo json_encode(['error' => 'Data inválida para ' . MESES_OPCOES[$mes][0] . " ($data) — use uma data real do mês de " . MESES_OPCOES[$mes][0] . "/$ano."]);
                exit;
            }
            $aGravar[$mes] = $data;
        }

        $pdo->beginTransaction();
        try {
            $up  = $pdo->prepare("INSERT INTO apl_vencimentos (ano, mes, data_venc) VALUES (?,?,?)
                                  ON DUPLICATE KEY UPDATE data_venc = VALUES(data_venc)");
            $del = $pdo->prepare("DELETE FROM apl_vencimentos WHERE ano=? AND mes=?");
            foreach ($aGravar as $mes => $data) { $up->execute([$ano, $mes, $data]); }
            foreach ($aLimpar as $mes)          { $del->execute([$ano, $mes]); }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        echo json_encode(['success' => true, 'ajustados' => count($aGravar)]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => "Acao invalida: $action"]);
}
