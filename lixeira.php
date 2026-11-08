<?php
require_once 'auth.php';
exigir_login();
$TITULO_PG = 'Lixeira';
$CSS_PG    = 'lixeira';
require __DIR__ . '/partials/head.php';
?>
<body>
<?php $ATIVO='lixeira'; require __DIR__.'/partials/nav.php'; ?>

<div class="wrap">
  <div class="page-head">
    <h1>&#128465;&#65039; Lixeira <span class="count" id="total-count">&mdash;</span></h1>
    <button class="btn btn-danger-solid" id="btn-empty-all" onclick="confirmarLimpar('all')">Limpar lixeira</button>
  </div>
  <div class="msg" id="msg"></div>

  <!-- CARTEIRA (Pessoal) -->
  <div class="section pessoal">
    <div class="section-header">
      <h2>&#128202; Carteira <span class="pill pessoal">Pessoal</span></h2>
      <div class="sp">
        <button class="btn btn-danger btn-sm" id="btn-del-carteira" onclick="excluirSelecionados('carteira')" disabled>Excluir selecionados</button>
        <button class="btn btn-ghost btn-sm" onclick="confirmarLimpar('carteira')">Limpar esta</button>
      </div>
    </div>
    <div class="tbl-wrap">
      <table>
        <thead><tr>
          <th class="c"><input type="checkbox" id="all-carteira" onchange="toggleAll('carteira',this.checked)"></th>
          <th>Código</th><th>Tipo</th><th>Início</th><th>Final</th>
          <th class="r">Preço Médio</th><th class="r">Lote</th><th class="r">Recebido</th>
          <th>Status</th><th>Excluído em</th><th>Ações</th>
        </tr></thead>
        <tbody id="tbody-carteira"></tbody>
      </table>
    </div>
  </div>

  <!-- NITRO (Terceiros) -->
  <div class="section terceiros">
    <div class="section-header">
      <h2>&#9889; Nitro <span class="pill terceiros">Terceiros</span></h2>
      <div class="sp">
        <button class="btn btn-danger btn-sm" id="btn-del-posicoes" onclick="excluirSelecionados('posicoes')" disabled>Excluir selecionados</button>
        <button class="btn btn-ghost btn-sm" onclick="confirmarLimpar('posicoes')">Limpar esta</button>
      </div>
    </div>
    <div class="tbl-wrap">
      <table>
        <thead><tr>
          <th class="c"><input type="checkbox" id="all-posicoes" onchange="toggleAll('posicoes',this.checked)"></th>
          <th>Código</th><th>Tipo</th><th>Início</th><th>Final</th>
          <th class="r">Pago</th><th class="r">Recebido</th>
          <th>Status</th><th>Excluído em</th><th>Ações</th>
        </tr></thead>
        <tbody id="tbody-posicoes"></tbody>
      </table>
    </div>
  </div>
</div>

<div class="modal-overlay" id="modal-confirm">
  <div class="modal">
    <h3 id="cf-title">Confirmar</h3>
    <p id="cf-text"></p>
    <div class="modal-actions">
      <button class="btn btn-ghost" onclick="fecharModal()">Cancelar</button>
      <button class="btn btn-danger-solid" id="cf-ok">Confirmar</button>
    </div>
  </div>
</div>

<script>
let TRASH=[];
function sqlToDisplay(d){if(!d)return'<span class="dashc">—</span>';const[y,m,dd]=d.split('-');return dd+'/'+m+'/'+y;}
function fmtDateTime(s){if(!s)return'—';const d=new Date(s.replace(' ','T'));if(isNaN(d))return s;const p=n=>String(n).padStart(2,'0');return p(d.getDate())+'/'+p(d.getMonth()+1)+'/'+d.getFullYear()+' '+p(d.getHours())+':'+p(d.getMinutes());}
function fmtMoney(v){if(v===null||v===undefined)return'<span class="dashc">—</span>';return'R$ '+Number(v).toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2});}
function showMsg(t,type){const el=document.getElementById('msg');el.textContent=t;el.className='msg '+(type||'ok');el.style.display='block';setTimeout(()=>{el.style.display='none'},4500);}
async function req(action,data){const o=data?{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(data)}:{};const r=await fetch('api.php?action='+action,o);if(r.status===401){location.href='/';return{};}return r.json();}

async function load(){
  const rows=await req('trash_list');
  TRASH=Array.isArray(rows)?rows:[];
  document.getElementById('total-count').textContent=TRASH.length;
  document.getElementById('btn-empty-all').disabled=!TRASH.length;
  renderSecao('carteira');
  renderSecao('posicoes');
}
function tipoBadge(e){return e?'<span class="'+(e==='PUT'?'badge-put':'badge-call')+'">'+e+'</span>':'<span class="dashc">—</span>';}

