/**
 * ショートコード非対応領域のフォールバック
 *
 * テーマのポップアップ等、do_shortcode() を通らない場所に書かれた
 * [affiros_ai_top...] は文字のまま画面に出てしまう。このスクリプトが
 * ページ内のテキストノードからそれを検出し、AJAXで取得したカードHTMLに
 * その場で置換する。
 *
 * - 初回スキャン: DOMContentLoaded
 * - 追いスキャン: クリック後 400ms (ポップアップ等の遅延DOM挿入に対応)
 * - 高速ガード: ページ内に "[affiros_ai_top" が無ければ何もしない
 */
(function () {
    'use strict';
    var cfg = window.AffirosAITop || {};
    if (!cfg.ajaxUrl || !cfg.postId) return;

    var RE = /\[affiros_ai_top([^\]]*)\]/;
    var processing = false;

    function parseAttrs(s) {
        var rank = /rank\s*=\s*"?(\d+)"?/.exec(s);
        var title = /title\s*=\s*"([^"]*)"/.exec(s);
        return {
            rank: rank ? rank[1] : '1',
            hasTitle: !!title,
            title: title ? title[1] : ''
        };
    }

    function replaceNode(node, m) {
        var attrs = parseAttrs(m[1]);
        var body = new URLSearchParams();
        body.append('action', 'affiros_ai_render_top');
        body.append('post_id', cfg.postId);
        body.append('rank', attrs.rank);
        body.append('has_title', attrs.hasTitle ? '1' : '0');
        body.append('title', attrs.title);

        fetch(cfg.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res || !res.success) return;
                var parent = node.parentNode;
                if (!parent) return;
                var text = node.nodeValue;
                var idx = text.indexOf(m[0]);
                if (idx === -1) return;
                // テキストノードを [前 | カードHTML | 後] に分割して差し込む
                var after = document.createTextNode(text.slice(idx + m[0].length));
                node.nodeValue = text.slice(0, idx);
                parent.insertBefore(after, node.nextSibling);
                var wrap = document.createElement('div');
                wrap.innerHTML = res.data || '';
                while (wrap.firstChild) parent.insertBefore(wrap.firstChild, after);
            })
            .catch(function () { /* 失敗時は文字のまま (実害は見た目のみ) */ });
    }

    function scan() {
        if (processing) return;
        if (!document.body || document.body.innerHTML.indexOf('[affiros_ai_top') === -1) return;
        processing = true;
        try {
            var walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT, null);
            var targets = [];
            var n;
            while ((n = walker.nextNode())) {
                var tag = n.parentNode && n.parentNode.nodeName;
                if (tag === 'SCRIPT' || tag === 'STYLE' || tag === 'TEXTAREA' || tag === 'NOSCRIPT') continue;
                var m = RE.exec(n.nodeValue);
                if (m) targets.push([n, m]);
            }
            targets.forEach(function (t) { replaceNode(t[0], t[1]); });
        } finally {
            processing = false;
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', scan);
    } else {
        scan();
    }
    document.addEventListener('click', function () { setTimeout(scan, 400); }, true);
})();
