<?php
require_once 'auth.php';
exigir_login();
// Nitro e Betha viraram pessoais (Fase 3): so o super_admin ve essas secoes do ranking.
$verNitro = pode_ver_nitro($_SESSION['user_papel'] ?? '');
$verBetha = pode_ver_betha($_SESSION['user_papel'] ?? '');
$nCols    = 1 + ($verNitro ? 1 : 0) + ($verBetha ? 1 : 0);   // 1 (usuario comum) ou 3 (super_admin)
$TITULO_PG = 'Ranking';
$CSS_PG    = 'ranking';
require __DIR__ . '/partials/head.php';
?>
<body>
<?php $ATIVO='ranking'; require __DIR__.'/partials/nav.php'; ?>

<div class="container">
  <div class="rank-head">
    <h1 class="rank-h1">&#127942; Ranking por empresa</h1>
    <div class="rank-hsub">Encerradas &mdash; melhor ao pior &middot; resultado acumulado &middot; atualizado em <span id="last-update">&hellip;</span></div>
  </div>

  <!-- As tres carteiras num container so: desktop = 3 colunas; mobile = cards deslizaveis.
       Cabecalho (titulo + KPIs) fica na 1a dobra; a lista de ativos rola dentro da coluna. -->
  <div class="rankings cols-<?= $nCols ?>">
    <!-- ── MINHA CARTEIRA (Pessoal) ── -->
    <section class="rank-col pessoal">
      <header class="rank-col-head">
        <div class="rank-title">&#128202; <?= htmlspecialchars($navCartAtiva ?? 'Minha Carteira') ?> <span class="pill pessoal">Pessoal</span></div>
        <div class="rank-kpis">
          <div class="kpi"><span class="k-lbl">Operações</span><span class="k-val" id="k-cart-ops">&mdash;</span></div>
          <div class="kpi"><span class="k-lbl">Acerto</span><span class="k-val" id="k-cart-ac">&mdash;</span></div>
          <div class="kpi"><span class="k-lbl">Resultado</span><span class="k-val" id="k-cart-res">&mdash;</span></div>
        </div>
      </header>
      <div class="rank-tbl">
        <table><thead><tr><th>Ticker</th><th class="r">Resultado</th><th class="r">Acerto</th><th class="r">Sequência</th></tr></thead><tbody id="cart-enc"></tbody></table>
      </div>
    </section>

    <?php if ($verNitro): ?>
    <!-- ── NITRO (Terceiros) ── -->
    <section class="rank-col terceiros">
      <header class="rank-col-head">
        <div class="rank-title">&#9889; Nitro <span class="pill terceiros">Terceiros</span></div>
        <div class="rank-kpis">
          <div class="kpi"><span class="k-lbl">Operações</span><span class="k-val" id="k-nit-ops">&mdash;</span></div>
          <div class="kpi"><span class="k-lbl">Acerto</span><span class="k-val" id="k-nit-ac">&mdash;</span></div>
          <div class="kpi"><span class="k-lbl">Resultado</span><span class="k-val" id="k-nit-res">&mdash;</span></div>
        </div>
      </header>
      <div class="rank-tbl">
        <table><thead><tr><th>Ticker</th><th class="r">Resultado</th><th class="r">Acerto</th><th class="r">Sequência</th></tr></thead><tbody id="nit-enc"></tbody></table>
      </div>
    </section>
    <?php endif; ?>

    <?php if ($verBetha): ?>
    <!-- ── BETHA (Simulação) ── -->
    <section class="rank-col betha">
      <header class="rank-col-head">
        <div class="rank-title">&#129518; Betha <span class="pill betha">Simulação</span></div>
        <div class="rank-kpis">
          <div class="kpi"><span class="k-lbl">Operações</span><span class="k-val" id="k-bet-ops">&mdash;</span></div>
          <div class="kpi"><span class="k-lbl">Acerto</span><span class="k-val" id="k-bet-ac">&mdash;</span></div>
          <div class="kpi"><span class="k-lbl">Resultado</span><span class="k-val" id="k-bet-res">&mdash;</span></div>
        </div>
      </header>
      <div class="rank-tbl">
        <table><thead><tr><th>Ticker</th><th class="r">Resultado</th><th class="r">Acerto</th><th class="r">Sequência</th></tr></thead><tbody id="bet-enc"></tbody></table>
      </div>
    </section>
    <?php endif; ?>
  </div>
</div>

