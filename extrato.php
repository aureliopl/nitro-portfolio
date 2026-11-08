<?php
require_once 'auth.php';
exigir_login();
$TITULO_PG = 'Extrato';
$CSS_PG    = 'extrato';
require __DIR__ . '/partials/head.php';
?>
<body>
<?php $ATIVO='extrato'; require __DIR__.'/partials/nav.php'; ?>

<div class="wrap">
  <div class="head">
    <div>
      <h1>&#129534; Extrato</h1>
      <div class="sub">Espelho simulado da sua conta &middot; aporte inicial, depósitos, saques e o caixa das notas.</div>
    </div>
  </div>

  <div class="hero">
    <div class="hero-top">
      <div>
        <div class="hero-lbl">Disponível para uso</div>
        <div class="hero-saldo" id="h-saldo">R$ 0,00</div>
        <div class="hero-meta" id="h-meta">&mdash;</div>
      </div>
      <button class="btn-add" onclick="abrirMov()">&plus; Nova movimentação</button>
    </div>
    <div class="hero-grid">
      <div class="hg-item" title="Caixa já liquidado na conta (inclui o que ainda vai liquidar). = Disponível + A liquidar."><div class="hg-lbl">Saldo em conta</div><div class="hg-val" id="h-saldoconta">R$ 0,00</div><div class="hg-sub">liquidado</div></div>
      <div class="hg-item" title="Notas com liquidação futura (D+1, pulando fim de semana). Ainda vão sair/entrar do caixa."><div class="hg-lbl">A liquidar</div><div class="hg-val" id="h-aliquidar">R$ 0,00</div><div class="hg-sub">lançamentos futuros</div></div>
      <div class="hg-item" title="Quanto você depositou (aporte inicial + depósitos)."><div class="hg-lbl">Total aportado</div><div class="hg-val pos" id="h-aportado">R$ 0,00</div><div class="hg-sub">inicial + depósitos</div></div>
      <div class="hg-item" title="Capital nas posições abertas (a vendida reduz). = 'valor aplicado' da RICO."><div class="hg-lbl">Valor aplicado</div><div class="hg-val" id="h-aberto">R$ 0,00</div><div class="hg-sub">nas abertas</div></div>
      <div class="hg-item" title="Valor das abertas a mercado hoje (a vendida reduz). = 'total em bolsa' da RICO."><div class="hg-lbl">Em bolsa</div><div class="hg-val" id="h-bolsa">R$ 0,00</div><div class="hg-sub">a mercado</div></div>
      <div class="hg-item" title="Tudo que você tem hoje: disponível + valor a mercado das abertas. = 'Meu Patrimônio' da RICO."><div class="hg-lbl">Patrimônio</div><div class="hg-val" id="h-patrimonio">R$ 0,00</div><div class="hg-sub">disponível + em bolsa</div></div>
    </div>
  </div>

  <div class="filters">
    <button class="chip active" data-f="tudo" onclick="setFiltro('tudo',this)">Tudo</button>
    <button class="chip" data-f="entrada" onclick="setFiltro('entrada',this)">&#8599; Entradas</button>
    <button class="chip" data-f="saida" onclick="setFiltro('saida',this)">&#8600; Saídas</button>
    <button class="chip" data-f="saque" onclick="setFiltro('saque',this)">&#128184; Saques</button>
    <button class="chip" data-f="op" onclick="setFiltro('op',this)">&#128202; Operações</button>
  </div>

  <div class="ledger">
    <div class="led-head"><div>Evento</div><div class="r">Valor</div><div class="r">Saldo</div></div>
    <div id="led-body"><div class="empty">Carregando&hellip;</div></div>
  </div>

  <div class="foot-note">
    &#129534; <strong>Como o saldo é montado:</strong> aporte inicial (definido na <a href="/config" style="color:#0d9488;font-weight:600">Config</a>) + depósitos − saques + o <strong>caixa de cada nota</strong> (vendas − compras − taxas).
    As notas importadas <em>antes</em> desta atualização entram como <strong>débito presumido</strong> (valor pontilhado) — clique no &#9998; para acertar o valor com sinal (ex: <code>-2212,62</code> para uma compra, <code>+99,89</code> para uma venda). Liquidação real na corretora é em D+1; aqui usamos a data do pregão.
  </div>
