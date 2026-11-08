<?php
require_once 'auth.php';
exigir_login();
$TITULO_PG = 'Importar Nota';
$CSS_PG    = 'importar';
require __DIR__ . '/partials/head.php';
?>
<body>
<?php $ATIVO='importar'; require __DIR__.'/partials/nav.php'; ?>

<div class="wrap">
  <h1>&#128229; Importar Nota de Corretagem <span class="pill">Carteira</span></h1>
  <div class="sub">Envie o PDF da nota (ex.: RICO). O sistema lê <strong>no seu navegador</strong>, mostra o que entendeu para você conferir, e só então lança na Carteira — com as taxas. O Nitro não é afetado.</div>

  <!-- 1) UPLOAD -->
  <div class="card pessoal" id="card-upload">
    <div class="card-title">1 · Selecionar PDF</div>
    <div class="upload-row">
      <div>
        <div class="dropzone" id="dropzone" onclick="document.getElementById('file').click()">
          <div id="dz-text">&#128206; Clique ou arraste o PDF da nota aqui</div>
        </div>
        <input type="file" id="file" accept="application/pdf,.pdf" class="hide">
      </div>
      <div>
        <label>Senha do PDF</label>
        <input type="password" id="senha" class="mono" placeholder="(da Configuração)" autocomplete="new-password">
        <button class="btn btn-primary" id="btn-ler" onclick="lerPDF()" style="margin-top:10px;width:100%" disabled>Ler PDF</button>
      </div>
    </div>
    <div class="spinner" id="spin"><span class="dot"></span> Lendo o PDF…</div>
    <div class="msg" id="up-msg"></div>
  </div>

  <!-- 2) PREVIEW -->
  <div class="card pessoal hide" id="card-preview">
    <div class="card-title">2 · Conferir e ajustar</div>
    <div class="card-desc">Revise os negócios e as taxas. Desmarque o que não quiser importar, corrija valores se preciso. Vendas tentam <strong>encerrar</strong> a posição comprada de mesmo código; se não houver, a venda vira uma <strong>posição vendida (lançada/descoberta)</strong> — você recebeu o prêmio.</div>

    <div class="fields-grid">
      <div class="field"><label>Nr. nota</label><input type="text" id="n-nr" class="mono"></div>
      <div class="field"><label>Data pregão</label><input type="date" id="n-data"></div>
      <div class="field"><label>Corretora</label><input type="text" id="n-cor" class="mono"></div>
      <div class="field"><label>Valor das operações</label><input type="text" id="n-valop" class="mono"></div>
    </div>
    <div class="fields-grid">
      <div class="field"><label>Taxa liquidação</label><input type="text" id="n-liq" class="mono fee"></div>
      <div class="field"><label>Taxa registro</label><input type="text" id="n-reg" class="mono fee"></div>
      <div class="field"><label>Emolumentos</label><input type="text" id="n-emol" class="mono fee"></div>
      <div class="field"><label>Total custos/despesas</label><input type="text" id="n-cust" class="mono fee"></div>
    </div>
    <div class="fields-grid">
      <div class="field"><label>&#931; Total de taxas</label><input type="text" id="n-tax" class="mono" style="font-weight:600"></div>
      <div class="field"><label>Líquido da nota</label><input type="text" id="n-liquido" class="mono"></div>
    </div>

    <div class="summary">
      <span>Negócios: <b id="sm-itens">0</b></span>
      <span>Comprar/abrir: <b id="sm-abrir">0</b></span>
      <span>Vender/encerrar: <b id="sm-enc">0</b></span>
    </div>

    <div class="tbl-wrap">
      <table>
        <thead><tr>
          <th class="c">Imp.</th><th>Código</th><th>Tipo</th><th>Ação</th><th class="c">C/V</th>
          <th>Lote</th><th>Preço</th><th>Data</th>
        </tr></thead>
        <tbody id="itens-body"></tbody>
      </table>
    </div>

    <details class="raw"><summary>Ver texto extraído do PDF (debug)</summary><pre id="raw-text"></pre></details>

    <div class="actions">
      <button class="btn btn-ghost" onclick="cancelar()">Cancelar</button>
      <button class="btn btn-ok" id="btn-confirm" onclick="confirmar()">&#10003; Confirmar importação</button>
    </div>
    <div class="msg" id="pv-msg"></div>
  </div>

  <!-- 3) HISTÓRICO -->
  <div class="card">
    <div class="card-title">&#128220; Notas importadas <span style="font-weight:400;color:var(--muted);font-size:11px">(taxas acumuladas)</span></div>
    <div class="tbl-wrap" style="margin-top:8px">
      <table class="hist">
        <thead><tr><th>Nr.</th><th>Corretora</th><th>Pregão</th><th class="num" style="text-align:right">Operações</th><th class="num" style="text-align:right">Taxas</th><th class="num" style="text-align:right">Líquido</th><th>Itens</th><th></th></tr></thead>
        <tbody id="hist-body"></tbody>
        <tfoot id="hist-foot"></tfoot>
      </table>
    </div>
  </div>
