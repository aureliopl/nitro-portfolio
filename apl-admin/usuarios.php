<?php
require __DIR__ . '/_admin.php';   // sessao + guard + $ADMIN + CSRF

// Papeis que ESTE admin pode atribuir (alimenta o <select> do formulario), com nivel.
$atribuiveis = [];
foreach (PAPEIS as $k => $v) {
    if (pode_atribuir($ADMIN['papel'], $k)) $atribuiveis[] = ['key' => $k, 'rotulo' => $v['rotulo'], 'nivel' => $v['nivel']];
}
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Usuários &middot; Nitro Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600&display=swap">
<link rel="stylesheet" href="/assets/css/apl-admin.css?v=<?= @filemtime(__DIR__.'/../assets/css/apl-admin.css') ?>">
<?php require __DIR__ . '/../security-head.php'; ?>
</head>
<body>
<?php $ADM_ATIVO='usuarios'; require __DIR__.'/partials/nav.php'; ?>

<main class="adm-main">
  <div class="adm-wrap">
    <div class="adm-head">
      <div class="eyebrow">Operação &middot; contas</div>
      <h1 class="adm-title">Usuários</h1>
    </div>

    <div class="panel" style="margin-top:0">
      <div class="panel-head">
        <div>
          <h2>Contas do sistema</h2>
          <div class="sub">Adicione, edite, redefina senha e remova usuários.</div>
        </div>
        <button class="btn btn-primary" onclick="abrirNovo()">+ Novo usuário</button>
      </div>
      <table class="utable">
        <thead><tr><th>Conta</th><th>Login</th><th>Papel</th><th>Status</th><th class="r">Ações</th></tr></thead>
        <tbody id="tbody"><tr><td colspan="5" class="empty">Carregando…</td></tr></tbody>
      </table>
    </div>
  </div>
</main>

<!-- Modal criar/editar -->
<div class="modal-bg" id="mUser">
  <div class="modal">
    <div class="modal-head"><div><span class="eyebrow" id="mUserEye">Nova conta</span><h3 id="mUserTit">Novo usuário</h3></div><button class="modal-x" onclick="fechar('mUser')">&times;</button></div>
    <div class="modal-body">
      <input type="hidden" id="f-id">
      <div class="field" id="box-avatar" style="display:none">
        <label>Avatar</label>
        <div class="ava-edit">
          <span class="ava-prev" id="av-prev"></span>
          <div class="ava-edit-acts">
            <input type="file" id="av-file" accept="image/png,image/jpeg,image/webp" hidden>
            <button type="button" class="btn btn-ghost" onclick="document.getElementById('av-file').click()">&#128247; Enviar foto</button>
            <button type="button" class="btn btn-ghost" id="av-rm" onclick="removerAvatarAdmin()" style="display:none">Remover</button>
            <div class="ava-edit-hint">JPG, PNG ou WebP · até 3 MB · cortada em quadrado.</div>
          </div>
        </div>
        <div class="msg" id="avMsg"></div>
      </div>
      <div class="grid2">
        <div class="field"><label>Primeiro nome</label><input type="text" id="f-nome"></div>
        <div class="field"><label>Sobrenome</label><input type="text" id="f-sobrenome"></div>
      </div>
      <div class="field"><label>E-mail</label><input type="email" id="f-email" class="mono" autocomplete="off"></div>
      <div class="grid2">
        <div class="field"><label>Usuário (login)</label><input type="text" id="f-usuario" class="mono" autocomplete="off"></div>
        <div class="field"><label>Papel</label><select id="f-papel"></select></div>
      </div>
      <div id="box-senha">
        <div class="grid2">
          <div class="field"><label>Senha</label>
            <div class="input-eye"><input type="password" id="f-senha" autocomplete="new-password"><button type="button" class="eye" onclick="toggleEye('f-senha',this)" aria-label="Mostrar senha">&#128065;</button></div>
          </div>
          <div class="field"><label>Confirmar senha</label>
            <div class="input-eye"><input type="password" id="f-senha2" autocomplete="new-password"><button type="button" class="eye" onclick="toggleEye('f-senha2',this)" aria-label="Mostrar senha">&#128065;</button></div>
          </div>
        </div>
        <div class="pwd-hint">Mínimo de 6 caracteres. Crie uma senha forte e anote em local seguro.</div>
      </div>
      <label class="check"><input type="checkbox" id="f-ativo" checked> Conta ativa (pode entrar)</label>
      <div class="msg" id="mUserMsg"></div>
    </div>
    <div class="modal-foot">
      <button class="btn btn-ghost" onclick="fechar('mUser')">Cancelar</button>
      <button class="btn btn-primary" id="mUserSave" onclick="salvarUser()">Salvar</button>
    </div>
  </div>
