<?php
require __DIR__ . '/_admin.php';   // sessao + guard + $ADMIN

// Contagens para o painel.
$pdo    = getDB();
$total  = (int)$pdo->query("SELECT COUNT(*) FROM apl_usuarios")->fetchColumn();
$ativos = (int)$pdo->query("SELECT COUNT(*) FROM apl_usuarios WHERE ativo=1")->fetchColumn();
$porPapel = [];
foreach ($pdo->query("SELECT papel, COUNT(*) c FROM apl_usuarios GROUP BY papel")->fetchAll() as $r) {
    $porPapel[$r['papel']] = (int)$r['c'];
}
$admins = ($porPapel['super_admin'] ?? 0) + ($porPapel['administrador'] ?? 0);

// Ordem + cor de cada papel para a barra ponderada (eco do "% peso" do app).
$ROLES = [
    'super_admin'   => '#6d28d9',
    'administrador' => '#1d4ed8',
    'editor'        => '#0e7490',
    'colaborador'   => '#4b5b7a',
];
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Painel &middot; Nitro Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600&display=swap">
<link rel="stylesheet" href="/assets/css/apl-admin.css?v=<?= @filemtime(__DIR__.'/../assets/css/apl-admin.css') ?>">
<?php require __DIR__ . '/../security-head.php'; ?>
</head>
<body>
<?php $ADM_ATIVO='dashboard'; require __DIR__.'/partials/nav.php'; ?>

<main class="adm-main">
  <div class="adm-wrap">
    <div class="adm-head">
      <div class="eyebrow">Painel &middot; mesa de operações</div>
      <h1 class="adm-title">Olá, <?= htmlspecialchars(explode(' ', trim($ADMIN['nome']))[0]) ?>.</h1>
    </div>

    <!-- READOUT: leitura de console -->
    <div class="readout">
      <div class="cell"><div class="k">Usuários</div><div class="v"><?= $total ?></div></div>
      <div class="cell"><div class="k">Ativos</div><div class="v"><?= $ativos ?><small>/ <?= $total ?></small></div></div>
      <div class="cell"><div class="k">Administradores</div><div class="v"><?= $admins ?></div></div>
      <div class="cell"><div class="k">Assinantes</div><div class="v"><?= $porPapel['colaborador'] ?? 0 ?></div></div>
    </div>

    <!-- DISTRIBUIÇÃO de papéis: barra ponderada -->
    <div class="dist">
      <h3>Distribuição por papel <span class="total"><?= $total ?> contas</span></h3>
      <div class="wbar">
        <?php foreach ($ROLES as $k => $cor): $c = $porPapel[$k] ?? 0; if (!$c) continue;
              $w = $total ? round($c / $total * 100, 2) : 0; ?>
          <span style="width:<?= $w ?>%;background:<?= $cor ?>" title="<?= htmlspecialchars(papel_rotulo($k)) ?>: <?= $c ?>"></span>
        <?php endforeach; ?>
      </div>
      <div class="wlegend">
        <?php foreach ($ROLES as $k => $cor): ?>
          <div class="it"><span class="sw" style="background:<?= $cor ?>"></span><?= htmlspecialchars(papel_rotulo($k)) ?>
            <span class="ct"><?= $porPapel[$k] ?? 0 ?></span></div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- ENTRADA: gestão de usuários -->
    <div class="panel">
      <div class="panel-head">
        <div>
          <h2>&#9783; Gestão de usuários</h2>
          <div class="sub">Cada conta tem sua carteira isolada. Excluir uma conta apaga todos os dados dela.</div>
        </div>
        <a class="btn btn-primary" href="/apl-admin/usuarios.php">Gerir usuários &rarr;</a>
      </div>
    </div>
  </div>
</main>
</body>
</html>