</div>

<!-- MODAL nova movimentação -->
<div class="overlay" id="ov-mov">
  <div class="modal">
    <h3 id="mov-title">Nova movimentação</h3>
    <div class="mdesc">Registro simulado de caixa — não envia nada à corretora.</div>
    <div class="seg">
      <label><input type="radio" name="mtipo" value="aporte" checked><span>&#8599; Depósito</span></label>
      <label><input type="radio" name="mtipo" value="saque"><span>&#8600; Saque</span></label>
    </div>
    <div class="fld"><label>Data</label><input type="date" id="m-data"></div>
    <div class="fld"><label>Valor (R$)</label><input type="text" id="m-valor" placeholder="ex: 500,00" inputmode="decimal"></div>
    <div class="fld"><label>Descrição (opcional)</label><input type="text" id="m-desc" placeholder="ex: TED Banco 260" style="font-family:var(--font)"></div>
    <div class="mmsg" id="m-msg"></div>
    <div class="modal-actions">
      <button class="btn btn-ghost" onclick="fecharMov()">Cancelar</button>
      <button class="btn btn-primary" onclick="salvarMov()">Registrar</button>
    </div>
  </div>
</div>

<script>
let DADOS=null, FILTRO='tudo';
async function req(action,data){const o=data?{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(data)}:{};const r=await fetch('api.php?action='+action,o);if(r.status===401){location.href='/';return null;}return r.json();}
function fmt(v){return'R$ '+Number(Math.abs(v)).toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2});}
function sign(v){return(v<0?'-':'+')+' '+fmt(v);}
function sqlDisp(d){if(!d)return'—';const[y,m,dd]=d.split('-');return dd+'/'+m+'/'+y;}
// liquidação D+1 (pula fim de semana) — uma nota de sexta liquida na segunda
function parseYmd(d){const[y,m,dd]=d.split('-').map(Number);return new Date(y,m-1,dd);}
function settleDate(ymd){const dt=parseYmd(ymd);dt.setDate(dt.getDate()+1);const wd=dt.getDay();if(wd===6)dt.setDate(dt.getDate()+2);else if(wd===0)dt.setDate(dt.getDate()+1);return dt;}
function isPending(ymd){const t=new Date();t.setHours(0,0,0,0);return settleDate(ymd)>t;}
function settleDisp(ymd){const dt=settleDate(ymd);const p=n=>String(n).padStart(2,'0');return p(dt.getDate())+'/'+p(dt.getMonth()+1)+'/'+dt.getFullYear();}

async function carregar(){
  const [ext,cart]=await Promise.all([req('list_extrato'),req('list')]);
  if(!ext||!ext.success){document.getElementById('led-body').innerHTML='<div class="empty">Erro ao carregar.</div>';return;}
  DADOS=ext;
  // abertas: valor aplicado (custo) e valor a mercado (cotação). VENDIDA entra NEGATIVA.
  let aberto=0,bolsa=0;
  if(Array.isArray(cart))cart.filter(r=>r.status==='Aberta').forEach(r=>{
    const sgn=r.direcao==='V'?-1:1;
    aberto+=sgn*Number(r.preco_medio)*Number(r.lote);
    if(r.recebido!==null)bolsa+=sgn*Number(r.recebido)*Number(r.lote);
  });
  DADOS._aberto=aberto;DADOS._bolsa=bolsa;
  render();
}

