<?php
require_once 'auth.php';
exigir_login();
$TITULO_PG = 'Configuração';
$CSS_PG    = 'config';
require __DIR__ . '/partials/head.php';
?>
<body>
<?php $ATIVO='config'; require __DIR__.'/partials/nav.php'; ?>

<div class="wrap">
  <h1>&#9881;&#65039; Configuração</h1>
  <div class="sub">Ajustes do sistema e credenciais para leitura das notas de corretagem.</div>

  <div class="card">
    <div class="card-title">&#128274; Senha dos PDFs da corretora</div>
    <div class="card-desc">As notas da <strong>RICO</strong> vêm protegidas por senha. Informe-a aqui uma vez — ao importar um PDF, o sistema usa essa senha automaticamente para abri-lo (a leitura acontece no seu navegador, o arquivo não é enviado a lugar nenhum).</div>

    <div class="field">
      <label>Corretora</label>
      <input type="text" id="cfg-corretora" placeholder="RICO" value="RICO">
    </div>
    <div class="field">
      <label>Senha do PDF</label>
      <div class="input-row">
        <input type="password" id="cfg-senha" placeholder="senha de abertura do PDF" autocomplete="new-password">
        <button class="toggle-eye" type="button" onclick="toggleEye()" title="Mostrar/ocultar">&#128065;&#65039;</button>
      </div>
    </div>
    <button class="btn btn-primary" onclick="salvar()">Salvar configuração</button>
    <div class="msg" id="msg"></div>

    <div class="hint">&#128161; Dica: a senha das notas da RICO costuma ser os <strong>primeiros dígitos do seu CPF</strong> (sem pontos). Se preferir, você também pode remover a senha do PDF antes (abrindo e "imprimindo para PDF") — mas com a senha salva aqui isso não é necessário.</div>
  </div>

  <div class="card">
    <div class="card-title">&#128200; Cotações automáticas (coluna Recebido)</div>
    <div class="card-desc">O sistema busca o preço de fechamento do <strong>último pregão</strong> das suas opções e preenche a coluna <strong>Recebido</strong> (na Carteira e no Nitro). A busca é feita pelo servidor, com prévia antes de gravar.</div>
    <div class="field">
      <label>Fonte de cotação</label>
      <select id="cfg-fonte" onchange="toggleFonte()">
        <option value="b3">B3 oficial — grátis, sem conta (recomendado)</option>
        <option value="oplab">OpLab — requer token (plano PRO, pago)</option>
      </select>
    </div>
    <div id="box-b3" class="hint" style="background:#ecfdf5;border-color:#a7f3d0;color:#065f46">
      &#9989; <strong>B3 oficial:</strong> usa o boletim diário de fechamento da própria B3. Gratuito, sem cadastro, nada a configurar. Pega automaticamente o último pregão disponível. Opção sem negócio no dia fica em branco.
    </div>
    <div id="box-oplab" style="display:none">
      <div class="field" style="margin-top:14px">
        <label>Token OpLab (Access-Token)</label>
        <div class="input-row">
          <input type="password" id="cfg-oplab" class="mono" placeholder="cole aqui o token da OpLab" autocomplete="new-password">
          <button class="toggle-eye" type="button" onclick="toggleEye2()" title="Mostrar/ocultar">&#128065;&#65039;</button>
        </div>
      </div>
      <div class="hint" style="background:#fffbeb;border-color:#fde68a;color:#92400e">
        &#9888;&#65039; <strong>A API da OpLab é paga</strong> (plano PRO; o cadastro dá 7 dias grátis). Só use esta opção se você assinar o PRO.<br>
        <strong>Como obter o token:</strong> crie conta em <strong>oplab.com.br</strong> &rarr; assine/ative o teste do <strong>plano PRO</strong> &rarr; em <strong>Conta &rarr; API / Tokens de acesso</strong>, gere um <strong>Access-Token</strong> e cole acima.
      </div>
    </div>
    <button class="btn btn-primary" onclick="salvar()" style="margin-top:14px">Salvar configuração</button>
  </div>