</div>

<!-- Modal redefinir senha -->
<div class="modal-bg" id="mPass">
  <div class="modal" style="max-width:430px">
    <div class="modal-head"><div><span class="eyebrow">Segurança</span><h3>Redefinir senha</h3></div><button class="modal-x" onclick="fechar('mPass')">&times;</button></div>
    <div class="modal-body">
      <input type="hidden" id="p-id">
      <div class="sub" id="p-quem" style="margin-bottom:14px;font-size:13px;color:var(--muted)"></div>
      <div class="field"><label>Nova senha (mín. 6)</label><input type="password" id="p-nova" autocomplete="new-password"></div>
      <div class="msg" id="mPassMsg"></div>
    </div>
    <div class="modal-foot">
      <button class="btn btn-ghost" onclick="fechar('mPass')">Cancelar</button>
      <button class="btn btn-primary" onclick="salvarSenha()">Redefinir senha</button>
    </div>
  </div>
</div>

<!-- Modal excluir -->
<div class="modal-bg" id="mDel">
  <div class="modal" style="max-width:450px">
    <div class="modal-head"><div><span class="eyebrow">Ação irreversível</span><h3>Excluir usuário</h3></div><button class="modal-x" onclick="fechar('mDel')">&times;</button></div>
    <div class="modal-body">
      <input type="hidden" id="d-id">
      <p style="font-size:14px;line-height:1.6;color:#54618a">Isto remove <strong id="d-quem"></strong> e
      <strong>todos os dados da conta</strong> — carteira, Nitro, notas, extrato, lixeira e pontos de restauração.
      Não há como desfazer.</p>
      <div class="msg" id="mDelMsg"></div>
    </div>
    <div class="modal-foot">
      <button class="btn btn-ghost" onclick="fechar('mDel')">Cancelar</button>
      <button class="btn btn-danger" onclick="confirmarExcluir()">Excluir conta</button>
    </div>
  </div>
</div>

<div class="toast" id="toast"></div>

<script>
const ADM = { csrf: <?= json_encode($_SESSION['admin_csrf']) ?>, id: <?= (int)$ADMIN['id'] ?>, papel: <?= json_encode($ADMIN['papel']) ?> };
const PAPEIS = <?= json_encode($atribuiveis) ?>;
const NIVEL  = { super_admin:100, administrador:80, editor:40, colaborador:10 };
const ROTULO = { super_admin:'Super administrador', administrador:'Administrador', editor:'Editor', colaborador:'Assinante' };
// Papeis oferecidos no formulario: super_admin nunca e' atribuivel pela UI.
const PAPEIS_SEL = PAPEIS.filter(p => p.key !== 'super_admin');
let USERS = [];

