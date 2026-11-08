<?php
require __DIR__ . '/_admin.php';   // sessao + guard + $ADMIN + CSRF + config_geral

$cfg = config_geral_all();

// Fuso atual valido? (senao cai no default) — usado para os exemplos de data/hora.
$tzAtual = in_array($cfg['fuso_horario'], timezone_identifiers_list(), true)
         ? $cfg['fuso_horario'] : CONFIG_GERAL_DEFAULTS['fuso_horario'];
date_default_timezone_set($tzAtual);
$agora = time();

// Lista de fusos agrupada por regiao (Continente/Cidade) para o <select>.
$fusos = [];
foreach (timezone_identifiers_list() as $tz) {
    $regiao = strpos($tz, '/') !== false ? explode('/', $tz, 2)[0] : 'Outros';
    $fusos[$regiao][] = $tz;
}

function he($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Configurações &middot; Nitro Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600&display=swap">
<link rel="stylesheet" href="/assets/css/apl-admin.css?v=<?= @filemtime(__DIR__.'/../assets/css/apl-admin.css') ?>">
<?php require __DIR__ . '/../security-head.php'; ?>
</head>
<body>
<?php $ADM_ATIVO='config'; require __DIR__.'/partials/nav.php'; ?>

<main class="adm-main">
  <div class="adm-wrap">
    <div class="adm-head">
      <div class="eyebrow">Operação &middot; sistema</div>
      <h1 class="adm-title">Configurações gerais</h1>
    </div>

    <form id="cfgForm" onsubmit="return false">
      <!-- IDENTIDADE da plataforma -->
      <div class="panel" style="margin-top:0">
        <div class="panel-head">
          <div>
            <h2>&#127760; Identidade da plataforma</h2>
            <div class="sub">Vale para o sistema inteiro — não é por usuário.</div>
          </div>
        </div>
        <div class="panel-body">
          <div class="field">
            <label>Título geral da plataforma</label>
            <input type="text" id="c-titulo" maxlength="120" value="<?= he($cfg['site_titulo']) ?>">
            <div class="hint">Alimenta o <code>%title%</code> usado nos títulos das páginas.</div>
          </div>
          <div class="field">
            <label>Descrição geral do site</label>
            <textarea id="c-descricao" maxlength="300" rows="2"><?= he($cfg['site_descricao']) ?></textarea>
          </div>
          <div class="field">
            <label>Ícone do site (favicon / logo)</label>
            <div class="icon-row">
              <img id="c-icone-prev" class="icon-prev" alt="" src="<?= he($cfg['site_icone'] ?: '') ?>"<?= $cfg['site_icone'] ? '' : ' style="display:none"' ?>>
              <span id="c-icone-ph" class="icon-ph"<?= $cfg['site_icone'] ? ' style="display:none"' : '' ?>>&#9633;</span>
              <input type="text" id="c-icone" class="mono" placeholder="https://… ou /assets/…" value="<?= he($cfg['site_icone']) ?>">
            </div>
            <div class="hint">URL do favicon/logo. O upload de imagem é uma etapa à parte (precisa de pasta gravável).</div>
          </div>
        </div>
      </div>

      <!-- REGIONAL: fuso + formatos -->
      <div class="panel">
        <div class="panel-head">
          <div>
            <h2>&#128340; Regional</h2>
            <div class="sub">Fuso e formatos padrão de data e hora.</div>
          </div>
        </div>
        <div class="panel-body">
          <div class="field">
            <label>Fuso horário</label>
            <select id="c-fuso">
              <?php foreach ($fusos as $regiao => $lista): ?>
                <optgroup label="<?= he($regiao) ?>">
                  <?php foreach ($lista as $tz): ?>
                    <option value="<?= he($tz) ?>"<?= $tz === $cfg['fuso_horario'] ? ' selected' : '' ?>><?= he($tz) ?></option>
                  <?php endforeach; ?>
                </optgroup>
              <?php endforeach; ?>
            </select>
            <div class="hint">Padrão: <strong>America/Sao_Paulo</strong> (horário da B3).</div>
          </div>
          <div class="grid2">
            <div class="field">
              <label>Formato de data</label>
              <select id="c-formato-data">
                <?php foreach (FORMATOS_DATA as $f): ?>
                  <option value="<?= he($f) ?>"<?= $f === $cfg['formato_data'] ? ' selected' : '' ?>><?= he($f) ?> &nbsp;·&nbsp; <?= he(date($f, $agora)) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="field">
              <label>Formato de hora</label>
              <select id="c-formato-hora">
                <?php foreach (FORMATOS_HORA as $f): ?>
                  <option value="<?= he($f) ?>"<?= $f === $cfg['formato_hora'] ? ' selected' : '' ?>><?= he($f) ?> &nbsp;·&nbsp; <?= he(date($f, $agora)) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="cfg-preview">
            <span class="k">Pré-visualização</span>
            <span class="v" id="c-preview"><?= he(date($cfg['formato_data'].' '.$cfg['formato_hora'], $agora)) ?></span>
            <span class="note">(atualiza após salvar)</span>
          </div>
        </div>
        <div class="panel-foot">
          <span class="msg" id="cfgMsg"></span>
          <button class="btn btn-primary" id="cfgSave" onclick="salvar()">Salvar configurações</button>
        </div>
      </div>
    </form>
  </div>
</main>

<div class="toast" id="toast"></div>

<script>
const CSRF = <?= json_encode($_SESSION['admin_csrf']) ?>;

function esc(s){ return (s==null?'':String(s)).replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])); }
function toast(t, err){ const el=document.getElementById('toast'); el.innerHTML=(err?'&#9888;&#65039; ':'&#10003; ')+esc(t); el.className='toast show'+(err?' err':''); setTimeout(()=>el.className='toast'+(err?' err':''),2600); }
function msg(t,type){ const el=document.getElementById('cfgMsg'); el.textContent=t||''; el.className='msg '+(type||''); }

