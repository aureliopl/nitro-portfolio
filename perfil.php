<?php
require_once 'auth.php';
exigir_login();
$TITULO_PG = 'Meu perfil';
$CSS_PG    = 'perfil';
require __DIR__ . '/partials/head.php';
?>
<body>
<?php $ATIVO='perfil'; require __DIR__.'/partials/nav.php'; ?>

<div class="wrap">
  <h1>&#128100; Meu perfil</h1>
  <div class="sub">Seus dados de acesso ao sistema.</div>

  <div class="card">
    <div class="avatar">
      <div class="ava" id="p-ava">&mdash;</div>
      <div class="ai">
        <div class="an" id="p-anome">&hellip;</div>
        <div class="ae" id="p-aemail">&hellip;</div>
        <div class="ava-acts">
          <input type="file" id="p-avafile" accept="image/png,image/jpeg,image/webp" hidden>
          <button type="button" class="btn btn-ghost btn-sm" onclick="document.getElementById('p-avafile').click()">&#128247; Enviar foto</button>
          <button type="button" class="btn btn-ghost btn-sm" id="p-avarm" onclick="removerAvatar()" style="display:none">Remover</button>
        </div>
      </div>
    </div>
    <div class="ava-hint">JPG, PNG ou WebP &middot; até 3 MB &middot; cortada em quadrado.</div>
    <div class="msg" id="msg-avatar"></div>
    <div class="card-title">Dados da conta</div>
    <div class="card-desc">Você pode entrar usando o <strong>usuário</strong> ou o <strong>e-mail</strong>.</div>
    <div class="field"><label>Nome completo</label><input type="text" id="p-nome"></div>
    <div class="field"><label>Usuário</label><input type="text" id="p-usuario" class="mono" autocomplete="username"></div>
    <div class="field"><label>E-mail</label><input type="email" id="p-email" class="mono" autocomplete="email"></div>
    <div class="field"><label>Bio</label><textarea id="p-bio" rows="3" placeholder="Uma breve descrição sobre você (opcional)."></textarea></div>
    <button class="btn btn-primary" onclick="salvarPerfil()">Salvar dados</button>
    <div class="msg" id="msg-perfil"></div>
  </div>

  <div class="card">
    <div class="card-title">&#128274; Trocar senha</div>
    <div class="card-desc">Mínimo de 6 caracteres. Você precisa informar a senha atual.</div>
    <div class="field"><label>Senha atual</label><input type="password" id="p-atual" autocomplete="current-password"></div>
    <div class="field"><label>Nova senha (mín. 6)</label><input type="password" id="p-nova" autocomplete="new-password"></div>
    <div class="field"><label>Confirmar nova senha</label><input type="password" id="p-nova2" autocomplete="new-password"></div>
    <button class="btn btn-primary" onclick="trocarSenha()">Trocar senha</button>
    <div class="msg" id="msg-senha"></div>
  </div>

  <div class="card">
    <div class="card-title">&#128274; Sessão</div>
    <div class="card-desc">Encerrar o acesso neste dispositivo.</div>
    <div class="logout-row"><a href="/?logout" class="btn btn-ghost">&#128274; Sair</a></div>
  </div>
</div>

<script>
async function req(action,data){const o=data?{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(data)}:{};const r=await fetch('api.php?action='+action,o);if(r.status===401){location.href='/';return null;}return r.json();}
function showMsg(id,t,type){const el=document.getElementById(id);el.textContent=t;el.className='msg '+(type||'ok');el.style.display='block';setTimeout(()=>{el.style.display='none'},4500);}
function inicial(s){return (s||'?').trim().charAt(0).toUpperCase();}

// Pinta o avatar grande (foto se houver, senao iniciais) + atualiza a foto da nav.
function pintarAvatar(url, nome){
  const box=document.getElementById('p-ava');
  if(url){ box.innerHTML='<img src="'+url+'" alt="">'; document.getElementById('p-avarm').style.display=''; }
  else   { box.textContent=inicial(nome); document.getElementById('p-avarm').style.display='none'; }
  const navAva=document.querySelector('.nav-ava');
  if(navAva){
    if(url){ if(navAva.tagName!=='IMG'){ const img=document.createElement('img'); img.className='nav-ava'; navAva.replaceWith(img); } document.querySelector('img.nav-ava').src=url; }
    else if(navAva.tagName==='IMG'){ const sp=document.createElement('span'); sp.className='nav-ava'; sp.textContent=inicial(nome); navAva.replaceWith(sp); }
  }
}

async function carregar(){
  const r=await req('me');
  if(!r||!r.success)return;
  const u=r.user;
  document.getElementById('p-nome').value=u.nome||'';
  document.getElementById('p-usuario').value=u.usuario||'';
  document.getElementById('p-email').value=u.email||'';
  document.getElementById('p-bio').value=u.bio||'';
  document.getElementById('p-anome').textContent=u.nome||'';
  document.getElementById('p-aemail').textContent=u.email||'';
  pintarAvatar(u.avatar, u.nome);
}

async function enviarAvatar(file){
  if(!file)return;
  const fd=new FormData(); fd.append('avatar', file);
  let r;
  try{ const resp=await fetch('api.php?action=avatar_upload',{method:'POST',body:fd}); if(resp.status===401){location.href='/';return;} r=await resp.json(); }
  catch(e){ showMsg('msg-avatar','Falha no envio. Tente novamente.','err'); return; }
  if(r&&r.success){ pintarAvatar(r.avatar, document.getElementById('p-nome').value); showMsg('msg-avatar','Foto atualizada.','ok'); }
  else showMsg('msg-avatar',(r&&r.error)||'Não foi possível enviar a foto.','err');
}
async function removerAvatar(){
  const r=await req('avatar_remove',{});
  if(r&&r.success){ pintarAvatar(null, document.getElementById('p-nome').value); showMsg('msg-avatar','Foto removida.','ok'); }
  else showMsg('msg-avatar',(r&&r.error)||'Não foi possível remover.','err');
}
document.getElementById('p-avafile').addEventListener('change',function(){ if(this.files&&this.files[0]) enviarAvatar(this.files[0]); this.value=''; });
async function salvarPerfil(){
  const nome=document.getElementById('p-nome').value.trim();
  const usuario=document.getElementById('p-usuario').value.trim();
  const email=document.getElementById('p-email').value.trim();
  const bio=document.getElementById('p-bio').value.trim();
  const r=await req('update_profile',{nome,usuario,email,bio});
  if(r&&r.success){showMsg('msg-perfil','Dados atualizados.','ok');carregar();}
  else showMsg('msg-perfil',(r&&r.error)||'Erro ao salvar.','err');
}
async function trocarSenha(){
  const atual=document.getElementById('p-atual').value;
  const nova=document.getElementById('p-nova').value;
  const nova2=document.getElementById('p-nova2').value;
  if(nova.length<6){showMsg('msg-senha','A nova senha precisa de ao menos 6 caracteres.','err');return;}
  if(nova!==nova2){showMsg('msg-senha','A confirmação não bate com a nova senha.','err');return;}
  const r=await req('change_password',{atual,nova});
  if(r&&r.success){showMsg('msg-senha','Senha alterada com sucesso.','ok');['p-atual','p-nova','p-nova2'].forEach(id=>document.getElementById(id).value='');}
  else showMsg('msg-senha',(r&&r.error)||'Erro ao trocar senha.','err');
}
carregar();
</script>
</body>
</html>
