<?php
require_once 'auth.php';
exigir_login();
$TITULO_PG = 'Carteira';
$CSS_PG    = ['carteira', 'grafico'];   // grafico.css = bloco Evolução (Fase 4.2)
require __DIR__ . '/partials/head.php';

// Carteiras simples visíveis no gráfico (só super_admin) — usado no botão da aba
// "Carteiras" (HTML) e na config do JS (Evolução). Definido aqui p/ estar pronto
// antes do HTML da aba.
$evSim = [];
if (function_exists('pode_ver_nitro') && pode_ver_nitro($_SESSION['user_papel'] ?? '')) $evSim['nitro'] = ['nome' => 'Nitro', 'cor' => '#ea580c', 'tab' => 'posicoes'];
if (function_exists('pode_ver_betha') && pode_ver_betha($_SESSION['user_papel'] ?? '')) $evSim['betha'] = ['nome' => 'Betha', 'cor' => '#0e7490', 'tab' => 'betha'];
?>
<body>
<?php $ATIVO='carteira'; require __DIR__.'/partials/nav.php'; ?>

<div class="wrap">
  <div class="cart-head">
    <span class="cart-head-lbl">&#128194; Carteira ativa:</span>
    <strong class="cart-head-nome"><?= htmlspecialchars($navCartAtiva ?? 'Principal') ?></strong>
    <a href="/carteiras" class="cart-head-link">trocar / gerenciar</a>
  </div>
  <!-- PATRIMÔNIO HOJE (Fase 4.3) — "onde estou": a fotografia do último pregão.
       Vem da série histórica (não dos cards), por isso respeita as regras de
       borda de preço: opção vencida vale R$ 0,00 aqui, mesmo que o card ainda
       mostre o último preço buscado. -->
  <div class="phoje" id="phoje" style="display:none">
    <div class="ph-now">
      <div class="ph-k" title="Fotografia do último pregão, vinda da série histórica — não dos cards abaixo. Aqui uma opção já vencida vale R$ 0,00 (ela virou pó), então este bloco pode divergir de propósito do card Resultados, que mostra o último preço buscado. Quando divergirem, a série é a que está certa.">Patrimônio hoje <span class="ph-ref" id="ph-ref"></span></div>
      <div class="ph-v" id="ph-v">&mdash;</div>
      <div class="ph-dia" id="ph-dia" title="Variação do dia sobre o patrimônio de ONTEM (retorno diário padrão). A RICO mostra a mesma variação em R$, mas divide pelo valor de HOJE (em bolsa) — por isso o % dela fica um pouco maior."></div>
    </div>
    <div class="ph-grid">
      <div class="ph-it" title="Dinheiro livre na conta: aporte inicial + aportes − saques + o efeito de caixa das notas. Mesma conta do Extrato.">
        <span class="ph-it-k">Caixa disponível</span><span class="ph-it-v" id="ph-caixa">&mdash;</span></div>
      <div class="ph-it" title="Estimativa da garantia disponível na corretora ≈ caixa disponível. Na RICO, garantia = patrimônio − deságio; opções COMPRADAS têm deságio ≈ 100% (não contam como garantia), então sobra praticamente só o caixa. É uma estimativa: não temos a tabela de deságio da RICO (a corretora pode conceder um resíduo pequeno), e posições VENDIDAS exigiriam margem, que não está refletida aqui.">
        <span class="ph-it-k">Garantia <small>estimada</small></span><span class="ph-it-v" id="ph-garantia">&mdash;</span></div>
      <div class="ph-it" title="Capital aplicado nas posições abertas: preço médio × lote. A vendida entra negativa (é um crédito recebido, não capital investido). É a base de custo do 'Em bolsa a mercado' ao lado.">
        <span class="ph-it-k">Capital <small>aplicado</small></span><span class="ph-it-v" id="ph-capital">&mdash;</span></div>
      <div class="ph-it" title="Quanto as posições abertas valem hoje a mercado. A vendida entra negativa (é um crédito, não capital aplicado).">
        <span class="ph-it-k">Em bolsa <small>a mercado</small></span><span class="ph-it-v" id="ph-bolsa">&mdash;</span></div>
      <div class="ph-it" title="Lucro/prejuízo das posições AINDA ABERTAS: valor a mercado − capital aplicado. Ainda é preço de tela, não virou dinheiro.">
        <span class="ph-it-k">Não realizado <small>abertas</small></span><span class="ph-it-v" id="ph-nreal">&mdash;</span></div>
      <div class="ph-it" title="Resultado das posições já encerradas, líquido das taxas de corretagem. Este já é dinheiro.">
        <span class="ph-it-k">Realizado <small>líq. de taxas</small></span><span class="ph-it-v" id="ph-real">&mdash;</span></div>
    </div>
  </div>

  <div class="stat-row">
    <div class="gcard">
      <div class="gcard-title">Resultados</div>
      <div class="metric sub-line" title="Capital nas posições abertas hoje (preço médio × lote). A vendida reduz (você recebeu o prêmio). = 'valor aplicado' da RICO."><span class="m-lbl">Capital aplicado</span><span class="m-val" id="st-investido">&mdash;</span></div>
      <div class="metric sub-line" title="Resultado das posições abertas sobre o CUSTO (capital aplicado) — o mesmo denominador que a RICO usa no % de cada ativo. Atenção: o header 'Resultado total' da RICO divide pelo valor ATUAL (não pelo custo), então lá aparece um % maior, incoerente com os próprios % por ativo dela."><span class="m-lbl">P&amp;L aberto</span><span class="m-val" id="st-carteira">&mdash;</span></div>
      <div class="metric sub-line" title="Quanto as abertas valem hoje a mercado (cotação × lote). A vendida reduz. = 'total em bolsa' da RICO. (Capital a mercado − Capital aplicado = P&L aberto.)"><span class="m-lbl">Capital a mercado</span><span class="m-val" id="st-patrimonio">&mdash;</span></div>

      <div class="grp-sep">Encerradas &middot; realizado</div>
      <div class="metric sub-line"><span class="m-lbl">P&amp;L encerrado</span><span class="m-val" id="st-realizado">&mdash;</span></div>

      <div class="grp-sep">Outros custos</div>
      <div class="metric sub-line"><span class="m-lbl">Taxas / emolumentos <span class="m-sub" id="st-notas-cnt"></span></span><span class="m-val" id="st-taxas">&mdash;</span></div>

      <div class="metric total" title="Resultado de tudo: P&L aberto + P&L encerrado − taxas. O % é sobre o seu aporte (do Extrato): 'do que depositei, estou X%'."><span class="m-lbl">Líquido total</span><span class="m-val" id="st-liquido">&mdash;</span></div>
    </div>
    <div class="gcard">
      <div class="gcard-title">Operações</div>
      <div class="metric big"><span class="m-lbl">Ordens<span class="m-sub" id="st-desde"></span></span><span class="m-val" id="st-total">&mdash;</span></div>
      <div class="metric"><span class="m-lbl">Abertas</span><span class="m-val" id="st-abertas">&mdash;</span></div>
      <div class="metric"><span class="m-lbl">Encerradas</span><span class="m-val" id="st-encerradas">&mdash;</span></div>
      <div class="metric" title="Posições vendidas (lançadas/descobertas) ainda abertas — você recebeu o prêmio e precisa recomprar ou aguardar o vencimento."><span class="m-lbl">Vendidas <span class="m-sub">lançadas &lowast;</span></span><span class="m-val" id="st-vendidas">&mdash;</span></div>
      <div class="metric" title="% das posições abertas (com cotação) que estão no positivo."><span class="m-lbl">Acerto <span class="m-sub">abertas</span></span><span class="m-val" id="st-acerto-ab">&mdash;</span></div>
      <div class="metric" title="% das posições encerradas que deram lucro."><span class="m-lbl">Acerto <span class="m-sub">encerradas</span></span><span class="m-val" id="st-acerto-enc">&mdash;</span></div>
      <div class="gcard-foot">atualizado em <span id="last-update">&hellip;</span></div>
    </div>
    <div class="gcard nova-card">
      <div class="gcard-title">&#43; Nova Posição</div>
      <div class="nova-form">
        <div class="fld f-cod"><label>Código</label><input type="text" id="n-cod" placeholder="ex: BOVAG52" style="text-transform:uppercase"></div>
        <div class="fld f-dir"><label>Direção</label>
          <select id="n-dir" title="Comprada: você paga o prêmio (titular). Vendida: você recebe o prêmio (lançada/descoberta).">
            <option value="C">Comprada</option>
            <option value="V">Vendida</option>
          </select>
        </div>
        <div class="fld f-data"><label>Início</label><input type="date" id="n-data"></div>
        <div class="fld f-preco"><label>Preço médio</label><input type="number" id="n-preco" placeholder="ex: 0,50" step="0.001" min="0"></div>
        <div class="fld f-lote"><label>Lote</label><input type="number" id="n-lote" placeholder="ex: 100" step="1" min="1"></div>
        <div class="fld f-rec"><label>Recebido <span class="opt">opc.</span></label><input type="number" id="n-rec" placeholder="ex: 0,54" step="0.001" min="0"></div>
        <div class="fld f-btn"><button class="btn btn-primary" onclick="criarPosicao()">Adicionar</button></div>
      </div>
      <div class="tipo-auto">
        <span id="n-tipo-hint" class="tipo-hint">tipo: detectado pelo código &darr;</span>
        <span id="n-tipo-badge" class="tipo-badge" style="display:none"></span>
        <span id="n-tipo-auto-tag" class="tipo-tag" style="display:none">(automático)</span>
        <select id="n-est" style="display:none;width:auto;margin:0">
          <option value="">Escolha o tipo…</option>
          <option value="CALL">CALL</option>
          <option value="PUT">PUT</option>
        </select>
      </div>
      <div class="msg" id="create-msg"></div>
    </div>
  </div>

  <!-- EVOLUÇÃO (Fase 4.2) — "como cheguei aqui": TWR ou patrimônio, com comparadores -->
  <div class="ev" id="ev">
    <div class="ev-top">
      <h2>&#128200; Evolução</h2>
      <div class="ev-abas" role="tablist">
        <button class="ev-aba on" id="ev-aba-rent" role="tab" onclick="evAba('rent')" title="Rentabilidade ponderada pelo tempo (TWR): neutraliza aportes e saques — é a única forma de comparar sua carteira com o CDI na mesma linha.">Rentabilidade</button>
        <button class="ev-aba" id="ev-aba-patr" role="tab" onclick="evAba('patr')" title="Patrimônio em R$: caixa disponível + posições a mercado, pregão a pregão.">Patrimônio</button>