async function api(action, data){
  const opt = { method: data ? 'POST' : 'GET', headers: {} };
  if (data){ opt.headers['Content-Type']='application/json'; opt.headers['X-CSRF']=ADM.csrf; opt.body=JSON.stringify(data); }
  const r = await fetch('/apl-admin/api.php?action='+action, opt);
  if (r.status===401){ location.href='/apl-login'; return null; }
  return r.json();
}
function esc(s){ return (s==null?'':String(s)).replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])); }
function inicial(s){ return ((s||'?').trim().charAt(0)||'?').toUpperCase(); }
// Foto enviada se houver; senao circulo de iniciais (Gravatar foi removido).
function avaCell(u){ return u.avatar ? `<img src="${esc(u.avatar)}" alt="">` : `<span class="ini">${esc(inicial(u.nome||u.usuario))}</span>`; }
function toast(t, err){ const el=document.getElementById('toast'); el.innerHTML=(err?'&#9888;&#65039; ':'&#10003; ')+esc(t); el.className='toast show'+(err?' err':''); setTimeout(()=>el.className='toast'+(err?' err':''),2600); }
function msg(id,t,type){ const el=document.getElementById(id); el.textContent=t; el.className='msg '+type; }
function abrir(id){ document.getElementById(id).classList.add('open'); }
function fechar(id){ document.getElementById(id).classList.remove('open'); }
document.addEventListener('keydown',e=>{ if(e.key==='Escape') document.querySelectorAll('.modal-bg.open').forEach(m=>m.classList.remove('open')); });
document.querySelectorAll('.modal-bg').forEach(bg=>bg.addEventListener('click',e=>{ if(e.target===bg) bg.classList.remove('open'); }));

function opt(value, text, sel){ const o=document.createElement('option'); o.value=value; o.textContent=text; if(sel)o.selected=true; return o; }
function preencherPapeis(sel, placeholder){
  const s=document.getElementById('f-papel'); s.innerHTML='';
  if(placeholder){ const ph=opt('','Selecione…',true); ph.disabled=true; s.appendChild(ph); }
  let tem=false;
  PAPEIS_SEL.forEach(p=>{ s.appendChild(opt(p.key, p.rotulo+'  ·  lv'+p.nivel, !placeholder && p.key===sel)); if(!placeholder && p.key===sel) tem=true; });
  // edicao: se o papel atual nao esta na lista (ex.: super_admin), mostra-o p/ nao perder o valor
  if(!placeholder && sel && !tem){ s.appendChild(opt(sel, (ROTULO[sel]||sel)+(NIVEL[sel]?'  ·  lv'+NIVEL[sel]:''), true)); }
}
function toggleEye(id, btn){
  const inp=document.getElementById(id), show=inp.type==='password';
  inp.type=show?'text':'password'; btn.classList.toggle('on',show);
  btn.setAttribute('aria-label', show?'Ocultar senha':'Mostrar senha');
}
function resetEyes(){ document.querySelectorAll('#box-senha input').forEach(i=>i.type='password'); document.querySelectorAll('#box-senha .eye').forEach(b=>b.classList.remove('on')); }

async function carregar(){
  const r = await api('users_list');
  if(!r || !r.success){ document.getElementById('tbody').innerHTML='<tr><td colspan="5" class="empty">Não foi possível carregar.</td></tr>'; return; }
  USERS = r.usuarios; render();
}
function render(){
  const tb=document.getElementById('tbody');
  if(!USERS.length){ tb.innerHTML='<tr><td colspan="5" class="empty">Nenhuma conta ainda.</td></tr>'; return; }
  tb.innerHTML = USERS.map((u,idx)=>{
    const nome = esc(u.nome) + (u.sobrenome?(' '+esc(u.sobrenome)):'');
    const manage = u.pode_gerir, self = u.is_self;
    const podeDel = manage && !self, podeTog = manage && !self;
    const nivel = NIVEL[u.papel] || 0;
    const tgl = u.ativo
      ? `<button class="iconbtn" title="Desativar conta" ${podeTog?'':'disabled'} onclick="toggleAtivo(${u.id},0)">&#9210;</button>`
      : `<button class="iconbtn" title="Ativar conta" ${podeTog?'':'disabled'} onclick="toggleAtivo(${u.id},1)">&#9205;</button>`;
    return `<tr style="--i:${idx}">
      <td><div class="u-cell">${avaCell(u)}
        <div><div class="u-name">${nome}${self?'<span class="tag-self">você</span>':''}</div>
        <div class="u-handle">@${esc(u.usuario)}</div></div></div></td>
      <td class="u-email">${esc(u.email)}</td>
      <td><span class="role" data-r="${esc(u.papel)}"><span class="nm">${esc(u.papel_rotulo)}</span><span class="lv">lv${nivel}</span></span></td>
      <td><span class="status ${u.ativo?'on':'off'}"><span class="dt"></span>${u.ativo?'Ativo':'Inativo'}</span></td>
      <td><div class="row-actions">
        <button class="iconbtn" title="Editar" ${manage?'':'disabled'} onclick="abrirEditar(${u.id})">&#9998;</button>
        <button class="iconbtn" title="Redefinir senha" ${manage?'':'disabled'} onclick="abrirSenha(${u.id})">&#128273;</button>
        ${tgl}
        <button class="iconbtn danger" title="Excluir" ${podeDel?'':'disabled'} onclick="abrirExcluir(${u.id})">&#128465;</button>
      </div></td>
    </tr>`;
  }).join('');
}