// monta os eventos do razão em ordem cronológica e calcula saldo corrido
function buildEvents(){
  const ev=[];
  if(DADOS.aporte_inicial&&DADOS.aporte_inicial>0){
    ev.push({date:DADOS.aporte_data||'0000-01-01',ord:0,kind:'ini',valor:+DADOS.aporte_inicial,desc:'Aporte inicial',sub:'ponto de partida (Config)'});
  }
  DADOS.movimentacoes.forEach(m=>{
    const cred=m.tipo==='aporte';
    ev.push({date:m.data,ord:1,kind:cred?'aporte':'saque',valor:cred?+m.valor:-Math.abs(m.valor),desc:cred?'Depósito':'Saque',sub:m.descricao||'',movId:m.id});
  });
  DADOS.notas.forEach(n=>{
    const assumed=(n.fluxo_caixa===null||n.fluxo_caixa===undefined);
    const v=assumed?-Math.abs(n.liquido):n.fluxo_caixa;
    const pend=isPending(n.data_pregao);
    ev.push({date:n.data_pregao,ord:2,kind:'op',valor:v,assumed:assumed,pending:pend,settle:pend?settleDisp(n.data_pregao):null,
      desc:'Operações em bolsa'+(n.nr_nota?' · nota '+n.nr_nota:''),
      sub:'líquido nota '+fmt(n.liquido)+' · taxas '+fmt(n.total_taxas),notaId:n.id});
  });
  ev.sort((a,b)=>a.date<b.date?-1:a.date>b.date?1:a.ord-b.ord);
  let s=0;ev.forEach(e=>{s+=e.valor;e.saldo=s;});
  return ev;
}

function render(){
  const ev=buildEvents();
  const disponivel=ev.length?ev[ev.length-1].saldo:0;          // = "Disponível para uso" da RICO
  let aLiquidar=0;ev.forEach(e=>{if(e.kind==='op'&&e.pending)aLiquidar+=e.valor;}); // notas ainda não liquidadas
  const saldoConta=disponivel-aLiquidar;                        // = "Saldo" (caixa liquidado)
  let aportado=(DADOS.aporte_inicial>0?+DADOS.aporte_inicial:0);
  DADOS.movimentacoes.forEach(m=>{if(m.tipo==='aporte')aportado+=+m.valor;});
  const bolsa=DADOS._bolsa||0;
  const patrimonio=disponivel+bolsa;                            // = "Meu Patrimônio" da RICO

  const hS=document.getElementById('h-saldo');hS.textContent=fmt(disponivel);hS.className='hero-saldo'+(disponivel<0?' neg':'');
  document.getElementById('h-meta').textContent=ev.length?('última movimentação em '+sqlDisp(ev[ev.length-1].date)):'sem movimentações ainda';
  document.getElementById('h-saldoconta').textContent=fmt(saldoConta);
  const hAl=document.getElementById('h-aliquidar');hAl.textContent=Math.abs(aLiquidar)<0.005?fmt(0):sign(aLiquidar);hAl.className='hg-val'+(aLiquidar<-0.005?' neg':aLiquidar>0.005?' pos':'');
  document.getElementById('h-aportado').textContent=fmt(aportado);
  document.getElementById('h-aberto').textContent=fmt(DADOS._aberto||0);
  document.getElementById('h-bolsa').textContent=fmt(bolsa);
  document.getElementById('h-patrimonio').textContent=fmt(patrimonio);

  // filtro
  let list=ev.slice().reverse();
  if(FILTRO==='entrada')list=list.filter(e=>e.valor>0);
  else if(FILTRO==='saida')list=list.filter(e=>e.valor<0&&e.kind!=='saque');
  else if(FILTRO==='saque')list=list.filter(e=>e.kind==='saque');
  else if(FILTRO==='op')list=list.filter(e=>e.kind==='op');

  const body=document.getElementById('led-body');
  if(!list.length){body.innerHTML='<div class="empty">Nenhum lançamento neste filtro.<br><span style="font-size:12px">Use “Nova movimentação” ou defina o aporte inicial na Config.</span></div>';return;}

  let html='',lastDate=null;
  list.forEach(e=>{
    if(e.date!==lastDate){html+='<div class="daygroup-date">'+sqlDisp(e.date)+'</div>';lastDate=e.date;}
    const tagCls=e.kind==='ini'?'ini':(e.valor>=0?'cred':'deb');
    const tagTxt=e.kind==='ini'?'Inicial':(e.valor>=0?'Crédito':'Débito');
    let actBtns='';
    if(e.kind==='aporte'||e.kind==='saque')actBtns='<button class="mini-btn" title="Excluir" onclick="delMov('+e.movId+')">&#128465;&#65039;</button>';
    else if(e.kind==='op')actBtns='<button class="mini-btn" title="Ajustar caixa (com sinal)" onclick="editFluxo('+e.notaId+','+e.valor+')">&#9998;</button>';
    const actDesk=actBtns?'<span class="row-actions desk">'+actBtns+'</span>':'';   // desktop: aparece no hover, na coluna Evento
    const actMob=actBtns?'<span class="row-actions mob">'+actBtns+'</span>':'';      // mobile: aparece ao tocar no valor, à direita dele
    const valTxt=e.assumed
      ?'<span class="assumed" title="Débito presumido — clique no lápis para acertar o valor com sinal.">'+sign(e.valor)+'</span>'
      :sign(e.valor);
    html+='<div class="row">'
      +'<div class="evt"><span class="tag '+tagCls+'">'+tagTxt+'</span>'+(e.pending?'<span class="tag pend" title="Ainda não liquidou — entra no caixa em '+e.settle+' (D+1).">a liquidar '+e.settle+'</span>':'')+'<div class="evt-desc"><div class="t">'+e.desc+'</div>'+(e.sub?'<div class="s">'+e.sub+'</div>':'')+'</div>'+actDesk+'</div>'
      +'<div class="val '+(e.valor>=0?'pos':'neg')+'"'+(actMob?' onclick="tapVal(this)"':'')+'>'+valTxt+actMob+'</div>'
      +'<div class="saldo">'+fmt(e.saldo)+'</div>'
      +'</div>';
  });
  body.innerHTML=html;
}