function renderSecao(origem){
  const rows=TRASH.filter(r=>r.origem===origem);
  const tb=document.getElementById('tbody-'+origem);
  const cols=origem==='carteira'?11:10;
  if(!rows.length){tb.innerHTML='<tr><td colspan="'+cols+'"><div class="empty">Nada na lixeira.</div></td></tr>';syncBtn(origem);document.getElementById('all-'+origem).checked=false;return;}
  tb.innerHTML=rows.map(r=>{
    const meio=origem==='carteira'
      ? `<td class="r"><span class="num">${fmtMoney(r.preco_medio)}</span></td><td class="r"><span class="num">${r.lote??'—'}</span></td>`
      : `<td class="r"><span class="num">${fmtMoney(r.pago)}</span></td>`;
    return`<tr>
      <td class="c"><input type="checkbox" class="chk-${origem}" value="${r.id}" onchange="syncBtn('${origem}')"></td>
      <td><span class="code">${r.codigo||'—'}</span>${r.direcao==='V'?'<sup style="color:#0d9488;font-weight:800;cursor:help" title="Posição vendida (lançada/descoberta)">&lowast;</sup>':''}</td>
      <td>${tipoBadge(r.estrutura)}</td>
      <td><span class="num">${sqlToDisplay(r.data_inicio)}</span></td>
      <td><span class="num">${sqlToDisplay(r.data_final)}</span></td>
      ${meio}
      <td class="r"><span class="num">${fmtMoney(r.recebido)}</span></td>
      <td><span class="st-tag">${r.status||'—'}</span></td>
      <td><span class="num">${fmtDateTime(r.excluido_em)}</span></td>
      <td><div class="act">
        <button class="ibtn reab" onclick="restaurar(${r.id})" title="Restaurar">&#8634;</button>
        <button class="ibtn del" onclick="purgarUm(${r.id})" title="Excluir definitivamente">&#128465;&#65039;</button>
      </div></td>
    </tr>`;}).join('');
  syncBtn(origem);
  document.getElementById('all-'+origem).checked=false;
}
function selecionados(origem){return Array.from(document.querySelectorAll('.chk-'+origem+':checked')).map(c=>parseInt(c.value));}
function syncBtn(origem){const n=selecionados(origem).length;const b=document.getElementById('btn-del-'+origem);b.disabled=!n;b.textContent=n?`Excluir selecionados (${n})`:'Excluir selecionados';}
function toggleAll(origem,checked){document.querySelectorAll('.chk-'+origem).forEach(c=>c.checked=checked);syncBtn(origem);}

async function restaurar(id){const r=await req('trash_restore',{id});if(r.success){showMsg('Item restaurado.','ok');load();}else showMsg(r.error||'Erro ao restaurar.','err');}

let pendente=null;
function abrirModal(title,text,onOk){document.getElementById('cf-title').textContent=title;document.getElementById('cf-text').innerHTML=text;pendente=onOk;document.getElementById('modal-confirm').classList.add('show');}
function fecharModal(){document.getElementById('modal-confirm').classList.remove('show');pendente=null;}
document.getElementById('cf-ok').addEventListener('click',()=>{if(pendente)pendente();});
document.getElementById('modal-confirm').addEventListener('click',e=>{if(e.target.id==='modal-confirm')fecharModal();});
document.addEventListener('keydown',e=>{if(e.key==='Escape')fecharModal();});

function purgarUm(id){abrirModal('Excluir definitivamente','Esta ação remove o item do banco de dados <strong>permanentemente</strong>. Continuar?',async()=>{const r=await req('trash_purge',{ids:[id]});fecharModal();if(r.success){showMsg('Item excluído definitivamente.','ok');load();}else showMsg(r.error||'Erro.','err');});}
function excluirSelecionados(origem){const ids=selecionados(origem);if(!ids.length)return;abrirModal('Excluir selecionados',`Excluir <strong>${ids.length}</strong> item(ns) <strong>permanentemente</strong> do banco de dados?`,async()=>{const r=await req('trash_purge',{ids});fecharModal();if(r.success){showMsg(r.removidos+' item(ns) excluído(s) definitivamente.','ok');load();}else showMsg(r.error||'Erro.','err');});}
function confirmarLimpar(escopo){
  const txt=escopo==='all'?'Esvaziar <strong>toda</strong> a lixeira (Carteira + Nitro) permanentemente?':'Esvaziar a lixeira <strong>'+(escopo==='carteira'?'da Carteira (Pessoal)':'do Nitro (Terceiros)')+'</strong> permanentemente?';
  abrirModal('Limpar lixeira',txt,async()=>{const payload=escopo==='all'?{}:{origem:escopo};const r=await req('trash_empty',payload);fecharModal();if(r.success){showMsg('Lixeira limpa.','ok');load();}else showMsg(r.error||'Erro.','err');});
}

load();
</script>
</body>
</html>
