/* カタログギフト診断（[kurabe_shindan]）。5問の答えで DB の行を絞り、コース単位で上位5件を出す。
   選んだ理由の文は、条件に合うコースの中での順位（掲載点数・送料込み・有効期限）から機械的に作る。
   データは REST（/wp-json/ckdb/v1/shindan/<投稿ID>）から読む。本文中の <script> はサイトによって削られるため */
(function () {
  'use strict';
  var C = {};   // 列名 → 位置
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function yen(n) { return Number(n).toLocaleString('ja-JP'); }
  function v(r, k) { return r[C[k]]; }
  function total(r) { var s = v(r, 'ship'); return s == null ? null : v(r, 'price') + s; }

  function init(box) {
    fetch(box.getAttribute('data-src'), { credentials: 'same-origin' })
      .then(function (res) { return res.json(); })
      .then(function (d) {
        if (!d || !d.rows) { throw new Error('no data'); }
        d.cols.forEach(function (k, i) { C[k] = i; });
        render(box, d);
      })
      .catch(function () {
        box.innerHTML = '<p class="ks-error">診断のデータを読み込めませんでした。ページを再読み込みしてください。</p>';
      });
  }

  /* 1問ずつ出す（2026-10-01 吉村さん「デザインみにくい」→ 5問を一度に並べるのをやめた）。
     答えを押すと次の問いへ進み、5問目のあとに結果。結果の上の「条件」から押した問いだけを選び直せる */
  var SUB = {   // 選択肢の下に添える一言（どれもデータの絞り方の説明で、品物の良し悪しは書かない）
    g: ['ジャンルで絞らない', '食品・雑貨・体験などが1冊にそろう', '肉・お酒・スイーツなど食べ物だけ', '食事・体験・宿泊から選ぶ', '出産祝い向けの子ども用品', '生活雑貨のカタログ'],
    f: ['形で絞らない', '写真の載った本の形で渡す', '申込用のカードだけを渡す', 'URLをメールやSNSで送る'],
    p: { items: '掲載点数の多い順', total: '送料込みの価格の安い順', hagaki: 'ハガキで申し込めるコースを先に', expiry: '申込の有効期限の長い順' }
  };
  var QS = [
    { k: 's', t: 'どんな用途で贈りますか', hint: '各社の公式通販がその用途向けとして案内しているコースから選びます。' },
    { k: 'b', t: '予算はいくらですか', hint: '本体の税込価格で選びます。送料込みの金額は結果に出します。' },
    { k: 'g', t: '中身の好みはありますか', hint: '' },
    { k: 'f', t: 'どの形で渡しますか', hint: '' },
    { k: 'p', t: 'いちばん重視することは何ですか', hint: '結果の並び順が変わります。' }
  ];

  function tile(q, val, label, sub, cur) {
    return '<button type="button" class="ks-opt" data-q="' + q + '" data-v="' + esc(val) + '" aria-pressed="' + (String(val) === String(cur) ? 'true' : 'false') + '">' +
      '<span class="ks-opt-l">' + esc(label) + '</span>' + (sub ? '<span class="ks-opt-s">' + esc(sub) + '</span>' : '') + '</button>';
  }

  function options(d, q, cur) {
    if (q === 's') {
      var h = '';
      d.sceneGroups.forEach(function (g) {
        h += '<p class="ks-group">' + esc(g[0]) + '</p><div class="ks-grid">' +
          g[1].map(function (i) { return tile('s', i, d.scenes[i], '', cur); }).join('') + '</div>';
      });
      return h + '<p class="ks-group">そのほか</p><div class="ks-grid">' + tile('s', -1, '決まっていない・ほかの用途', '', cur) + '</div>';
    }
    if (q === 'b') {
      return '<div class="ks-grid ks-grid-b">' + d.budgets.map(function (b, i) { return tile('b', i, b[0], '', cur); }).join('') + '</div>';
    }
    if (q === 'g') {
      return '<div class="ks-grid ks-grid-wide">' + d.genres.map(function (g, i) { return tile('g', i, g[0], SUB.g[i], cur); }).join('') + '</div>';
    }
    if (q === 'f') {
      return '<div class="ks-grid ks-grid-wide">' + d.formats.map(function (f, i) { return tile('f', i, f, SUB.f[i], cur); }).join('') + '</div>';
    }
    return '<div class="ks-grid ks-grid-wide">' + d.priorities.map(function (p) { return tile('p', p[0], p[1], SUB.p[p[0]], cur); }).join('') + '</div>';
  }

  function answerLabel(d, k, val) {
    if (k === 's') { return Number(val) < 0 ? '用途は未定' : d.scenes[val]; }
    if (k === 'b') { return d.budgets[val][0]; }
    if (k === 'g') { return Number(val) === 0 ? '中身はこだわらない' : d.genres[val][0]; }
    if (k === 'f') { return Number(val) === 0 ? '形はこだわらない' : d.formats[val]; }
    return d.priorities.filter(function (p) { return p[0] === val; })[0][1];
  }

  function render(box, d) {
    var st = { s: null, b: null, g: null, f: null, p: null }, step = 0, done = false;

    function top() {
      var r = box.getBoundingClientRect();
      if (r.top < 160 || r.top > window.innerHeight * 0.6) { box.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
    }
    function showQ() {
      var q = QS[step], h = '<div class="ks-panel">';
      h += '<div class="ks-progress"><span class="ks-step">質問 ' + (step + 1) + ' / ' + QS.length + '</span><span class="ks-bar">';
      for (var i = 0; i < QS.length; i++) { h += '<i class="' + (i <= step ? 'on' : '') + '"></i>'; }
      h += '</span></div>';
      h += '<p class="ks-title">' + esc(q.t) + '</p>' + (q.hint ? '<p class="ks-hint">' + esc(q.hint) + '</p>' : '');
      h += options(d, q.k, st[q.k]);
      h += '<div class="ks-nav">' + (step > 0 && !done ? '<button type="button" class="ks-back" data-act="back">← ひとつ前の質問へ</button>' : '') +
        (done ? '<button type="button" class="ks-back" data-act="result">選び直さずに結果へ戻る</button>' : '') + '</div>';
      box.innerHTML = h + '</div>';
    }
    function showResult() {
      var h = '<div class="ks-cond"><span class="ks-cond-l">選んだ条件</span>';
      QS.forEach(function (q, i) {
        h += '<button type="button" class="ks-cond-b" data-act="edit" data-i="' + i + '">' + esc(answerLabel(d, q.k, st[q.k])) + '</button>';
      });
      h += '<button type="button" class="ks-reset" data-act="reset">最初からやり直す</button></div>';
      box.innerHTML = h + '<div class="ks-out" aria-live="polite">' + result(d, st) + '</div>';
    }

    box.addEventListener('click', function (e) {
      var b = e.target.closest('button');
      if (!b || !box.contains(b)) { return; }
      var act = b.getAttribute('data-act');
      if (b.hasAttribute('data-q')) {
        st[b.getAttribute('data-q')] = b.getAttribute('data-v');
        if (done || step === QS.length - 1) { done = true; showResult(); } else { step++; showQ(); }
      } else if (act === 'back') { step--; showQ(); }
      else if (act === 'edit') { step = Number(b.getAttribute('data-i')); showQ(); }
      else if (act === 'result') { showResult(); }
      else if (act === 'reset') { st = { s: null, b: null, g: null, f: null, p: null }; step = 0; done = false; showQ(); }
      else { return; }
      top();
    });
    showQ();
  }

  function filter(d, c) {
    var b = d.budgets[c.b], genres = d.genres[c.g][1], fmt = d.formats[c.f];
    return d.rows.filter(function (r) {
      var p = v(r, 'price');
      if (!c.wide && (p < b[1] || p > b[2])) { return false; }
      if (c.wide && (p < d.budgets[Math.max(0, c.b - 1)][1] || p > d.budgets[Math.min(d.budgets.length - 1, c.b + 1)][2])) { return false; }
      if (c.s >= 0 && v(r, 'scenes').indexOf(c.s) < 0) { return false; }
      if (genres && genres.indexOf(v(r, 'genre')) < 0) { return false; }
      if (c.f > 0 && v(r, 'format') !== fmt) { return false; }
      return true;
    });
  }

  var KEY = {
    items: function (r) { var n = v(r, 'items'); return n == null ? -Infinity : n; },
    total: function (r) { var t = total(r); return t == null ? -Infinity : -t; },
    expiry: function (r) { var n = v(r, 'expDays'); return n == null ? -Infinity : n; },
    hagaki: function (r) { return v(r, 'hagaki') === 1 ? 1 : 0; }
  };
  function cmp(p) {
    var second = p === 'total' ? KEY.items : KEY.total;
    if (p === 'hagaki') { second = KEY.items; }
    return function (a, b) {
      return (KEY[p](b) - KEY[p](a)) || (second(b) - second(a)) || (KEY.items(b) - KEY.items(a)) || (v(a, 'price') - v(b, 'price'));
    };
  }

  function result(d, st) {
    var base = { s: Number(st.s), b: Number(st.b), g: Number(st.g), f: Number(st.f), wide: false };
    var c = base, relaxed = [];
    var rows = filter(d, c);
    if (!rows.length) {
      // ぴったり合うコースが無いときは、ゆるめる条件がいちばん少ない組み合わせから試す。
      // 優先して残すのは 用途 → 予算 → 中身 → 渡し方。「こだわらない」を選んだ問いはゆるめたことにしない
      var can = [];
      if (base.f > 0) { can.push(['f', '渡し方']); }
      if (base.g > 0) { can.push(['g', '中身の好み']); }
      can.push(['b', '予算']);
      if (base.s >= 0) { can.push(['s', '用途']); }
      var W = { f: 1, g: 2, b: 4, s: 8 }, sets = [];
      for (var m = 1; m < (1 << can.length); m++) {
        var set = can.filter(function (x, i) { return m & (1 << i); });
        sets.push({ set: set, n: set.length, w: set.reduce(function (a, x) { return a + W[x[0]]; }, 0) });
      }
      sets.sort(function (a, b) { return (a.n - b.n) || (a.w - b.w); });
      for (var k = 0; k < sets.length && !rows.length; k++) {
        c = { s: base.s, b: base.b, g: base.g, f: base.f, wide: false };
        sets[k].set.forEach(function (x) {
          if (x[0] === 'f') { c.f = 0; } else if (x[0] === 'g') { c.g = 0; } else if (x[0] === 'b') { c.wide = true; } else { c.s = -1; }
        });
        rows = filter(d, c);
        if (rows.length) { relaxed = sets[k].set.map(function (x) { return x[1]; }); }
      }
    }
    if (!rows.length) { return '<p class="ks-wait">条件に合うコースがありませんでした。予算を変えてみてください。</p>'; }

    // コース単位にまとめる（同じコースの冊子・カード・eギフトは1件に。代表は重視することでいちばん良い行）
    var sorter = cmp(st.p), g = {};
    rows.forEach(function (r) { (g[v(r, 'course')] = g[v(r, 'course')] || []).push(r); });
    var courses = Object.keys(g).map(function (k) {
      var rs = g[k].slice().sort(sorter);
      return { rep: rs[0], fmts: rs.map(function (r) { return v(r, 'format'); }).filter(function (x, i, a) { return x && a.indexOf(x) === i; }) };
    });
    courses.sort(function (a, b) { return sorter(a.rep, b.rep); });
    var N = courses.length;
    var issuers = {};
    courses.forEach(function (x) { issuers[v(x.rep, 'issuer')] = true; });
    var nHagaki = courses.filter(function (x) { return v(x.rep, 'hagaki') === 1; }).length;

    function rank(x, key) {
      var mine = KEY[key](x.rep);
      return 1 + courses.filter(function (y) { return KEY[key](y.rep) > mine; }).length;
    }
    function ord(r, most) { return r === 1 ? 'いちばん' + most : r + '番目に' + most; }

    var h = '<p class="ks-sum">条件に合うのは <b>' + N + '</b> コース（' + Object.keys(issuers).length + '社）です。' +
      (N > 5 ? '「' + esc(d.priorities.filter(function (p) { return p[0] === st.p; })[0][1]) + '」の順に5つ出しています。' : '') + '</p>';
    if (relaxed.length) {
      h += '<p class="ks-relax">ぴったり合うコースが無かったため、' + esc(relaxed.join('・')) + 'の条件をゆるめて探しました。' + (c.wide ? '予算は、選んだ価格帯の前後まで広げています。' : '') + '</p>';
    }
    h += '<ol class="ks-list">';
    courses.slice(0, 5).forEach(function (x, i) {
      var r = x.rep, iss = d.issuers[v(r, 'issuer')], t = total(r), why = [];
      if (st.p === 'items') {
        why.push(v(r, 'items') == null ? '掲載点数の記載がないコースです（ポイント制や体験型など）。' : '条件に合う' + N + 'コースのうち、掲載点数が' + ord(rank(x, 'items'), '多い') + 'コースです。');
      } else if (st.p === 'total') {
        why.push(t == null ? '送料の記載がないコースです。' : '条件に合う' + N + 'コースのうち、送料込みの価格が' + ord(rank(x, 'total'), '安い') + 'コースです。');
      } else if (st.p === 'expiry') {
        why.push(v(r, 'expDays') == null ? '申込の有効期限の日数が書かれていないコースです（' + esc(v(r, 'expLabel') || '記載なし') + '）。' : '条件に合う' + N + 'コースのうち、申込の有効期限が' + ord(rank(x, 'expiry'), '長い') + 'コースです。');
      } else {
        var hk = courses.filter(function (y) { return v(y.rep, 'hagaki') === 1; });
        var hr = 1 + hk.filter(function (y) { return KEY.items(y.rep) > KEY.items(r); }).length;
        why.push(v(r, 'hagaki') === 1 ? 'ハガキで申し込めると公式通販に書かれているコース（条件に合う' + N + 'コースのうち' + nHagaki + 'コース）の中で、' +
          (v(r, 'items') == null ? '掲載点数の記載がないコースです。' : '掲載点数が' + ord(hr, '多い') + 'コースです。')
          : nHagaki === 0
            ? '条件に合う' + N + 'コースには、ハガキで申し込めると公式通販に書かれているコースがありません。このコースのハガキ申込は「' + (v(r, 'hagaki') === 0 ? '不可' : '記載なし') + '」です。'
            : 'ハガキで申し込めるコースが条件の中に' + nHagaki + 'コースしかないため、ハガキ申込が「' + (v(r, 'hagaki') === 0 ? '不可' : '記載なし') + '」のコースも出しています。');
      }
      if (c.s >= 0) { why.push(esc(iss[0]) + 'の公式通販で「' + esc(d.scenes[c.s]) + '」向けとして案内されています。'); }
      h += '<li class="ks-card"><span class="ks-rank">' + (i + 1) + '</span><div class="ks-body">';
      h += '<p class="ks-head"><span class="kurabe-store" style="--kurabe-c:' + esc(iss[1]) + '">' + esc(iss[0]) + '</span>' +
        '<a class="ks-name" href="' + esc(v(r, 'url')) + '" target="_blank" rel="noopener">' + esc(v(r, 'name')) + '</a></p>';
      h += '<p class="ks-why">' + why.join('') + '</p>';
      h += '<dl class="ks-specs">' +
        '<div><dt>価格（税込）</dt><dd>' + yen(v(r, 'price')) + '円</dd></div>' +
        '<div><dt>送料込み</dt><dd>' + (t == null ? '記載なし' : yen(t) + '円' + (v(r, 'ship') === 0 ? '（送料無料）' : '')) + '</dd></div>' +
        '<div><dt>掲載点数</dt><dd>' + (v(r, 'items') == null ? '記載なし' : yen(v(r, 'items')) + '点') + '</dd></div>' +
        '<div><dt>申込の有効期限</dt><dd>' + esc(v(r, 'expLabel') || '記載なし') + '</dd></div>' +
        '<div><dt>ハガキ申込</dt><dd>' + (v(r, 'hagaki') === 1 ? '可' : v(r, 'hagaki') === 0 ? '不可' : '記載なし') + '</dd></div>' +
        '<div><dt>渡し方</dt><dd>' + esc(x.fmts.join('・') || '記載なし') + '</dd></div>' +
        '</dl>';
      h += '<a class="ks-btn" href="' + esc(v(r, 'url')) + '" target="_blank" rel="noopener">' + esc(iss[0]) + 'の公式通販で見る</a>';
      h += '</div></li>';
    });
    h += '</ol>';
    var L = d.links, link = null;
    if (c.s >= 0 && !c.wide) { link = L['s:' + d.scenes[c.s] + '|b:' + c.b]; }
    if (!link && c.s >= 0) { link = L['s:' + d.scenes[c.s]]; }
    if (!link && !c.wide) { link = L['b:' + c.b]; }
    if (!link) { link = L['g:' + c.g]; }
    if (link) {
      h += '<p class="ks-more"><a href="' + esc(link) + '">同じ条件のコースを比較表で全部見る</a></p>';
    }
    h += '<p class="ks-note">' + esc(d.checked.replace(/^(\d+)-0?(\d+)-0?(\d+)$/, '$1年$2月$3日')) + '時点の各社の公式通販の掲載値です。販売店によって価格や有効期限が変わる場合があります。</p>';
    return h;
  }

  function boot() { document.querySelectorAll('.kurabe-shindan[data-src]').forEach(init); }
  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', boot); } else { boot(); }
})();
