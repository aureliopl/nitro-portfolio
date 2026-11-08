<?php
require_once 'auth.php';
exigir_login();
$TITULO_PG = 'Minhas carteiras';
$CSS_PG    = 'carteiras';
require __DIR__ . '/partials/head.php';
?>
<body>
<?php $ATIVO='carteiras'; require __DIR__.'/partials/nav.php'; ?>

<div class="wrap">
  <h1>&#128194; Minhas carteiras</h1>
  <div class="sub">Cada carteira é uma conta independente — posições, notas, extrato e aporte próprios. A carteira <strong>ativa</strong> é a que aparece em toda a Carteira (Pessoal).</div>

  <div class="card">
    <div class="card-title">&#43; Nova carteira</div>
    <div class="card-desc">Crie quantas carteiras quiser (ex.: "Principal", "Agressiva", "Longo prazo").</div>
    <div class="nova-row">
      <input type="text" id="nova-nome" maxlength="60" placeholder="Nome da carteira" onkeydown="if(event.key==='Enter')criar()">
      <button class="btn btn-primary" onclick="criar()">Criar</button>
    </div>
    <div class="msg" id="msg-nova"></div>
  </div>

  <div class="card">
    <div class="card-title">Suas carteiras</div>
    <div class="card-desc">Use ↑ ↓ para reordenar. A carteira padrão é a que abre quando você entra.</div>
    <div class="wlist" id="wlist"><div class="wempty">Carregando…</div></div>
    <div class="msg" id="msg-lista"></div>
  </div>
</div>

<script>
async function req(action,data){const o=data?{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(data)}:{};const r=await fetch('api.php?action='+action,o);if(r.status===401){location.href='/';return null;}return r.json();}
function showMsg(id,t,type){const el=document.getElementById(id);el.textContent=t;el.className='msg '+(type||'ok');el.style.display='block';setTimeout(()=>{el.style.display='none'},4500);}
function esc(s){return (s||'').replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));}
function fmtAporte(v){return v===null||v===undefined?'<span class="w-na">sem aporte</span>':'R$ '+Number(v).toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2});}

let CART=[], ATIVA=0;

async function carregar(){
  const r=await req('wallets_list');
  if(!r||!r.success){showMsg('msg-lista','Erro ao carregar carteiras.','err');return;}
  CART=r.carteiras||[]; ATIVA=r.ativa||0;
  pintar();
}

function pintar(){
  const box=document.getElementById('wlist');
  if(!CART.length){box.innerHTML='<div class="wempty">Nenhuma carteira.</div>';return;}
  box.innerHTML=CART.map((w,i)=>{
    const ativa=w.id===ATIVA;
    const ord='<div class="w-ord">'
      +'<button class="w-arrow" title="Subir" '+(i===0?'disabled':'')+' onclick="mover('+i+',-1)">&#9650;</button>'
      +'<button class="w-arrow" title="Descer" '+(i===CART.length-1?'disabled':'')+' onclick="mover('+i+',1)">&#9660;</button>'
      +'</div>';
    const badges=(w.is_default?'<span class="w-badge def">padrão</span>':'')+(ativa?'<span class="w-badge ativa">ativa</span>':'');
    const meta='<div class="w-meta">'+fmtAporte(w.aporte_inicial)+' &middot; '+w.posicoes+' posiç'+(w.posicoes===1?'ão':'ões')+'</div>';
    const acts='<div class="w-acts">'
      +(ativa?'':'<button class="btn btn-ghost btn-sm" onclick="usar('+w.id+')">Usar</button>')
      +'<button class="btn btn-ghost btn-sm" onclick="renomear('+w.id+',this)" data-nome="'+esc(w.nome)+'">Renomear</button>'
      +(w.is_default?'':'<button class="btn btn-ghost btn-sm" onclick="tornarPadrao('+w.id+')">Tornar padrão</button>')
      +(CART.length>1?'<button class="btn btn-danger btn-sm" onclick="excluir('+w.id+',this)" data-nome="'+esc(w.nome)+'" data-pos="'+w.posicoes+'">Excluir</button>':'')
      +'</div>';
    return '<div class="wrow'+(ativa?' on':'')+'">'+ord
      +'<div class="w-main"><div class="w-nome">'+esc(w.nome)+badges+'</div>'+meta+'</div>'
      +acts+'</div>';
  }).join('');
}

async function criar(){
  const inp=document.getElementById('nova-nome');
  const nome=inp.value.trim();
  if(!nome){showMsg('msg-nova','Informe um nome.','err');return;}
  const r=await req('wallet_create',{nome});
  if(r&&r.success){inp.value='';showMsg('msg-nova','Carteira criada.','ok');carregar();}
  else showMsg('msg-nova',(r&&r.error)||'Erro ao criar.','err');
}

async function usar(id){
  const r=await req('wallet_switch',{id});
  if(r&&r.success) location.reload();   // recarrega p/ a nav e as paginas refletirem a nova ativa
  else showMsg('msg-lista',(r&&r.error)||'Erro ao trocar.','err');
}

async function renomear(id,btn){
  const atual=btn.getAttribute('data-nome')||'';
  const nome=(prompt('Novo nome da carteira:',atual)||'').trim();
  if(!nome||nome===atual)return;
  const r=await req('wallet_rename',{id,nome});
  if(r&&r.success){showMsg('msg-lista','Carteira renomeada.','ok');carregar();}
  else showMsg('msg-lista',(r&&r.error)||'Erro ao renomear.','err');
}

async function tornarPadrao(id){
  const r=await req('wallet_set_default',{id});
  if(r&&r.success){showMsg('msg-lista','Carteira padrão atualizada.','ok');carregar();}
  else showMsg('msg-lista',(r&&r.error)||'Erro.','err');
}

async function mover(i,dir){
  const j=i+dir;
  if(j<0||j>=CART.length)return;
  const ids=CART.map(w=>w.id);
  [ids[i],ids[j]]=[ids[j],ids[i]];
  const r=await req('wallet_reorder',{ids});
  if(r&&r.success){carregar();}
  else showMsg('msg-lista',(r&&r.error)||'Erro ao reordenar.','err');
}

async function excluir(id,btn){
  const nome=btn.getAttribute('data-nome')||'';
  const pos=parseInt(btn.getAttribute('data-pos')||'0',10);
  const aviso='Excluir a carteira "'+nome+'"?\n\nIsto apaga PERMANENTEMENTE '+pos+' posiç'+(pos===1?'ão':'ões')+' + notas, extrato e pontos de restauração desta carteira. Esta ação não tem como desfazer.';
  if(!confirm(aviso))return;
  const r=await req('wallet_delete',{id});
  if(r&&r.success){showMsg('msg-lista','Carteira excluída.','ok');
    if(id===ATIVA){location.reload();return;}   // se era a ativa, recarrega (nav/paginas)
    carregar();
  } else showMsg('msg-lista',(r&&r.error)||'Erro ao excluir.','err');
}

carregar();
</script>
</body>
</html>
