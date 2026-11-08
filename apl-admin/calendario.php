<?php
require __DIR__ . '/_admin.php';            // sessao + guard + $ADMIN + CSRF + config_geral
require_once __DIR__ . '/../vencimentos.php';   // calendario de vencimento (store global)

// Fuso da plataforma (config geral) para "hoje" e "proximo vencimento".
$tz = config_geral_get('fuso_horario', 'America/Sao_Paulo');
if (!in_array($tz, timezone_identifiers_list(), true)) $tz = 'America/Sao_Paulo';
date_default_timezone_set($tz);
$fmtData = config_geral_get('formato_data', 'd/m/Y');

$anoAtual    = (int)date('Y');
$anoSeguinte = $anoAtual + 1;
$anos = [$anoAtual, $anoSeguinte];
$hoje = date('Y-m-d');

// Pre-carrega os dois anos e descobre o PROXIMO vencimento (>= hoje) no conjunto.
$cal = [];
$proximo = null;   // ['ano'=>..,'data'=>..]
foreach ($anos as $a) {
    $cal[$a] = vencimentos_ano($a);
    foreach ($cal[$a] as $l) {
        if ($l['data'] >= $hoje && ($proximo === null || $l['data'] < $proximo['data'])) {
            $proximo = ['ano' => $a, 'data' => $l['data']];
        }
    }
}

function he($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function br($iso, $fmt) { $d = DateTime::createFromFormat('Y-m-d', $iso); return $d ? $d->format($fmt) : $iso; }
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Calendário de vencimento &middot; Nitro Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600&display=swap">
<link rel="stylesheet" href="/assets/css/apl-admin.css?v=<?= @filemtime(__DIR__.'/../assets/css/apl-admin.css') ?>">
<?php require __DIR__ . '/../security-head.php'; ?>
</head>
<body>
<?php $ADM_ATIVO='calendario'; require __DIR__.'/partials/nav.php'; ?>

<main class="adm-main">
  <div class="adm-wrap">
    <div class="adm-head">
      <div class="eyebrow">Operação &middot; mercado</div>
      <h1 class="adm-title">Calendário de vencimento</h1>
    </div>

    <!-- INTRO: regra B3 + proximo vencimento + legenda de series -->
    <div class="cal-intro">
      <p class="cal-rule">Opções de ações da B3 vencem na <strong>3ª sexta-feira</strong> do mês de vencimento.
        Quando essa sexta cai em feriado, o vencimento <strong>antecipa</strong> — ajuste a data do mês aqui.</p>
      <?php if ($proximo): ?>
        <div class="cal-next">
          <span class="lbl">Próximo vencimento</span>
          <span class="val"><?= he(br($proximo['data'], $fmtData)) ?></span>
          <span class="note"><?= he(MESES_OPCOES[(int)substr($proximo['data'],5,2)][0]) ?>/<?= (int)$proximo['ano'] ?></span>
        </div>
      <?php endif; ?>
      <div class="cal-legend">
        <span class="serie"><span class="badge call">A–L</span> CALL (jan→dez)</span>
        <span class="serie"><span class="badge put">M–X</span> PUT (jan→dez)</span>
        <span class="leg-dot"><span class="d adj"></span> ajustado (≠ padrão B3)</span>
      </div>
    </div>

    <?php foreach ($anos as $a): $linhas = $cal[$a]; ?>
    <div class="panel cal-panel" data-ano="<?= $a ?>">
      <div class="panel-head">
        <div>
          <h2>&#128197; Vencimentos <?= $a ?> <?= $a === $anoAtual ? '<span class="cal-tag">ano corrente</span>' : '<span class="cal-tag soft">ano seguinte</span>' ?></h2>
          <div class="sub">Padrão = 3ª sexta-feira; edite só os meses afetados por feriado.</div>
        </div>
      </div>
      <table class="utable cal-table">
        <thead>
          <tr>
            <th>Mês</th>
            <th>Série</th>
            <th>Vencimento</th>
            <th class="r">Padrão B3</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($linhas as $i => $l):
            $isProx = $proximo && $proximo['ano'] === $a && $proximo['data'] === $l['data'];
            $isPast = $l['data'] < $hoje;
            $cls = trim(($isPast ? 'past ' : '') . ($isProx ? 'next' : '')); ?>
          <tr class="<?= $cls ?>" style="--i:<?= $i ?>">
            <td class="cal-mes"><?= he($l['nome']) ?><?php if ($isProx): ?> <span class="cal-flag next">próximo</span><?php endif; ?></td>
            <td>
              <span class="serie">
                <span class="badge call" title="CALL <?= he($l['nome']) ?>"><?= he($l['call']) ?></span>
                <span class="badge put" title="PUT <?= he($l['nome']) ?>"><?= he($l['put']) ?></span>
              </span>
            </td>
            <td class="venc-cell">
              <input type="date" class="venc-inp" value="<?= he($l['data']) ?>"
                     data-mes="<?= $l['mes'] ?>" data-padrao="<?= he($l['data_padrao']) ?>"
                     min="<?= $a ?>-01-01" max="<?= $a ?>-12-31">
              <button type="button" class="venc-reset" title="Voltar ao padrão B3 (3ª sexta)"<?= $l['ajustado'] ? '' : ' hidden' ?>>&#8635;</button>
              <span class="cal-flag adj"<?= $l['ajustado'] ? '' : ' hidden' ?>>ajustado</span>
            </td>
            <td class="r cal-padrao"><?= he(br($l['data_padrao'], $fmtData)) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <div class="panel-foot">
        <span class="msg" data-msg></span>
        <button class="btn btn-primary" data-save="<?= $a ?>" onclick="salvarAno(<?= $a ?>, this)">Salvar <?= $a ?></button>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</main>

<div class="toast" id="toast"></div>

<script>
const CSRF = <?= json_encode($_SESSION['admin_csrf']) ?>;

function esc(s){ return (s==null?'':String(s)).replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])); }
function toast(t, err){ const el=document.getElementById('toast'); el.innerHTML=(err?'&#9888;&#65039; ':'&#10003; ')+esc(t); el.className='toast show'+(err?' err':''); setTimeout(()=>el.className='toast'+(err?' err':''),2600); }
function panelOf(ano){ return document.querySelector('.cal-panel[data-ano="'+ano+'"]'); }
function msg(ano,t,type){ const el=panelOf(ano).querySelector('[data-msg]'); el.textContent=t||''; el.className='msg '+(type||''); }

