<?php
require_once 'auth.php';
exigir_login();
// Nitro virou pessoal (Fase 3): so o super_admin acessa esta pagina.
if (!pode_ver_nitro($_SESSION['user_papel'] ?? '')) { header('Location: /'); exit; }
$TITULO_PG = 'Carteira de Terceiros';
$CSS_PG    = 'nitro';
require __DIR__ . '/partials/head.php';
?>
<body>
<?php $ATIVO='nitro'; require __DIR__.'/partials/nav.php'; ?>

<div class="wrap">
  <div class="trio">
    <div class="gcard">
      <div class="gcard-title">Resultados</div>
      <div class="metric sub-line"><span class="m-lbl">P&amp;L aberto</span><span class="m-val" id="st-carteira">&mdash;</span></div>
      <div class="metric sub-line"><span class="m-lbl">P&amp;L encerrados</span><span class="m-val" id="st-realizado">&mdash;</span></div>
      <div class="metric total"><span class="m-lbl">Resultado líquido total</span><span class="m-val" id="st-liquido">&mdash;</span></div>
    </div>
    <div class="gcard">
      <div class="gcard-title">Operações</div>
      <div class="metric big"><span class="m-lbl">Ordens<span class="m-sub" id="st-desde"></span></span><span class="m-val" id="st-total">&mdash;</span></div>
      <div class="metric"><span class="m-lbl">Abertas</span><span class="m-val" id="st-abertas">&mdash;</span></div>
      <div class="metric"><span class="m-lbl">Encerradas</span><span class="m-val" id="st-encerradas">&mdash;</span></div>
      <div class="metric" title="% das posições abertas (com cotação) que estão no positivo."><span class="m-lbl">Acerto <span class="m-sub">abertas</span></span><span class="m-val" id="st-acerto-ab">&mdash;</span></div>
      <div class="metric" title="% das posições encerradas que deram lucro."><span class="m-lbl">Acerto <span class="m-sub">encerradas</span></span><span class="m-val" id="st-acerto-enc">&mdash;</span></div>
      <div class="gcard-foot">atualizado em <span id="last-update">&hellip;</span></div>
    </div>
    <div class="gcard nova-card">
      <div class="gcard-title">&#43; Nova Posição</div>
      <input type="text" id="n-cod" placeholder="CÓDIGO EX: BOVAG52" style="text-transform:uppercase">
      <div class="tipo-auto">
        <span id="n-tipo-hint" class="tipo-hint">tipo: detectado pelo código &darr;</span>
        <span id="n-tipo-badge" class="tipo-badge" style="display:none"></span>
        <span id="n-tipo-auto-tag" class="tipo-tag" style="display:none">(automático)</span>
        <select id="n-est" style="display:none;margin:0;width:auto">
          <option value="">Escolha o tipo…</option>
          <option value="CALL">CALL</option>
          <option value="PUT">PUT</option>
        </select>
      </div>
      <label class="fld-lbl" for="n-dir">Direção</label>
      <select id="n-dir" title="Comprada: você paga o prêmio (titular). Vendida: você recebe o prêmio (lançada/descoberta) e lucra quando ele cai.">
        <option value="C">Comprada</option>
        <option value="V">Vendida</option>
      </select>
      <input type="date" id="n-data">
      <input type="number" id="n-pago" placeholder="Pago ex: 1.25" step="0.001" min="0">
      <input type="number" id="n-rec" placeholder="Recebido (opcional)" step="0.001" min="0">
      <button class="btn btn-primary" onclick="criarPosicao()">Adicionar</button>
      <div class="msg" id="create-msg"></div>
    </div>
  </div>

  <div class="section">
    <div class="section-header">
      <h2>&#128203; Posições Abertas</h2><span class="badge-count" id="cnt-abertas">&mdash;</span>
      <span class="msg" id="cot-msg" style="margin:0"></span>
      <label class="sinal-toggle" id="sinal-toggle-wrap" style="display:none" title="Mostrar só as posições com sinal de entrada (🟢/🟡/↩).">
        <input type="checkbox" id="f-so-sinal" onchange="renderAbertas()"> Só com sinal <span class="sinal-toggle-cnt" id="sinal-cnt"></span>
      </label>
      <button class="btn btn-del btn-sm" style="margin-left:auto" onclick="buscarCotacoes()" title="Buscar fechamento do último pregão (B3 grátis ou OpLab — definido em Config)">&#8635; Buscar cotações</button>
    </div>
    <div class="tbl-wrap">
      <table>
        <thead><tr id="head-abertas">
          <th class="sortable" data-k="codigo">Código<span class="arr">▲▼</span></th>
          <th class="sortable" data-k="estrutura">Tipo<span class="arr">▲▼</span></th>
          <th class="sortable" data-k="data_inicio">Início<span class="arr">▲▼</span></th>
          <th class="sortable r" data-k="dias">Dias<span class="arr">▲▼</span></th>
          <th class="sortable r" data-k="pago">Pago<span class="arr">▲▼</span></th>
          <th class="sortable r" data-k="recebido">Recebido<span class="arr">▲▼</span></th>
          <th class="sortable r" data-k="resultado">Resultado<span class="arr">▲▼</span></th>
          <th class="sortable" data-k="sinal" title="Gatilho de entrada por histórico — clique no sinal para o racional.">Sinal<span class="arr">▲▼</span></th>
          <th>Ações</th>
        </tr></thead>
        <tbody id="tbody-abertas"></tbody>
      </table>
    </div>
  </div>

  <details class="section" open>
    <summary>&#10003; Encerradas <span class="badge-count" id="cnt-enc" style="margin-left:8px">&mdash;</span>
      <span class="filtro-per" onclick="event.stopPropagation()">
        <label for="f-periodo">Período</label>
        <select id="f-periodo" onchange="trocarPeriodo(this.value)"></select>
      </span>
    </summary>
    <div class="tbl-wrap">
      <table>
        <thead><tr id="head-enc">
          <th class="sortable" data-k="codigo">Código<span class="arr">▲▼</span></th>
          <th class="sortable" data-k="estrutura">Tipo<span class="arr">▲▼</span></th>
          <th class="sortable" data-k="data_inicio">Início<span class="arr">▲▼</span></th>
          <th class="sortable" data-k="data_final">Final<span class="arr">▲▼</span></th>
          <th class="sortable r" data-k="dias">Dias<span class="arr">▲▼</span></th>
          <th class="sortable r" data-k="pago">Pago<span class="arr">▲▼</span></th>
          <th class="sortable r" data-k="recebido">Recebido<span class="arr">▲▼</span></th>
          <th class="sortable r" data-k="resultado">Resultado<span class="arr">▲▼</span></th>
          <th>Ações</th>
        </tr></thead>
        <tbody id="tbody-enc"></tbody>
      </table>
    </div>
  </details>

  <!-- RESULTADO DAS ENCERRADAS POR PERÍODO — Ano > Mês > Dia (um section por ano) -->
  <div class="periodo-head">
    <h2>&#128197; Resultado das encerradas por período</h2>
    <span class="periodo-sub">Ano &rarr; Mês &rarr; Dia &middot; clique na linha para abrir</span>
  </div>
  <div id="periodo-anos"></div>