</div>

<!-- MODAL: detalhe da nota -->
<div class="nota-ov" id="nota-ov" onclick="if(event.target===this)fecharNota()">
  <div class="nota-modal" role="dialog" aria-modal="true">
    <div class="nota-mhead">
      <div class="nota-mtitle">Nota <span id="dn-nr">—</span></div>
      <button class="nota-mclose" onclick="fecharNota()" aria-label="Fechar">&times;</button>
    </div>
    <div class="nota-msub" id="dn-sub">&mdash;</div>
    <div class="nota-mbody">
      <div class="nota-sec">Negociações <span class="nota-sec-count" id="dn-qtd"></span></div>
      <div id="dn-negocios"></div>
      <div class="nota-sec">Resumo financeiro</div>
      <div id="dn-resumo"></div>
    </div>
  </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
<script>
if(window.pdfjsLib)pdfjsLib.GlobalWorkerOptions.workerSrc='https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
let CONFIG={}, parsed=null;

async function req(action,data){const o=data?{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(data)}:{};const r=await fetch('api.php?action='+action,o);if(r.status===401){location.href='/';return{};}return r.json();}
function showMsg(id,t,type){const el=document.getElementById(id);el.innerHTML=t;el.className='msg '+(type||'ok');el.style.display='block';}
function hideMsg(id){document.getElementById(id).style.display='none';}
function parseBR(s){if(s==null)return 0;return parseFloat(String(s).replace(/\./g,'').replace(',','.'))||0;}
function brToISO(d){const m=String(d).match(/(\d{2})\/(\d{2})\/(\d{4})/);return m?m[3]+'-'+m[2]+'-'+m[1]:'';}
function fmtBR(v){return Number(v).toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2});}
function detectarTipoJS(cod){cod=(cod||'').toUpperCase().trim();if(cod.length<5)return null;const L=cod[4];if(L>='A'&&L<='L')return'CALL';if(L>='M'&&L<='X')return'PUT';return null;}

/* ───── leitura do arquivo ───── */
const fileInput=document.getElementById('file');
fileInput.addEventListener('change',()=>{
  const f=fileInput.files[0];
  document.getElementById('dz-text').innerHTML=f?('&#128196; <strong>'+f.name+'</strong>'):'&#128206; Clique ou arraste o PDF da nota aqui';
  document.getElementById('btn-ler').disabled=!f;
});
const dz=document.getElementById('dropzone');
['dragover','dragenter'].forEach(e=>dz.addEventListener(e,ev=>{ev.preventDefault();dz.classList.add('drag');}));
['dragleave','drop'].forEach(e=>dz.addEventListener(e,ev=>{ev.preventDefault();dz.classList.remove('drag');}));
dz.addEventListener('drop',ev=>{if(ev.dataTransfer.files[0]){fileInput.files=ev.dataTransfer.files;fileInput.dispatchEvent(new Event('change'));}});

