/* 100均くらべ：一覧表の絞り込み・並べ替えと縮尺図 */
(function () {
  var src = document.querySelector('[data-kurabe]');
  if (!src) return;
  var D;
  try { D = JSON.parse(src.getAttribute('data-kurabe')); } catch (e) { return; }
  var COL = { 'ダイソー': 'var(--k-daiso)', 'キャンドゥ': 'var(--k-cando)', 'ワッツ': 'var(--k-watts)' };
  var LBL = { 'ダイソー': 'DAISO', 'キャンドゥ': 'Can★Do', 'ワッツ': 'Watts' };
  var lbl = function (s) { return LBL[s] || s; };
  var fmt = function (n) { return Number.isInteger(n) ? String(n) : String(Math.round(n * 10) / 10); };

  /* ---------- 一覧表 ---------- */
  var box = document.querySelector('.kurabe-table');
  if (box) {
    var tbody = box.querySelector('tbody');
    var trs = Array.prototype.slice.call(tbody.querySelectorAll('tr'));
    var state = { s: {}, p: {}, fit: [], sort: null, dir: 'asc' };
    box.querySelectorAll('.kurabe-chips').forEach(function (g) {
      var key = g.getAttribute('data-filter');
      g.querySelectorAll('.kurabe-chip').forEach(function (b) {
        state[key][b.getAttribute('data-v')] = true;
        b.addEventListener('click', function () {
          var v = b.getAttribute('data-v');
          state[key][v] = !state[key][v];
          b.setAttribute('aria-pressed', state[key][v] ? 'true' : 'false');
          render();
        });
      });
    });
    var hasP = !!box.querySelector('[data-filter="p"]');
    box.querySelectorAll('.kurabe-fit input').forEach(function (inp) {
      inp.addEventListener('input', function () {
        var v = parseFloat(inp.value);
        state.fit[+inp.getAttribute('data-i')] = isNaN(v) ? null : v;
        render();
      });
    });
    var btns = box.querySelectorAll('th button');
    btns.forEach(function (b) {
      b.addEventListener('click', function () {
        var k = b.getAttribute('data-sort');
        state.dir = state.sort === k && state.dir === 'asc' ? 'desc' : 'asc';
        state.sort = k;
        btns.forEach(function (x) { x.removeAttribute('data-dir'); });
        b.setAttribute('data-dir', state.dir);
        render();
      });
    });

    function perms(a) {
      if (a.length <= 1) return [a];
      var out = [];
      a.forEach(function (x, i) {
        perms(a.slice(0, i).concat(a.slice(i + 1))).forEach(function (p) { out.push([x].concat(p)); });
      });
      return out;
    }
    function fits(row) {
      var lim = state.fit, any = lim.some(function (v) { return v != null; });
      if (!any) return true;
      if (D.mode === 'range') {
        if (!row.rg) return false;
        var x = lim[0];
        return x == null || (row.rg[0] <= x && x <= row.rg[1]);
      }
      if (!row.d) return false;
      /* 向きは問わない：寸法の並べ替えのどれかで収まればよい */
      return perms(row.d).some(function (p) {
        return lim.every(function (l, i) { return l == null || (p[i] != null && p[i] <= l); });
      });
    }
    function num(tr, k) { var v = tr.getAttribute('data-' + k); return v === '' || v == null ? null : parseFloat(v); }
    function render() {
      var shown = trs.filter(function (tr) {
        var row = D.rows[+tr.getAttribute('data-i')];
        var ok = state.s[tr.getAttribute('data-s')] && (!hasP || state.p[tr.getAttribute('data-p')]) && fits(row);
        tr.hidden = !ok;
        return ok;
      });
      if (state.sort) {
        var k = state.sort, sg = state.dir === 'asc' ? 1 : -1;
        trs.slice().sort(function (a, b) {
          var x = num(a, k), y = num(b, k);
          if (x == null && y == null) return 0;
          if (x == null) return 1;
          if (y == null) return -1;
          return sg * (x - y);
        }).forEach(function (tr) { tbody.appendChild(tr); });
      }
      box.querySelector('.kurabe-count b').textContent = shown.length;
      box.querySelector('.kurabe-empty').hidden = shown.length > 0;
    }
  }

  /* ---------- 縮尺図 ---------- */
  var sc = document.querySelector('.kurabe-scale');
  if (sc && D.mode !== 'none' && D.mode !== 'range') {
    var svg = sc.querySelector('svg'), maxW = 900, pad = 16, out = '', H;
    var groups = {}, order = [];
    D.rows.forEach(function (r) {
      var v = D.mode === 'range' ? r.rg : r.d;
      if (!v) return;
      var k = v.map(fmt).join(D.mode === 'range' ? '〜' : '×');
      if (!groups[k]) { groups[k] = { v: v, stores: {}, prices: {} }; order.push(k); }
      groups[k].stores[r.s] = true;
      if (r.p != null) groups[k].prices[r.p] = true;
    });
    var keys = function (o) { return Object.keys(o); };
    var strokeOf = function (g) { var s = keys(g.stores); return s.length === 1 ? COL[s[0]] || 'var(--k-accent)' : 'var(--k-accent)'; };
    var sub = function (g) { return keys(g.stores).map(lbl).join('・') + ' ' + keys(g.prices).sort(function (a, b) { return a - b; }).join('/') + '円'; };

    if (D.mode === 'range') {
      order.sort(function (a, b) { return groups[a].v[1] - groups[b].v[1] || groups[a].v[0] - groups[b].v[0]; });
      var top = Math.max.apply(null, order.map(function (k) { return groups[k].v[1]; }));
      var step = top > 200 ? 50 : top > 100 ? 20 : 10, axisMax = Math.ceil(top / step) * step;
      var left = 150, S = (maxW - left - pad) / axisMax, rowH = 30, y0 = 28;
      for (var t = 0; t <= axisMax; t += step) {
        var x = left + t * S;
        out += '<line x1="' + x + '" y1="18" x2="' + x + '" y2="' + (y0 + order.length * rowH) + '" stroke="var(--k-line2)"/>';
        out += '<text x="' + x + '" y="12" font-size="11" text-anchor="middle" fill="var(--k-ink2)">' + t + 'cm</text>';
      }
      order.forEach(function (k, i) {
        var g = groups[k], y = y0 + i * rowH + 10;
        out += '<text x="0" y="' + (y + 4) + '" font-size="12" fill="var(--k-ink)">' + k + 'cm</text>';
        out += '<text x="0" y="' + (y + 17) + '" font-size="10" fill="var(--k-ink2)">' + sub(g) + '</text>';
        var x1 = left + g.v[0] * S, x2 = left + g.v[1] * S;
        out += '<line x1="' + left + '" y1="' + y + '" x2="' + x1 + '" y2="' + y + '" stroke="var(--k-mesh)" stroke-width="2" stroke-dasharray="2 3"/>';
        out += '<rect x="' + x1 + '" y="' + (y - 5) + '" width="' + Math.max(3, x2 - x1) + '" height="10" rx="5" fill="' + strokeOf(g) + '"/>';
      });
      H = y0 + order.length * rowH + 8;
    } else {
      order.sort(function (a, b) { var p = groups[a].v, q = groups[b].v; return p[0] * p[p.length - 1] - q[0] * q[q.length - 1]; });
      /* 3dは表記順が店ごとに違うため、いちばん大きい面（長い2辺）を描く */
      var face = function (v) { return D.mode === '3d' ? v.slice().sort(function (a, b) { return b - a; }).slice(0, 2) : [v[0], v[1]]; };
      var big = Math.max.apply(null, order.map(function (k) { return Math.max.apply(null, face(groups[k].v)); }));
      var S2 = Math.min(4.2, (maxW - 2 * pad) / big), gapX = 18, gapY = 44, x0 = pad, yy = pad, rh = 0;
      out += '<defs><pattern id="kurabe-mesh" width="' + 2.5 * S2 + '" height="' + 2.5 * S2 + '" patternUnits="userSpaceOnUse"><path d="M ' + 2.5 * S2 + ' 0 L 0 0 0 ' + 2.5 * S2 + '" fill="none" stroke="var(--k-mesh)" stroke-width="1"/></pattern></defs>';
      order.forEach(function (k) {
        var g = groups[k], f = face(g.v), w = f[0] * S2, h = f[1] * S2;
        if (x0 + Math.max(w, 110) > maxW - pad && x0 > pad) { x0 = pad; yy += rh + gapY; rh = 0; }
        out += '<rect x="' + x0 + '" y="' + yy + '" width="' + w + '" height="' + h + '" fill="' + (D.mode === '2d' ? 'url(#kurabe-mesh)' : 'var(--k-bg)') + '" stroke="' + strokeOf(g) + '" stroke-width="3" rx="3"/>';
        out += '<text x="' + x0 + '" y="' + (yy + h + 16) + '" font-size="12" fill="var(--k-ink)">' + k + '</text>';
        out += '<text x="' + x0 + '" y="' + (yy + h + 31) + '" font-size="11" fill="var(--k-ink2)">' + sub(g) + '</text>';
        x0 += Math.max(w, 110) + gapX; rh = Math.max(rh, h);
      });
      H = yy + rh + gapY;
    }
    svg.setAttribute('viewBox', '0 0 ' + maxW + ' ' + H);
    svg.setAttribute('width', maxW);
    svg.innerHTML = out;
    var names = { '2d': '', '3d': '各ケースのいちばん大きい面（長い2辺）を描いています。', 'range': '線の太い部分が伸縮できる範囲です。' };
    var present = D.rows.reduce(function (o, r) { o[r.s] = true; return o; }, {});
    sc.querySelector('.kurabe-legend').innerHTML = ['ダイソー', 'キャンドゥ', 'ワッツ'].filter(function (s) { return present[s]; }).map(function (s) {
      return '<span><i style="background:' + COL[s] + '"></i>' + lbl(s) + 'のみ</span>';
    }).join('') + '<span><i style="background:var(--k-accent)"></i>複数の店にあるサイズ</span>' + (names[D.mode] ? '<span>' + names[D.mode] + '</span>' : '');
  }
})();