<!-- MODAL: extrato detalhado de uma ação -->
<div class="ac-ov" id="ac-ov" onclick="if(event.target===this)fecharAcao()">
  <div class="ac-modal" role="dialog" aria-modal="true">
    <div class="ac-head">
      <div class="ac-htitle"><span class="ac-tk" id="ac-tk">&mdash;</span><span class="ac-ctx" id="ac-ctx"></span></div>
      <button class="ac-close" onclick="fecharAcao()" aria-label="Fechar">&times;</button>
    </div>
    <div class="ac-resumo" id="ac-resumo"></div>
    <div class="ac-body">
      <div class="ac-sec">Operações nesta ação</div>
      <div id="ac-ops"></div>
      <div class="ac-foot">As taxas são cobradas por <strong>nota</strong> (não por operação) — veja o detalhe em <a href="/importar" style="color:var(--accent)">Importar</a>.</div>
    </div>
  </div>
</div>

<script>
const TICKER_MAP={MGLU:"MGLU3",COGN:"COGN3",RENT:"RENT3",USIM:"USIM5",BRAV:"BRAV3",LREN:"LREN3",BEEF:"BEEF3",CSNA:"CSNA3",CSAN:"CSAN3",MBRF:"MBRF3",PRIO:"PRIO3",CYRE:"CYRE3",PETR:"PETR4",EMBJ:"EMBJ3",MRVE:"MRVE3",WEGE:"WEGE3",BRKM:"BRKM3",CMIN:"CMIN3",BRAP:"BRAP4",BOVA:"BOVA11"};
function ticker(code){const root=(code||'').slice(0,4).toUpperCase();return TICKER_MAP[root]||(root+"3");}
function daysBetween(a,b){return Math.floor((a-b)/86400000);}
function parseSql(d){const[y,m,dd]=d.split('-').map(Number);return new Date(y,m-1,dd);}
function sqlToDisplay(d){if(!d)return'—';const[y,m,dd]=d.split('-');return dd+'/'+m+'/'+y;}
function resBadge(v){if(Math.abs(v)<0.005)return'<span class="badge b-zero">0,00%</span>';const fmt=Math.abs(v).toFixed(2).replace(".",",")+"%";return v<0?'<span class="badge b-neg">-'+fmt+'</span>':'<span class="badge b-pos">+'+fmt+'</span>';}
function posBadge(i){const cls=i===0?"gold":i===1?"silver":i===2?"bronze":"";return'<span class="rank-pos '+cls+'">'+(i+1)+'</span>';}

/* modelo rico (Carteira) x simples (Nitro/Betha) — mesma matematica p/ as duas simples */
function lpCarteira(r){const d=(r.recebido-r.preco_medio)*r.lote;return r.direcao==='V'?-d:d;}
function invCarteira(r){return r.preco_medio*r.lote;}
function lpSimples(r){const d=r.recebido-r.pago;return r.direcao==='V'?-d:d;}   /* vendida: lucra quando o premio cai */
function invSimples(r){return r.pago;}

const VER_NITRO=<?= $verNitro ? 'true' : 'false' ?>;   // Nitro so p/ super_admin (Fase 3)
const VER_BETHA=<?= $verBetha ? 'true' : 'false' ?>;   // Betha so p/ super_admin (Fase 3)
let DADOS={cart:[],nit:[],bet:[]};
function opLP(r,ds){return ds==='cart'?lpCarteira(r):lpSimples(r);}
function opINV(r,ds){return ds==='cart'?invCarteira(r):invSimples(r);}
function opEntrada(r,ds){return ds==='cart'?r.preco_medio:r.pago;}
function fmtP(v){return'R$ '+Number(v).toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2});}
function fmtLP(v){return(v<0?'-':'+')+' R$ '+Math.abs(v).toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2});}