<?php if (!empty($evSim)): ?>
        <button class="ev-aba" id="ev-aba-cart" role="tab" onclick="evAba('cart')" title="Confronto entre as suas carteiras (Minha carteira × Nitro × Betha) na MESMA base: soma pura do %, para poderem ser comparadas lado a lado.">Carteiras</button>
<?php endif; ?>
      </div>
      <div class="ev-pers" id="ev-pers"></div>
      <div class="ev-datas" id="ev-datas">
        <label for="ev-de">de</label><input type="date" id="ev-de">
        <label for="ev-ate">até</label><input type="date" id="ev-ate">
        <button class="ev-per" onclick="evPersonalizado()">Aplicar</button>
      </div>
    </div>
    <div class="ev-head">
      <span class="ev-val" id="ev-val">&mdash;</span>
      <span class="ev-ctx" id="ev-ctx"></span>
    </div>
    <div class="ev-chips" id="ev-chips"></div>
    <div class="ev-box"><div id="ev-graf"></div></div>
    <div class="ev-foot" id="ev-foot"></div>
  </div>

  <div class="section">
    <div class="section-header">
      <h2>&#128203; Posições Abertas</h2><span class="badge-count" id="cnt-abertas">&mdash;</span>
      <span class="msg" id="cot-msg" style="margin:0"></span>
      <button class="btn btn-del btn-sm" style="margin-left:auto" onclick="buscarCotacoes()" title="Buscar fechamento do último pregão (B3 grátis ou OpLab — definido em Config)">&#8635; Buscar cotações</button>
    </div>
    <div class="tbl-wrap">
      <table>
        <thead><tr id="head-abertas">
          <th class="sortable" data-k="codigo">Código<span class="arr">▲▼</span></th>
          <th class="sortable" data-k="estrutura">Tipo<span class="arr">▲▼</span></th>
          <th class="sortable" data-k="data_inicio">Início<span class="arr">▲▼</span></th>
          <th class="sortable r" data-k="dias">Dias<span class="arr">▲▼</span></th>
          <th class="sortable r" data-k="preco_medio">Preço Médio<span class="arr">▲▼</span></th>
          <th class="sortable r" data-k="lote">Lote<span class="arr">▲▼</span></th>
          <th class="sortable r" data-k="investido">Investido<span class="arr">▲▼</span></th>
          <th class="sortable r" data-k="recebido">Recebido<span class="arr">▲▼</span></th>
          <th class="sortable r" data-k="posicao">Posição<span class="arr">▲▼</span></th>
          <th class="sortable r" data-k="lp">R$ L/P<span class="arr">▲▼</span></th>
          <th class="sortable r" data-k="resultado">Result.<span class="arr">▲▼</span></th>
          <th class="sortable r" data-k="peso">% Peso<span class="arr">▲▼</span></th>
          <th>Ações</th>
        </tr></thead>
        <tbody id="tbody-abertas"></tbody>
        <tfoot id="foot-abertas"></tfoot>
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
          <th class="sortable r" data-k="preco_medio">Preço Médio<span class="arr">▲▼</span></th>
          <th class="sortable r" data-k="lote">Lote<span class="arr">▲▼</span></th>
          <th class="sortable r" data-k="investido">Investido<span class="arr">▲▼</span></th>
          <th class="sortable r" data-k="recebido">Recebido<span class="arr">▲▼</span></th>
          <th class="sortable r" data-k="posicao">Posição<span class="arr">▲▼</span></th>
          <th class="sortable r" data-k="lp">R$ L/P<span class="arr">▲▼</span></th>
          <th class="sortable r" data-k="resultado">Result.<span class="arr">▲▼</span></th>
          <th>Ações</th>
        </tr></thead>
        <tbody id="tbody-enc"></tbody>
        <tfoot id="foot-enc"></tfoot>
      </table>
    </div>
  </details>

  <!-- RESULTADO POR PERÍODO — Ano > Mês > Dia (um section por ano), duas lentes.
       Realizado (encerradas, pela data_final) | A mercado (variação do patrimônio
       − aportes, que inclui as abertas). Fase 4.3 -->
  <div class="periodo-head">
    <h2>&#128197; Resultado por período</h2>
    <div class="lente" role="tablist">
      <button class="lente-b on" id="lt-real" role="tab" onclick="setLente('realizado')" title="Só as posições ENCERRADAS, pela data de encerramento. É o dinheiro que já entrou ou saiu.">Realizado</button>
      <button class="lente-b" id="lt-merc" role="tab" onclick="setLente('mercado')" title="Variação do patrimônio no período menos os aportes/saques — inclui as posições ainda abertas pela marcação a mercado. É assim que a corretora diz 'seu julho rendeu X'.">A mercado</button>
    </div>
    <span class="periodo-sub" id="periodo-sub">Ano &rarr; Mês &rarr; Dia &middot; clique na linha para abrir</span>
  </div>
  <div id="periodo-anos"></div>
  <div class="periodo-foot" id="periodo-foot"></div>

  <div class="legend" id="legend-short">
    <span class="short-star">&lowast;</span> <strong>Posição vendida (lançada / descoberta).</strong>
    Você <strong>recebeu</strong> o prêmio na abertura (operação “a crédito”) — o “Preço Médio” aqui é esse prêmio por unidade.
    O resultado é <strong>invertido</strong>: você lucra quando o preço da opção <strong>cai</strong> (recompra mais barato, ou ela vira pó no vencimento e você fica com o prêmio).
    <br><span style="opacity:.85">Na <strong>tabela</strong> cada linha mostra o prêmio negociado (bruto, positivo). Nos <strong>cards de resumo</strong> a vendida é <strong>descontada</strong> (é um crédito, não capital aplicado) — por isso o “Valor aplicado” lá bate com a corretora. Os dois estão certos: a tabela é o diário de negócios; o resumo é o espelho da conta.</span>
  </div>
</div>

<!-- MODAIS -->
<div class="modal-overlay" id="modal-close">
  <div class="modal">
    <h3>&#128274; Encerrar posição</h3>
    <p>Confirma o encerramento de <span class="modal-code" id="mc-code"></span>?</p>
    <div class="modal-fields">
      <label>Data Final</label><input type="date" id="mc-data">
      <label id="mc-rec-lbl">Recebido (preço de venda por unidade)</label><input type="number" id="mc-rec" step="0.001" min="0" placeholder="ex: 1.25">
      <label>Quantidade a encerrar <span id="mc-lote-tot" style="font-weight:400;color:var(--muted)"></span></label><input type="number" id="mc-lote" step="1" min="1" placeholder="lote total">
    </div>
    <div class="modal-hint" id="mc-parcial-hint" style="display:none;font-size:12px;color:var(--muted);margin:-4px 2px 10px">Encerramento <strong>parcial</strong>: o restante continua aberto numa linha separada.</div>
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

<!-- ── Detalhe do dia (o que foi encerrado naquela data — base NORMAL da carteira) ── -->
<div class="modal-overlay" id="modal-dia">
  <div class="modal" style="max-width:960px;text-align:left">
    <h3 id="dia-titulo" style="text-align:center">Operações do dia</h3>
    <div id="dia-body" style="max-height:60vh;overflow:auto;margin:14px 0"></div>
    <div class="modal-actions">
      <button class="btn btn-primary" onclick="fecharModal()">Fechar</button>
    </div>
  </div>
</div>

<!-- MODAL: detalhe da ação (da opção clicada) -->
<div class="ac-ov" id="ac-ov" onclick="if(event.target===this)fecharAcao()">
  <div class="ac-modal" role="dialog" aria-modal="true">
    <div class="ac-head">
      <div class="ac-htitle"><span class="ac-tk" id="ac-tk">&mdash;</span><span class="ac-ctx pess">Pessoal</span></div>
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

<script src="/assets/js/grafico.js?v=<?= @filemtime(__DIR__.'/assets/js/grafico.js') ?>"></script>
<script>
const TABELA='carteira';
let allRows=[], NOTAS=[], APORTE=0, modalId=null, modalLote=0;
/* custos de corretagem rateados por operação (id → R$) + reconciliação com o total de taxas */
let CUSTOS={}, TOT_TAXAS=0;
/* período exibido na tabela de Encerradas: 'AAAA-MM' ou 'all' (null = ainda não definido → mês corrente) */
let PERIODO=null;
/* consolidado (Fase 4.3): árvore Ano→Mês→Dia da lente "A mercado", vinda pronta do servidor */
let CONSOL=[], LENTE='realizado';
const MESES_ABR=['JAN','FEV','MAR','ABR','MAI','JUN','JUL','AGO','SET','OUT','NOV','DEZ'];
let sortState={abertas:{k:'data_inicio',dir:'desc'},enc:{k:'data_final',dir:'desc'}};
let detectedTipo=null;