</div>

<!-- MODAIS -->
<div class="modal-overlay" id="modal-close">
  <div class="modal">
    <h3>&#128274; Encerrar posição</h3>
    <p>Confirma o encerramento de <span class="modal-code" id="mc-code"></span>?</p>
    <div class="modal-fields">
      <label>Data Final</label><input type="date" id="mc-data">
      <label id="mc-rec-lbl">Recebido (preço de venda por unidade)</label><input type="number" id="mc-rec" step="0.001" min="0" placeholder="ex: 1.25">
    </div>
    <div class="modal-actions">
      <button class="btn btn-del" onclick="fecharModal()">Cancelar</button>
      <button class="btn btn-enc" onclick="confirmarEncerrar()">Sim, encerrar</button>
    </div>
  </div>
</div>
<div class="modal-overlay" id="modal-reopen">
  <div class="modal">
    <h3>&#128275; Reabrir posição</h3>
    <p>Deseja reabrir <span class="modal-code" id="mr-code"></span>? A data final e os dias serão apagados.</p>
    <div class="modal-actions">
      <button class="btn btn-del" onclick="fecharModal()">Cancelar</button>
      <button class="btn btn-reabrir" onclick="confirmarReabrir()">Sim, reabrir</button>
    </div>
  </div>
</div>
<div class="modal-overlay" id="modal-del">
  <div class="modal">
    <h3>&#128465;&#65039; Excluir posição</h3>
    <p>Excluir <span class="modal-code" id="md-code"></span>?<br>Ela irá para a <strong>Lixeira</strong> e poderá ser restaurada de lá.</p>
    <div class="modal-actions">
      <button class="btn btn-del" onclick="fecharModal()">Cancelar</button>
      <button class="btn btn-enc" onclick="confirmarExcluir()">Sim, excluir</button>
    </div>
  </div>
</div>
<div class="modal-overlay" id="modal-cot">
  <div class="modal" style="max-width:480px;text-align:left">
    <h3 style="text-align:center">&#8635; Cotações OpLab</h3>
    <p id="cot-info" style="text-align:center"></p>
    <div id="cot-list" style="max-height:52vh;overflow:auto;margin:12px 0;border:1px solid var(--border);border-radius:10px"></div>
    <div class="modal-actions">
      <button class="btn btn-del" onclick="fecharModal()">Cancelar</button>
      <button class="btn btn-primary" id="cot-apply" onclick="aplicarCotacoes()">Aplicar selecionadas</button>
    </div>
  </div>
</div>

<!-- ── Racional de entrada (Painel de Sinais — ADR 021) ── -->
<div class="modal-overlay" id="modal-sinal">
  <div class="modal sinal-modal">
    <h3 id="sm-titulo">Racional de entrada</h3>
    <div id="sm-body"></div>
    <div class="modal-actions">
      <button class="btn btn-primary" onclick="fecharModal()">Fechar</button>
    </div>
    <p class="sinal-rodape">Estudo descritivo de ~2 meses do histórico da Sala Nitro. <strong>Não é recomendação.</strong></p>
  </div>
</div>

<!-- ── Detalhe do dia (o que foi negociado/encerrado naquela data) ── -->
<div class="modal-overlay" id="modal-dia">
  <div class="modal" style="max-width:880px;text-align:left">
    <h3 id="dia-titulo" style="text-align:center">Operações do dia</h3>
    <div id="dia-body" style="max-height:60vh;overflow:auto;margin:14px 0"></div>
    <div class="modal-actions">
      <button class="btn btn-primary" onclick="fecharModal()">Fechar</button>
    </div>
  </div>
</div>

<script>
const TABELA='posicoes';
let allRows=[], modalId=null;
/* Painel de Sinais (ADR 021): resposta do endpoint `sinais` + mapa id->sinal da posição aberta */
let SINAIS=null, SINAL_POR_ID={};
/* período exibido na tabela de Encerradas: 'AAAA-MM' ou 'all' (null = ainda não definido → mês corrente) */
let PERIODO=null;
const MESES_ABR=['JAN','FEV','MAR','ABR','MAI','JUN','JUL','AGO','SET','OUT','NOV','DEZ'];
let sortState={abertas:{k:'data_inicio',dir:'desc'},enc:{k:'data_final',dir:'desc'}};
let detectedTipo=null;

function sqlToDisplay(d){if(!d)return'—';const[y,m,dd]=d.split('-');return dd+'/'+m+'/'+y;}
function fmtMoney(v){if(v===null||v===undefined||v==='')return'<span class="dashc">—</span>';return'R$ '+Number(v).toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2});}
function fmtMoneyPlain(v){return'R$ '+Number(v).toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2});}
function signMoney(v){const s=v<0?'-':'+';return s+'R$ '+Math.abs(v).toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2});}
/* sentido da operação: C = comprada (padrão) | V = vendida (lançada/descoberta) — na vendida o
   resultado é invertido (você recebeu o prêmio: lucra quando ele cai). */