async function lerPDF(){
  hideMsg('up-msg');
  const f=fileInput.files[0]; if(!f)return;
  if(!window.pdfjsLib){showMsg('up-msg','Biblioteca de leitura de PDF não carregou (verifique a internet).','err');return;}
  document.getElementById('spin').classList.add('show');
  document.getElementById('btn-ler').disabled=true;
  try{
    const buf=await f.arrayBuffer();
    const senha=document.getElementById('senha').value||CONFIG.pdf_password||'';
    let pdf;
    try{
      pdf=await pdfjsLib.getDocument({data:new Uint8Array(buf),password:senha}).promise;
    }catch(e){
      if(e&&e.name==='PasswordException'){showMsg('up-msg','&#128274; PDF protegido: senha ausente ou incorreta. Informe a senha do PDF ao lado (ou salve-a em Configuração) e tente de novo.','err');document.getElementById('senha').focus();return;}
      throw e;
    }
    let lines=[],full='';
    for(let p=1;p<=pdf.numPages;p++){
      const page=await pdf.getPage(p);
      const tc=await page.getTextContent();
      const byY={};
      tc.items.forEach(it=>{if(it.str==null)return;const y=Math.round(it.transform[5]);(byY[y]=byY[y]||[]).push({x:it.transform[4],s:it.str});});
      Object.keys(byY).map(Number).sort((a,b)=>b-a).forEach(y=>{
        const line=byY[y].sort((a,b)=>a.x-b.x).map(o=>o.s).join(' ').replace(/\s+/g,' ').trim();
        if(line){lines.push(line);full+=line+'\n';}
      });
    }
    parsed=parseNota(lines,full);
    renderPreview();
    if(!parsed.itens.length)showMsg('up-msg','PDF lido, mas não reconheci linhas de negócios automaticamente. Confira o "texto extraído" abaixo e ajuste manualmente, ou me mande esse texto para eu calibrar.','warn');
  }catch(e){
    showMsg('up-msg','Não consegui ler o PDF: '+(e&&e.message?e.message:e),'err');
  }finally{
    document.getElementById('spin').classList.remove('show');
    document.getElementById('btn-ler').disabled=false;
  }
}