/* ── helpers ── */
function sqlToDisplay(d){if(!d)return'—';const[y,m,dd]=d.split('-');return dd+'/'+m+'/'+y;}
function fmtMoney(v){if(v===null||v===undefined||v==='')return'<span class="dashc">—</span>';return'R$ '+Number(v).toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2});}
function fmtMoneyPlain(v){return'R$ '+Number(v).toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2});}
function signMoney(v){const s=v<0?'-':'+';return s+'R$ '+Math.abs(v).toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2});}
function showMsg(id,t,type){const el=document.getElementById(id);el.textContent=t;el.className='msg '+(type||'ok');el.style.display='block';setTimeout(()=>{el.style.display='none'},4500);}
async function req(action,data,qs){const o=data?{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(data)}:{};const r=await fetch('api.php?action='+action+'&tabela='+TABELA+(qs||''),o);if(r.status===401){location.href='/';return{};}return r.json();}
function detectarTipoJS(cod){cod=(cod||'').toUpperCase().trim();if(cod.length<5)return null;const L=cod[4];if(L>='A'&&L<='L')return'CALL';if(L>='M'&&L<='X')return'PUT';return null;}
function shortMark(r){return r.direcao==='V'?'<sup class="short-star" title="Venda descoberta — posição vendida (lançada). Veja a legenda abaixo.">&lowast;</sup>':'';}
function ehManual(r){return r.origem==='manual'||Number(r.fecho_manual)===1;}
function manualDot(r){return ehManual(r)?'<span class="manual-dot" title="Lançado/encerrado manualmente — provisório. Ao importar a nota de corretagem deste ativo, os dados serão atualizados pela nota e este sinal some."></span>':'';}

/* ── cálculos da carteira ── */
function isShort(r){return r.direcao==='V';}                         /* posição vendida (lançada/descoberta) */
function investido(r){return r.preco_medio*r.lote;}                  /* base de capital por linha (sempre +, usada na tabela/peso) */
function posicao(r){return r.recebido===null?null:r.recebido*r.lote;}
/* versões "líquidas" p/ os cards: a VENDIDA reduz o capital (você recebeu o prêmio, não aplicou) — bate com "valor aplicado"/"total em bolsa" da RICO */
function invNet(r){return isShort(r)?-investido(r):investido(r);}
function posNet(r){return r.recebido===null?0:(isShort(r)?-posicao(r):posicao(r));}
/* na vendida o resultado é invertido: lucra quando o prêmio cai */
function lpR(r){if(r.recebido===null)return null;const d=(r.recebido-r.preco_medio)*r.lote;return isShort(r)?-d:d;}
function calcRes(r){if(r.recebido===null||!r.preco_medio)return null;const p=(r.recebido-r.preco_medio)/r.preco_medio*100;return isShort(r)?-p:p;}
function resBadge(pct){if(pct===null)return'<span class="dashc">—</span>';const fmt=Math.abs(pct).toFixed(2).replace('.',',')+' %';if(Math.abs(pct)<0.005)return'<span class="b-zero">0,00 %</span>';return pct<0?'<span class="b-neg">-'+fmt+'</span>':'<span class="b-pos">+'+fmt+'</span>';}
function lpBadge(v){if(v===null)return'<span class="dashc">—</span>';if(Math.abs(v)<0.005)return'<span class="num">R$ 0,00</span>';return'<span class="num" style="color:'+(v<0?'var(--neg)':'var(--pos)')+'">'+signMoney(v)+'</span>';}

async function loadAll(){const[rows,notas,ext,cst,con]=await Promise.all([req('list'),req('list_notas'),req('list_extrato'),req('custos_ops'),req('serie_consolidado')]);if(!rows)return;allRows=rows;NOTAS=Array.isArray(notas)?notas:[];
  CUSTOS=(cst&&cst.custos)||{};TOT_TAXAS=(cst&&+cst.total_taxas)||0;
  CONSOL=(con&&con.anos)||[];
  APORTE=(ext&&ext.aporte_inicial>0)?+ext.aporte_inicial:0;
  if(ext&&Array.isArray(ext.movimentacoes))ext.movimentacoes.forEach(m=>{if(m.tipo==='aporte')APORTE+=+m.valor;});
  renderStats(rows);renderPatrimonioHoje(con&&con.hoje);renderAbertas();montarFiltroPeriodo();renderEncerradas();renderPeriodos();updateTag(rows);document.getElementById('legend-short').style.display=rows.some(isShort)?'block':'none';
  evCarregar();}   /* a série é cache e o api.php a reconstrói no shutdown: recarrega o gráfico junto */
function updateTag(rows){if(!rows.length)return;const ds=rows.filter(r=>r.data_inicio).map(r=>r.data_inicio).sort();const el=document.getElementById('last-update');if(el&&ds.length)el.textContent=sqlToDisplay(ds[ds.length-1]);}

function renderStats(rows){
  const enc=rows.filter(r=>r.status==='Encerrada'),abr=rows.filter(r=>r.status==='Aberta');
  document.getElementById('st-total').textContent=rows.length;
  document.getElementById('st-abertas').textContent=abr.length;
  document.getElementById('st-encerradas').textContent=enc.length;
  document.getElementById('cnt-abertas').textContent=abr.length;
  document.getElementById('cnt-enc').textContent=enc.length;
  // vendidas (lançadas/descobertas) ainda abertas
  document.getElementById('st-vendidas').textContent=abr.filter(isShort).length;
  // % de acerto (posições no positivo): abertas com cotação e encerradas
  const abRec=abr.filter(r=>r.recebido!==null);
  setAcerto('st-acerto-ab', abRec.length?abRec.filter(r=>lpR(r)>0.0001).length/abRec.length*100:null);
  setAcerto('st-acerto-enc', enc.length?enc.filter(r=>lpR(r)>0.0001).length/enc.length*100:null);
  // desde (primeiro lançamento)
  const ds=rows.filter(r=>r.data_inicio).map(r=>r.data_inicio).sort();
  document.getElementById('st-desde').textContent=ds.length?' · desde '+sqlToDisplay(ds[0]):'';

  // ── CAPITAL ── valor aplicado net (vendida reduz, = RICO). APORTE (do Extrato) é só a base do %.
  let invAb=0;abr.forEach(r=>invAb+=invNet(r));             // valor aplicado nas abertas (= "valor aplicado" RICO)
  document.getElementById('st-investido').textContent=fmtMoneyPlain(invAb);

  // ── ABERTAS (não realizado) ──
  const mercadoAb=abr.filter(r=>r.recebido!==null).reduce((s,r)=>s+posNet(r),0); // valor a mercado (= "total em bolsa" RICO)
  document.getElementById('st-patrimonio').textContent=fmtMoneyPlain(mercadoAb);
  let aPnl=0,aInv=0;abr.filter(r=>r.recebido!==null).forEach(r=>{aPnl+=lpR(r);aInv+=invNet(r);});
  setPnl('st-carteira',aInv?aPnl/aInv*100:null,aPnl,aInv);

  // ── ENCERRADAS (realizado) ──
  let ePnl=0,eInv=0;enc.forEach(r=>{ePnl+=lpR(r);eInv+=invNet(r);});
  setPnl('st-realizado',eInv?ePnl/eInv*100:null,ePnl,eInv);

  // ── CUSTOS (somente Carteira) ──
  const totalTaxas=NOTAS.reduce((s,n)=>s+parseFloat(n.total_taxas||0),0);
  const elT=document.getElementById('st-taxas');
  elT.className='m-val'+(totalTaxas>0.005?' neg':'');
  elT.textContent=totalTaxas>0.005?'-'+fmtMoneyPlain(totalTaxas):fmtMoneyPlain(0);
  document.getElementById('st-notas-cnt').textContent=NOTAS.length?NOTAS.length+(NOTAS.length>1?' notas':' nota'):'';

  // ── RESULTADO LÍQUIDO TOTAL (headline) ── P&L aberto + P&L encerrado − taxas; % sobre o APORTE real (do Extrato)
  const liquido=(aPnl+ePnl)-totalTaxas;
  const pctLiq=APORTE>0?liquido/APORTE*100:null;
  const elL=document.getElementById('st-liquido');
  const clsL=liquido>=0.005?'pos':liquido<=-0.005?'neg':'';
  elL.className='m-val '+clsL;
  elL.innerHTML=signMoney(liquido)+(pctLiq!==null?'<span class="m-sub '+clsL+'">('+(pctLiq>=0?'+':'')+pctLiq.toFixed(2).replace('.',',')+'%)</span>':'');
}
function setAcerto(id,pct){const el=document.getElementById(id);if(!el)return;if(pct===null){el.innerHTML='<span class="dashc">—</span>';return;}el.textContent=pct.toFixed(1).replace('.',',')+'%';}
function setPnl(id,pct,valR,base){
  const el=document.getElementById(id);
  if(pct===null||!base){el.innerHTML='<span class="dashc">—</span>';return;}
  const cls=pct>=0.005?'pos':pct<=-0.005?'neg':'';
  el.className='m-val '+cls;
  el.innerHTML=signMoney(valR)+'<span class="m-sub '+cls+'">('+(pct>=0?'+':'')+pct.toFixed(2).replace('.',',')+'%)</span>';
}

/* ══ PATRIMÔNIO HOJE (Fase 4.3) ══════════════════════════════════════════════
   "Onde estou": caixa + posições a mercado, com realizado e não realizado
   separados. Os números vêm da SÉRIE (`serie_consolidado`), não dos cards —
   ela aplica as regras de borda de preço (opção vencida vale R$ 0,00), então
   pode divergir de propósito do card Resultados. Quando divergir, a série é a
   que está certa: `php series_cli.php aceite` nomeia o motivo posição a posição. */