function isShort(r){return r.direcao==='V';}
function shortMark(r){return isShort(r)?'<sup class="short-star" title="Posição vendida (lançada/descoberta): você recebeu o prêmio na abertura e lucra quando ele cai.">&lowast;</sup>':'';}
function lpR(r){if(r.recebido===null||r.recebido===undefined)return null;const d=r.recebido-r.pago;return isShort(r)?-d:d;}
function calcRes(r){if(r.recebido===null||r.recebido===undefined||!r.pago)return null;const p=(r.recebido-r.pago)/r.pago*100;return isShort(r)?-p:p;}
function resBadge(r){const v=calcRes(r);if(v===null)return'<span class="dashc">—</span>';const fmt=Math.abs(v).toFixed(2).replace('.',',')+' %';if(Math.abs(v)<0.005)return'<span class="b-zero">0,00 %</span>';return v<0?'<span class="b-neg">-'+fmt+'</span>':'<span class="b-pos">+'+fmt+'</span>';}
function showMsg(id,t,type){const el=document.getElementById(id);el.textContent=t;el.className='msg '+(type||'ok');el.style.display='block';setTimeout(()=>{el.style.display='none'},4500);}
/* ── Painel de Sinais: aparência de cada estado ── */
const SINAL_META={
  sinal :{emoji:'🟢',cls:'s-sinal' ,label:'Sinal'},
  alerta:{emoji:'🟡',cls:'s-alerta',label:'Alerta'},
  recuou:{emoji:'↩' ,cls:'s-recuou',label:'Recuou'},
};
function sinalDe(r){return SINAL_POR_ID[r.id]||null;}
function temSinal(r){const s=sinalDe(r);return s&&SINAL_META[s.estado];}
function sinalRank(r){const s=sinalDe(r);const ord={sinal:4,alerta:3,recuou:2,nenhum:1,sem_cotacao:0};return s?(ord[s.estado]??0):-1;}
/* célula da coluna Sinal: chip clicável (com sinal) ou traço discreto */
function sinalCell(r){
  const s=sinalDe(r);
  if(!s)return'<span class="dashc">—</span>';
  const m=SINAL_META[s.estado];
  if(!m)return'<span class="s-none" title="Rentabilidade atual abaixo do gatilho, sem pico histórico relevante.">—</span>';
  const rot=s.rent_atual!=null?(s.rent_atual>=0?'+':'')+s.rent_atual.toFixed(0).replace('.',',')+'%':'';
  return`<button class="sinal-chip ${m.cls}" onclick="abrirSinal(${r.id})" title="${m.label} — clique para o racional de entrada">${m.emoji} ${rot}</button>`;
}
async function req(action,data){const o=data?{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(data)}:{};const r=await fetch('api.php?action='+action+'&tabela='+TABELA,o);if(r.status===401){location.href='/';return{};}return r.json();}
function detectarTipoJS(cod){cod=(cod||'').toUpperCase().trim();if(cod.length<5)return null;const L=cod[4];if(L>='A'&&L<='L')return'CALL';if(L>='M'&&L<='X')return'PUT';return null;}

async function loadAll(){const rows=await req('list');if(!rows)return;allRows=rows;await carregarSinais();renderStats(rows);renderAbertas();montarFiltroPeriodo();renderEncerradas();renderPeriodos();updateTag(rows);}
/* busca o estudo de sinais (read-only). Falha silenciosa: a página funciona sem a coluna. */
async function carregarSinais(){try{const s=await req('sinais');if(s&&s.success){SINAIS=s;SINAL_POR_ID={};(s.abertas||[]).forEach(a=>SINAL_POR_ID[a.id]=a);}else{SINAIS=null;SINAL_POR_ID={};}}catch(e){SINAIS=null;SINAL_POR_ID={};}}
function updateTag(rows){if(!rows.length)return;const ds=rows.filter(r=>r.data_inicio).map(r=>r.data_inicio).sort();const el=document.getElementById('last-update');if(el&&ds.length)el.textContent=sqlToDisplay(ds[ds.length-1]);}

function renderStats(rows){
  const enc=rows.filter(r=>r.status==='Encerrada'),abr=rows.filter(r=>r.status==='Aberta');
  document.getElementById('st-total').textContent=rows.length;
  document.getElementById('st-abertas').textContent=abr.length;
  document.getElementById('st-encerradas').textContent=enc.length;
  document.getElementById('cnt-abertas').textContent=abr.length;
  document.getElementById('cnt-enc').textContent=enc.length;
  const ds=rows.filter(r=>r.data_inicio).map(r=>r.data_inicio).sort();
  document.getElementById('st-desde').textContent=ds.length?' · desde '+sqlToDisplay(ds[0]):'';
  // ── % de acerto (posições no positivo) ──
  const abRec=abr.filter(r=>r.recebido!==null);
  setAcerto('st-acerto-ab', abRec.length?abRec.filter(r=>lpR(r)>0.0001).length/abRec.length*100:null);
  setAcerto('st-acerto-enc', enc.length?enc.filter(r=>lpR(r)>0.0001).length/enc.length*100:null);
  // ── RESULTADOS (SOMA PURA dos percentuais de cada operação, calcRes) ──
  // Nas carteiras simples o preço é só acompanhamento de entrada/saída e não tem
  // significado financeiro ponderável: cada card acumula o percentual das suas
  // operações (o líquido total é o aberto + encerrados), não o ROI ponderado.
  let aPct=0,aN=0;abr.forEach(r=>{const p=calcRes(r);if(p!==null){aPct+=p;aN++;}});
  setPct('st-carteira',aN?aPct:null);                            // P&L aberto %
  let ePct=0,eN=0;enc.forEach(r=>{const p=calcRes(r);if(p!==null){ePct+=p;eN++;}});
  setPct('st-realizado',eN?ePct:null);                           // P&L encerrados %
  setPct('st-liquido',(aN+eN)?aPct+ePct:null);                   // Resultado líquido total %
}
function setAcerto(id,pct){const el=document.getElementById(id);if(!el)return;if(pct===null){el.innerHTML='<span class="dashc">—</span>';return;}el.textContent=pct.toFixed(1).replace('.',',')+'%';}
function setPct(id,pct){
  const el=document.getElementById(id);
  if(pct===null){el.innerHTML='<span class="dashc">—</span>';return;}
  const cls=pct>=0.005?'pos':pct<=-0.005?'neg':'';
  el.className='m-val '+cls;
  el.innerHTML=(pct>=0?'+':'')+pct.toFixed(2).replace('.',',')+'%';
}

function sortRows(rows,st){
  const{k,dir}=st,mul=dir==='asc'?1:-1;
  return rows.slice().sort((a,b)=>{
    let va,vb;
    if(k==='resultado'){va=calcRes(a);vb=calcRes(b);va=va===null?-Infinity:va;vb=vb===null?-Infinity:vb;}
    else if(k==='dias'){va=a.dias!=null?a.dias:diasCorridos(a.data_inicio);vb=b.dias!=null?b.dias:diasCorridos(b.data_inicio);va=va==null?-Infinity:va;vb=vb==null?-Infinity:vb;}
    else if(['pago','recebido'].includes(k)){va=a[k]===null?-Infinity:parseFloat(a[k]);vb=b[k]===null?-Infinity:parseFloat(b[k]);}
    else if(['data_inicio','data_final'].includes(k)){va=a[k]||'';vb=b[k]||'';}
    else{va=(a[k]||'').toString().toLowerCase();vb=(b[k]||'').toString().toLowerCase();}
    if(va<vb)return -1*mul;if(va>vb)return 1*mul;return 0;
  });
}
function markHeader(headId,st){
  document.querySelectorAll('#'+headId+' th').forEach(th=>{
    th.classList.remove('sort-asc','sort-desc');
    const a=th.querySelector('.arr');if(a)a.textContent='▲▼';
    if(th.dataset.k===st.k){th.classList.add(st.dir==='asc'?'sort-asc':'sort-desc');if(a)a.textContent=st.dir==='asc'?'▲':'▼';}
  });
}