/* ───── parser da nota ───── */
function grabNum(text,labelRe){const re=new RegExp(labelRe+'[^0-9\\-]{0,25}([0-9][0-9.]*,[0-9]{2})','i');const m=text.match(re);return m?parseBR(m[1]):0;}
function parseNota(lines,full){
  const nota={};
  const mNr=full.match(/Nr\.?\s*nota[\s\S]{0,40}?(\d{6,})/i); nota.nr_nota=mNr?mNr[1]:'';
  const mDp=full.match(/Data\s*preg[aã]o[\s\S]{0,40}?(\d{2}\/\d{2}\/\d{4})/i); nota.data_pregao=mDp?brToISO(mDp[1]):'';
  nota.valor_operacoes=grabNum(full,'Valor das opera[cç][oõ]es')||grabNum(full,'Op[cç][oõ]es\\s*-\\s*compras');
  nota.taxa_liquidacao=grabNum(full,'Taxa de liquida');
  nota.taxa_registro=grabNum(full,'Taxa de [Rr]egistro');
  nota.emolumentos=grabNum(full,'Emolumentos');
  nota.total_custos=grabNum(full,'Total Custos');
  nota.outros=grabNum(full,'Outros');
  const mLiq=full.match(/L[ií]quido para\s*(?:\d{2}\/\d{2}\/\d{4})?\s*([0-9][0-9.]*,[0-9]{2})/i); nota.liquido=mLiq?parseBR(mLiq[1]):0;
  nota.total_taxas=+(nota.taxa_liquidacao+nota.taxa_registro+nota.emolumentos+nota.total_custos).toFixed(2);
  nota.corretora=CONFIG.corretora||'RICO';

  // Reconstrói os negócios de forma tolerante ao layout do PDF.
  // Algumas notas quebram UMA operação em várias linhas visuais (o pdf.js agrupa por Y),
  // deixando o código numa linha, "1-BOVESPA V ... qtd preço valor D/C" noutra e "COMPRA /EDJ" numa 3ª.
  // Âncora = a linha com "BOVESPA" + direção C/V + a cauda numérica (qtd preço valor D/C).
  // O código pode estar na própria âncora (layout antigo) ou na linha imediatamente anterior/seguinte (novo).
  const itens=[];
  const tailRe=/(\d[\d.]*)\s+(\d[\d.]*,\d{2,3})\s+(\d[\d.]*,\d{2})\s+([DC])\s*$/;
  const cvRe=/BOVESPA\s+([CV])\b/i;
  const codRe=/\b([A-Z]{4}[A-Z]\d{2,4})\b/;
  lines.forEach((line,i)=>{
    if(!/BOVESPA/i.test(line))return;
    const mTail=line.match(tailRe); if(!mTail)return;
    const mCV=line.match(cvRe);     if(!mCV)return;
    let mCod=line.match(codRe);                                  // código na própria âncora (layout antigo)
    if(!mCod&&i>0)              mCod=(lines[i-1]||'').match(codRe); // ou na linha acima (layout quebrado)
    if(!mCod&&i+1<lines.length) mCod=(lines[i+1]||'').match(codRe); // ou na linha abaixo
    if(!mCod)return;
    const codigo=mCod[1].toUpperCase();
    let estrutura=detectarTipoJS(codigo);                        // CALL/PUT pela 5ª letra (critério do sistema)
    if(!estrutura){                                              // fallback: rótulo "OPCAO DE COMPRA/VENDA" no bloco
      const bloco=(lines[i-1]||'')+' '+line+' '+(lines[i+1]||'');
      const mL=bloco.match(/OP[CÇ][AÃ]O\s+DE\s+(COMPRA|VENDA)/i);
      if(mL)estrutura=mL[1].toUpperCase()==='COMPRA'?'CALL':'PUT';
    }
    const cv=mCV[1].toUpperCase();
    const lote=parseInt(String(mTail[1]).replace(/\./g,''),10)||0;
    const preco=parseBR(mTail[2]);
    itens.push({codigo,estrutura,cv,acao:(cv==='V'?'encerrar':'abrir'),lote,preco});
  });
  // agrega por codigo+acao (lotes somam, preço = média ponderada)
  const agg={};
  itens.forEach(it=>{
    const k=it.codigo+'|'+it.acao;
    if(!agg[k]){agg[k]={codigo:it.codigo,estrutura:it.estrutura,cv:it.cv,acao:it.acao,lote:it.lote,_pl:it.preco*it.lote};}
    else{agg[k].lote+=it.lote;agg[k]._pl+=it.preco*it.lote;}
  });
  const finalItens=Object.values(agg).map(it=>({importar:true,codigo:it.codigo,estrutura:it.estrutura,cv:it.cv,acao:it.acao,lote:it.lote,preco:(it.lote?it._pl/it.lote:0)}));
  return {nota,itens:finalItens,raw:full};
}