function abrirNovo(){
  document.getElementById('mUserEye').textContent='Nova conta';
  document.getElementById('mUserTit').textContent='Novo usuário';
  document.getElementById('f-id').value='';
  ['f-nome','f-sobrenome','f-usuario','f-email','f-senha','f-senha2'].forEach(i=>document.getElementById(i).value='');
  document.getElementById('f-ativo').checked=true;
  document.getElementById('box-senha').style.display='';
  document.getElementById('box-avatar').style.display='none';   // avatar so na edicao (usuario precisa existir)
  resetEyes();
  preencherPapeis('', true);          // placeholder "Selecione…", sem papel pre-selecionado
  document.getElementById('mUserMsg').className='msg';
  abrir('mUser');
}
function abrirEditar(id){
  const u=USERS.find(x=>x.id===id); if(!u) return;
  document.getElementById('mUserEye').textContent='@'+u.usuario;
  document.getElementById('mUserTit').textContent='Editar usuário';
  document.getElementById('f-id').value=u.id;
  document.getElementById('f-nome').value=u.nome||'';
  document.getElementById('f-sobrenome').value=u.sobrenome||'';
  document.getElementById('f-usuario').value=u.usuario||'';
  document.getElementById('f-email').value=u.email||'';
  document.getElementById('f-ativo').checked=!!u.ativo;
  document.getElementById('box-senha').style.display='none';   // senha so via "redefinir senha"
  document.getElementById('box-avatar').style.display='';
  document.getElementById('avMsg').className='msg';
  pintarAvaPrev(u.avatar, u.nome||u.usuario);
  preencherPapeis(u.papel, false);
  document.getElementById('mUserMsg').className='msg';
  abrir('mUser');
}

// ── Avatar (no modal de edicao) ──────────────────────────────────────────────
function pintarAvaPrev(url, nome){
  const box=document.getElementById('av-prev');
  if(url){ box.innerHTML=`<img src="${esc(url)}" alt="">`; document.getElementById('av-rm').style.display=''; }
  else   { box.textContent=inicial(nome); document.getElementById('av-rm').style.display='none'; }
}
function avaUserId(){ return parseInt(document.getElementById('f-id').value,10); }
function atualizaAvaNaLista(id, url){ const u=USERS.find(x=>x.id===id); if(u){ u.avatar=url||null; render(); } }