function renderPatrimonioHoje(h){
  const box=document.getElementById('phoje');
  if(!h){box.style.display='none';return;}          /* sem série ainda: a faixa não aparece */
  box.style.display='';
  document.getElementById('ph-v').textContent=fmtMoneyPlain(+h.patrimonio);
  document.getElementById('ph-ref').textContent='· pregão de '+sqlToDisplay(h.data);
  document.getElementById('ph-caixa').textContent=fmtMoneyPlain(+h.caixa);
  /* Garantia estimada ≈ caixa: opções compradas têm deságio ~100% na RICO, então
     não contam como garantia e sobra praticamente só o caixa. Estimativa honesta
     (sem a tabela de deságio da RICO e sem a margem de eventuais vendidas). */
  phValor('ph-garantia',+h.caixa);
  document.getElementById('ph-capital').textContent=fmtMoneyPlain(+h.capital_aplicado);
  document.getElementById('ph-bolsa').textContent=fmtMoneyPlain(+h.valor_mercado);
  phValor('ph-nreal',+h.nao_realizado);
  phValor('ph-real',(+h.realizado)-(+h.custos));    /* realizado já líquido das taxas */
  const d=h.dia||{},el=document.getElementById('ph-dia');
  if(d.valor===null||d.valor===undefined){el.innerHTML='<span class="ph-dia-lbl">primeiro pregão da série</span>';return;}
  const cls=d.valor>=0.005?'pos':d.valor<=-0.005?'neg':'';
  el.className='ph-dia '+cls;
  el.innerHTML='<span class="ph-dia-lbl">no dia</span>'+signMoney(d.valor)+
    (d.pct!==null?' <span class="ph-dia-pct">('+(d.pct>=0?'+':'−')+Math.abs(d.pct).toFixed(2).replace('.',',')+'%)</span>':'');
}
function phValor(id,v){const el=document.getElementById(id);el.className='ph-it-v '+(v>=0.005?'pos':v<=-0.005?'neg':'');el.textContent=signMoney(v);}

/* ── ordenação ── */
function valForSort(r,k){
  switch(k){
    case 'investido':return investido(r);
    case 'posicao':return posicao(r);
    case 'lp':return lpR(r);
    case 'resultado':return calcRes(r);
    case 'peso':return investido(r);   /* % Peso agora segue o INVESTIDO */
    case 'dias':return r.dias!=null?r.dias:diasCorridos(r.data_inicio);
    case 'preco_medio':case 'lote':case 'recebido':return r[k];
    case 'data_inicio':case 'data_final':return r[k]||'';
    default:return (r[k]||'').toString().toLowerCase();
  }
}
function sortRows(rows,st){
  const{k,dir}=st,mul=dir==='asc'?1:-1;
  return rows.slice().sort((a,b)=>{
    let va=valForSort(a,k),vb=valForSort(b,k);
    if(va===null||va===undefined)va=-Infinity;if(vb===null||vb===undefined)vb=-Infinity;
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

/* ── edição inline (money | int | date) ── */
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
  /* step de 1 centavo: as setas do input andam de 0,01 (nao de 0,001, que virava
     "1,049" ao clicar). Digitar 3 casas na mao continua valendo — nao ha <form>
     nem estilo :invalid, e salvarCampo le o .value direto, entao um preco medio
     ponderado de nota (ex.: 0,073) abre e salva normalmente. */
  const attrs=itype==='number'?`step="${tipo==='int'?'1':'0.01'}" min="0"`:'';
  el.outerHTML=`<span class="edit-box"><input type="${itype}" ${attrs} value="${raw}" id="ie-${id}-${campo}"><button class="icon-btn icon-ok" onclick="salvarCampo(${id},'${campo}')" title="Salvar">&#10003;</button><button class="icon-btn icon-no" onclick="loadAll()" title="Cancelar">&#10005;</button></span>`;
  setTimeout(()=>{const i=document.getElementById('ie-'+id+'-'+campo);if(i){i.focus();if(itype==='number')i.select();i.addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();salvarCampo(id,campo);}else if(e.key==='Escape'){loadAll();}});}},40);
}
async function salvarCampo(id,campo){const v=document.getElementById('ie-'+id+'-'+campo)?.value;if(v===''||v===undefined){loadAll();return;}const r=await req('update_field',{id,campo,valor:v});if(r.success)loadAll();else{alert(r.error||'Erro');loadAll();}}

/* dias corridos da data de início até hoje (posições abertas) */
function diasCorridos(d){if(!d)return null;const[y,m,dd]=d.split('-').map(Number);const ini=new Date(y,m-1,dd);const hoje=new Date();hoje.setHours(0,0,0,0);return Math.max(0,Math.floor((hoje-ini)/86400000));}

/* ── ação-objeto da opção + modal de detalhe da ação ── */
const TICKER_MAP={MGLU:"MGLU3",COGN:"COGN3",RENT:"RENT3",USIM:"USIM5",BRAV:"BRAV3",LREN:"LREN3",BEEF:"BEEF3",CSNA:"CSNA3",CSAN:"CSAN3",MBRF:"MBRF3",PRIO:"PRIO3",CYRE:"CYRE3",PETR:"PETR4",EMBJ:"EMBJ3",MRVE:"MRVE3",WEGE:"WEGE3",BRKM:"BRKM3",CMIN:"CMIN3",BRAP:"BRAP4",BOVA:"BOVA11"};
function ticker(code){const root=(code||'').slice(0,4).toUpperCase();return TICKER_MAP[root]||(root+"3");}
function verAcao(tk){
  const rows=allRows.filter(r=>ticker(r.codigo)===tk);
  if(!rows.length)return;
  rows.sort((a,b)=>{const da=a.data_final||a.data_inicio||'',db=b.data_final||b.data_inicio||'';return db<da?-1:db>da?1:0;});
  const comRes=rows.filter(r=>r.recebido!==null);
  const wins=comRes.filter(r=>lpR(r)>0.0001).length;
  const acerto=comRes.length?wins/comRes.length*100:null;
  let tLp=0,tInv=0;comRes.forEach(r=>{tLp+=lpR(r);tInv+=investido(r);});
  const resTot=tInv?tLp/tInv*100:null;
  document.getElementById('ac-tk').textContent=tk;
  document.getElementById('ac-resumo').innerHTML=
    '<div class="ac-stat"><div class="ac-k">Operações</div><div class="ac-v">'+rows.length+'</div></div>'
    +'<div class="ac-stat"><div class="ac-k">Acerto</div><div class="ac-v">'+(acerto===null?'&mdash;':acerto.toFixed(0)+'%')+'</div></div>'
    +'<div class="ac-stat"><div class="ac-k">Resultado</div><div class="ac-v">'+(resTot===null?'&mdash;':resBadge(resTot))+'</div></div>';
  document.getElementById('ac-ops').innerHTML=rows.map(r=>{
    const enc=r.status==='Encerrada',temRes=r.recebido!==null;
    const lp=temRes?lpR(r):null, res=temRes?calcRes(r):null;
    const dias=enc?(r.dias??'—'):diasCorridos(r.data_inicio);
    const periodo=sqlToDisplay(r.data_inicio)+(enc?(' &rarr; '+sqlToDisplay(r.data_final)):' · aberta');
    return '<div class="ac-op"><div class="ac-op-top">'+manualDot(r)+'<span class="code">'+r.codigo+'</span>'+shortMark(r)+' <span class="'+(r.estrutura==='PUT'?'badge-put':'badge-call')+'">'+(r.estrutura||'')+'</span>'
      +'<span class="ac-op-res">'+(res===null?'<span class="num" style="color:#94a3b8;font-size:11px">sem cotação</span>':resBadge(res))+'</span></div>'
      +'<div class="ac-op-sub">'+periodo+' · '+dias+'d · '+fmtMoneyPlain(r.preco_medio)+(temRes?(' &rarr; '+fmtMoneyPlain(r.recebido)):'')+(lp!==null?('  ·  L/P '+signMoney(lp)):'')+'</div></div>';
  }).join('');
  document.getElementById('ac-ov').classList.add('show');
}
function fecharAcao(){document.getElementById('ac-ov').classList.remove('show');}
document.addEventListener('keydown',function(e){if(e.key==='Escape')fecharAcao();});