<?php if (pode_ver_nitro($_SESSION['user_papel'] ?? '')): ?>
  <div class="card">
    <div class="card-title">&#128200; Painel de Sinais (Nitro)</div>
    <div class="card-desc">Gatilho de entrada por histórico da <strong>Sala Nitro</strong>. Quando uma opção comprada cruza a rentabilidade de <strong>alerta</strong> (🟡) ou <strong>sinal</strong> (🟢), a página do Nitro destaca a linha e mostra o racional. Ajuste os limiares abaixo. <em>Estudo descritivo — não é recomendação.</em></div>
    <div class="grid2">
      <div>
        <label class="fld-lbl">Gatilho de alerta 🟡 (% de rentabilidade)</label>
        <input type="number" id="cfg-sin-alerta" step="1" min="0" placeholder="40">
      </div>
      <div>
        <label class="fld-lbl">Gatilho de sinal 🟢 (%)</label>
        <input type="number" id="cfg-sin-sinal" step="1" min="0" placeholder="50">
      </div>
      <div>
        <label class="fld-lbl">Alvo no Nitro (%)</label>
        <input type="number" id="cfg-sin-alvo" step="1" min="0" placeholder="140">
      </div>
      <div>
        <label class="fld-lbl">Margem antes do vencimento (pregões)</label>
        <input type="number" id="cfg-sin-margem" step="1" min="0" placeholder="3">
      </div>
    </div>
    <div class="hint" style="margin-top:8px">O <strong>pico histórico</strong> é medido até <strong>N pregões antes do vencimento</strong> — você nunca segura a opção até vencer, então o pico do último dia de vida não conta.</div>
    <button class="btn btn-primary" onclick="salvarSinais()" style="margin-top:14px">Salvar limiares</button>
    <span class="msg" id="msg-sinais" style="display:none;margin-left:10px"></span>
  </div>
<?php endif; ?>

  <div class="card">
    <div class="card-title">&#128176; Aporte inicial (Extrato)</div>
    <div class="card-desc">Quanto você <strong>depositou na corretora ao começar</strong>. É o ponto de partida do seu <a href="/extrato" style="color:var(--accent);font-weight:600">Extrato</a> — o saldo cresce/diminui a partir daqui com depósitos, saques e o caixa das notas. <em>Simulado: o sistema não se conecta à corretora.</em></div>
    <div class="hint" style="background:#faf8ff;border-color:#e6dcfb;color:#5b21b6">&#128194; O aporte é <strong>por carteira</strong>. Você está editando a carteira <strong id="cfg-cart-nome">ativa</strong>. Para outra carteira, troque a carteira ativa primeiro (menu do usuário ou <a href="/carteiras" style="color:var(--accent);font-weight:600">Minhas carteiras</a>).</div>
    <div class="field">
      <label>Valor do aporte inicial (R$)</label>
      <input type="text" id="cfg-aporte" placeholder="ex: 3500,00" inputmode="decimal">
    </div>
    <div class="field">
      <label>Data do aporte inicial</label>
      <input type="date" id="cfg-aporte-data" style="font-family:var(--mono)">
    </div>
    <button class="btn btn-primary" onclick="salvar()">Salvar configuração</button>
    <div class="hint" style="background:#ecfdf5;border-color:#a7f3d0;color:#065f46">&#128161; Se você fez vários depósitos iniciais (ex: duas TEDs), informe aqui o primeiro/maior e registre os demais como <strong>depósitos</strong> direto na tela de Extrato.</div>
  </div>

  <div class="card">
    <div class="card-title">&#9851;&#65039; Pontos de restauração</div>
    <div class="card-desc">Fotografias do estado completo do sistema (carteira, posições, notas, movimentações e lixeira). Use para <strong>voltar a um estado anterior</strong> se um dia de lançamentos sair errado. <strong>Um ponto é criado automaticamente antes de cada importação</strong> de nota; você também pode criar um manualmente (ex.: no fechamento do pregão).</div>
    <div class="input-row" style="margin-bottom:14px">
      <input type="text" id="cp-rotulo" placeholder="Rótulo (ex: fechamento 16/06)">
      <button class="btn btn-primary" style="white-space:nowrap" onclick="criarPonto()">&#43; Criar ponto</button>
    </div>
    <div id="cp-list" style="font-size:13px"></div>
    <div class="hint" style="background:#fef2f2;border-color:#fecaca;color:#991b1b">&#9888;&#65039; <strong>Restaurar substitui</strong> todo o estado atual pelo do ponto escolhido (o que foi feito depois é descartado). Antes de restaurar, o sistema salva um ponto automático do estado atual — então dá pra voltar atrás.</div>
    <div class="msg" id="cp-msg"></div>
  </div>

  <div class="card">
    <div class="card-title">&#128274; Sessão</div>
    <div class="card-desc">Encerrar o acesso ao sistema neste dispositivo.</div>
    <div class="logout-row"><a href="/?logout" class="btn btn-ghost">&#128274; Sair</a></div>
  </div>