function editCell(id,campo,valor,tipo){
  let disp;
  if(valor===null||valor===undefined||valor===''){disp='<span class="dashc">—</span>';}
  else if(tipo==='money'){disp=fmtMoney(valor);}
  else if(tipo==='date'){disp='<span class="num">'+sqlToDisplay(valor)+'</span>';}
  else{disp='<span class="num">'+valor+'</span>';}
  const raw=(valor===null||valor===undefined)?'':String(valor);
  return`<span class="editable" onclick="abrirEdit(this,${id},'${campo}','${raw}','${tipo}')">${disp}<span class="pencil">&#9998;</span></span>`;
}
function abrirEdit(el,id,campo,raw,tipo){
  const itype=tipo==='date'?'date':'number';
  /* step de 1 centavo nas setas (mesma regra da Carteira — ver index.php) */
  const attrs=itype==='number'?`step="0.01" min="0"`:'';
  el.outerHTML=`<span class="edit-box"><input type="${itype}" ${attrs} value="${raw}" id="ie-${id}-${campo}"><button class="icon-btn icon-ok" onclick="salvarCampo(${id},'${campo}')" title="Salvar">&#10003;</button><button class="icon-btn icon-no" onclick="loadAll()" title="Cancelar">&#10005;</button></span>`;
  setTimeout(()=>{const i=document.getElementById('ie-'+id+'-'+campo);if(i){i.focus();if(itype==='number')i.select();i.addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();salvarCampo(id,campo);}else if(e.key==='Escape'){loadAll();}});}},40);
}
async function salvarCampo(id,campo){const v=document.getElementById('ie-'+id+'-'+campo)?.value;if(v===''||v===undefined){loadAll();return;}const r=await req('update_field',{id,campo,valor:v});if(r.success)loadAll();else{alert(r.error||'Erro');loadAll();}}

/* dias corridos da data de início até hoje (posições abertas) */
function diasCorridos(d){if(!d)return null;const[y,m,dd]=d.split('-').map(Number);const ini=new Date(y,m-1,dd);const hoje=new Date();hoje.setHours(0,0,0,0);return Math.max(0,Math.floor((hoje-ini)/86400000));}
function renderAbertas(){
  let rows=allRows.filter(r=>r.status==='Aberta');
  /* toggle "Só com sinal": 🟢/🟡/↩ */
  const soSinal=document.getElementById('f-so-sinal')?.checked;
  if(soSinal)rows=rows.filter(temSinal);
  if(sortState.abertas.k==='sinal'){const mul=sortState.abertas.dir==='asc'?1:-1;rows=rows.slice().sort((a,b)=>(sinalRank(a)-sinalRank(b))*mul);}
  else rows=sortRows(rows,sortState.abertas);
  markHeader('head-abertas',sortState.abertas);
  /* atualiza o toggle: só aparece quando há pelo menos um sinal */
  const comSinal=allRows.filter(r=>r.status==='Aberta'&&temSinal(r)).length;
  const wrap=document.getElementById('sinal-toggle-wrap');
  if(wrap){wrap.style.display=comSinal?'inline-flex':'none';const c=document.getElementById('sinal-cnt');if(c)c.textContent='('+comSinal+')';}
  const tb=document.getElementById('tbody-abertas');
  if(!rows.length){tb.innerHTML='<tr><td colspan="9" style="text-align:center;padding:32px;color:#999">'+(soSinal?'Nenhuma posição aberta com sinal no momento.':'Sem posições abertas.')+'</td></tr>';return;}
  tb.innerHTML=rows.map(r=>{const m=temSinal(r);return`<tr class="${m?'row-'+SINAL_META[sinalDe(r).estado].cls:''}">
    <td><span class="code">${r.codigo}</span>${shortMark(r)}</td>
    <td><span class="${r.estrutura==='PUT'?'badge-put':'badge-call'}">${r.estrutura}</span></td>
    <td>${editCell(r.id,'data_inicio',r.data_inicio,'date')}</td>
    <td class="r"><span class="num">${diasCorridos(r.data_inicio)??'—'}</span></td>
    <td class="r">${editCell(r.id,'pago',r.pago,'money')}</td>
    <td class="r">${editCell(r.id,'recebido',r.recebido,'money')}</td>
    <td class="r">${resBadge(r)}</td>
    <td>${sinalCell(r)}</td>
    <td><div class="act">
      <button class="ibtn enc" onclick="abrirEncerrar(${r.id},'${r.codigo}')" title="Encerrar posição">&#128274;</button>
      <button class="ibtn del" onclick="abrirExcluir(${r.id},'${r.codigo}')" title="Excluir">&#128465;&#65039;</button>
    </div></td>
  </tr>`;}).join('');
}
/* ── filtro de período da tabela de Encerradas (mês corrente por padrão) ── */
function mesCorrente(){const h=new Date();return h.getFullYear()+'-'+String(h.getMonth()+1).padStart(2,'0');}
function rotuloPeriodo(k){const[y,m]=k.split('-');return MESES_ABR[+m-1]+'/'+y;}
function montarFiltroPeriodo(){
  const enc=allRows.filter(r=>r.status==='Encerrada'&&r.data_final);
  const cont={};enc.forEach(r=>{const k=r.data_final.slice(0,7);cont[k]=(cont[k]||0)+1;});
  const atual=mesCorrente();if(!(atual in cont))cont[atual]=0;
  if(PERIODO===null)PERIODO=atual;                        // 1ª carga: abre no mês corrente
  if(PERIODO!=='all'&&!(PERIODO in cont))cont[PERIODO]=0; // mantém o período escolhido na lista
  const chaves=Object.keys(cont).sort((a,b)=>b.localeCompare(a));
  const sel=document.getElementById('f-periodo');
  sel.innerHTML=chaves.map(k=>'<option value="'+k+'"'+(k===PERIODO?' selected':'')+'>'+rotuloPeriodo(k)+' ('+cont[k]+')</option>').join('')
    +'<option value="all"'+(PERIODO==='all'?' selected':'')+'>Todos os períodos ('+enc.length+')</option>';
}
function trocarPeriodo(v){PERIODO=v;renderEncerradas();}

