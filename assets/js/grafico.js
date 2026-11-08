/* ============================================================
   grafico.js — componente de LINHA em SVG puro (Fase 4.2)

   Por que SVG na mao e nao uma biblioteca: o projeto e' vanilla
   (sem build, sem npm) e o grafico e' simples o bastante — linha
   multi-serie + crosshair + tooltip — para nao justificar 60 KB
   de terceiros. Ver ADR 015.

   O componente NAO sabe nada de carteira, TWR ou indice: recebe
   series ja prontas e desenha. E' isso que o torna reaproveitavel
   pelos graficos por setor/ativo do backlog da Fase 4.

   USO
   ---
     const g = Grafico.linha(document.getElementById('meu-box'), opts);
     g.atualizar(opts);   // redesenha com dados novos
     g.destruir();

   OPCOES
   ------
     series : [{ key, nome, cor, valores:[{d:'YYYY-MM-DD', v:Number|null}],
                 area:bool, tracejada:bool }]
              A 1a serie e' a principal (ganha a area sob a curva).
     fmtY   : (v) => string          rotulo do eixo Y (e do tooltip, se nao houver fmtTip)
     fmtTip : (v) => string          valor no tooltip (o eixo pode ser abreviado, o tooltip nao)
     fmtX   : (d, longo) => string   rotulo do eixo X (curto) e do tooltip (longo)
     zero   : bool                   realca a linha do zero (grafico de %)
     altura : px (default 300; 220 em tela estreita)
     vazio  : texto do estado vazio
   ============================================================ */