// Marca a linha como ajustada (≠ padrao) e mostra/esconde reset + flag.
function refreshRow(inp){
  const ajustado = inp.value && inp.value !== inp.dataset.padrao;
  const cell = inp.closest('.venc-cell');
  cell.querySelector('.venc-reset').hidden = !ajustado;
  cell.querySelector('.cal-flag.adj').hidden = !ajustado;
  inp.classList.toggle('adj', !!ajustado);
}

document.querySelectorAll('.venc-inp').forEach(inp=>{
  refreshRow(inp);
  inp.addEventListener('change', ()=>refreshRow(inp));
  inp.addEventListener('input',  ()=>refreshRow(inp));
});
document.querySelectorAll('.venc-reset').forEach(btn=>{
  btn.addEventListener('click', ()=>{
    const inp = btn.closest('.venc-cell').querySelector('.venc-inp');
    inp.value = inp.dataset.padrao;
    refreshRow(inp);
  });
});

async function salvarAno(ano, btn){
  btn.disabled=true; msg(ano,'');
  const meses={};
  panelOf(ano).querySelectorAll('.venc-inp').forEach(inp=>{ meses[inp.dataset.mes] = inp.value || ''; });
  try{
    const r=await fetch('/apl-admin/api.php?action=vencimentos_save',{
      method:'POST', headers:{'Content-Type':'application/json','X-CSRF':CSRF}, body:JSON.stringify({ano, meses})
    });
    if(r.status===401){ location.href='/apl-login'; return; }
    const j=await r.json();
    if(j&&j.success){
      const n=j.ajustados||0;
      toast('Calendário de '+ano+' salvo.');
      msg(ano, n? (n+' mês(es) ajustado(s); os demais seguem o padrão B3.') : 'Tudo no padrão B3 (3ª sexta).', 'ok');
    } else { msg(ano,(j&&j.error)||'Não foi possível salvar.','err'); }
  }catch(e){ msg(ano,'Falha de rede ao salvar.','err'); }
  finally{ btn.disabled=false; }
}
</script>
</body>
</html>