/* ── render ENCERRADAS (respeita o filtro de período; os cards do topo seguem gerais) ── */
function renderEncerradas(){
  const todas=allRows.filter(r=>r.status==='Encerrada');
  const doPeriodo=PERIODO==='all'?todas:todas.filter(r=>(r.data_final||'').slice(0,7)===PERIODO);
  const rows=sortRows(doPeriodo,sortState.enc);
  markHeader('head-enc',sortState.enc);
  const tb=document.getElementById('tbody-enc');
  const bc=document.getElementById('cnt-enc');
  if(bc)bc.textContent=(PERIODO==='all'||rows.length===todas.length)?todas.length:(rows.length+' de '+todas.length);
  if(!rows.length){tb.innerHTML='<tr><td colspan="9" style="text-align:center;padding:32px;color:#999">Nenhuma posição encerrada em '+(PERIODO==='all'?'nenhum período':rotuloPeriodo(PERIODO))+'. Troque o período no filtro acima.</td></tr>';return;}
  tb.innerHTML=rows.map(r=>`<tr>
    <td><span class="code">${r.codigo}</span>${shortMark(r)}</td>
    <td><span class="${r.estrutura==='PUT'?'badge-put':'badge-call'}">${r.estrutura}</span></td>
    <td>${editCell(r.id,'data_inicio',r.data_inicio,'date')}</td>
    <td>${editCell(r.id,'data_final',r.data_final,'date')}</td>
    <td class="r"><span class="num">${r.dias??'—'}</span></td>
    <td class="r">${editCell(r.id,'pago',r.pago,'money')}</td>
    <td class="r">${editCell(r.id,'recebido',r.recebido,'money')}</td>
    <td class="r">${resBadge(r)}</td>
    <td><div class="act">
      <button class="ibtn reab" onclick="abrirReabrir(${r.id},'${r.codigo}')" title="Reabrir">&#128275;</button>
      <button class="ibtn del" onclick="abrirExcluir(${r.id},'${r.codigo}')" title="Excluir">&#128465;&#65039;</button>
    </div></td>
  </tr>`).join('');
}

/* ══ RESULTADO DAS ENCERRADAS POR PERÍODO (Ano > Mês > Dia) ══════════════════
   Só posições ENCERRADAS, agrupadas pela DATA DE ENCERRAMENTO (data_final).
   Na vendida as pontas se invertem: o dinheiro entra na abertura (prêmio = Recebido)
   e sai na recompra (Investido) — assim "Recebido − Investido" é o Rendimento em
   qualquer linha. O Nitro não tem notas de corretagem, logo não há coluna de custos.
   Rentabilidade = SOMA PURA dos percentuais de cada operação (calcRes), NÃO
   Rendimento ÷ Investido: aqui o preço é só acompanhamento de entrada/saída e não
   tem significado financeiro ponderável, então o período acumula o percentual das
   suas operações (o dia soma seus percentuais, o mês soma os dias, o ano os meses). */
function opInvestido(r){return isShort(r)?+r.recebido:+r.pago;}
function opRecebido(r){return isShort(r)?+r.pago:+r.recebido;}
function agregar(rows){
  let inv=0,rec=0,pctSum=0,nPct=0;
  rows.forEach(r=>{inv+=opInvestido(r);rec+=opRecebido(r);const p=calcRes(r);if(p!==null){pctSum+=p;nPct++;}});
  const rend=rec-inv;
  return{inv,rec,rend,pct:nPct?pctSum:null,n:rows.length};
}
function lpBadge(v){if(Math.abs(v)<0.005)return'<span class="num">R$ 0,00</span>';return'<span class="num" style="color:'+(v<0?'var(--neg)':'var(--pos)')+'">'+signMoney(v)+'</span>';}
function pctBadge(p){if(p===null)return'<span class="dashc">—</span>';const fmt=Math.abs(p).toFixed(2).replace('.',',')+' %';if(Math.abs(p)<0.005)return'<span class="b-zero">0,00 %</span>';return p<0?'<span class="b-neg">-'+fmt+'</span>':'<span class="b-pos">+'+fmt+'</span>';}
function celulasPeriodo(a){
  return'<td class="r"><span class="num">'+fmtMoneyPlain(a.inv)+'</span></td>'
      +'<td class="r"><span class="num">'+fmtMoneyPlain(a.rec)+'</span></td>'
      +'<td class="r">'+lpBadge(a.rend)+'</td>'
      +'<td class="r">'+pctBadge(a.pct)+'</td>';
}
function ops(n){return'<span class="pr-ops">'+n+(n>1?' ops':' op')+'</span>';}
function renderPeriodos(){
  const box=document.getElementById('periodo-anos');
  const enc=allRows.filter(r=>r.status==='Encerrada'&&r.data_final&&r.recebido!==null);
  if(!enc.length){box.innerHTML='<div class="section pr-vazio">Nenhuma posição encerrada ainda.</div>';return;}
  const anos={};
  enc.forEach(r=>{const y=r.data_final.slice(0,4),m=r.data_final.slice(5,7),d=r.data_final.slice(8,10);
    anos[y]=anos[y]||{};anos[y][m]=anos[y][m]||{};(anos[y][m][d]=anos[y][m][d]||[]).push(r);});
  const desc=(a,b)=>b.localeCompare(a);
  const anoAtual=String(new Date().getFullYear());   // o ano corrente ja nasce aberto (meses a vista)
  box.innerHTML=Object.keys(anos).sort(desc).map(y=>{
    const aberto=(y===anoAtual);
    const meses=Object.keys(anos[y]).sort(desc);
    const rowsAno=[];meses.forEach(m=>Object.keys(anos[y][m]).forEach(d=>rowsAno.push(...anos[y][m][d])));
    let linhas='<tr class="pr-ano'+(aberto?' open':'')+'" onclick="togglePer(this,\'m-'+y+'\')">'
      +'<td class="pr-lbl"><span class="pr-chev">&#9662;</span><strong>'+y+'</strong>'+ops(rowsAno.length)+'</td>'
      +celulasPeriodo(agregar(rowsAno))+'</tr>';
    meses.forEach(m=>{
      const dias=Object.keys(anos[y][m]).sort(desc);
      const rowsMes=[];dias.forEach(d=>rowsMes.push(...anos[y][m][d]));
      linhas+='<tr class="pr-mes m-'+y+(aberto?' show':'')+'" data-sub="d-'+y+m+'" onclick="togglePer(this,\'d-'+y+m+'\')">'
        +'<td class="pr-lbl"><span class="pr-chev">&#9662;</span>'+MESES_ABR[+m-1]+ops(rowsMes.length)+'</td>'
        +celulasPeriodo(agregar(rowsMes))+'</tr>';
      dias.forEach(d=>{
        linhas+='<tr class="pr-dia d-'+y+m+'">'
          +'<td class="pr-lbl"><button type="button" class="pr-dia-link" onclick="abrirDiaDetalhe(\''+y+'-'+m+'-'+d+'\')" title="Ver o que foi negociado neste dia">'+d+'/'+m+'</button>'+ops(anos[y][m][d].length)+'</td>'
          +celulasPeriodo(agregar(anos[y][m][d]))+'</tr>';
      });
    });
    return'<div class="section pr-section"><div class="tbl-wrap"><table class="pr-tbl"><thead><tr>'
      +'<th>Período</th><th class="r">Investido</th><th class="r">Recebido</th>'
      +'<th class="r">Rendimento</th><th class="r">Rentabilidade</th></tr></thead><tbody>'+linhas+'</tbody></table></div></div>';
  }).join('');
}
/* Modal "o que foi negociado no dia": lista as ENCERRADAS cuja data_final é o dia
   clicado (mesma base do bloco por período). Rendimento e Resultado respeitam a
   vendida (lpR/resBadge invertem o sinal); o rodapé traz a SOMA PURA do %. */
