<?php
// ============================================================
//  <head> COMPARTILHADO do app (partials/head.php). (Fase 2 — wiring config geral)
//
//  Cada pagina define ANTES de dar require:
//      $TITULO_PG  rotulo da pagina (ex.: 'Carteira') — compoe "<rotulo> · <site_titulo>"
//      $CSS_PG     css especifico da pagina — opcional. String ('carteira') ou
//                  array na ordem de carga (['carteira','grafico'], Fase 4.2).
//
//  Consome as Configuracoes Gerais (store global apl_config_geral):
//      site_titulo    -> compoe o <title>
//      site_descricao -> <meta name="description">
//      site_icone     -> <link rel="icon"> (favicon, por URL)
//      fuso_horario   -> date_default_timezone_set (lado servidor desta pagina)
//
//  Emite de <!DOCTYPE html> ate <?php require __DIR__ . '/../security-head.php'; ?>
</head>. A pagina segue com <body> + nav.
//  Editar o <head> do app = editar SO este arquivo (como a nav em partials/nav.php).
// ============================================================
require_once __DIR__ . '/../config_geral.php';
require_once __DIR__ . '/../avatars.php';   // avatar_url() p/ a nav renderizar a foto

date_default_timezone_set(config_fuso());   // fuso da plataforma (lado servidor)

$cgTitulo = config_geral_get('site_titulo', 'Nitro');
$cgDesc   = trim((string) config_geral_get('site_descricao', ''));
$cgIcone  = trim((string) config_geral_get('site_icone', ''));

$rotulo = trim((string) ($TITULO_PG ?? ''));
$titulo = $rotulo !== '' ? ($rotulo . ' · ' . $cgTitulo) : $cgTitulo;

$_h    = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$_css  = __DIR__ . '/../assets/css/';
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $_h($titulo) ?></title>
<?php if ($cgDesc !== ''): ?><meta name="description" content="<?= $_h($cgDesc) ?>">
<?php endif; ?><?php if ($cgIcone !== ''): ?><link rel="icon" href="<?= $_h($cgIcone) ?>">
<?php endif; ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap">
<link rel="stylesheet" href="/assets/css/base.css?v=<?= @filemtime($_css.'base.css') ?>">
<link rel="stylesheet" href="/assets/css/nav.css?v=<?= @filemtime($_css.'nav.css') ?>">
<?php foreach ((array) ($CSS_PG ?? []) as $_c): if ($_c === '') continue; ?><link rel="stylesheet" href="/assets/css/<?= $_h($_c) ?>.css?v=<?= @filemtime($_css.$_c.'.css') ?>">
<?php endforeach; ?>
<?php require __DIR__ . '/../security-head.php'; ?>
</head>
