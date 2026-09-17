/* 給湯器エラーコード診断 */
(function () {
  'use strict';
  var D = window.KYUTOKI_SHINDAN;
  if (!D) { return; }

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  // リンクに使ってよいのは http と https だけにする。
  // 万一データに javascript: が混ざっても、そのまま href に出さない。
  function safeUrl(u) {
    var s = String(u || '').trim();
    return /^https?:\/\//i.test(s) ? s : '';
  }

  function badge(repair) {
    if (repair === true)  { return ['kys__badge--repair', '業者に修理を依頼してください']; }
    if (repair === false) { return ['kys__badge--self',   '自分で確認できる範囲です']; }
    if (repair)           { return ['kys__badge--cond',   '条件によって変わります']; }
    return ['kys__badge--unknown', '公式に自分で直せるとは書かれていません'];
  }

  // 番号の正規化。全角・前後の空白・E/Uなどの前置きを落として比べる
  function norm(v) {
    return String(v || '')
      .replace(/[０-９]/g, function (c) {
        return String.fromCharCode(c.charCodeAt(0) - 0xFEE0);
      })
      .replace(/[\s　]/g, '')
      .toUpperCase();
  }

  function findCode(maker, input) {
    var q = norm(input);
    if (!q) { return null; }
    var list = maker.codes || [];
    var i, j;
    for (i = 0; i < list.length; i++) {
      // 「88/888」のような複合キーは、ボタンの値がキーそのものになる。
      // 個別の番号だけで突き合わせると、自分のボタンで引けなくなる。
      if (norm(list[i].code) === q) { return list[i]; }
      for (j = 0; j < list[i].numbers.length; j++) {
        if (norm(list[i].numbers[j]) === q) { return list[i]; }
      }
    }
    // 前ゼロ違い（032 と 32）も拾う
    var qn = q.replace(/^0+/, '');
    for (i = 0; i < list.length; i++) {
      for (j = 0; j < list[i].numbers.length; j++) {
        if (norm(list[i].numbers[j]).replace(/^0+/, '') === qn) { return list[i]; }
      }
    }
    return null;
  }

  function ctaHtml(entry) {
    var c = D.cta || {}, out = '';
    // 交換の案内は、寿命や消耗が絡む番号だけに出す。
    // 「部品」「交換」まで拾うと、点火不良のような一過性の番号にも出てしまう。
    var wantsReplace = /中和器|寿命|経年|標準使用期間|点検時期/.test(
      (entry.meaning || '') + ' ' + (entry.extra || ''));
    var repairUrl = safeUrl(c.repair_url);
    var replaceUrl = safeUrl(c.replace_url);
    if (entry.repair !== false && repairUrl) {
      out += '<div class="kys__cta"><h4>' + esc(c.repair_title) + '</h4>' +
             '<p>' + esc(c.repair_text) + '</p>' +
             '<a class="kys__btn" href="' + esc(repairUrl) +
             '" rel="nofollow sponsored noopener" target="_blank">' +
             esc(c.repair_label) + '</a></div>';
    }
    if (wantsReplace && replaceUrl) {
      out += '<div class="kys__cta"><h4>' + esc(c.replace_title) + '</h4>' +
             '<p>' + esc(c.replace_text) + '</p>' +
             '<a class="kys__btn" href="' + esc(replaceUrl) +
             '" rel="nofollow sponsored noopener" target="_blank">' +
             esc(c.replace_label) + '</a></div>';
    }
    return out;
  }

  function render(makerKey, entry) {
    var maker = D.makers[makerKey];
    var b = badge(entry.repair);
    var h = '<div class="kys__card"><div class="kys__head">' +
            '<span class="kys__maker-name">' + esc(maker.name) + '</span>' +
            '<span class="kys__code-big">' + esc(entry.code) + '</span>' +
            '</div><div class="kys__body">';

    if (entry.danger) {
      h += '<div class="kys__danger"><strong>使用を止めて、メーカーか業者に連絡してください</strong>' +
           '<p>この番号は、燃焼や排気、温度の異常を知らせる表示です。' +
           '運転を続けると危険な場合があります。給湯器の使用を止めて、' +
           'ガスのにおいがするときはガス会社にも連絡してください。</p></div>';
    }

    if (entry.meaning) {
      h += '<h4>この番号の意味</h4><p>' + esc(entry.meaning) + '</p>';
    }

    if (entry.causes && entry.causes.length) {
      h += '<h4>考えられる原因</h4>';
      entry.causes.forEach(function (c) {
        if (c.label) { h += '<p class="kys__sub">' + esc(c.label) + '</p>'; }
        h += '<p>' + esc(c.text) + '</p>';
      });
    }

    if (entry.actions && entry.actions.length) {
      h += '<h4>自分で確認できること</h4>';
      entry.actions.forEach(function (a) {
        if (a.label) { h += '<p class="kys__sub">' + esc(a.label) + '</p>'; }
        h += '<p>' + esc(a.text) + '</p>';
      });
    }

    if (entry.extra) {
      h += '<h4>補足</h4><p>' + esc(entry.extra) + '</p>';
    }

    h += '<span class="kys__badge ' + b[0] + '">' + b[1] + '</span>';

    if (entry.warning) {
      h += '<div class="kys__warn"><strong>電源を抜く前に読んでください</strong>' +
           '<p>' + esc(entry.warning) + '</p></div>';
    }

    if (entry.link) {
      h += '<div><a class="kys__more" href="' + esc(entry.link.url) + '">' +
           esc(entry.link.title) + '</a></div>';
    }

    entry.source = safeUrl(entry.source);
    if (entry.source) {
      // 個別ページがあるものはその番号の説明へ、
      // 長府のようにメーカー単位でしか出典が無いものは検索ページへ送る
      var label = entry.source_is_index
        ? '公式のエラーコード検索ページを開く'
        : 'この番号の公式の説明を見る';
      h += '<p class="kys__source">出典：' + esc(maker.name) +
           'の公式ページ（<a href="' + esc(entry.source) +
           '" target="_blank" rel="nofollow noopener">' + label + '</a>）　' +
           '確認した時点：' + esc(D.fetchedAt) + '</p>';
    }

    h += '</div></div>' + ctaHtml(entry);
    return h;
  }

  document.querySelectorAll('[data-kys]').forEach(function (root) {
    var step1 = root.querySelector('[data-kys-step="1"]');
    var step2 = root.querySelector('[data-kys-step="2"]');
    var codesBox = root.querySelector('[data-kys-codes]');
    var nameBox = root.querySelector('[data-kys-makername]');
    var result = root.querySelector('[data-kys-result]');
    var input = root.querySelector('[data-kys-input]');
    var current = null;

    function show(html) {
      result.innerHTML = html;
      result.hidden = false;
      result.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    function pickMaker(key) {
      current = key;
      var maker = D.makers[key];
      root.querySelectorAll('[data-kys-maker]').forEach(function (b) {
        b.setAttribute('aria-pressed', String(b.dataset.kysMaker === key));
      });
      nameBox.textContent = maker.name;
      result.hidden = true;
      result.innerHTML = '';

      if (maker.blocked) {
        step2.hidden = true;
        show('<div class="kys__blocked"><p>' + esc(maker.note) + '</p></div>');
        return;
      }
      codesBox.innerHTML = maker.codes.map(function (c) {
        return '<button type="button" class="kys__code" data-kys-pick="' +
               esc(c.code) + '">' + esc(c.code) + '</button>';
      }).join('');
      step2.hidden = false;
      step2.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    function lookup(value) {
      var maker = D.makers[current];
      if (!maker) { return; }
      var entry = findCode(maker, value);
      if (entry) { show(render(current, entry)); return; }

      var msg = '<div class="kys__notfound"><p>' + esc(maker.name) +
                'の公式ページで確認できている番号の中に、' + esc(value) +
                ' はありませんでした。</p>';
      (maker.negatives || []).forEach(function (n) {
        msg += '<p>' + esc(n.claim) + '</p>';
      });
      msg += '<p>番号の読み違いか、機種が違う可能性があります。' +
             '給湯器本体の銘板でメーカーと型番を確認してから、もう一度お試しください。</p></div>';
      show(msg);
    }

    root.addEventListener('click', function (e) {
      var m = e.target.closest('[data-kys-maker]');
      if (m) { pickMaker(m.dataset.kysMaker); return; }
      var p = e.target.closest('[data-kys-pick]');
      if (p) { lookup(p.dataset.kysPick); return; }
      if (e.target.closest('[data-kys-go]')) { lookup(input.value); return; }
      if (e.target.closest('[data-kys-back]')) {
        step2.hidden = true;
        result.hidden = true;
        step1.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
      }
    });

    input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') { e.preventDefault(); lookup(input.value); }
    });
  });
}());