function abrirDiaDetalhe(ymd){
  const ops=allRows.filter(r=>r.status==='Encerrada'&&(r.data_final||'').slice(0,10)===ymd);
  const [Y,M,D]=ymd.split('-');
  document.getElementById('dia-titulo').innerHTML='&#128197; Encerradas em '+D+'/'+M+'/'+Y
    +' <span style="font-weight:400;color:var(--muted)">· '+ops.length+(ops.length>1?' operações':' operação')+'</span>';
  const body=document.getElementById('dia-body');
  if(!ops.length){body.innerHTML='<p style="text-align:center;color:#999;padding:20px">Nada encontrado neste dia.</p>';}
  else{
    let pctSum=0,rendSum=0;
    const linhas=ops.map(r=>{const rend=lpR(r);const p=calcRes(r);if(p!==null)pctSum+=p;if(rend!==null)rendSum+=rend;
      return '<tr>'
        +'<td><span class="code">'+r.codigo+'</span>'+shortMark(r)+'</td>'
        +'<td><span class="'+(r.estrutura==='PUT'?'badge-put':'badge-call')+'">'+r.estrutura+'</span></td>'
        +'<td class="r"><span class="num">'+fmtMoney(r.pago)+'</span></td>'
        +'<td class="r"><span class="num">'+(r.recebido!=null?fmtMoney(r.recebido):'—')+'</span></td>'
        +'<td class="r">'+(rend!=null?lpBadge(rend):'<span class="dashc">—</span>')+'</td>'
        +'<td class="r">'+resBadge(r)+'</td>'
      +'</tr>';}).join('');
    body.innerHTML='<table class="dia-tbl"><thead><tr>'
      +'<th>Código</th><th>Tipo</th><th class="r">Pago</th><th class="r">Recebido</th><th class="r">Rendimento</th><th class="r">Resultado</th>'
      +'</tr></thead><tbody>'+linhas+'</tbody>'
      +'<tfoot><tr><td colspan="4" class="r"><strong>Soma pura do %</strong></td><td class="r">'+lpBadge(rendSum)+'</td><td class="r">'+pctBadge(pctSum)+'</td></tr></tfoot>'
      +'</table>';
  }
  document.getElementById('modal-dia').classList.add('show');
}
function togglePer(tr,cls){
  const abrir=!tr.classList.contains('open');
  tr.classList.toggle('open',abrir);
  const tb=tr.closest('tbody');
  tb.querySelectorAll('tr.'+cls).forEach(x=>{
    x.classList.toggle('show',abrir);
    if(!abrir&&x.classList.contains('pr-mes')){        // fechar o ano fecha os meses e os dias
      x.classList.remove('open');
      const sub=x.getAttribute('data-sub');
      if(sub)tb.querySelectorAll('tr.'+sub).forEach(y=>y.classList.remove('show'));
    }
  });
}

function bindSort(headId,key){
  document.querySelectorAll('#'+headId+' th.sortable').forEach(th=>{
    th.addEventListener('click',()=>{
      const k=th.dataset.k,st=sortState[key];
      if(st.k===k)st.dir=st.dir==='asc'?'desc':'asc';else{st.k=k;st.dir='asc';}
      key==='abertas'?renderAbertas():renderEncerradas();
    });
  });
}


function atualizarTipo(){
  const code=document.getElementById('n-cod').value.toUpperCase();
  const t=detectarTipoJS(code);
  const hint=document.getElementById('n-tipo-hint'),badge=document.getElementById('n-tipo-badge'),tag=document.getElementById('n-tipo-auto-tag'),sel=document.getElementById('n-est');
  if(t){detectedTipo=t;badge.textContent=t;badge.className='tipo-badge '+(t==='PUT'?'badge-put':'badge-call');badge.style.display='inline-block';tag.style.display='inline';hint.style.display='none';sel.style.display='none';sel.classList.remove('invalid');}
  else if(code.length>=5){detectedTipo=null;badge.style.display='none';tag.style.display='none';hint.style.display='none';sel.style.display='inline-block';}
  else{detectedTipo=null;badge.style.display='none';tag.style.display='none';hint.style.display='inline';sel.style.display='none';}
}
async function criarPosicao(){
  const cEl=document.getElementById('n-cod'),dEl=document.getElementById('n-data'),pEl=document.getElementById('n-pago'),sEl=document.getElementById('n-est');
  [cEl,dEl,pEl,sEl].forEach(x=>x.classList.remove('invalid'));
  const cod=cEl.value.trim().toUpperCase(),data=dEl.value,pago=pEl.value,rec=document.getElementById('n-rec').value;
  const tipo=detectedTipo||sEl.value;
  let falhou=false;
  if(!cod){cEl.classList.add('invalid');falhou=true;}
  if(!tipo){sEl.style.display='inline-block';sEl.classList.add('invalid');falhou=true;}
  if(!data){dEl.classList.add('invalid');falhou=true;}
  if(!pago){pEl.classList.add('invalid');falhou=true;}
  if(falhou){showMsg('create-msg','Preencha código, data e valor pago (recebido é opcional).','err');return;}
  const direcao=document.getElementById('n-dir').value;
  const payload={codigo:cod,estrutura:tipo,direcao,data_inicio:data,pago:pago};if(rec)payload.recebido=rec;
  const r=await req('create',payload);
  if(r.success){showMsg('create-msg','Posição '+cod+' ('+r.estrutura+(direcao==='V'?' · vendida':'')+') adicionada.','ok');['n-cod','n-data','n-pago','n-rec'].forEach(id=>document.getElementById(id).value='');sEl.selectedIndex=0;document.getElementById('n-dir').selectedIndex=0;atualizarTipo();loadAll();}
  else showMsg('create-msg',r.error||'Erro ao criar.','err');
}