/* ── render ABERTAS ── */
function renderAbertas(){
  const rows=sortRows(allRows.filter(r=>r.status==='Aberta'),sortState.abertas);
  markHeader('head-abertas',sortState.abertas);
  const tb=document.getElementById('tbody-abertas'),ft=document.getElementById('foot-abertas');
  if(!rows.length){tb.innerHTML='<tr><td colspan="13" style="text-align:center;padding:32px;color:#999">Sem posições abertas.</td></tr>';ft.innerHTML='';return;}
  const totPos=rows.filter(r=>r.recebido!==null).reduce((s,r)=>s+posicao(r),0);
  const totInvPeso=rows.reduce((s,r)=>s+investido(r),0);   // % Peso pelo INVESTIDO (todas as abertas)
  tb.innerHTML=rows.map(r=>{
    const res=calcRes(r),pos=posicao(r),lp=lpR(r);
    const peso=totInvPeso?investido(r)/totInvPeso*100:null;
    return`<tr>
      <td>${manualDot(r)}<a href="#" class="code-link" title="Ver detalhes da ação" onclick="verAcao('${ticker(r.codigo)}');return false">${r.codigo}</a>${shortMark(r)}</td>
      <td><span class="${r.estrutura==='PUT'?'badge-put':'badge-call'}">${r.estrutura}</span></td>
      <td>${editCell(r.id,'data_inicio',r.data_inicio,'date')}</td>
      <td class="r"><span class="num">${diasCorridos(r.data_inicio)??'—'}</span></td>
      <td class="r">${editCell(r.id,'preco_medio',r.preco_medio,'money')}</td>
      <td class="r">${editCell(r.id,'lote',r.lote,'int')}</td>
      <td class="r"><span class="num">${fmtMoneyPlain(investido(r))}</span></td>
      <td class="r">${editCell(r.id,'recebido',r.recebido,'money')}</td>
      <td class="r"><span class="num">${pos===null?'<span class=dashc>—</span>':fmtMoneyPlain(pos)}</span></td>
      <td class="r">${lpBadge(lp)}</td>
      <td class="r">${resBadge(res)}</td>
      <td class="r"><span class="num">${peso===null?'<span class=dashc>—</span>':peso.toFixed(2).replace('.',',')+'%'}</span></td>
      <td><div class="act">
        <button class="ibtn enc" onclick="abrirEncerrar(${r.id},'${r.codigo}')" title="Encerrar posição">&#128274;</button>
        <button class="ibtn del" onclick="abrirExcluir(${r.id},'${r.codigo}')" title="Excluir">&#128465;&#65039;</button>
      </div></td>
    </tr>`;}).join('');
  // totais
  const tInv=rows.reduce((s,r)=>s+investido(r),0);
  const withRec=rows.filter(r=>r.recebido!==null);
  const tLp=withRec.reduce((s,r)=>s+lpR(r),0);
  const tInvRec=withRec.reduce((s,r)=>s+investido(r),0);
  const tRes=tInvRec?tLp/tInvRec*100:null;
  ft.innerHTML=`<tr>
    <td>Totais</td><td></td><td></td><td></td><td></td><td></td>
    <td class="r"><span class="num">${fmtMoneyPlain(tInv)}</span></td>
    <td></td>
    <td class="r"><span class="num">${fmtMoneyPlain(totPos)}</span></td>
    <td class="r">${lpBadge(tLp)}</td>
    <td class="r">${resBadge(tRes)}</td>
    <td class="r"><span class="num">100,00%</span></td>
    <td></td></tr>`;
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
  const tb=document.getElementById('tbody-enc'),ft=document.getElementById('foot-enc');
  const bc=document.getElementById('cnt-enc');
  if(bc)bc.textContent=(PERIODO==='all'||rows.length===todas.length)?todas.length:(rows.length+' de '+todas.length);
  if(!rows.length){tb.innerHTML='<tr><td colspan="13" style="text-align:center;padding:32px;color:#999">Nenhuma posição encerrada em '+(PERIODO==='all'?'nenhum período':rotuloPeriodo(PERIODO))+'. Troque o período no filtro acima.</td></tr>';ft.innerHTML='';return;}
  tb.innerHTML=rows.map(r=>{
    const res=calcRes(r),pos=posicao(r),lp=lpR(r);
    return`<tr>
      <td>${manualDot(r)}<a href="#" class="code-link" title="Ver detalhes da ação" onclick="verAcao('${ticker(r.codigo)}');return false">${r.codigo}</a>${shortMark(r)}</td>
      <td><span class="${r.estrutura==='PUT'?'badge-put':'badge-call'}">${r.estrutura}</span></td>
      <td>${editCell(r.id,'data_inicio',r.data_inicio,'date')}</td>
      <td>${editCell(r.id,'data_final',r.data_final,'date')}</td>
      <td class="r"><span class="num">${r.dias??'—'}</span></td>
      <td class="r">${editCell(r.id,'preco_medio',r.preco_medio,'money')}</td>
      <td class="r">${editCell(r.id,'lote',r.lote,'int')}</td>
      <td class="r"><span class="num">${fmtMoneyPlain(investido(r))}</span></td>
      <td class="r">${editCell(r.id,'recebido',r.recebido,'money')}</td>
      <td class="r"><span class="num">${pos===null?'<span class=dashc>—</span>':fmtMoneyPlain(pos)}</span></td>
      <td class="r">${lpBadge(lp)}</td>
      <td class="r">${resBadge(res)}</td>
      <td><div class="act">
        <button class="ibtn reab" onclick="abrirReabrir(${r.id},'${r.codigo}')" title="Reabrir">&#128275;</button>
        <button class="ibtn del" onclick="abrirExcluir(${r.id},'${r.codigo}')" title="Excluir">&#128465;&#65039;</button>
      </div></td>
    </tr>`;}).join('');
  const tInv=rows.reduce((s,r)=>s+investido(r),0);
  const tPos=rows.filter(r=>r.recebido!==null).reduce((s,r)=>s+posicao(r),0);
  const tLp=rows.reduce((s,r)=>s+lpR(r),0);
  const tRes=tInv?tLp/tInv*100:null;
  ft.innerHTML=`<tr>
    <td>Totais</td><td></td><td></td><td></td><td></td><td></td><td></td>
    <td class="r"><span class="num">${fmtMoneyPlain(tInv)}</span></td>
    <td></td>
    <td class="r"><span class="num">${fmtMoneyPlain(tPos)}</span></td>
    <td class="r">${lpBadge(tLp)}</td>
    <td class="r">${resBadge(tRes)}</td>
    <td></td></tr>`;
}

/* ══ RESULTADO DAS ENCERRADAS POR PERÍODO (Ano > Mês > Dia) ══════════════════
   Só posições ENCERRADAS, agrupadas pela DATA DE ENCERRAMENTO (data_final).
   Na vendida as pontas se invertem: o dinheiro entra na abertura (prêmio = Recebido)
   e sai na recompra (Investido) — assim "Recebido − Investido − Custos" é o Rendimento
   em qualquer linha. Custos = taxas das notas rateadas por operação (entrada + saída),
   calculadas no servidor (`custos_ops`). Rentabilidade = Rendimento ÷ Investido. */
function opInvestido(r){return isShort(r)?r.recebido*r.lote:r.preco_medio*r.lote;}
function opRecebido(r){return isShort(r)?r.preco_medio*r.lote:r.recebido*r.lote;}
function opCusto(r){const c=CUSTOS[r.id];return c?+c:0;}
function agregar(rows){
  let inv=0,rec=0,cus=0;
  rows.forEach(r=>{inv+=opInvestido(r);rec+=opRecebido(r);cus+=opCusto(r);});
  const rend=rec-inv-cus;
  return{inv,rec,cus,rend,pct:inv>0.005?rend/inv*100:null,n:rows.length};
}
function celulasPeriodo(a){
  return'<td class="r"><span class="num">'+fmtMoneyPlain(a.inv)+'</span></td>'
      +'<td class="r"><span class="num'+(a.cus>0.005?' pr-cus':'')+'">'+(a.cus>0.005?'-'+fmtMoneyPlain(a.cus):fmtMoneyPlain(0))+'</span></td>'
      +'<td class="r"><span class="num">'+fmtMoneyPlain(a.rec)+'</span></td>'
      +'<td class="r">'+lpBadge(a.rend)+'</td>'
      +'<td class="r">'+resBadge(a.pct)+'</td>';
}
function ops(n){return'<span class="pr-ops">'+n+(n>1?' ops':' op')+'</span>';}
function pregoes(n){return'<span class="pr-ops">'+n+(n>1?' pregões':' pregão')+'</span>';}

/* ── as duas lentes do bloco (Fase 4.3) ──────────────────────────────────────
   Realizado = encerradas pela data_final (o que já virou dinheiro).
   A mercado  = variação do patrimônio − aportes (inclui as abertas).
   Uma posição ABERTA não pertence a um período — ela está viva —, então em vez
   de somar realizado com não realizado por data, cada lente responde uma
   pergunta inteira e coerente, na mesma tabela e na mesma hierarquia. */
function setLente(l){
  LENTE=l;
  document.getElementById('lt-real').classList.toggle('on',l==='realizado');
  document.getElementById('lt-merc').classList.toggle('on',l==='mercado');
  document.getElementById('periodo-sub').innerHTML=(l==='realizado')
    ? 'Só encerradas &middot; pela data de encerramento &middot; clique na linha para abrir'
    : 'Inclui as abertas (a mercado) &middot; pregão a pregão &middot; clique na linha para abrir';
  renderPeriodos();
}
function renderPeriodos(){LENTE==='mercado'?renderPeriodosMercado():renderPeriodosReal();}

/* ── lente A MERCADO: a árvore já vem agregada do servidor ───────────────────
   Nada é recalculado aqui: `series_periodos()` (núcleo puro do series.php) já
   devolveu patrimônio inicial/final, fluxo, rendimento e o TWR de cada período.
   Como cada período parte do fechamento do anterior, os meses COMPÕEM para o
   ano — foi exatamente esse elo que faltou no aceite da 4.1 (ADR 017). */
