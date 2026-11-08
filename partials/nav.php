<?php
// ============================================================
//  Menu de navegacao COMPARTILHADO (partials/nav.php)
//  Cada pagina define a variavel $ATIVO antes de dar require neste arquivo.
//  Valores de $ATIVO: carteira | ranking | importar | extrato | nitro | config | lixeira | perfil | carteiras
// ============================================================
require_once __DIR__ . '/../config.php';     // getDB()
require_once __DIR__ . '/../wallets.php';     // multi-carteira (Fase 3)

$ATIVO   = $ATIVO ?? '';
$navUser = $_SESSION['user_nome'] ?? 'Aurélio';
$navIni  = mb_strtoupper(mb_substr(trim($navUser) ?: 'A', 0, 1));
$navAva  = function_exists('avatar_url') ? avatar_url($_SESSION['user_avatar'] ?? null) : null;
$act = function ($id) use ($ATIVO) { return $ATIVO === $id ? ' active' : ''; };

// ── Carteiras do usuario (seletor "Mudar carteira", estilo YouTube) ──────────
$navPdo  = getDB();
$navUid  = (int) ($_SESSION['user_id'] ?? 0);
wallet_ensure_default($navPdo, $navUid);                 // todo usuario tem ao menos a "Principal"
$navCarteiras   = wallets_list($navPdo, $navUid);
$navCartAtivaId = wallet_active_id($navPdo, $navUid);
$navCartAtiva   = 'Carteira';
foreach ($navCarteiras as $w) { if ((int) $w['id'] === $navCartAtivaId) { $navCartAtiva = $w['nome']; break; } }

// Nitro e Betha viraram pessoais (Fase 3): so o super_admin as enxerga.
$navVeNitro = function_exists('pode_ver_nitro') && pode_ver_nitro($_SESSION['user_papel'] ?? '');
$navVeBetha = function_exists('pode_ver_betha') && pode_ver_betha($_SESSION['user_papel'] ?? '');
?>
<nav class="nav"><div class="nav-inner">
  <div class="nav-left">
    <a href="/" class="nav-link<?= $act('carteira') ?>">&#128202; Minha Carteira</a>
    <?php if ($navVeNitro): ?><a href="/nitro" class="nav-link<?= $act('nitro') ?>">&#9889; Nitro</a><?php endif; ?>
    <?php if ($navVeBetha): ?><a href="/betha" class="nav-link<?= $act('betha') ?>">&#129518; Betha</a><?php endif; ?>
    <a href="/ranking" class="nav-link<?= $act('ranking') ?>">&#127942; Ranking</a>
    <a href="/importar" class="nav-link<?= $act('importar') ?>">&#128229; Importar</a>
    <a href="/extrato" class="nav-link<?= $act('extrato') ?>">&#129534; Extrato</a>
  </div>
  <div class="nav-right">
    <div class="nav-user" id="navUser">
      <button class="nav-user-btn" id="navUserBtn" type="button">
        <?php if ($navAva): ?><img class="nav-ava" src="<?= htmlspecialchars($navAva) ?>" alt=""><?php else: ?><span class="nav-ava"><?= htmlspecialchars($navIni) ?></span><?php endif; ?>
        <span class="nav-ucol">
          <span class="nav-uname"><?= htmlspecialchars($navUser) ?></span>
          <span class="nav-uwallet">&#128194; <?= htmlspecialchars($navCartAtiva) ?></span>
        </span>
        <span class="nav-caret">&#9660;</span>
      </button>
      <div class="nav-menu">
        <div class="mhead">
          <div class="mn"><?= htmlspecialchars($navUser) ?></div>
          <div class="me"><?= htmlspecialchars($_SESSION['user_email'] ?? 'arcainveste') ?></div>
        </div>
        <!-- Seletor de carteira (estilo YouTube: "Mudar carteira" abre as carteiras) -->
        <div class="nav-wallet" id="navWallet">
          <button type="button" class="nav-wallet-toggle" id="navWalletToggle">
            <span class="nw-cur">&#128194; <span class="nw-cur-nm"><?= htmlspecialchars($navCartAtiva) ?></span></span>
            <span class="nw-sub">Mudar carteira</span>
            <span class="nw-arrow">&#9656;</span>
          </button>
          <div class="nav-wallet-list" id="navWalletList">
            <?php foreach ($navCarteiras as $w): $on = ((int) $w['id'] === $navCartAtivaId); ?>
            <button type="button" class="nw-item<?= $on ? ' on' : '' ?>" onclick="trocarCarteira(<?= (int) $w['id'] ?>)">
              <span class="nw-check"><?= $on ? '&#10003;' : '' ?></span>
              <span class="nw-nm"><?= htmlspecialchars($w['nome']) ?></span>
            </button>
            <?php endforeach; ?>
            <button type="button" class="nw-item nw-new" onclick="novaCarteira()">&#43; Nova carteira</button>
            <a class="nw-item nw-manage" href="/carteiras">&#9881;&#65039; Gerenciar carteiras</a>
          </div>
        </div>
        <div class="sep"></div>
        <a href="/perfil"<?= $ATIVO==='perfil'?' style="background:#f1ecfe;color:#5b21b6"':'' ?>>&#128100; Meu perfil</a>
        <a href="/config"<?= $ATIVO==='config'?' style="background:#f1ecfe;color:#5b21b6"':'' ?>>&#9881;&#65039; Configurações</a>
        <a href="/lixeira"<?= $ATIVO==='lixeira'?' style="background:#f1ecfe;color:#5b21b6"':'' ?>>&#128465;&#65039; Lixeira</a>
        <?php if (function_exists('pode_admin') && pode_admin($_SESSION['user_papel'] ?? '')): ?>
        <div class="sep"></div>
        <a href="/apl-admin/">&#128736;&#65039; Painel admin</a>
        <?php endif; ?>
        <div class="sep"></div>
        <form method="post" action="/"><input type="hidden" name="auth_acao" value="logout"><input type="hidden" name="_csrf" value="<?= htmlspecialchars(nitro_csrf_token(), ENT_QUOTES, 'UTF-8') ?>"><button type="submit">Sair</button></form>
      </div>
    </div>
  </div>