function abrirEncerrar(id,cod){modalId=id;const r=allRows.find(x=>x.id===id);document.getElementById('mc-rec-lbl').textContent=(r&&isShort(r))?'Recompra (preço pago por unidade para zerar a venda)':'Recebido (preço de venda por unidade)';document.getElementById('mc-code').textContent=cod;document.getElementById('mc-data').value=new Date().toISOString().split('T')[0];document.getElementById('mc-rec').value='';document.getElementById('modal-close').classList.add('show');setTimeout(()=>document.getElementById('mc-rec').focus(),60);}
async function confirmarEncerrar(){const rec=document.getElementById('mc-rec').value,data=document.getElementById('mc-data').value;if(!rec){alert('Informe o valor recebido.');return;}const r=await req('close',{id:modalId,recebido:rec,data_final:data});if(r.success){fecharModal();loadAll();}else alert(r.error||'Erro.');}
function abrirReabrir(id,cod){modalId=id;document.getElementById('mr-code').textContent=cod;document.getElementById('modal-reopen').classList.add('show');}
async function confirmarReabrir(){const r=await req('reopen',{id:modalId});if(r.success){fecharModal();loadAll();}else alert(r.error||'Erro.');}
function abrirExcluir(id,cod){modalId=id;document.getElementById('md-code').textContent=cod;document.getElementById('modal-del').classList.add('show');}
async function confirmarExcluir(){const r=await req('delete',{id:modalId});if(r.success){fecharModal();loadAll();}else alert(r.error||'Erro.');}
function fecharModal(){document.querySelectorAll('.modal-overlay').forEach(m=>m.classList.remove('show'));modalId=null;}

/* ══ RACIONAL DE ENTRADA (Painel de Sinais — ADR 021) ═══════════════════════════
   Nenhum texto livre: todo número vem do endpoint `sinais` (cálculo do banco).
   Manchete = taxa AGREGADA (robusta). Precedente do ativo-mãe = cor qualitativa,
   SEMPRE com o n à mostra + aviso de amostra pequena. */
const N_MIN_AMOSTRA=8;
function pct0(v){return(v>=0?'+':'')+Number(v).toFixed(0).replace('.',',')+'%';}
function abrirSinal(id){
  if(!SINAIS){alert('Estudo de sinais indisponível.');return;}
  const r=allRows.find(x=>x.id===id), s=SINAL_POR_ID[id];
  if(!r||!s){return;}
  const cfg=SINAIS.config, m=SINAL_META[s.estado]||{emoji:'•',cls:'',label:'—'};
  /* universo agregado conforme o gatilho do estado: sinal→50% ; alerta/recuou→40% */
  const uni=(s.estado==='sinal')?SINAIS.agregado.sinal:SINAIS.agregado.alerta;
  const gat=(s.estado==='sinal')?cfg.gatilho_sinal:cfg.gatilho_alerta;
  const prec=(SINAIS.por_ativo||[]).find(a=>a.ticker===s.ticker);

  document.getElementById('sm-titulo').innerHTML=
    `<span class="sinal-chip ${m.cls}" style="pointer-events:none">${m.emoji} ${m.label}</span> `+
    `<span class="code">${r.codigo}</span> <span class="sinal-tk">${s.ticker}</span>`;

  /* 1) estado atual */
  let recuoNota='';
  if(s.estado==='recuou'){
    const q=prec&&prec.recupera!==null
      ?(prec.recupera
        ?`No histórico da Sala, <strong>${s.ticker}</strong> já recuou abaixo do gatilho e <strong>voltou</strong> a atingir o alvo — pode ser entrada mais barata.`
        :`No histórico da Sala, quando <strong>${s.ticker}</strong> recuou, <strong>não voltou</strong> ao alvo — trate como aviso.`)
      :`Amostra insuficiente de <strong>${s.ticker}</strong> para dizer se costuma voltar ao alvo — ambivalente por natureza.`;
    recuoNota=`<p class="sinal-recuo">↩ O pico de vida (${pct0(s.pico_vida)}) passou do gatilho, mas a cotação atual caiu abaixo. ${q}</p>`;
  }
  const kpis=`<div class="sinal-kpis">
    <div><span class="k-lbl">Rentabilidade atual</span><span class="k-val ${s.rent_atual>=0?'pos':'neg'}">${s.rent_atual!=null?pct0(s.rent_atual):'—'}</span></div>
    <div><span class="k-lbl">Pico de vida</span><span class="k-val">${s.pico_vida!=null?pct0(s.pico_vida):'—'}</span></div>
    <div><span class="k-lbl">Dias em carteira</span><span class="k-val">${s.dias_vida}</span></div>
    <div><span class="k-lbl">Alvo (Nitro)</span><span class="k-val">≥ ${pct0(cfg.alvo).replace('+','')}</span></div>
  </div>`;

  /* 2) manchete — taxa agregada */
  const taxaTxt=uni.taxa!=null?Math.round(uni.taxa*100)+'%':'—';
  const dias=uni.dias_ate_alvo||{};
  const diasTxt=(dias.mediana!=null)?`mediana <strong>${dias.mediana} dias</strong> (${dias.min}–${dias.max})`:'sem casos suficientes';
  const manchete=`<div class="sinal-manchete">
    <div class="sm-taxa">${taxaTxt}</div>
    <div class="sm-txt">das <strong>${uni.n}</strong> recomendações que cruzaram <strong>${pct0(gat).replace('+','')}</strong> chegaram a atingir o <strong>alvo ≥ ${pct0(cfg.alvo).replace('+','')}</strong>.
      <br>Pico mediano <strong>${uni.pico_mediano!=null?pct0(uni.pico_mediano):'—'}</strong> (máx ${uni.pico_max!=null?pct0(uni.pico_max):'—'}). Do cruzamento ao alvo: ${diasTxt}.</div>
  </div>`;

  /* 3) precedente do ativo-mãe (sempre com n + aviso de amostra pequena) */
  let precBloco;
  if(prec){
    const pouco=prec.n<N_MIN_AMOSTRA;
    precBloco=`<div class="sinal-prec">
      <span class="sp-frac">${prec.atingiram_alvo}/${prec.n}</span>
      <span class="sp-txt">recomendações de <strong>${prec.ticker}</strong> que cruzaram o gatilho chegaram ao alvo (pico mediano ${prec.pico_mediano!=null?pct0(prec.pico_mediano):'—'}).</span>
      ${pouco?`<span class="sp-aviso">⚠ Amostra pequena (n=${prec.n}): use como cor qualitativa, não como probabilidade.</span>`:''}
    </div>`;
  }else{
    precBloco=`<div class="sinal-prec sp-vazio">Sem histórico de <strong>${s.ticker}</strong> cruzando o gatilho na base — vá pela taxa agregada acima.</div>`;
  }

  /* 4) teste de cotação ao vivo (recalcula no cliente, NÃO grava) */
  const teste=`<div class="sinal-teste">
    <label>Testar cotação ao vivo <span class="st-hint">(não grava — só recalcula o sinal)</span></label>
    <div class="st-row">
      <span class="st-pago">Pago R$ ${Number(r.pago).toLocaleString('pt-BR',{minimumFractionDigits:2})}</span>
      <input type="number" step="0.01" min="0" id="st-preco" placeholder="preço atual" oninput="sinalTeste(${id})">
      <span id="st-out" class="st-out"></span>
    </div>
  </div>`;

  /* cobertura (honestidade de dado) */
  const cob=SINAIS.cobertura;
  const cobTxt=cob.de?`Base: ${cob.base_total} compradas com cobertura total (${sqlToDisplay(cob.de)}–${sqlToDisplay(cob.ate)})${cob.fora_da_base?`, ${cob.fora_da_base} fora da base por falta de histórico`:''}.`:'';

  document.getElementById('sm-body').innerHTML=
    kpis+recuoNota+
    `<h4 class="sinal-h">Base histórica da Sala (agregado — robusto)</h4>`+manchete+
    `<h4 class="sinal-h">Precedentes de ${s.ticker}</h4>`+precBloco+
    teste+
    `<p class="sinal-cob">${cobTxt}</p>`;
  document.getElementById('modal-sinal').classList.add('show');
}
/* recalcula rentabilidade + estado a partir de um preço digitado (sem gravar) */
function sinalTeste(id){
  const r=allRows.find(x=>x.id===id), s=SINAL_POR_ID[id], cfg=SINAIS.config;
  const out=document.getElementById('st-out'), v=parseFloat(document.getElementById('st-preco').value);
  if(!r||isNaN(v)||v<=0||!r.pago){out.textContent='';out.className='st-out';return;}
  const rent=(v-r.pago)/r.pago*100;
  const pico=Math.max(rent, s.pico_vida??-Infinity);
  let est;
  if(rent>=cfg.gatilho_sinal)est='sinal';
  else if(rent>=cfg.gatilho_alerta)est='alerta';
  else if(pico>=cfg.gatilho_alerta)est='recuou';
  else est='nenhum';
  const m=SINAL_META[est]||{emoji:'—',label:'sem sinal'};
  out.className='st-out '+(m.cls||'');
  out.innerHTML=`${m.emoji} ${m.label||'—'} · <strong>${pct0(rent)}</strong>`;
}