function celulasMercado(a){
  return'<td class="r"><span class="num">'+fmtMoneyPlain(a.patr_ini)+'</span></td>'
      +'<td class="r"><span class="num'+(Math.abs(a.fluxo)>0.005?' pr-flx':'')+'">'+(Math.abs(a.fluxo)>0.005?signMoney(a.fluxo):fmtMoneyPlain(0))+'</span></td>'
      +'<td class="r"><span class="num">'+fmtMoneyPlain(a.patr_fim)+'</span></td>'
      +'<td class="r">'+lpBadge(a.rendimento)+'</td>'
      +'<td class="r">'+resBadge(a.twr)+'</td>';
}
function renderPeriodosMercado(){
  const box=document.getElementById('periodo-anos'),foot=document.getElementById('periodo-foot');
  if(!CONSOL.length){
    box.innerHTML='<div class="section pr-vazio">A série do patrimônio ainda não foi construída para esta carteira. '
      +'Ela nasce do histórico de cotações — busque as cotações ou rode o backfill.</div>';
    foot.innerHTML='';return;}
  const anoAtual=String(new Date().getFullYear());
  box.innerHTML=CONSOL.map(A=>{
    const aberto=(A.ano===anoAtual);
    let linhas='<tr class="pr-ano'+(aberto?' open':'')+'" onclick="togglePer(this,\'m-'+A.ano+'\')">'
      +'<td class="pr-lbl"><span class="pr-chev">&#9662;</span><strong>'+A.ano+'</strong>'+pregoes(A.pregoes)+'</td>'
      +celulasMercado(A)+'</tr>';
    A.meses.forEach(M=>{
      linhas+='<tr class="pr-mes m-'+A.ano+(aberto?' show':'')+'" data-sub="d-'+A.ano+M.mes+'" onclick="togglePer(this,\'d-'+A.ano+M.mes+'\')">'
        +'<td class="pr-lbl"><span class="pr-chev">&#9662;</span>'+MESES_ABR[+M.mes-1]+pregoes(M.pregoes)+'</td>'
        +celulasMercado(M)+'</tr>';
      M.dias.forEach(D=>{
        linhas+='<tr class="pr-dia d-'+A.ano+M.mes+'">'
          +'<td class="pr-lbl">'+D.dia+'/'+M.mes+'</td>'
          +celulasMercado(D)+'</tr>';
      });
    });
    return'<div class="section pr-section"><div class="tbl-wrap"><table class="pr-tbl"><thead><tr>'
      +'<th>Período</th><th class="r">Patrimônio inicial</th><th class="r">Aportes / saques</th><th class="r">Patrimônio final</th>'
      +'<th class="r">Rendimento</th><th class="r">Rentabilidade</th></tr></thead><tbody>'+linhas+'</tbody></table></div></div>';
  }).join('');
  foot.innerHTML='<strong>A mercado</strong> = variação do patrimônio no período <strong>menos os aportes e saques</strong> '
    +'— inclui as posições ainda abertas pela marcação a mercado, que é como a corretora diz “seu mês rendeu X”. '
    +'Cada período parte do <strong>fechamento do período anterior</strong>, então os meses compõem exatamente para o ano. '
    +'A <strong>Rentabilidade</strong> é o TWR (ponderado pelo tempo): neutraliza os aportes, por isso não é “rendimento ÷ patrimônio inicial”. '
    +'Os números vêm da série histórica — quando divergirem dos cards do topo (opção vencida, por exemplo), a série é a que está certa.';
}

function renderPeriodosReal(){
  const box=document.getElementById('periodo-anos'),foot=document.getElementById('periodo-foot');
  const enc=allRows.filter(r=>r.status==='Encerrada'&&r.data_final&&r.recebido!==null);
  if(!enc.length){box.innerHTML='<div class="section pr-vazio">Nenhuma posição encerrada ainda.</div>';foot.innerHTML='';return;}
  // árvore ano → mês → dia
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
          +'<td class="pr-lbl"><button type="button" class="pr-dia-link" onclick="abrirDiaDetalhe(\''+y+'-'+m+'-'+d+'\')" title="Ver o que foi encerrado neste dia">'+d+'/'+m+'</button>'+ops(anos[y][m][d].length)+'</td>'
          +celulasPeriodo(agregar(anos[y][m][d]))+'</tr>';
      });
    });
    return'<div class="section pr-section"><div class="tbl-wrap"><table class="pr-tbl"><thead><tr>'
      +'<th>Período</th><th class="r">Investido</th><th class="r">Outros custos</th><th class="r">Recebido</th>'
      +'<th class="r">Rendimento</th><th class="r">Rentabilidade</th></tr></thead><tbody>'+linhas+'</tbody></table></div></div>';
  }).join('');
  // rodapé: de onde vem a coluna "Outros custos" e o que ainda não virou custo realizado
  const custoEnc=enc.reduce((s,r)=>s+opCusto(r),0);
  const presos=TOT_TAXAS-custoEnc;
  foot.innerHTML='<strong>Outros custos</strong> = taxas das notas de corretagem (liquidação + registro + emolumentos) '
    +'rateadas por operação, proporcionalmente ao volume financeiro de cada negócio — cada opção encerrada carrega o custo de '
    +'<strong>entrada + saída</strong>. Já realizados nas encerradas: <strong>'+fmtMoneyPlain(custoEnc)+'</strong> dos '
    +fmtMoneyPlain(TOT_TAXAS)+' pagos em taxas'+(presos>0.005?('; os '+fmtMoneyPlain(presos)+' restantes estão em posições ainda abertas.'):'.');
}
/* Modal "o que foi encerrado no dia" (lente Realizado). Base NORMAL da carteira:
   Investido/Recebido em R$ (preço médio × lote; invertidos na vendida), Outros
   custos = taxas rateadas, Rendimento = Recebido − Investido − Custos e
   Rentabilidade = Rendimento ÷ Investido — NÃO é soma pura (isso é só a 3ª aba
   do gráfico). O total do dia usa o mesmo agregar() do bloco por período. */
