/* スーツくらべ：一覧表の絞り込み・並べ替えと縮尺図。店の表記と色はデータ（D.stores）から読む */
(function () {
  var src = document.querySelector('[data-kurabe]');
  if (!src) return;
  var D;
  try { D = JSON.parse(src.getAttribute('data-kurabe')); } catch (e) { return; }
  var COL = {}, LBL = {};
  (D.stores || []).forEach(function (st) {
    COL[st.s] = st.color || '#666';
    LBL[st.s] = st.label || st.s;
  });
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
  if (sc && (D.mode === '2d' || D.mode === '3d')) {
    var grid = sc.querySelector('.kurabe-scale-grid');
    var groups = {}, order = [];
    D.rows.forEach(function (r) {
      if (!r.d) return;
      var k = r.d.map(fmt).join('×');
      if (!groups[k]) { groups[k] = { v: r.d, stores: {}, prices: {} }; order.push(k); }
      groups[k].stores[r.s] = true;
      if (r.p != null) groups[k].prices[r.p] = true;
    });
    var keys = function (o) { return Object.keys(o); };
    /* 3dは表記順が店ごとに違うため、いちばん大きい面（長い2辺）を描く */
    var face = function (v) { return D.mode === '3d' ? v.slice().sort(function (a, b) { return b - a; }).slice(0, 2) : [v[0], v[1]]; };
    order.sort(function (a, b) { var p = face(groups[a].v), q = face(groups[b].v); return p[0] * p[1] - q[0] * q[1]; });
    /* 全商品で同じ縮尺：いちばん長い辺が 180px になる倍率 */
    var big = Math.max.apply(null, order.map(function (k) { return Math.max.apply(null, face(groups[k].v)); }));
    var S = 180 / big;
    var tallest = Math.max.apply(null, order.map(function (k) { var f = face(groups[k].v); return Math.min(f[0], f[1]); }));
    var esc = function (t) { return String(t).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); };
    grid.style.setProperty('--k-stage', Math.ceil(tallest * S) + 'px');
    grid.innerHTML = order.map(function (k) {
      var g = groups[k], f = face(g.v), st = keys(g.stores);
      var w = Math.max(4, f[0] * S), h = Math.max(4, f[1] * S);
      var col = st.length === 1 ? (COL[st[0]] || 'var(--k-accent)') : 'var(--k-accent)';
      var mesh = D.mode === '2d' ? ' kurabe-mesh" style="--k-cell:' + (2.5 * S) + 'px;' : '" style="';
      return '<figure class="kurabe-scale-item"><div class="kurabe-scale-stage"><div class="kurabe-scale-box' + mesh +
        'width:' + w.toFixed(1) + 'px;height:' + h.toFixed(1) + 'px;border-color:' + col + '"></div></div>' +
        '<figcaption><b>' + esc(k) + '</b><span>' + esc(st.map(lbl).join('・')) + '</span><span>' +
        keys(g.prices).sort(function (a, b) { return a - b; }).join('/') + '円</span></figcaption></figure>';
    }).join('');
    var names = { '2d': '', '3d': '各ケースのいちばん大きい面（長い2辺）を描いています。', 'range': '線の太い部分が伸縮できる範囲です。' };
    var present = D.rows.reduce(function (o, r) { o[r.s] = true; return o; }, {});
    sc.querySelector('.kurabe-legend').innerHTML = (D.stores || []).map(function (st) { return st.s; }).filter(function (s) { return present[s]; }).map(function (s) {
      return '<span><i style="background:' + esc(COL[s]) + '"></i>' + esc(lbl(s)) + 'のみ</span>';
    }).join('') + '<span><i style="background:var(--k-accent)"></i>複数の店にあるサイズ</span>' + (names[D.mode] ? '<span>' + names[D.mode] + '</span>' : '');
  }
})();