/* ── cotações OpLab ── */
let COT=[];
async function buscarCotacoes(){
  showMsg('cot-msg','Buscando cotações na OpLab…','ok');
  const r=await req('quote_preview');
  if(!r||!r.success){showMsg('cot-msg',(r&&r.error)||'Erro ao buscar cotações.','err');return;}
  COT=r.itens||[];
  if(!COT.length){showMsg('cot-msg','Não há posições abertas para cotar.','err');return;}
  renderCot(r.ref);
  document.getElementById('modal-cot').classList.add('show');
}
function renderCot(ref){
  const comPreco=COT.filter(i=>i.preco!==null).length;
  let refTxt='';
  if(ref){if(/^\d{4}-\d{2}-\d{2}/.test(ref)){const[y,m,d]=ref.slice(0,10).split('-');refTxt=' · pregão '+d+'/'+m+'/'+y;}else{const dt=new Date(ref);if(!isNaN(dt))refTxt=' · ref: '+dt.toLocaleString('pt-BR');}}
  document.getElementById('cot-info').innerHTML='Fechamento do último pregão · <b>'+comPreco+'</b> de <b>'+COT.length+'</b> com preço'+refTxt;
  document.getElementById('cot-list').innerHTML='<table style="width:100%;font-size:12.5px;min-width:0"><tbody>'+COT.map((i,ix)=>{
    const has=i.preco!==null;
    return'<tr><td style="padding:7px 10px;border-bottom:1px solid var(--border)"><input type="checkbox" class="cotchk" data-i="'+ix+'" '+(has?'checked':'disabled')+'></td><td style="padding:7px 10px;border-bottom:1px solid var(--border)"><span class="code">'+i.codigo+'</span> <span style="color:var(--muted);font-size:10px">'+i.underlying+'</span></td><td style="padding:7px 10px;text-align:right;border-bottom:1px solid var(--border)" class="num">'+(has?('R$ '+Number(i.preco).toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2})):'<span class="dashc">sem negócio</span>')+'</td></tr>';
  }).join('')+'</tbody></table>';
}
async function aplicarCotacoes(){
  const precos={};
  document.querySelectorAll('.cotchk:checked').forEach(c=>{const i=COT[c.dataset.i];precos[i.codigo]=i.preco;});
  if(!Object.keys(precos).length){fecharModal();return;}
  const r=await req('apply_quotes',{precos});
  fecharModal();
  if(r&&r.success){showMsg('cot-msg',r.updated+' cotação(ões) aplicada(s).','ok');loadAll();}
  else showMsg('cot-msg',(r&&r.error)||'Erro ao aplicar.','err');
}
document.querySelectorAll('.modal-overlay').forEach(m=>m.addEventListener('click',e=>{if(e.target===m)fecharModal();}));
document.addEventListener('keydown',e=>{if(e.key==='Escape')fecharModal();});
document.getElementById('n-cod').addEventListener('input',atualizarTipo);

bindSort('head-abertas','abertas');
bindSort('head-enc','enc');
loadAll();
</script>
</body>
</html>