// Pre-visualizacao do icone enquanto digita a URL.
const icoInp=document.getElementById('c-icone'), icoImg=document.getElementById('c-icone-prev'), icoPh=document.getElementById('c-icone-ph');
function refreshIcone(){
  const v=icoInp.value.trim();
  if(v){ icoImg.src=v; icoImg.style.display=''; icoPh.style.display='none'; }
  else { icoImg.style.display='none'; icoPh.style.display=''; }
}
icoInp.addEventListener('input', refreshIcone);
icoImg.addEventListener('error', ()=>{ icoImg.style.display='none'; icoPh.style.display=''; });

async function salvar(){
  const btn=document.getElementById('cfgSave'); btn.disabled=true; msg('');
  const body={
    site_titulo:    document.getElementById('c-titulo').value.trim(),
    site_descricao: document.getElementById('c-descricao').value.trim(),
    site_icone:     document.getElementById('c-icone').value.trim(),
    fuso_horario:   document.getElementById('c-fuso').value,
    formato_data:   document.getElementById('c-formato-data').value,
    formato_hora:   document.getElementById('c-formato-hora').value,
  };
  try{
    const r=await fetch('/apl-admin/api.php?action=config_geral_save',{
      method:'POST', headers:{'Content-Type':'application/json','X-CSRF':CSRF}, body:JSON.stringify(body)
    });
    if(r.status===401){ location.href='/apl-login'; return; }
    const j=await r.json();
    if(j&&j.success){ toast('Configurações salvas.'); msg('Salvo. As páginas que consomem estes valores usarão os novos a partir de agora.','ok'); }
    else { msg((j&&j.error)||'Não foi possível salvar.','err'); }
  }catch(e){ msg('Falha de rede ao salvar.','err'); }
  finally{ btn.disabled=false; }
}
</script>
</body>
</html>