window.Grafico = (function () {
  'use strict';
  const NS  = 'http://www.w3.org/2000/svg';
  const PAD = { t: 16, r: 16, b: 28, l: 62 };

  function svgEl(tag, attrs) {
    const e = document.createElementNS(NS, tag);
    for (const k in attrs) if (attrs[k] !== null && attrs[k] !== undefined) e.setAttribute(k, attrs[k]);
    return e;
  }

  /* Escala "bonita" do eixo Y: passo 1 / 2 / 2,5 / 5 x 10^n.
     Sem isso os rotulos saem em 13,7 / 27,4 / 41,1 — legiveis so para o autor. */
  function ticksY(min, max, alvo) {
    if (!(max > min)) { const d = Math.abs(max) || 1; min = max - d * 0.1; max = max + d * 0.1; }
    const bruto = (max - min) / Math.max(1, alvo);
    const mag   = Math.pow(10, Math.floor(Math.log10(bruto)));
    const n     = bruto / mag;
    const passo = (n <= 1 ? 1 : n <= 2 ? 2 : n <= 2.5 ? 2.5 : n <= 5 ? 5 : 10) * mag;
    const ini   = Math.floor(min / passo) * passo;
    const fim   = Math.ceil(max / passo) * passo;
    const out   = [];
    for (let v = ini; v <= fim + passo * 1e-9; v += passo) out.push(+v.toFixed(10));
    return out;
  }

  /* Indices do eixo X a rotular: 1 marcacao a cada ~110px (76px em tela estreita,
     senao o mobile fica so com a primeira e a ultima data). */
  function ticksX(n, larg, estreito) {
    const quer = Math.max(2, Math.min(n, Math.floor(larg / (estreito ? 76 : 110))));
    if (n <= quer) return Array.from({ length: n }, (_, i) => i);
    const passo = (n - 1) / (quer - 1);
    const out = [];
    for (let i = 0; i < quer; i++) out.push(Math.round(i * passo));
    return Array.from(new Set(out));
  }

  function linha(box, opts) {
    box.classList.add('gx');
    box.innerHTML = '';
    const tip = document.createElement('div');
    tip.className = 'gx-tip';
    box.appendChild(tip);

    let cfg   = {};      // opcoes atuais
    let svg   = null;    // <svg> do desenho corrente
    let ro    = null;    // ResizeObserver
    /* estado do desenho corrente, usado pelo crosshair */
    let st = { datas: [], xs: [], py: null, series: [], larg: 0, ih: 0 };

    function desenhar() {
      const series = (cfg.series || []).filter(s => s.valores && s.valores.length);
      const larg   = Math.max(280, box.clientWidth || 640);
      const alt    = cfg.altura || (larg < 560 ? 220 : 300);

      if (svg) { svg.remove(); svg = null; }
      box.querySelector('.gx-vazio')?.remove();
      tip.style.display = 'none';

      /* eixo X = uniao das datas de todas as series (o indices_get ja devolve os
         indices alinhados aos pregoes da serie, mas ser tolerante nao custa) */
      const set = new Set();
      series.forEach(s => s.valores.forEach(p => set.add(p.d)));
      const datas = Array.from(set).sort();

      if (!datas.length) {
        const v = document.createElement('div');
        v.className = 'gx-vazio';
        v.textContent = cfg.vazio || 'Sem dados no período.';
        box.appendChild(v);
        st = { datas: [], xs: [], py: null, series: [], larg, ih: 0 };
        return;
      }

      /* mapa data -> valor por serie + dominio do Y */
      let min = Infinity, max = -Infinity;
      series.forEach(s => {
        s._map = {};
        s.valores.forEach(p => {
          const v = (p.v === null || p.v === undefined) ? NaN : +p.v;
          if (isNaN(v)) return;
          s._map[p.d] = v;
          if (v < min) min = v;
          if (v > max) max = v;
        });
      });
      if (min === Infinity) { min = 0; max = 1; }
      if (cfg.zero) { min = Math.min(min, 0); max = Math.max(max, 0); }

      const tks = ticksY(min, max, larg < 560 ? 4 : 5);
      const y0 = tks[0], y1 = tks[tks.length - 1];
      const iw = larg - PAD.l - PAD.r, ih = alt - PAD.t - PAD.b;
      const px = i => PAD.l + (datas.length === 1 ? iw / 2 : (i / (datas.length - 1)) * iw);
      const py = v => PAD.t + ih - ((v - y0) / (y1 - y0 || 1)) * ih;

      svg = svgEl('svg', { viewBox: `0 0 ${larg} ${alt}`, width: larg, height: alt, class: 'gx-svg' });

      /* grade horizontal + rotulos do Y */
      tks.forEach(v => {
        const y = py(v);
        svg.appendChild(svgEl('line', { x1: PAD.l, x2: larg - PAD.r, y1: y, y2: y,
          class: (cfg.zero && Math.abs(v) < 1e-9) ? 'gx-zero' : 'gx-grid' }));
        const t = svgEl('text', { x: PAD.l - 9, y: y + 3.5, class: 'gx-lbl gx-lbl-y' });
        t.textContent = (cfg.fmtY || String)(v);
        svg.appendChild(t);
      });

      /* rotulos do X */
      ticksX(datas.length, iw, larg < 560).forEach(i => {
        const t = svgEl('text', { x: px(i), y: alt - 8, class: 'gx-lbl gx-lbl-x' });
        t.textContent = (cfg.fmtX || String)(datas[i], false);
        svg.appendChild(t);
      });

      /* series — de tras para a frente, para a principal ficar por cima */
      series.slice().reverse().forEach(s => {
        const segs = [];                 // trechos continuos: buraco de dado quebra a linha
        let atual = null;
        datas.forEach((d, i) => {
          const v = s._map[d];
          if (v === undefined) { atual = null; return; }
          if (!atual) { atual = []; segs.push(atual); }
          atual.push([px(i), py(v)]);
        });
        if (!segs.length) return;

        if (s.area) {
          const maior = segs.reduce((a, b) => (b.length > a.length ? b : a), segs[0]);
          const base  = PAD.t + ih;
          svg.appendChild(svgEl('path', {
            d: 'M' + maior[0][0] + ',' + base + 'L' + maior.map(p => p[0] + ',' + p[1]).join('L') +
               'L' + maior[maior.length - 1][0] + ',' + base + 'Z',
            fill: s.cor, 'fill-opacity': .10, stroke: 'none'
          }));
        }
        segs.forEach(seg => {
          if (seg.length === 1) {        // trecho de 1 ponto nao vira linha: marca com bolinha
            svg.appendChild(svgEl('circle', { cx: seg[0][0], cy: seg[0][1], r: 2.6, fill: s.cor }));
            return;
          }
          svg.appendChild(svgEl('path', {
            d: 'M' + seg.map(p => p[0] + ',' + p[1]).join('L'),
            fill: 'none', stroke: s.cor, 'stroke-width': s.area ? 2.2 : 1.6,
            'stroke-linejoin': 'round', 'stroke-linecap': 'round',
            'stroke-dasharray': s.tracejada ? '5 4' : null
          }));
        });
      });

      /* crosshair: linha-guia + uma bolinha por serie (escondidos ate o mouse entrar) */
      const guia = svgEl('line', { y1: PAD.t, y2: PAD.t + ih, class: 'gx-guia', style: 'display:none' });
      svg.appendChild(guia);
      const marcas = series.map(s => {
        const c = svgEl('circle', { r: 4, fill: '#fff', stroke: s.cor, 'stroke-width': 2, style: 'display:none' });
        svg.appendChild(c);
        return { s, c };
      });

      /* area invisivel de captura do mouse, por cima de tudo */
      const hit = svgEl('rect', { x: PAD.l, y: PAD.t, width: iw, height: ih, fill: 'transparent' });
      svg.appendChild(hit);
      box.appendChild(svg);

      st = { datas, xs: datas.map((_, i) => px(i)), py, series, marcas, guia, larg, ih };

      hit.addEventListener('mousemove', mover);
      hit.addEventListener('mouseleave', sair);
      hit.addEventListener('touchstart', e => { if (e.touches[0]) mover(e.touches[0]); }, { passive: true });
      hit.addEventListener('touchmove',  e => { if (e.touches[0]) mover(e.touches[0]); }, { passive: true });
      hit.addEventListener('touchend', sair);
    }

    function mover(ev) {
      if (!svg || !st.datas.length) return;
      const r   = svg.getBoundingClientRect();
      const esc = r.width / st.larg;                       // SVG -> pixels de tela
      const x   = (ev.clientX - r.left) / esc;
      let i = 0, dist = Infinity;
      st.xs.forEach((p, k) => { const d = Math.abs(p - x); if (d < dist) { dist = d; i = k; } });

      const data = st.datas[i];
      st.guia.setAttribute('x1', st.xs[i]);
      st.guia.setAttribute('x2', st.xs[i]);
      st.guia.style.display = '';

      let html = '<div class="gx-tip-d">' + (cfg.fmtX ? cfg.fmtX(data, true) : data) + '</div>';
      st.marcas.forEach(m => {
        const v = m.s._map[data];
        if (v === undefined) { m.c.style.display = 'none'; return; }
        m.c.setAttribute('cx', st.xs[i]);
        m.c.setAttribute('cy', st.py(v));
        m.c.style.display = '';
        html += '<div class="gx-tip-l"><i style="background:' + m.s.cor + '"></i><span>' +
                m.s.nome + '</span><b>' + (cfg.fmtTip || cfg.fmtY || String)(v) + '</b></div>';
      });
      tip.innerHTML = html;
      tip.style.display = 'block';
      /* perto da borda direita o tooltip vira para a esquerda do cursor */
      const tw = tip.offsetWidth, xTela = st.xs[i] * esc;
      tip.style.left = Math.max(4, Math.min(box.clientWidth - tw - 4, xTela + 14)) + 'px';
    }

    function sair() {
      if (st.guia) st.guia.style.display = 'none';
      if (st.marcas) st.marcas.forEach(m => { m.c.style.display = 'none'; });
      tip.style.display = 'none';
    }

    const api = {
      atualizar(novo) { cfg = Object.assign({}, cfg, novo || {}); desenhar(); return api; },
      destruir() { if (ro) ro.disconnect(); box.innerHTML = ''; }
    };

    if (window.ResizeObserver) {
      let t = null, w = 0;
      ro = new ResizeObserver(() => {
        if (box.clientWidth === w) return;              // so redesenha se a largura mudou
        w = box.clientWidth;
        clearTimeout(t); t = setTimeout(desenhar, 120);
      });
      ro.observe(box);
    } else {
      window.addEventListener('resize', () => desenhar());
    }

    api.atualizar(opts);
    return api;
  }

  return { linha };
})();