function abrirDiaDetalhe(ymd){
  const ops=allRows.filter(r=>r.status==='Encerrada'&&r.recebido!==null&&(r.data_final||'').slice(0,10)===ymd);
  const [Y,M,D]=ymd.split('-');
  document.getElementById('dia-titulo').innerHTML='&#128197; Encerradas em '+D+'/'+M+'/'+Y
    +' <span style="font-weight:400;color:var(--muted)">· '+ops.length+(ops.length>1?' operações':' operação')+'</span>';
  const body=document.getElementById('dia-body');
  if(!ops.length){body.innerHTML='<p style="text-align:center;color:#999;padding:20px">Nada encontrado neste dia.</p>';document.getElementById('modal-dia').classList.add('show');return;}
  const linhas=ops.map(r=>{
    const inv=opInvestido(r),rec=opRecebido(r),cus=opCusto(r),rend=rec-inv-cus,pct=inv>0.005?rend/inv*100:null;
    return '<tr>'
      +'<td><span class="code">'+r.codigo+'</span>'+shortMark(r)+'</td>'
      +'<td><span class="'+(r.estrutura==='PUT'?'badge-put':'badge-call')+'">'+r.estrutura+'</span></td>'
      +'<td class="r"><span class="num">'+fmtMoneyPlain(inv)+'</span></td>'
      +'<td class="r"><span class="num'+(cus>0.005?' pr-cus':'')+'">'+(cus>0.005?'-'+fmtMoneyPlain(cus):fmtMoneyPlain(0))+'</span></td>'
      +'<td class="r"><span class="num">'+fmtMoneyPlain(rec)+'</span></td>'
      +'<td class="r">'+lpBadge(rend)+'</td>'
      +'<td class="r">'+resBadge(pct)+'</td>'
    +'</tr>';}).join('');
  const a=agregar(ops);
  body.innerHTML='<table class="dia-tbl"><thead><tr>'
    +'<th>Código</th><th>Tipo</th><th class="r">Investido</th><th class="r">Outros custos</th><th class="r">Recebido</th><th class="r">Rendimento</th><th class="r">Rentabilidade</th>'
    +'</tr></thead><tbody>'+linhas+'</tbody>'
    +'<tfoot><tr><td colspan="2" class="r"><strong>Total do dia</strong></td>'
      +'<td class="r"><span class="num">'+fmtMoneyPlain(a.inv)+'</span></td>'
      +'<td class="r"><span class="num'+(a.cus>0.005?' pr-cus':'')+'">'+(a.cus>0.005?'-'+fmtMoneyPlain(a.cus):fmtMoneyPlain(0))+'</span></td>'
      +'<td class="r"><span class="num">'+fmtMoneyPlain(a.rec)+'</span></td>'
      +'<td class="r">'+lpBadge(a.rend)+'</td>'
      +'<td class="r">'+resBadge(a.pct)+'</td></tr></tfoot>'
    +'</table>';
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


/* ── detecção CALL/PUT no formulário ── */
function atualizarTipo(){
  const code=document.getElementById('n-cod').value.toUpperCase();
  const t=detectarTipoJS(code);
  const hint=document.getElementById('n-tipo-hint'),badge=document.getElementById('n-tipo-badge'),tag=document.getElementById('n-tipo-auto-tag'),sel=document.getElementById('n-est');
  if(t){
    detectedTipo=t;
    badge.textContent=t;badge.className='tipo-badge '+(t==='PUT'?'badge-put':'badge-call');
    badge.style.display='inline-block';tag.style.display='inline';hint.style.display='none';sel.style.display='none';sel.classList.remove('invalid');
  }else if(code.length>=5){
    // código longo mas letra não reconhecida → deixa escolher manualmente
    detectedTipo=null;badge.style.display='none';tag.style.display='none';hint.style.display='none';sel.style.display='inline-block';
  }else{
    detectedTipo=null;badge.style.display='none';tag.style.display='none';hint.style.display='inline';sel.style.display='none';
  }
}
async function criarPosicao(){
  const cEl=document.getElementById('n-cod'),dEl=document.getElementById('n-data'),pEl=document.getElementById('n-preco'),lEl=document.getElementById('n-lote'),sEl=document.getElementById('n-est');
  [cEl,dEl,pEl,lEl,sEl].forEach(x=>x.classList.remove('invalid'));
  const cod=cEl.value.trim().toUpperCase(),data=dEl.value,preco=pEl.value,lote=lEl.value,rec=document.getElementById('n-rec').value;
  const tipo=detectedTipo||sEl.value;
  let falhou=false;
  if(!cod){cEl.classList.add('invalid');falhou=true;}
  if(!tipo){sEl.style.display='inline-block';sEl.classList.add('invalid');falhou=true;}
  if(!data){dEl.classList.add('invalid');falhou=true;}
  if(!preco){pEl.classList.add('invalid');falhou=true;}
  if(!lote){lEl.classList.add('invalid');falhou=true;}
  if(falhou){showMsg('create-msg','Preencha código, data, preço médio e lote (recebido é opcional).','err');return;}
  const direcao=document.getElementById('n-dir').value;
  const payload={codigo:cod,estrutura:tipo,direcao,data_inicio:data,preco_medio:preco,lote:lote};if(rec)payload.recebido=rec;
  const r=await req('create',payload);
  if(r.success){showMsg('create-msg','Posição '+cod+' ('+r.estrutura+(direcao==='V'?' · vendida':'')+') adicionada.','ok');['n-cod','n-data','n-preco','n-lote','n-rec'].forEach(id=>document.getElementById(id).value='');sEl.selectedIndex=0;document.getElementById('n-dir').selectedIndex=0;atualizarTipo();loadAll();}
  else showMsg('create-msg',r.error||'Erro ao criar.','err');
}

/* ── modais ── */
function abrirEncerrar(id,cod){modalId=id;const r=allRows.find(x=>x.id===id);const short=r&&r.direcao==='V';const lote=r?Number(r.lote):0;modalLote=lote;document.getElementById('mc-code').textContent=cod;document.getElementById('mc-rec-lbl').textContent=short?'Recompra (preço pago por unidade para zerar a venda)':'Recebido (preço de venda por unidade)';document.getElementById('mc-data').value=new Date().toISOString().split('T')[0];document.getElementById('mc-rec').value='';const ml=document.getElementById('mc-lote');ml.value=lote;ml.max=lote;document.getElementById('mc-lote-tot').textContent='(total: '+lote+')';document.getElementById('mc-parcial-hint').style.display='none';ml.oninput=()=>{const q=Number(ml.value);document.getElementById('mc-parcial-hint').style.display=(q>0&&q<modalLote)?'block':'none';};document.getElementById('modal-close').classList.add('show');setTimeout(()=>document.getElementById('mc-rec').focus(),60);}
async function confirmarEncerrar(){const rec=document.getElementById('mc-rec').value,data=document.getElementById('mc-data').value;const lote=Number(document.getElementById('mc-lote').value);if(!rec){alert('Informe o valor recebido.');return;}if(!lote||lote<1||lote>modalLote){alert('Quantidade inválida (1 a '+modalLote+').');return;}const r=await req('close',{id:modalId,recebido:rec,data_final:data,lote:lote});if(r.success){fecharModal();loadAll();}else alert(r.error||'Erro.');}
function abrirReabrir(id,cod){modalId=id;document.getElementById('mr-code').textContent=cod;document.getElementById('modal-reopen').classList.add('show');}
async function confirmarReabrir(){const r=await req('reopen',{id:modalId});if(r.success){fecharModal();loadAll();}else alert(r.error||'Erro.');}
function abrirExcluir(id,cod){modalId=id;document.getElementById('md-code').textContent=cod;document.getElementById('modal-del').classList.add('show');}
async function confirmarExcluir(){const r=await req('delete',{id:modalId});if(r.success){fecharModal();loadAll();}else alert(r.error||'Erro.');}
function fecharModal(){document.querySelectorAll('.modal-overlay').forEach(m=>m.classList.remove('show'));modalId=null;}

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

/* ══════════════════════════════════════════════════════════════════════════
   EVOLUÇÃO DO PATRIMÔNIO (Fase 4.2)

   A conta toda já veio pronta do servidor (Fase 4.1): `serie_get` devolve o
   TWR acumulado e `indices_get` devolve os comparadores JÁ normalizados em
   base 100 nos MESMOS pregões da série. Aqui só montamos as linhas — nada de
   recalcular rentabilidade no navegador.
   ══════════════════════════════════════════════════════════════════════════ */
const EV_CORES={carteira:'#5b21b6',cdi:'#0d9488',ipca:'#b45309',ibov:'#2563eb',sp500:'#db2777',ifix:'#65a30d'};
/* Índices em ordem ALFABÉTICA pelo rótulo (CDI · Ibovespa · IFIX · IPCA · S&P 500). */
const EV_NOMES={cdi:'CDI',ibov:'Ibovespa',ifix:'IFIX',ipca:'IPCA',sp500:'S&P 500'};
/* Carteiras simples (Nitro/Betha) — só p/ super_admin. Agora vivem SÓ na aba
   "Carteiras" (confronto), não mais na Rentabilidade. (pedido 08/08/2026) */
const EV_SIM=<?= json_encode($evSim, JSON_UNESCAPED_UNICODE) ?>;
const EV_CONFRONTO=<?= !empty($evSim) ? 'true' : 'false' ?>;   /* mostra a aba "Carteiras" */
const EV_PERS=[['1m','1M'],['3m','3M'],['6m','6M'],['12m','12M'],['ytd','YTD'],['max','Máx']];
let evAbaAtiva='rent', evPeriodo='max', evDe=null, evAte=null;
/* Padrão: CDI e Ibovespa ligados na Rentabilidade; demais índices como clique.
   Nitro/Betha ligados por padrão na aba "Carteiras". Lista em ordem alfabética. */
let evLigados={betha:true,cdi:true,ibov:true,ifix:false,ipca:false,nitro:true,sp500:false};
let evDados=null, evGraf=null;
/* fetch dedicado do serie_sim (aba Rentabilidade — não usado mais, mantido p/ compat) */
async function reqSim(tab,qs){const r=await fetch('api.php?action=serie_sim&tabela='+tab+(qs||''));if(r.status===401){location.href='/';return{};}return r.json();}
/* fetch da SOMA PURA do % (aba "Carteiras") — a carteira vai no ?tabela, sem herdar a ativa */
async function reqSoma(tab,qs){const r=await fetch('api.php?action=serie_pct_soma&tabela='+tab+(qs||''));if(r.status===401){location.href='/';return{};}return r.json();}

function evFmtPct(v){return (v>=0?'+':'−')+Math.abs(v).toFixed(2).replace('.',',')+'%';}
function evFmtPctEixo(v){return (v>0?'+':v<0?'−':'')+Math.abs(v).toFixed(Math.abs(v)<10?1:0).replace('.',',')+'%';}
/* eixo Y do patrimônio abreviado (R$ 21,7 mil) — o tooltip mostra o valor cheio */
function evFmtRsEixo(v){
  const a=Math.abs(v);
  if(a>=1e6)return'R$ '+(v/1e6).toFixed(1).replace('.',',')+' mi';
  if(a>=1e4)return'R$ '+(v/1e3).toFixed(0)+' mil';
  if(a>=1e3)return'R$ '+(v/1e3).toFixed(1).replace('.',',')+' mil';
  return'R$ '+v.toFixed(0);
}
function evFmtData(d,longo){const[y,m,dd]=d.split('-');return longo?(dd+'/'+m+'/'+y):(dd+'/'+m);}

/* Comparador na aba PATRIMÔNIO: "quanto eu teria se o mesmo dinheiro tivesse
   ido para o índice". Parte do mesmo patrimônio inicial, rende o índice e
   recebe os MESMOS aportes/saques nos mesmos dias — por isso é comparável em
   R$ com a linha da carteira (e não uma linha de % perdida num eixo de reais). */
function evHipotetico(serie,pontos){
  const out=[]; let h=null, ref=null;
  serie.forEach((l,k)=>{
    const idx=pontos[l.data];
    if(k===0){ h=+l.patrimonio; ref=(idx!==undefined?idx:null); out.push({d:l.data,v:h}); return; }
    if(idx!==undefined&&ref!==null&&ref>0) h=h*(idx/ref);
    if(idx!==undefined) ref=idx;
    h+=(+l.fluxo_externo||0);                    /* aporte/saque entra sem render no dia */
    out.push({d:l.data,v:h});
  });
  return out;
}

function evAba(k){
  evAbaAtiva=k;
  document.getElementById('ev-aba-rent').classList.toggle('on',k==='rent');
  document.getElementById('ev-aba-patr').classList.toggle('on',k==='patr');
  const c=document.getElementById('ev-aba-cart'); if(c)c.classList.toggle('on',k==='cart');
  evRender();
}
function evSetPeriodo(p){
  if(p==='custom'){const d=document.getElementById('ev-datas');d.classList.toggle('show');return;}
  evPeriodo=p; evDe=null; evAte=null;
  document.getElementById('ev-datas').classList.remove('show');
  evCarregar();
}
function evPersonalizado(){
  const de=document.getElementById('ev-de').value, ate=document.getElementById('ev-ate').value;
  if(!de&&!ate){alert('Informe pelo menos uma das datas.');return;}
  if(de&&ate&&de>ate){alert('A data inicial não pode ser maior que a final.');return;}
  evDe=de||null; evAte=ate||null; evPeriodo='custom';
  evCarregar();
}
function evToggle(k){evLigados[k]=!evLigados[k];evRender();}

function evBotoes(){
  const el=document.getElementById('ev-pers');
  el.innerHTML=EV_PERS.map(([k,r])=>'<button class="ev-per'+(evPeriodo===k?' on':'')+'" onclick="evSetPeriodo(\''+k+'\')">'+r+'</button>').join('')+
    '<button class="ev-per'+(evPeriodo==='custom'?' on':'')+'" onclick="evSetPeriodo(\'custom\')" title="Período personalizado">&#128197;</button>';
}

async function evCarregar(){
  evBotoes();
  const qs=(evDe||evAte)?('&de='+(evDe||'')+'&ate='+(evAte||'')):('&periodo='+evPeriodo);
  const reqs=[req('serie_get',null,qs),req('indices_get',null,qs+'&quais=cdi,ipca,ibov,sp500,ifix')];
  /* aba "Carteiras": soma pura do % da carteira rica + Nitro/Betha (só super_admin) */
  const somaKeys=[];
  if(EV_CONFRONTO){
    reqs.push(reqSoma('carteira',qs)); somaKeys.push('carteira');
    Object.keys(EV_SIM).forEach(k=>{reqs.push(reqSoma(EV_SIM[k].tab,qs));somaKeys.push(k);});
  }
  const res=await Promise.all(reqs);
  const s=res[0], i=res[1], soma={};
  somaKeys.forEach((k,ix)=>{const r=res[2+ix];soma[k]=(r&&r.pontos)?r:{pontos:{},cobertura:0};});
  evDados={serie:(s&&s.serie)||[],indices:(i&&i.indices)||{},soma};
  evRender();
}

function evRender(){
  if(!evDados)return;
  const serie=evDados.serie, idx=evDados.indices;
  const rent=evAbaAtiva==='rent', patr=evAbaAtiva==='patr', cart=evAbaAtiva==='cart';
  const val=document.getElementById('ev-val'), ctx=document.getElementById('ev-ctx'), foot=document.getElementById('ev-foot');

  /* ═══ ABA "CARTEIRAS": confronto na mesma base (soma pura do %) ═══ */
  if(cart){
    const soma=evDados.soma||{};
    /* chips: Minha carteira (fixa) + Nitro/Betha (liga-desliga) */
    let chips='<span class="ev-chip fixo"><span class="dot" style="background:'+EV_CORES.carteira+'"></span>Minha carteira</span>';
    Object.keys(EV_SIM).forEach(k=>{
      const sm=soma[k], cor=EV_SIM[k].cor;
      const semDados=!sm||!sm.pontos||!Object.keys(sm.pontos).length;
      chips+='<button class="ev-chip'+(evLigados[k]?' on':'')+'" style="color:'+(evLigados[k]?cor:'')+'" onclick="evToggle(\''+k+'\')"'+
        (semDados?' title="Sem histórico de preços desta carteira — rode o backfill do COTAHIST para a linha aparecer."':'')+'>'+
        '<span class="dot" style="background:'+cor+'"></span>'+EV_SIM[k].nome+(semDados?'<span class="px">sem dados</span>':'')+'</button>';
    });
    document.getElementById('ev-chips').innerHTML=chips;
    /* linhas: todas em soma pura do %, plotadas direto */
    const linhas=[];
    const cs=soma.carteira, cpts=(cs&&cs.pontos)||{}, cds=Object.keys(cpts).sort();
    if(cds.length) linhas.push({key:'carteira',nome:'Minha carteira',cor:EV_CORES.carteira,area:true,valores:cds.map(d=>({d,v:cpts[d]}))});
    Object.keys(EV_SIM).forEach(k=>{
      if(!evLigados[k])return;
      const sm=soma[k]; if(!sm||!sm.pontos)return;
      const ds=Object.keys(sm.pontos).sort(); if(!ds.length)return;
      linhas.push({key:k,nome:EV_SIM[k].nome,cor:EV_SIM[k].cor,tracejada:true,valores:ds.map(d=>({d,v:sm.pontos[d]}))});
    });
    const box=document.getElementById('ev-graf');
    const opts={series:linhas,zero:true,fmtX:evFmtData,fmtY:evFmtPctEixo,fmtTip:evFmtPct,
      vazio:'Sem dados para o confronto. As carteiras precisam de histórico de preços (COTAHIST).'};
    if(evGraf)evGraf.atualizar(opts); else evGraf=Grafico.linha(box,opts);
    /* headline + rodapé */
    if(!cds.length){val.textContent='—';ctx.textContent='';foot.textContent='';return;}
    const iniD=cds[0], fimD=cds[cds.length-1], vfin=cpts[fimD];
    val.className='ev-val '+(vfin>=0.005?'pos':vfin<=-0.005?'neg':'');
    val.textContent=evFmtPct(vfin);
    const jan='de <b>'+evFmtData(iniD,true)+'</b> a <b>'+evFmtData(fimD,true)+'</b> · '+cds.length+' pregõe'+(cds.length>1?'s':'');
    let cmpArr=[];
    Object.keys(EV_SIM).forEach(k=>{const sm=soma[k];if(evLigados[k]&&sm&&sm.pontos&&sm.pontos[fimD]!==undefined)cmpArr.push(EV_SIM[k].nome+' '+evFmtPct(sm.pontos[fimD]));});
    ctx.innerHTML=jan+(cmpArr.length?'<br>no mesmo período: '+cmpArr.join(' · '):'');
    foot.innerHTML='<b>Confronto entre carteiras</b> na MESMA base: <b>soma pura do %</b> de cada operação (o capital não pondera). '+
      'É a única forma de comparar Minha carteira, Nitro e Betha lado a lado. Por isso o % da <b>Minha carteira</b> aqui difere do da aba Rentabilidade — lá ele é ponderado pelos valores, casado com a corretora.';
    return;
  }

  /* ═══ ABAS "RENTABILIDADE" e "PATRIMÔNIO" ═══ */
  /* ── chips (são a legenda E o liga-desliga dos comparadores) ── */
  let chips='<span class="ev-chip fixo"><span class="dot" style="background:'+EV_CORES.carteira+'"></span>Minha carteira</span>';
  Object.keys(EV_NOMES).forEach(k=>{
    const px=(idx[k]&&idx[k].proxy)?'<span class="px">via '+idx[k].proxy+'</span>':'';
    chips+='<button class="ev-chip'+(evLigados[k]?' on':'')+'" style="color:'+(evLigados[k]?EV_CORES[k]:'')+'" onclick="evToggle(\''+k+'\')">'+
           '<span class="dot" style="background:'+EV_CORES[k]+'"></span>'+EV_NOMES[k]+px+'</button>';
  });
  document.getElementById('ev-chips').innerHTML=chips;

  /* ── linhas ── */
  const linhas=[{key:'carteira',nome:'Minha carteira',cor:EV_CORES.carteira,area:true,
    valores:serie.map(l=>({d:l.data,v:rent?+l.twr_acum:+l.patrimonio}))}];
  Object.keys(EV_NOMES).forEach(k=>{
    /* sem série da carteira não se desenha comparador sozinho: um gráfico só com
       CDI e Ibovespa não é "a minha evolução" — melhor o estado vazio honesto */
    if(!serie.length||!evLigados[k]||!idx[k])return;
    const pts=idx[k].pontos||{};
    linhas.push({key:k,nome:EV_NOMES[k]+(idx[k].proxy?' · via '+idx[k].proxy:''),cor:EV_CORES[k],tracejada:true,
      valores:rent?Object.keys(pts).sort().map(d=>({d,v:pts[d]-100})):evHipotetico(serie,pts)});
  });

  const opts={series:linhas,zero:rent,fmtX:evFmtData,
    fmtY:rent?evFmtPctEixo:evFmtRsEixo,
    fmtTip:rent?evFmtPct:fmtMoneyPlain,
    vazio:'Sem dados no período. A série começa no primeiro pregão após a sua primeira operação.'};
  const box=document.getElementById('ev-graf');
  if(evGraf)evGraf.atualizar(opts); else evGraf=Grafico.linha(box,opts);

  /* ── headline + rodapé ── */
  if(!serie.length){val.textContent='—';ctx.textContent='';foot.textContent='';return;}
  const ini=serie[0], fim=serie[serie.length-1];
  const jan='de <b>'+evFmtData(ini.data,true)+'</b> a <b>'+evFmtData(fim.data,true)+'</b> · '+serie.length+' pregõe'+(serie.length>1?'s':'');
  if(rent){
    const t=+fim.twr_acum;
    val.className='ev-val '+(t>=0.005?'pos':t<=-0.005?'neg':'');
    val.textContent=evFmtPct(t);
    let cmpArr=Object.keys(EV_NOMES).filter(k=>evLigados[k]&&idx[k]&&idx[k].pontos[fim.data]!==undefined)
      .map(k=>EV_NOMES[k]+' '+evFmtPct(idx[k].pontos[fim.data]-100));
    const cmp=cmpArr.join(' · ');
    ctx.innerHTML=jan+(cmp?'<br>no mesmo período: '+cmp:'');
  }else{
    /* variação em R$ descontando os aportes/saques do período — senão um depósito
       de R$ 5.000 apareceria como "ganho" de R$ 5.000 */
    let fluxo=0; serie.slice(1).forEach(l=>{fluxo+=+l.fluxo_externo||0;});
    const ganho=(+fim.patrimonio)-(+ini.patrimonio)-fluxo;
    val.className='ev-val';
    val.textContent=fmtMoneyPlain(+fim.patrimonio);
    ctx.innerHTML=jan+'<br>variação no período: <b style="color:'+(ganho>=0?'var(--pos)':'var(--neg)')+'">'+signMoney(ganho)+
      '</b> <span style="opacity:.8">(fora aportes e saques'+(fluxo?': '+signMoney(fluxo):'')+')</span>';
  }
  const proxies=Object.keys(EV_NOMES).filter(k=>evLigados[k]&&idx[k]&&idx[k].proxy).map(k=>EV_NOMES[k]+' via '+idx[k].proxy);
  foot.innerHTML=
    (rent?'<b>Rentabilidade = TWR</b> (ponderada pelo tempo): neutraliza aportes e saques e mede a qualidade das decisões — é o que torna a sua linha comparável com o CDI. O resultado em R$ está no card <b>Líquido total</b>.'
        :'<b>Patrimônio</b> = caixa disponível + posições a mercado. Os comparadores simulam <b>o mesmo dinheiro, com os mesmos aportes</b>, rendendo o índice.')+
    (proxies.length?'<br>Índices por ETF-proxy ('+proxies.join(', ')+') — há descolamento em relação ao índice puro.':'');
}

bindSort('head-abertas','abertas');
bindSort('head-enc','enc');
evBotoes();
loadAll();
</script>
</body>
</html>
