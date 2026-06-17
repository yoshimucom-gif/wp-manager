<?php
/**
 * 連続カード検出ページ
 *
 * Affiros9 v1.7.27 以前の広告挿入位置バグで、設定上は「冒頭」「末尾」と別位置に
 * なってるはずのカードが両方とも記事冒頭に固まってしまった記事を抽出する。
 *
 * 検出ロジック:
 *   wp:html ブロック（aipi-* div を含む）が直後に別の wp:html ブロックと
 *   くっついている、または間に空 <p></p> や見出しが無いまま隣接している
 *   箇所を「連続カード」と判定する。
 */

if (!defined('ABSPATH')) exit;

function ai_pi_render_adjacent_cards_page() {
    if (!current_user_can('manage_options')) return;
    ?>
    <div class="wrap">
        <h1>🔍 連続カード検出</h1>
        <p style="font-size:13px;line-height:1.7">
            商品カード（比較表・ランキング・縦置き）が記事内で<strong>連続して2つ以上配置されている記事</strong>を抽出します。<br>
            Affiros9 の広告挿入位置バグで「冒頭 + 末尾」で配置したつもりが両方冒頭に
            固まってしまった記事の特定に使います。
        </p>

        <div style="background:#fffbeb;border:1px solid #fbbf24;padding:12px;margin:16px 0;border-radius:4px">
            <strong>⚠️ 判定基準</strong>
            <ul style="margin:6px 0 0 20px;line-height:1.7;font-size:13px">
                <li><strong>連続</strong>: カード（<code>&lt;div class="aipi-*"&gt;</code>）の終了直後に、間に H2/H3/通常段落 をはさまずに別のカードが配置されている状態</li>
                <li>空 <code>&lt;p&gt;&lt;/p&gt;</code>、<code>&lt;br&gt;</code>、wp ブロックコメント、改行のみが間にある場合は「連続」扱い</li>
                <li>本物のテキストが間に1段落でもあれば「正常配置」</li>
            </ul>
        </div>

        <div style="margin:20px 0">
            <button type="button" id="aipi-adj-scan-btn" class="button button-primary">🔍 全記事スキャン</button>
            <span id="aipi-adj-scan-status" style="margin-left:12px;color:#666;font-size:13px"></span>
        </div>

        <div id="aipi-adj-result" style="display:none;margin-top:16px">
            <h2 style="margin-bottom:8px">🚨 連続カードを含む記事</h2>
            <p id="aipi-adj-summary" style="margin:4px 0 12px"></p>

            <table class="wp-list-table widefat striped">
                <thead>
                    <tr>
                        <th style="width:60px">ID</th>
                        <th>タイトル</th>
                        <th style="width:100px">連続箇所</th>
                        <th style="width:160px">カード種類</th>
                        <th style="width:80px">ステータス</th>
                        <th style="width:80px">編集</th>
                    </tr>
                </thead>
                <tbody id="aipi-adj-tbody"></tbody>
            </table>
        </div>
    </div>

    <script>
    (function ($) {
        const ajaxUrl = (window.aiPI && aiPI.ajaxUrl) || ajaxurl;
        const nonce   = (window.aiPI && aiPI.nonce) || '';

        $('#aipi-adj-scan-btn').on('click', scan);

        async function scan() {
            $('#aipi-adj-scan-btn').prop('disabled', true);
            $('#aipi-adj-result').hide();
            $('#aipi-adj-tbody').empty();
            $('#aipi-adj-scan-status').text('スキャン中...');
            try {
                const res = await $.post(ajaxUrl, {
                    action: 'ai_pi_scan_adjacent_cards',
                    nonce: nonce,
                });
                if (!res || !res.success) {
                    alert('スキャン失敗: ' + (res && res.data ? res.data : ''));
                    return;
                }
                const posts = res.data.posts || [];
                $('#aipi-adj-scan-status').text(
                    `完了: ${res.data.scanned}件チェック / 連続カードあり ${posts.length}件`
                );
                $('#aipi-adj-summary').text(`${posts.length} 件の記事で商品カードが連続配置されています`);
                render(posts);
                if (posts.length) $('#aipi-adj-result').show();
            } catch (e) {
                alert('通信エラー: ' + (e.responseText || e.statusText));
            } finally {
                $('#aipi-adj-scan-btn').prop('disabled', false);
            }
        }

        function render(posts) {
            const tbody = $('#aipi-adj-tbody').empty();
            posts.forEach(p => {
                const editUrl = `${location.origin}/wp-admin/post.php?post=${p.id}&action=edit`;
                tbody.append(`
                    <tr>
                        <td>${p.id}</td>
                        <td><a href="${editUrl}" target="_blank">${esc(p.title)}</a></td>
                        <td style="text-align:center;color:#dc2626;font-weight:600">${p.adjacent_count}</td>
                        <td><code style="font-size:11px">${esc(p.designs.join(' + '))}</code></td>
                        <td>${esc(p.status)}</td>
                        <td><a href="${editUrl}" target="_blank" class="button button-small">編集</a></td>
                    </tr>
                `);
            });
        }

        function esc(s) {
            return String(s == null ? '' : s).replace(/[<>&"]/g, c =>
                ({'<':'&lt;','>':'&gt;','&':'&amp;','"':'&quot;'}[c])
            );
        }
    })(jQuery);
    </script>
    <?php
}

/**
 * AJAX: 連続カード検出
 */
add_action('wp_ajax_ai_pi_scan_adjacent_cards', function () {
    check_ajax_referer('ai_pi_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('権限がありません');
    @set_time_limit(120);

    global $wpdb;
    $rows = $wpdb->get_results(
        "SELECT ID, post_title, post_status, post_content
         FROM {$wpdb->posts}
         WHERE post_type = 'post'
           AND post_status IN ('publish', 'future', 'draft', 'private')
         ORDER BY ID DESC"
    );

    $found = [];
    foreach ($rows as $r) {
        $analysis = ai_pi_find_adjacent_cards($r->post_content);
        if ($analysis['adjacent_count'] <= 0) continue;
        $found[] = [
            'id'             => (int)$r->ID,
            'title'          => $r->post_title,
            'status'         => $r->post_status,
            'adjacent_count' => $analysis['adjacent_count'],
            'designs'        => $analysis['designs'],
        ];
    }

    wp_send_json_success([
        'scanned' => count($rows),
        'posts'   => $found,
    ]);
});

/**
 * 記事内の商品カード配置を解析して連続箇所を返す。
 *
 * Returns:
 *   adjacent_count: int 連続発生回数
 *   designs:        string[] 連続している箇所のカード種類リスト（例: ["compare → compare"]）
 */
function ai_pi_find_adjacent_cards($content) {
    if (!$content) return ['adjacent_count' => 0, 'designs' => []];

    // aipi-* div を含む wp:html ブロックを順番に抜き出す（位置情報付き）
    $pattern = '/<!--\s*wp:html\s*-->\s*<div\s+class="(aipi-[a-z]+)[^"]*"[\s\S]*?<\/div>\s*<!--\s*\/wp:html\s*-->/i';
    if (!preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
        return ['adjacent_count' => 0, 'designs' => []];
    }

    $blocks = []; // [start, end, design]
    for ($i = 0; $i < count($matches[0]); $i++) {
        $start = $matches[0][$i][1];
        $end   = $start + strlen($matches[0][$i][0]);
        $design_raw = $matches[1][$i][0]; // aipi-compare / aipi-ranking / aipi-vertical 等
        $design = preg_replace('/^aipi-/', '', $design_raw);
        $blocks[] = ['start' => $start, 'end' => $end, 'design' => $design];
    }

    if (count($blocks) < 2) return ['adjacent_count' => 0, 'designs' => []];

    $adjacent_pairs = [];
    for ($i = 0; $i < count($blocks) - 1; $i++) {
        $between = substr($content, $blocks[$i]['end'], $blocks[$i + 1]['start'] - $blocks[$i]['end']);
        if (ai_pi_is_empty_between($between)) {
            $adjacent_pairs[] = $blocks[$i]['design'] . ' → ' . $blocks[$i + 1]['design'];
        }
    }
    return [
        'adjacent_count' => count($adjacent_pairs),
        'designs'        => array_values(array_unique($adjacent_pairs)),
    ];
}

/**
 * 2つのカードの間にあるコンテンツが「実質的に空」かを判定。
 * 「空」の定義:
 *   - 空白・改行のみ
 *   - wp ブロックコメントのみ
 *   - 中身ゼロの <p></p> や <p>&nbsp;</p>
 *   - 区切り線 <hr> のみ
 *
 * 一方、これらがあれば「本物の段落あり」と判定:
 *   - <h2>, <h3>, <h4>
 *   - 文字数が3字以上ある <p>
 *   - <ul>, <ol>, <table>, <figure>, <img>
 */
function ai_pi_is_empty_between($html) {
    // wp ブロックコメント・空白・改行を消す
    $stripped = preg_replace('/<!--[\s\S]*?-->/', '', $html);
    $stripped = preg_replace('/\s+/u', '', $stripped);
    if ($stripped === '') return true;

    // <p></p> や <p>&nbsp;</p> を消す
    $stripped = preg_replace('/<p[^>]*>(?:&nbsp;|\s|　)*<\/p>/iu', '', $stripped);
    // <hr> を消す
    $stripped = preg_replace('/<hr\s*\/?>/i', '', $stripped);
    if ($stripped === '') return true;

    // 本物の見出し・段落・リスト・画像があれば「空じゃない」
    if (preg_match('/<(h[1-6]|ul|ol|table|figure|img|blockquote)\b/i', $stripped)) {
        return false;
    }

    // 残ってる <p>...</p> の中身が3文字未満なら空扱い
    if (preg_match('/<p[^>]*>([\s\S]*?)<\/p>/iu', $stripped, $m)) {
        $text = trim(preg_replace('/<[^>]+>/u', '', $m[1]));
        if (mb_strlen($text) < 3) return true;
        return false;
    }

    // タグの残骸しかなければ空扱い
    $text_only = trim(preg_replace('/<[^>]+>/u', '', $stripped));
    return mb_strlen($text_only) < 3;
}
