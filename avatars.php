<?php
// ============================================================
//  Avatar personalizado (Fase 2). Upload + processamento + rendering.
//  Arquivo de funcoes, SEM efeitos colaterais — pode ser incluido por
//  api.php (app), apl-admin/api.php (painel), partials/head.php e
//  apl-admin/_admin.php sem conflito. Bloqueado no .htaccess.
//
//  Regra de exibicao (decidida com o usuario): avatar enviado SE houver,
//  senao iniciais coloridas (Gravatar foi removido do projeto). Por isso o
//  rendering aqui so resolve a URL do arquivo enviado; a fallback de iniciais
//  e' feita por quem renderiza (nav/perfil/admin).
//
//  Armazenamento: arquivos em /uploads/avatars/ (web-acessivel, com
//  .htaccess que bloqueia execucao de codigo). No banco guarda-se o caminho
//  RELATIVO a raiz do projeto, ex.: "uploads/avatars/u5_a1b2c3d4.jpg".
// ============================================================

const AVATAR_WEB_DIR = 'uploads/avatars';     // relativo a raiz do projeto (e do dominio)
const AVATAR_MAX_BYTES = 3 * 1024 * 1024;     // 3 MB (o upload_max_filesize do servidor manda)
const AVATAR_LADO = 320;                       // miniatura quadrada final (px)
const AVATAR_MAX_PX = 8000;                    // sanidade: rejeita imagens absurdas (bomba de memoria)

// Tipos aceitos: mime detectado => extensao de saida.
const AVATAR_TIPOS = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
];

// Diretorio FISICO dos avatares (cria se faltar). Raiz = pasta deste arquivo.
function avatar_dir_fs() {
    return __DIR__ . '/' . AVATAR_WEB_DIR;
}

// URL publica do avatar a partir do caminho guardado no banco. Acrescenta
// cache-bust (?v=mtime) p/ refletir trocas. Devolve null se nao houver arquivo.
function avatar_url($stored, $bust = true) {
    $stored = trim((string)$stored);
    if ($stored === '') return null;
    // So aceita caminhos dentro da pasta de avatares (defesa contra valor torto no banco).
    if (strpos($stored, AVATAR_WEB_DIR . '/') !== 0) return null;
    $fs = __DIR__ . '/' . $stored;
    if (!is_file($fs)) return null;
    $url = '/' . $stored;
    if ($bust) $url .= '?v=' . filemtime($fs);
    return $url;
}

// Apaga o arquivo de avatar (se existir e estiver dentro da pasta de avatares).
// Seguro chamar com null/'' (no-op).
function avatar_apagar_arquivo($stored) {
    $stored = trim((string)$stored);
    if ($stored === '' || strpos($stored, AVATAR_WEB_DIR . '/') !== 0) return;
    $fs = __DIR__ . '/' . $stored;
    if (is_file($fs)) @unlink($fs);
}

// Valida + processa o arquivo enviado e grava uma miniatura quadrada.
//   $file    = entrada de $_FILES['avatar']
//   $userId  = id do dono (compoe o nome do arquivo)
// Retorna ['ok'=>true, 'path'=>'uploads/avatars/...'] ou ['ok'=>false, 'error'=>msg].
function avatar_processar_upload($file, $userId) {
    if (!is_array($file) || !isset($file['error'])) {
        return ['ok' => false, 'error' => 'Nenhum arquivo recebido.'];
    }
    switch ($file['error']) {
        case UPLOAD_ERR_OK: break;
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return ['ok' => false, 'error' => 'Imagem grande demais (máx. 3 MB).'];
        case UPLOAD_ERR_NO_FILE:
            return ['ok' => false, 'error' => 'Selecione uma imagem.'];
        default:
            return ['ok' => false, 'error' => 'Falha no envio da imagem. Tente novamente.'];
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        return ['ok' => false, 'error' => 'Envio inválido.'];
    }
    if ($file['size'] > AVATAR_MAX_BYTES) {
        return ['ok' => false, 'error' => 'Imagem grande demais (máx. 3 MB).'];
    }

    // Tipo real pelo conteudo (nunca pela extensao/nome enviado).
    $info = @getimagesize($file['tmp_name']);
    if ($info === false || empty($info['mime'])) {
        return ['ok' => false, 'error' => 'O arquivo não é uma imagem válida.'];
    }
    $mime = $info['mime'];
    if (!isset(AVATAR_TIPOS[$mime])) {
        return ['ok' => false, 'error' => 'Formato não suportado. Use JPG, PNG ou WebP.'];
    }
    [$w, $h] = $info;
    if ($w < 1 || $h < 1 || $w > AVATAR_MAX_PX || $h > AVATAR_MAX_PX) {
        return ['ok' => false, 'error' => 'Dimensões da imagem fora do permitido.'];
    }
    if (!function_exists('imagecreatetruecolor')) {
        return ['ok' => false, 'error' => 'Servidor sem suporte a imagens (GD).'];
    }

    // Carrega a origem conforme o tipo.
    switch ($mime) {
        case 'image/jpeg': $src = @imagecreatefromjpeg($file['tmp_name']); break;
        case 'image/png':  $src = @imagecreatefrompng($file['tmp_name']);  break;
        case 'image/webp': $src = @imagecreatefromwebp($file['tmp_name']); break;
        default:           $src = false;
    }
    if (!$src) return ['ok' => false, 'error' => 'Não foi possível ler a imagem.'];

    // Recorte quadrado central + redimensiona para AVATAR_LADO.
    $lado = min($w, $h);
    $sx = (int)(($w - $lado) / 2);
    $sy = (int)(($h - $lado) / 2);
    $dst = imagecreatetruecolor(AVATAR_LADO, AVATAR_LADO);
    // Preserva transparencia (png/webp).
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    $transp = imagecolorallocatealpha($dst, 0, 0, 0, 127);
    imagefilledrectangle($dst, 0, 0, AVATAR_LADO, AVATAR_LADO, $transp);
    imagecopyresampled($dst, $src, 0, 0, $sx, $sy, AVATAR_LADO, AVATAR_LADO, $lado, $lado);
    // (sem imagedestroy: no PHP 8+ os recursos GD sao objetos coletados pelo GC;
    //  a funcao virou no-op e foi deprecada no 8.5 — chamar so polui a saida.)

    // Garante a pasta (em dev pode nao existir; em producao deve existir e ser gravavel).
    $dir = avatar_dir_fs();
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    if (!is_dir($dir) || !is_writable($dir)) {
        return ['ok' => false, 'error' => 'Pasta de avatares indisponível para gravação.'];
    }

    $ext  = AVATAR_TIPOS[$mime];
    $nome = 'u' . (int)$userId . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $fs   = $dir . '/' . $nome;

    switch ($ext) {
        case 'jpg':  $ok = imagejpeg($dst, $fs, 85); break;
        case 'png':  $ok = imagepng($dst, $fs, 6);   break;
        case 'webp': $ok = imagewebp($dst, $fs, 85); break;
        default:     $ok = false;
    }
    if (!$ok) return ['ok' => false, 'error' => 'Não foi possível salvar a imagem.'];

    return ['ok' => true, 'path' => AVATAR_WEB_DIR . '/' . $nome];
}
