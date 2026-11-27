/**
 * セールマイクロコピーの自動フィット (v0.21.0)
 *
 * 長い文言 (例: ブラックフライデー先行セール開催中) が狭い3カラムカードで
 * はみ出すのを、はみ出す要素だけフォントを縮小して1行に収める。
 * 下限9px。リサイズ・フォント読み込み後にも再計算する。
 */
(function () {
    'use strict';

    function setScale(el, icon, size) {
        el.style.fontSize = size + 'px';
        if (icon) {
            var k = size / 11.5;
            var d = Math.max(12, Math.round(16 * k));
            icon.style.width = d + 'px';
            icon.style.height = d + 'px';
            icon.style.fontSize = Math.max(8, Math.round(11 * k)) + 'px';
        }
    }

    function fitOne(el) {
        // いったん既定状態に戻して実測
        var icon = el.querySelector('.affiros-ai-sale-p');
        el.style.fontSize = '';
        el.classList.remove('affiros-ai-sale--noslash');
        if (icon) { icon.style.width = ''; icon.style.height = ''; icon.style.fontSize = ''; }

        var cw = el.clientWidth;
        if (!cw || el.scrollWidth <= cw) return;

        // 段階1: フォントとアイコンを比率で縮小 (下限9px)
        var base = parseFloat(window.getComputedStyle(el).fontSize) || 11.5;
        var size = Math.max(9, Math.floor(base * (cw / el.scrollWidth) * 10) / 10);
        setScale(el, icon, size);
        var guard = 6;
        while (guard-- > 0 && el.scrollWidth > el.clientWidth && size > 9) {
            size = Math.max(9, size - 0.5);
            setScale(el, icon, size);
        }
        if (el.scrollWidth <= el.clientWidth) return;

        // 段階2: それでも溢れる長文言は ＼／ を外して文言を活かす
        el.classList.add('affiros-ai-sale--noslash');
    }

    function fitAll() {
        var els = document.querySelectorAll('.affiros-ai-sale');
        for (var i = 0; i < els.length; i++) fitOne(els[i]);
    }

    var timer = null;
    function fitSoon() {
        if (timer) clearTimeout(timer);
        timer = setTimeout(fitAll, 150);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', fitAll);
    } else {
        fitAll();
    }
    window.addEventListener('load', fitAll);      // Webフォント適用後の実寸で再計算
    window.addEventListener('resize', fitSoon);
    if (document.fonts && document.fonts.ready && document.fonts.ready.then) {
        document.fonts.ready.then(fitAll);
    }
})();