/* ───── preview ───── */
function renderPreview(){
  const n=parsed.nota;
  document.getElementById('card-preview').classList.remove('hide');
  document.getElementById('n-nr').value=n.nr_nota||'';
  document.getElementById('n-data').value=n.data_pregao||'';
  document.getElementById('n-cor').value=n.corretora||'RICO';
  document.getElementById('n-valop').value=fmtBR(n.valor_operacoes||0);
  document.getElementById('n-liq').value=fmtBR(n.taxa_liquidacao||0);
  document.getElementById('n-reg').value=fmtBR(n.taxa_registro||0);
  document.getElementById('n-emol').value=fmtBR(n.emolumentos||0);
  document.getElementById('n-cust').value=fmtBR(n.total_custos||0);
  document.getElementById('n-tax').value=fmtBR(n.total_taxas||0);
  document.getElementById('n-liquido').value=fmtBR(n.liquido||0);
  document.getElementById('raw-text').textContent=parsed.raw;
  renderItens();
  document.querySelectorAll('.fee').forEach(el=>el.addEventListener('input',recalcTaxas));
  document.getElementById('card-preview').scrollIntoView({behavior:'smooth',block:'start'});
}
function recalcTaxas(){
  const t=parseBR(document.getElementById('n-liq').value)+parseBR(document.getElementById('n-reg').value)+parseBR(document.getElementById('n-emol').value)+parseBR(document.getElementById('n-cust').value);
  document.getElementById('n-tax').value=fmtBR(t);
}
function renderItens(){
  const tb=document.getElementById('itens-body');
  if(!parsed.itens.length){tb.innerHTML='<tr><td colspan="8" style="text-align:center;padding:24px;color:#999">Nenhum negócio reconhecido. Ajuste manualmente ou revise o texto extraído.</td></tr>';atualizaSumario();return;}
  tb.innerHTML=parsed.itens.map((it,i)=>`<tr>
    <td class="c"><input type="checkbox" class="it-imp" data-i="${i}" ${it.importar?'checked':''} onchange="atualizaSumario()"></td>
    <td><input type="text" class="it-cod itm-cod" data-i="${i}" value="${it.codigo}" oninput="this.value=this.value.toUpperCase()"></td>
    <td><select class="it-est" data-i="${i}"><option value="CALL"${it.estrutura==='CALL'?' selected':''}>CALL</option><option value="PUT"${it.estrutura==='PUT'?' selected':''}>PUT</option></select></td>
    <td><select class="it-acao" data-i="${i}" onchange="atualizaSumario()"><option value="abrir"${it.acao==='abrir'?' selected':''}>Abrir</option><option value="encerrar"${it.acao==='encerrar'?' selected':''}>Encerrar</option></select></td>
    <td class="c"><span class="${it.cv==='C'?'cv-c':'cv-v'}">${it.cv}</span></td>
    <td><input type="number" class="it-lote" data-i="${i}" value="${it.lote}" min="0" step="1"></td>
    <td><input type="number" class="it-preco" data-i="${i}" value="${it.preco.toFixed(3)}" min="0" step="0.001"></td>
    <td><input type="date" class="it-data" data-i="${i}" value="${parsed.nota.data_pregao||''}"></td>
  </tr>`).join('');
  atualizaSumario();
}
function atualizaSumario(){
  let imp=0,abrir=0,enc=0;
  document.querySelectorAll('.it-imp').forEach(c=>{
    if(c.checked){imp++;const i=c.dataset.i;const a=document.querySelector('.it-acao[data-i="'+i+'"]').value;a==='encerrar'?enc++:abrir++;}
  });
  document.getElementById('sm-itens').textContent=imp;
  document.getElementById('sm-abrir').textContent=abrir;
  document.getElementById('sm-enc').textContent=enc;
}