async function fetchTabela(tab){
  const res=await fetch('api.php?action=list&tabela='+tab);
  if(res.status===401){location.href='/';return[];}
  if(!res.ok)throw new Error('API indisponivel');
  return res.json();
}
function buildEnc(rows,lpFn,invFn){
  const g={};
  rows.filter(r=>r.status==='Encerrada'&&r.recebido!==null).forEach(r=>{
    const t=ticker(r.codigo);
    if(!g[t])g[t]={resSum:0,diasSum:0,n:0,wins:0,ops:[]};
    const lp=lpFn(r),inv=invFn(r);
    // Resultado = SOMA acumulada dos % de cada operacao (nao a media ponderada).
    g[t].resSum+=inv?lp/inv*100:0;g[t].diasSum+=(r.dias||0);g[t].n++;if(lp>0.0001)g[t].wins++;
    g[t].ops.push({d:r.data_final||r.data_inicio||'',cod:r.codigo,lp:lp});
  });
  return Object.entries(g).map(([t,v])=>({
    t,res:v.resSum,dias:v.n?v.diasSum/v.n:0,n:v.n,ac:v.n?v.wins/v.n*100:0,
    // sequencia: as 10 operacoes encerradas mais recentes (antiga -> recente)
    seq:v.ops.slice().sort((a,b)=>a.d<b.d?-1:a.d>b.d?1:0).slice(-10)
  })).sort((a,b)=>b.res-a.res);
}
/* resumo da carteira p/ o mini-cabecalho (KPIs sempre visiveis na 1a dobra).
   RESULTADO = ROI PONDERADO PELO CAPITAL das encerradas (Sigma L/P / Sigma investido)
   — regra de ouro nº1. NAO e' a soma dos % (que passaria de -100% e enganaria como
   "retorno de carteira"). A coluna por ticker segue com a soma dos % (score de ranking). */
function resumo(rows,lpFn,invFn){
  const enc=rows.filter(r=>r.status==='Encerrada'&&r.recebido!==null);
  let lpSum=0,invSum=0,wins=0;
  enc.forEach(r=>{const inv=invFn(r);if(inv){lpSum+=lpFn(r);invSum+=inv;}if(lpFn(r)>0.0001)wins++;});
  return{ops:enc.length,ac:enc.length?wins/enc.length*100:null,res:invSum>0?lpSum/invSum*100:null};
}
function setKpis(pfx,s){
  document.getElementById('k-'+pfx+'-ops').textContent=s.ops;
  document.getElementById('k-'+pfx+'-ac').innerHTML=s.ac===null?'&mdash;':s.ac.toFixed(0)+'%';
  document.getElementById('k-'+pfx+'-res').innerHTML=s.res===null?'&mdash;':resBadge(s.res);
}
/* quadradinhos de sequencia (max. 10 ultimos resultados) */
function seqHtml(ops){
  if(!ops||!ops.length)return'<span class="seq-empty">&mdash;</span>';
  return'<span class="seq">'+ops.map(o=>{
    const cls=o.lp>0.0001?'w':(o.lp<-0.0001?'l':'z');
    const tip=o.cod+' · '+sqlToDisplay(o.d)+' · '+fmtLP(o.lp);
    return'<i class="'+cls+'" title="'+tip+'"></i>';
  }).join('')+'</span>';
}
function render(tbodyId,data,ds){
  const tb=document.getElementById(tbodyId);
  if(!data.length){tb.innerHTML='<tr><td colspan="4"><div class="rank-empty">Sem dados disponíveis.</div></td></tr>';return;}
  tb.innerHTML=data.map((row,i)=>'<tr><td>'+posBadge(i)+'<a href="#" class="rk-link" onclick="verAcao(\''+row.t+'\',\''+ds+'\');return false">'+row.t+'</a>'+(row.n>1?'<span class="ops-count">('+row.n+' ops)</span>':'')+'</td><td class="r">'+resBadge(row.res)+'</td><td class="r"><span class="num">'+row.ac.toFixed(0)+'%</span></td><td class="r">'+seqHtml(row.seq)+'</td></tr>').join('');
}

