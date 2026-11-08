<?php
// Rail do painel administrativo. Cada pagina define $ADM_ATIVO antes de incluir.
// Depende de $ADMIN (do _admin.php) e dos helpers de papeis.php.
$ADM_ATIVO = $ADM_ATIVO ?? '';
$admAct = fn($id) => $ADM_ATIVO === $id ? 'active' : '';
$admNome  = $ADMIN['nome'] ?? 'Operador';
$admUser  = $ADMIN['usuario'] ?? '';
$admEmail = $ADMIN['email'] ?? '';
$admPapel = $ADMIN['papel'] ?? '';
$ROLE_DOT = ['super_admin'=>'#7c3aed','administrador'=>'#1d4ed8','editor'=>'#0e7490','colaborador'=>'#4b5b7a'];
$admDot = $ROLE_DOT[$admPapel] ?? '#7c3aed';
$admAva = function_exists('avatar_url') ? avatar_url($ADMIN['avatar'] ?? null) : null;
$admIni = mb_strtoupper(mb_substr(trim($admNome) ?: 'O', 0, 1));
?>
<aside class="adm-side">
  <div class="adm-brand">
    <span class="bolt">&#9889;</span>
    <span class="wm">Nitro</span>
    <span class="tag">Admin</span>
  </div>

  <nav class="adm-nav">
    <div class="lbl">Operação</div>
    <a href="/apl-admin/" class="<?= $admAct('dashboard') ?>"><span class="ico">&#9632;</span> <span>Painel</span></a>
    <a href="/apl-admin/usuarios.php" class="<?= $admAct('usuarios') ?>"><span class="ico">&#9783;</span> <span>Usuários</span></a>
    <a href="/apl-admin/configuracoes.php" class="<?= $admAct('config') ?>"><span class="ico">&#9881;</span> <span>Configurações</span></a>
    <a href="/apl-admin/calendario.php" class="<?= $admAct('calendario') ?>"><span class="ico">&#128197;</span> <span>Calendário</span></a>
    <div class="lbl" style="margin-top:14px">Atalhos</div>
    <a href="/"><span class="ico">&#8599;</span> <span>Voltar ao app</span></a>
  </nav>

  <div class="spacer"></div>

  <div class="op-card">
    <div class="op-top">
      <?php if ($admAva): ?><img class="ava" src="<?= htmlspecialchars($admAva) ?>" alt=""><?php else: ?><span class="ava ava-ini"><?= htmlspecialchars($admIni) ?></span><?php endif; ?>
      <div class="op-id">
        <div class="op-name"><?= htmlspecialchars($admNome) ?></div>
        <div class="op-handle">@<?= htmlspecialchars($admUser) ?></div>
      </div>
    </div>
    <div class="op-role">
      <span class="dot" style="background:<?= $admDot ?>"></span>
      <span class="nm"><?= htmlspecialchars(papel_rotulo($admPapel)) ?></span>
      <span class="lv">lv<?= (int)papel_nivel($admPapel) ?></span>
    </div>
    <form method="post" action="/apl-login"><input type="hidden" name="acao" value="logout"><input type="hidden" name="_csrf" value="<?= htmlspecialchars(nitro_csrf_token(), ENT_QUOTES, 'UTF-8') ?>"><button type="submit">Encerrar sessão</button></form>
  </div>
</aside>