function coletarItens(){
  return parsed.itens.map((_,i)=>({
    importar:document.querySelector('.it-imp[data-i="'+i+'"]').checked,
    codigo:document.querySelector('.it-cod[data-i="'+i+'"]').value.trim().toUpperCase(),
    estrutura:document.querySelector('.it-est[data-i="'+i+'"]').value,
    acao:document.querySelector('.it-acao[data-i="'+i+'"]').value,
    lote:document.querySelector('.it-lote[data-i="'+i+'"]').value,
    preco:document.querySelector('.it-preco[data-i="'+i+'"]').value,
    data:document.querySelector('.it-data[data-i="'+i+'"]').value,
  }));
}
async function confirmar(){
  hideMsg('pv-msg');
  const data=document.getElementById('n-data').value;
  if(!data){showMsg('pv-msg','Informe a data do pregão.','err');return;}
  // Envia números JÁ LIMPOS (parseBR trata "1.350,00" -> 1350 e "99,89" -> 99.89).
  // Não enviar a string formatada crua: o servidor faria (float)"1.350,00" -> 1.35 (perde o milhar).
  const nota={
    nr_nota:document.getElementById('n-nr').value.trim(),
    corretora:document.getElementById('n-cor').value.trim()||'RICO',
    data_pregao:data,
    valor_operacoes:parseBR(document.getElementById('n-valop').value),
    taxa_liquidacao:parseBR(document.getElementById('n-liq').value),
    taxa_registro:parseBR(document.getElementById('n-reg').value),
    emolumentos:parseBR(document.getElementById('n-emol').value),
    outros:0,
    total_custos:parseBR(document.getElementById('n-cust').value),
    total_taxas:parseBR(document.getElementById('n-tax').value),
    liquido:parseBR(document.getElementById('n-liquido').value),
  };
  const itens=coletarItens();
  document.getElementById('btn-confirm').disabled=true;
  const r=await req('import_nota',{nota,itens});
  document.getElementById('btn-confirm').disabled=false;
  if(r&&r.success){
    let t='&#10003; Importado: <b>'+r.abertas+'</b> aberta(s), <b>'+r.encerradas+'</b> encerrada(s)';
    if(r.lancadas)t+=', <b>'+r.lancadas+'</b> vendida(s)/lançada(s) &lowast;';
    if(r.parciais)t+=', <b>'+r.parciais+'</b> parcial(is)';
    if(r.acumuladas)t+=', <b>'+r.acumuladas+'</b> acumulada(s)';
    if(r.invertidas)t+=', <b>'+r.invertidas+'</b> invertida(s)';
    if(r.reconciliadas)t+=', <b>'+r.reconciliadas+'</b> manual(is) confirmada(s) &#9998;';
    t+='. Taxas registradas.';
    if(r.avisos&&r.avisos.length)t+='<br>&#9888;&#65039; '+r.avisos.join('<br>&#9888;&#65039; ');
    t+='<br><a href="/" class="btn btn-primary" style="margin-top:10px">Ver na Carteira &rarr;</a>';
    showMsg('pv-msg',t,r.avisos&&r.avisos.length?'warn':'ok');
    document.getElementById('btn-confirm').disabled=true;
    carregarHist();
  }else{
    showMsg('pv-msg',(r&&r.error)||'Erro ao importar.','err');
  }
}
function cancelar(){parsed=null;document.getElementById('card-preview').classList.add('hide');fileInput.value='';fileInput.dispatchEvent(new Event('change'));hideMsg('pv-msg');hideMsg('up-msg');}

/* ───── histórico ───── */
async function carregarHist(){
  const rows=await req('list_notas');
  const tb=document.getElementById('hist-body'),ft=document.getElementById('hist-foot');
  if(!Array.isArray(rows)||!rows.length){tb.innerHTML='<tr><td colspan="8" style="text-align:center;padding:22px;color:#999">Nenhuma nota importada ainda.</td></tr>';ft.innerHTML='';return;}
  tb.innerHTML=rows.map(r=>`<tr>
    <td><a href="#" class="nota-link" title="Ver detalhes da nota" onclick="verNota(${r.id});return false">${r.nr_nota||'—'}</a></td><td>${r.corretora||'—'}</td>
    <td>${r.data_pregao?r.data_pregao.split('-').reverse().join('/'):'—'}</td>
    <td style="text-align:right">R$ ${fmtBR(r.valor_operacoes)}</td>
    <td style="text-align:right;color:var(--neg)">R$ ${fmtBR(r.total_taxas)}</td>
    <td style="text-align:right">R$ ${fmtBR(r.liquido)}</td>
    <td>${r.qtd_itens}</td>
    <td style="white-space:nowrap"><span class="trash-link" style="color:#7c3aed;cursor:pointer;font-size:13px;display:inline-flex;align-items:center;gap:5px" title="Desfazer esta importação: reverte as posições que ela criou/encerrou e remove a nota" onclick="undoNota(${r.id})">&#8634; Desfazer</span></td>
  </tr>`).join('');
  const tTax=rows.reduce((s,r)=>s+parseFloat(r.total_taxas||0),0);
  ft.innerHTML=`<tr><td colspan="4" style="text-align:right;font-weight:600">Total de taxas acumuladas</td><td style="text-align:right;font-weight:600;color:var(--neg)">R$ ${fmtBR(tTax)}</td><td colspan="3"></td></tr>`;
}
async function delNota(id){if(!confirm('Remover esta nota da lista de taxas? (Os lançamentos na carteira NÃO são desfeitos.)'))return;const r=await req('delete_nota',{id});if(r&&r.success)carregarHist();}
async function undoNota(id){if(!confirm('DESFAZER esta importação?\n\nIsso reverte as posições que ela criou/encerrou (volta ao estado anterior à nota) e remove a nota da lista.'))return;const r=await req('undo_nota',{id});if(r&&r.success){alert(r.msg||'Importação desfeita.');carregarHist();}else alert((r&&r.error)||'Erro ao desfazer.');}