</div></nav>
<script>
(function(){
  var u=document.getElementById('navUser'),b=document.getElementById('navUserBtn');
  if(!u||!b)return;
  b.addEventListener('click',function(e){e.stopPropagation();u.classList.toggle('open');});
  document.addEventListener('click',function(){u.classList.remove('open');});
  document.addEventListener('keydown',function(e){if(e.key==='Escape')u.classList.remove('open');});
  // mantem o menu aberto ao interagir com o seletor de carteira
  var menu=u.querySelector('.nav-menu'); if(menu) menu.addEventListener('click',function(e){e.stopPropagation();});
  // "Mudar carteira": expande/recolhe a lista de carteiras
  var wt=document.getElementById('navWalletToggle'),wbox=document.getElementById('navWallet');
  if(wt&&wbox) wt.addEventListener('click',function(){wbox.classList.toggle('open');});
})();
// troca a carteira ativa (grava na sessao no servidor) e recarrega a pagina
async function trocarCarteira(id){
  try{
    const r=await fetch('/api.php?action=wallet_switch',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id})});
    const j=await r.json();
    if(j&&j.success) location.reload(); else alert((j&&j.error)||'Não foi possível trocar de carteira.');
  }catch(e){alert('Falha de rede ao trocar de carteira.');}
}
// cria carteira nova (pergunta o nome), ja troca para ela e recarrega
async function novaCarteira(){
  const nome=(prompt('Nome da nova carteira:')||'').trim();
  if(!nome) return;
  try{
    const r=await fetch('/api.php?action=wallet_create',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({nome})});
    const j=await r.json();
    if(j&&j.success&&j.id){ await trocarCarteira(j.id); }
    else alert((j&&j.error)||'Não foi possível criar a carteira.');
  }catch(e){alert('Falha de rede ao criar a carteira.');}
}
</script>