async function enviarAvatarAdmin(file){
  const id=avaUserId(); if(!id||!file) return;
  const fd=new FormData(); fd.append('id', id); fd.append('avatar', file);
  let r;
  try{
    const resp=await fetch('/apl-admin/api.php?action=user_avatar_upload',{method:'POST',headers:{'X-CSRF':ADM.csrf},body:fd});
    if(resp.status===401){ location.href='/apl-login'; return; }
    r=await resp.json();
  }catch(e){ msg('avMsg','Falha no envio. Tente novamente.','err'); return; }
  if(r&&r.success){ pintarAvaPrev(r.avatar, document.getElementById('f-nome').value); atualizaAvaNaLista(id, r.avatar); msg('avMsg','Foto atualizada.','ok'); }
  else msg('avMsg',(r&&r.error)||'Não foi possível enviar a foto.','err');
}
async function removerAvatarAdmin(){
  const id=avaUserId(); if(!id) return;
  const r=await api('user_avatar_remove',{id});
  if(r&&r.success){ pintarAvaPrev(null, document.getElementById('f-nome').value); atualizaAvaNaLista(id, null); msg('avMsg','Foto removida.','ok'); }
  else msg('avMsg',(r&&r.error)||'Não foi possível remover.','err');
}
document.getElementById('av-file').addEventListener('change',function(){ if(this.files&&this.files[0]) enviarAvatarAdmin(this.files[0]); this.value=''; });
async function salvarUser(){
  const id=document.getElementById('f-id').value;
  const papel=document.getElementById('f-papel').value;
  const body={
    nome:document.getElementById('f-nome').value.trim(),
    sobrenome:document.getElementById('f-sobrenome').value.trim(),
    usuario:document.getElementById('f-usuario').value.trim(),
    email:document.getElementById('f-email').value.trim(),
    papel:papel,
    ativo:document.getElementById('f-ativo').checked?1:0,
  };
  let action='user_update';
  if(id){ body.id=parseInt(id,10); }
  else {
    action='user_create';
    if(!papel){ msg('mUserMsg','Selecione um papel.','err'); return; }
    const s1=document.getElementById('f-senha').value, s2=document.getElementById('f-senha2').value;
    if(s1.length<6){ msg('mUserMsg','A senha precisa de ao menos 6 caracteres.','err'); return; }
    if(s1!==s2){ msg('mUserMsg','As senhas não conferem — digite a mesma senha nos dois campos.','err'); return; }
    body.senha=s1;
  }
  const r=await api(action, body);
  if(r&&r.success){ fechar('mUser'); toast(id?'Usuário atualizado.':'Usuário criado.'); carregar(); }
  else { msg('mUserMsg',(r&&r.error)||'Não foi possível salvar.','err'); }
}

function abrirSenha(id){
  const u=USERS.find(x=>x.id===id); if(!u) return;
  document.getElementById('p-id').value=u.id;
  document.getElementById('p-nova').value='';
  document.getElementById('p-quem').textContent='Nova senha para '+(u.nome||u.usuario)+' (@'+u.usuario+').';
  document.getElementById('mPassMsg').className='msg';
  abrir('mPass');
}
async function salvarSenha(){
  const id=parseInt(document.getElementById('p-id').value,10);
  const nova=document.getElementById('p-nova').value;
  if(nova.length<6){ msg('mPassMsg','A senha precisa de ao menos 6 caracteres.','err'); return; }
  const r=await api('user_reset_password',{id,nova});
  if(r&&r.success){ fechar('mPass'); toast('Senha redefinida.'); }
  else { msg('mPassMsg',(r&&r.error)||'Não foi possível redefinir.','err'); }
}

async function toggleAtivo(id,ativo){
  const r=await api('user_toggle_active',{id,ativo});
  if(r&&r.success){ toast(ativo?'Conta ativada.':'Conta desativada.'); carregar(); }
  else { toast((r&&r.error)||'Não foi possível alterar.',true); }
}

function abrirExcluir(id){
  const u=USERS.find(x=>x.id===id); if(!u) return;
  document.getElementById('d-id').value=u.id;
  document.getElementById('d-quem').textContent=(u.nome||u.usuario)+' (@'+u.usuario+')';
  document.getElementById('mDelMsg').className='msg';
  abrir('mDel');
}
async function confirmarExcluir(){
  const id=parseInt(document.getElementById('d-id').value,10);
  const r=await api('user_delete',{id});
  if(r&&r.success){ fechar('mDel'); toast('Usuário excluído.'); carregar(); }
  else { msg('mDelMsg',(r&&r.error)||'Não foi possível excluir.','err'); }
}

carregar();
</script>
</body>
</html>