/* ── modal: extrato detalhado de uma ação ── */
function verAcao(tk,ds){
  const rows=(DADOS[ds]||[]).filter(r=>ticker(r.codigo)===tk);
  if(!rows.length)return;
  rows.sort((a,b)=>{const da=a.data_final||a.data_inicio||'',db=b.data_final||b.data_inicio||'';return db<da?-1:db>da?1:0;});
  const comRes=rows.filter(r=>r.recebido!==null);
  const wins=comRes.filter(r=>opLP(r,ds)>0.0001).length;
  const acerto=comRes.length?wins/comRes.length*100:null;
  // Resultado do modal = SOMA acumulada dos % de cada operacao (nao a media ponderada).
  let somaRes=0,temAlgum=false;
  comRes.forEach(r=>{const inv=opINV(r,ds);if(inv){somaRes+=opLP(r,ds)/inv*100;temAlgum=true;}});
  const resTot=temAlgum?somaRes:null;
  // media de dias das encerradas (migrou da tabela p/ aqui)
  const enc=rows.filter(r=>r.status==='Encerrada');
  const mediaDias=enc.length?enc.reduce((s,r)=>s+(r.dias||0),0)/enc.length:null;
  document.getElementById('ac-tk').textContent=tk;
  const ctx=document.getElementById('ac-ctx');
  const rot=ds==='cart'?'Pessoal':ds==='nit'?'Terceiros':'Simulação';
  ctx.textContent=rot;ctx.className='ac-ctx '+(ds==='cart'?'pess':ds==='nit'?'terc':'bet');
  document.getElementById('ac-resumo').innerHTML=
    '<div class="ac-stat"><div class="ac-k">Operações</div><div class="ac-v">'+rows.length+'</div></div>'
    +'<div class="ac-stat"><div class="ac-k">Acerto</div><div class="ac-v">'+(acerto===null?'&mdash;':acerto.toFixed(0)+'%')+'</div></div>'
    +'<div class="ac-stat"><div class="ac-k">Média de dias</div><div class="ac-v">'+(mediaDias===null?'&mdash;':mediaDias.toFixed(1).replace('.',','))+'</div></div>'
    +'<div class="ac-stat"><div class="ac-k">Resultado acumulado</div><div class="ac-v">'+(resTot===null?'&mdash;':resBadge(resTot))+'</div></div>';
  const today=new Date();today.setHours(0,0,0,0);
  document.getElementById('ac-ops').innerHTML=rows.map(r=>{
    const encO=r.status==='Encerrada',temRes=r.recebido!==null;
    const lp=temRes?opLP(r,ds):null;
    const res=(temRes&&opINV(r,ds))?lp/opINV(r,ds)*100:null;
    const dias=encO?(r.dias??'—'):daysBetween(today,parseSql(r.data_inicio));
    const periodo=sqlToDisplay(r.data_inicio)+(encO?(' &rarr; '+sqlToDisplay(r.data_final)):' · aberta');
    return '<div class="ac-op">'
      +'<div class="ac-op-top"><span class="code">'+r.codigo+'</span> <span class="'+(r.estrutura==='PUT'?'badge-put':'badge-call')+'">'+(r.estrutura||'')+'</span>'
      +'<span class="ac-op-res">'+(res===null?'<span class="num" style="color:#94a3b8;font-size:11px">sem cotação</span>':resBadge(res))+'</span></div>'
      +'<div class="ac-op-sub">'+periodo+' · '+dias+'d · '+fmtP(opEntrada(r,ds))+(temRes?(' &rarr; '+fmtP(r.recebido)):'')+(lp!==null?('  ·  L/P '+fmtLP(lp)):'')+'</div>'
      +'</div>';
  }).join('');
  document.getElementById('ac-ov').classList.add('show');
}
function fecharAcao(){document.getElementById('ac-ov').classList.remove('show');}
document.addEventListener('keydown',function(e){if(e.key==='Escape')fecharAcao();});
async function loadAll(){
  try{
    const cart=await fetchTabela('carteira');
    const nit=VER_NITRO?await fetchTabela('posicoes'):[];
    const bet=VER_BETHA?await fetchTabela('betha'):[];
    DADOS.cart=cart;DADOS.nit=nit;DADOS.bet=bet;
    const allDates=[...cart,...nit,...bet].filter(r=>r.data_inicio).map(r=>r.data_inicio).sort();
    const lu=document.getElementById('last-update');if(lu&&allDates.length)lu.textContent=sqlToDisplay(allDates[allDates.length-1]);
    render('cart-enc',buildEnc(cart,lpCarteira,invCarteira),'cart');
    setKpis('cart',resumo(cart,lpCarteira,invCarteira));
    if(VER_NITRO){render('nit-enc',buildEnc(nit,lpSimples,invSimples),'nit');setKpis('nit',resumo(nit,lpSimples,invSimples));}
    if(VER_BETHA){render('bet-enc',buildEnc(bet,lpSimples,invSimples),'bet');setKpis('bet',resumo(bet,lpSimples,invSimples));}
  }catch(e){
    const ids=['cart-enc'];if(VER_NITRO)ids.push('nit-enc');if(VER_BETHA)ids.push('bet-enc');
    ids.forEach(id=>{const tb=document.getElementById(id);if(tb)tb.innerHTML='<tr><td colspan="4" style="text-align:center;padding:32px;color:#999">Erro ao carregar.</td></tr>';});
  }
}
loadAll();
</script>
</body>
</html>