// mobile: tocar no valor revela o lápis à direita dele (desktop usa hover, intacto)
function tapVal(el){if(window.matchMedia('(max-width:560px)').matches)el.closest('.row').classList.toggle('tap');}
function setFiltro(f,btn){FILTRO=f;document.querySelectorAll('.chip').forEach(c=>c.classList.remove('active'));btn.classList.add('active');render();}

// ── modal movimentação ──
function abrirMov(){document.getElementById('m-data').value=new Date().toISOString().split('T')[0];document.getElementById('m-valor').value='';document.getElementById('m-desc').value='';document.getElementById('m-msg').textContent='';document.querySelector('input[name=mtipo][value=aporte]').checked=true;document.getElementById('ov-mov').classList.add('show');setTimeout(()=>document.getElementById('m-valor').focus(),60);}
function fecharMov(){document.getElementById('ov-mov').classList.remove('show');}
async function salvarMov(){
  const tipo=document.querySelector('input[name=mtipo]:checked').value;
  const data=document.getElementById('m-data').value;
  const valor=document.getElementById('m-valor').value.trim().replace(/\./g,'').replace(',','.');
  const descricao=document.getElementById('m-desc').value.trim();
  const msg=document.getElementById('m-msg');
  if(!data){msg.textContent='Informe a data.';return;}
  if(!valor||parseFloat(valor)<=0){msg.textContent='Informe um valor maior que zero.';return;}
  const r=await req('add_mov',{tipo,data,valor,descricao});
  if(r&&r.success){fecharMov();carregar();}else msg.textContent=(r&&r.error)||'Erro ao registrar.';
}
async function delMov(id){if(!confirm('Excluir esta movimentação?'))return;const r=await req('delete_mov',{id});if(r&&r.success)carregar();}
async function editFluxo(id,atual){
  const sug=Number(atual).toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2});
  const v=prompt('Efeito de caixa desta nota, COM SINAL:\n• negativo = saiu dinheiro (compra)  ex: -2212,62\n• positivo = entrou dinheiro (venda)  ex: 99,89',sug);
  if(v===null)return;
  const fluxo=v.trim().replace(/\./g,'').replace(',','.');
  if(isNaN(parseFloat(fluxo))){alert('Valor inválido.');return;}
  const r=await req('set_nota_fluxo',{id,fluxo});
  if(r&&r.success)carregar();else alert((r&&r.error)||'Erro.');
}
document.getElementById('ov-mov').addEventListener('click',e=>{if(e.target.id==='ov-mov')fecharMov();});
document.addEventListener('keydown',e=>{if(e.key==='Escape')fecharMov();});
carregar();
</script>
</body>
</html>