/* ── modal: detalhe da nota ── */
async function verNota(id){
  const r=await req('nota_detalhe',{id});
  if(!r||!r.success){alert((r&&r.error)||'Erro ao carregar a nota.');return;}
  const n=r.nota, neg=r.negocios||[];
  document.getElementById('dn-nr').textContent=n.nr_nota||'—';
  const dt=n.data_pregao?n.data_pregao.split('-').reverse().join('/'):'—';
  document.getElementById('dn-sub').textContent=(n.corretora||'—')+' · pregão '+dt;
  document.getElementById('dn-qtd').textContent=neg.length?('· '+neg.length+(neg.length>1?' negócios':' negócio')):'';
  const nb=document.getElementById('dn-negocios');
  if(!neg.length){
    nb.innerHTML='<div class="nota-empty">Detalhe das negociações não disponível para esta nota (importada antes deste recurso).</div>';
  }else{
    nb.innerHTML=neg.map(t=>{
      const compra=t.cv==='C';
      return '<div class="neg-row">'
        +'<span class="neg-cv '+(compra?'c':'v')+'" title="'+(compra?'Compra':'Venda')+'">'+(compra?'C':'V')+'</span>'
        +'<div class="neg-mid"><div class="neg-cod">'+t.codigo+' <span class="neg-tipo '+(t.estrutura==='PUT'?'put':'call')+'">'+(t.estrutura||'')+'</span></div>'
        +'<div class="neg-det">'+t.lote+' &times; R$ '+fmtBR(t.preco)+'</div></div>'
        +'<div class="neg-val">R$ '+fmtBR(t.preco*t.lote)+'</div>'
        +'</div>';
    }).join('');
  }
  const linhas=[
    ['Valor das operações',n.valor_operacoes,''],
    ['Taxa de liquidação',n.taxa_liquidacao,'neg'],
    ['Taxa de registro',n.taxa_registro,'neg'],
    ['Emolumentos',n.emolumentos,'neg'],
    ['Outros custos',n.total_custos,'neg'],
    ['Total de taxas',n.total_taxas,'neg strong'],
    ['Líquido da nota',n.liquido,'strong'],
  ];
  let html='<div class="res-box">';
  linhas.forEach(function(L){
    const lbl=L[0],v=L[1],cls=L[2]||'';
    if(v===null||v===undefined)return;
    const isNeg=cls.indexOf('neg')>=0, isStrong=cls.indexOf('strong')>=0;
    const val=(isNeg&&v>0.0001?'- ':'')+'R$ '+fmtBR(Math.abs(v));
    html+='<div class="res-row'+(isStrong?' strong':'')+'"><span>'+lbl+'</span><span class="res-v'+(isNeg&&v>0.0001?' v-neg':'')+'">'+val+'</span></div>';
  });
  if(n.fluxo_caixa!==null&&n.fluxo_caixa!==undefined){
    const f=n.fluxo_caixa;
    html+='<div class="res-row strong res-caixa"><span>Efeito no caixa</span><span class="res-v '+(f<0?'v-neg':'v-pos')+'">'+(f<0?'- ':'+ ')+'R$ '+fmtBR(Math.abs(f))+'</span></div>';
  }
  html+='</div>';
  document.getElementById('dn-resumo').innerHTML=html;
  document.getElementById('nota-ov').classList.add('show');
}
function fecharNota(){document.getElementById('nota-ov').classList.remove('show');}
document.addEventListener('keydown',function(e){if(e.key==='Escape')fecharNota();});

/* ───── init ───── */
(async()=>{
  const c=await req('get_config');
  if(c&&c.success){CONFIG=c.config||{};if(CONFIG.pdf_password)document.getElementById('senha').value=CONFIG.pdf_password;}
  carregarHist();
})();
</script>
</body>
</html>