</div>

<script>
async function req(action,data){const o=data?{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(data)}:{};const r=await fetch('api.php?action='+action,o);if(r.status===401){location.href='/';return{};}return r.json();}
function showMsg(t,type){const el=document.getElementById('msg');el.textContent=t;el.className='msg '+(type||'ok');el.style.display='block';setTimeout(()=>{el.style.display='none'},4000);}
function toggleEye(){const i=document.getElementById('cfg-senha');i.type=i.type==='password'?'text':'password';}
function toggleEye2(){const i=document.getElementById('cfg-oplab');i.type=i.type==='password'?'text':'password';}
function toggleFonte(){const f=document.getElementById('cfg-fonte').value;document.getElementById('box-oplab').style.display=f==='oplab'?'block':'none';document.getElementById('box-b3').style.display=f==='oplab'?'none':'block';}
async function carregar(){
  const r=await req('get_config');
  if(r&&r.success){document.getElementById('cfg-senha').value=r.config.pdf_password||'';document.getElementById('cfg-corretora').value=r.config.corretora||'RICO';document.getElementById('cfg-oplab').value=r.config.oplab_token||'';document.getElementById('cfg-fonte').value=r.config.cotacao_fonte||'b3';toggleFonte();
    // limiares do Painel de Sinais (só existem no DOM para super_admin)
    const set=(id,v,d)=>{const el=document.getElementById(id);if(el)el.value=(v!==undefined&&v!==null&&v!=='')?v:d;};
    set('cfg-sin-alerta',r.config.sinal_gatilho_alerta,40);set('cfg-sin-sinal',r.config.sinal_gatilho_sinal,50);
    set('cfg-sin-alvo',r.config.sinal_alvo,140);set('cfg-sin-margem',r.config.sinal_margem_pregoes,3);}
  // aporte agora e' POR CARTEIRA (Fase 3): vem da carteira ativa
  const w=await req('wallets_list');
  if(w&&w.success){
    const at=(w.carteiras||[]).find(c=>c.id===w.ativa);
    if(at){
      document.getElementById('cfg-cart-nome').textContent=at.nome;
      const ap=at.aporte_inicial;
      document.getElementById('cfg-aporte').value=(ap!==undefined&&ap!==null)?String(ap).replace('.',','):'';
      document.getElementById('cfg-aporte-data').value=at.aporte_inicial_data||'';
    }
  }
}
async function salvarSinais(){
  const val=id=>{const el=document.getElementById(id);const n=el?parseFloat(el.value):NaN;return isNaN(n)?null:n;};
  const payload={
    sinal_gatilho_alerta:val('cfg-sin-alerta')??40,
    sinal_gatilho_sinal :val('cfg-sin-sinal') ??50,
    sinal_alvo          :val('cfg-sin-alvo')  ??140,
    sinal_margem_pregoes:Math.max(0,Math.round(val('cfg-sin-margem')??3)),
  };
  const el=document.getElementById('msg-sinais');
  const r=await req('save_config',payload);
  if(el){el.textContent=r&&r.success?'Limiares salvos.':'Erro ao salvar.';el.className='msg '+(r&&r.success?'ok':'err');el.style.display='inline-block';setTimeout(()=>{el.style.display='none'},3500);}
}
async function salvar(){
  const pdf_password=document.getElementById('cfg-senha').value;
  const corretora=document.getElementById('cfg-corretora').value.trim()||'RICO';
  const oplab_token=document.getElementById('cfg-oplab').value.trim();
  const cotacao_fonte=document.getElementById('cfg-fonte').value;
  const apRaw=document.getElementById('cfg-aporte').value.trim().replace(/\./g,'').replace(',','.');
  const aporte_inicial=apRaw===''?'':String(parseFloat(apRaw)||0);
  const aporte_inicial_data=document.getElementById('cfg-aporte-data').value;
  // config geral do usuario (sem aporte) + aporte na carteira ativa
  const r=await req('save_config',{pdf_password,corretora,oplab_token,cotacao_fonte});
  const r2=await req('wallet_set_aporte',{aporte_inicial,aporte_inicial_data});  // sem id => carteira ativa
  if(r&&r.success&&r2&&r2.success)showMsg('Configuração salva.','ok');else showMsg((r&&r.error)||(r2&&r2.error)||'Erro ao salvar.','err');
}
/* ── Pontos de restauração ── */
function cpMsg(t,type){const el=document.getElementById('cp-msg');el.textContent=t;el.className='msg '+(type||'ok');el.style.display='block';setTimeout(()=>{el.style.display='none'},4000);}
function cpData(s){if(!s)return'';const d=new Date(s.replace(' ','T'));if(isNaN(d))return s;const z=n=>String(n).padStart(2,'0');return z(d.getDate())+'/'+z(d.getMonth()+1)+'/'+d.getFullYear()+' '+z(d.getHours())+':'+z(d.getMinutes());}
async function carregarPontos(){
  const r=await req('checkpoint_list');
  const el=document.getElementById('cp-list');
  if(!r||!r.success||!r.pontos||!r.pontos.length){el.innerHTML='<div style="color:#94a3b8;padding:4px 0 14px">Nenhum ponto ainda.</div>';return;}
  el.innerHTML='<div style="border:1px solid var(--border);border-radius:10px;overflow:hidden;margin-bottom:14px">'+r.pontos.map(p=>{
    const tag=p.tipo==='auto'
      ?'<span style="font-size:10px;background:#eef1f7;color:#64719a;padding:1px 7px;border-radius:10px;margin-left:7px">auto</span>'
      :'<span style="font-size:10px;background:#f1ecfe;color:#5b21b6;padding:1px 7px;border-radius:10px;margin-left:7px">manual</span>';
    return '<div style="display:flex;align-items:center;gap:10px;padding:9px 12px;border-bottom:1px solid var(--border)">'
      +'<div style="flex:1;min-width:0"><div style="font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">'+((p.rotulo||'—'))+tag+'</div>'
      +'<div style="font-size:11px;color:#94a3b8;font-family:var(--mono)">'+cpData(p.criado_em)+'</div></div>'
      +'<button class="btn btn-ghost" style="padding:6px 13px;font-size:12px" onclick="restaurarPonto('+p.id+')">Restaurar</button>'
      +'<span title="Excluir ponto" style="cursor:pointer;color:#cbd5e1;font-size:14px" onclick="excluirPonto('+p.id+')">&#128465;&#65039;</span>'
      +'</div>';
  }).join('')+'</div>';
}
async function criarPonto(){
  const rotulo=document.getElementById('cp-rotulo').value.trim();
  const r=await req('checkpoint_create',{rotulo});
  if(r&&r.success){document.getElementById('cp-rotulo').value='';cpMsg('Ponto de restauração criado.','ok');carregarPontos();}
  else cpMsg((r&&r.error)||'Erro ao criar ponto.','err');
}
async function restaurarPonto(id){
  if(!confirm('RESTAURAR este ponto?\n\nTodo o estado atual (carteira, posições, notas, movimentações e lixeira) será substituído pelo deste ponto. O que foi feito depois será descartado.\n\nO sistema salva um ponto automático do estado atual antes — então dá pra voltar atrás.'))return;
  const r=await req('checkpoint_restore',{id});
  if(r&&r.success){cpMsg('Estado restaurado com sucesso.','ok');carregarPontos();}
  else cpMsg((r&&r.error)||'Erro ao restaurar.','err');
}
async function excluirPonto(id){if(!confirm('Excluir este ponto de restauração?'))return;const r=await req('checkpoint_delete',{id});if(r&&r.success)carregarPontos();}
carregar();
carregarPontos();
</script>
</body>
</html>
