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
        <h1>🔍 連続カード／マーカー検出（比較表 / ランキング）</h1>
        <p style="font-size:13px;line-height:1.7">
            <strong>比較表（compare）／ランキング型（ranking）の「大きいカード」または未処理マーカーが連続している記事</strong>を抽出します。<br>
            Affiros9 の広告挿入位置バグで「冒頭 + 末尾」で配置したつもりが両方冒頭に
            固まってしまった記事を特定する用途です。
            <strong>予約投稿でまだ挿入処理していない記事（マーカーのみ）も対象</strong>。
        </p>

        <div style="background:#fffbeb;border:1px solid #fbbf24;padding:12px;margin:16px 0;border-radius:4px">
            <strong>⚠️ 判定基準</strong>
            <ul style="margin:6px 0 0 20px;line-height:1.7;font-size:13px">
                <li><strong>検出対象</strong>: 連続するブロックのうち、<strong>1枚以上が比較表（compare）またはランキング型（ranking）</strong> の「heavy カード」または「未処理マーカー」</li>
                <li><strong>検出する形式</strong>: 挿入処理済み <code>&lt;div class="aipi-compare"&gt;</code> / <code>&lt;div class="aipi-ranking"&gt;</code> カード／未処理 <code>&lt;!--ai-product:compare:3--&gt;</code> / <code>&lt;!--ai-product:ranking:3--&gt;</code> マーカー（両方混在もOK）</li>
                <li><strong>除外</strong>: vertical（縦置き1商品）同士の連続は読者誘導用の意図的な配置として<strong>触らない</strong>／<code>:brand</code> サフィックス付きマーカーも除外（商品深掘り構造で意図配置）</li>
                <li><strong>連続</strong>: ブロックの終了直後に、間に H2/H3/通常段落 をはさまずに別のブロックが配置されている状態</li>
                <li>空 <code>&lt;p&gt;&lt;/p&gt;</code>、wp ブロックコメント、改行のみが間にある場合は「連続」扱い</li>
                <li>本物のテキストが間に1段落でもあれば「正常配置」</li>
            </ul>
        </div>

        <div style="background:#eff6ff;border:1px solid #60a5fa;padding:12px;margin:16px 0;border-radius:4px">
            <strong>🛠 自動修正の仕組み</strong>
            <ul style="margin:6px 0 0 20px;line-height:1.7;font-size:13px">
                <li>連続した2枚のうち <strong>2枚目を削除</strong>（1枚目を保持）</li>
                <li>修正前にリビジョンが自動保存されるので、編集画面の「リビジョン」から元に戻せる</li>
                <li>連続が3枚以上ある場合は、2枚目以降をすべて削除（1枚目だけ残る）</li>
                <li>記事内の正常な配置のカード（間にコンテンツあり）には触らない</li>
            </ul>
        </div>

        <div style="margin:20px 0">
            <button type="button" id="aipi-adj-scan-btn" class="button button-primary">🔍 全記事スキャン</button>
            <span id="aipi-adj-scan-status" style="margin-left:12px;color:#666;font-size:13px"></span>
        </div>

        <div id="aipi-adj-result" style="display:none;margin-top:16px">
            <h2 style="margin-bottom:8px">🚨 連続カードを含む記事</h2>
            <p id="aipi-adj-summary" style="margin:4px 0 12px"></p>

            <div style="margin:0 0 12px">
                <button type="button" id="aipi-adj-fix-all-btn" class="button button-primary">🛠 全件 自動修正（2枚目以降を削除）</button>
                <span id="aipi-adj-fix-status" style="margin-left:12px;font-size:13px"></span>
            </div>

            <table class="wp-list-table widefat striped">
                <thead>
                    <tr>
                        <th style="width:60px">ID</th>
                        <th>タイトル</th>
                        <th style="width:100px">連続箇所</th>
                        <th style="width:160px">カード種類</th>
                        <th style="width:80px">ステータス</th>
                        <th style="width:160px">アクション</th>
                    </tr>
                </thead>
                <tbody id="aipi-adj-tbody"></tbody>
            </table>
        </div>
    </div>

    <script>
    (function ($) {
        // aiPI は wp_enqueue_script('...', true) でフッターに出力されるため、
        // 本インライン script が実行される時点ではまだ未定義のことがある。
        // 値を IIFE 開始時ではなく、AJAX呼び出し時に都度 window から読む。
        function ajaxUrl() { return (window.aiPI && aiPI.ajaxUrl) || (typeof ajaxurl !== 'undefined' ? ajaxurl : '/wp-admin/admin-ajax.php'); }
        function nonce()   { return (window.aiPI && aiPI.nonce) || ''; }
        let scannedPosts = [];

        $('#aipi-adj-scan-btn').on('click', scan);
        $('#aipi-adj-fix-all-btn').on('click', fixAll);

        async function scan() {
            $('#aipi-adj-scan-btn').prop('disabled', true);
            $('#aipi-adj-result').hide();
            $('#aipi-adj-tbody').empty();
            $('#aipi-adj-scan-status').text('スキャン中...');
            try {
                const res = await $.post(ajaxUrl(), {
                    action: 'ai_pi_scan_adjacent_cards',
                    nonce: nonce(),
                });
                if (!res || !res.success) {
                    alert('スキャン失敗: ' + (res && res.data ? res.data : ''));
                    return;
                }
                scannedPosts = res.data.posts || [];
                const tc = res.data.total_cards || 0;
                const tm = res.data.total_markers || 0;
                const pwb = res.data.posts_with_blocks || 0;
                $('#aipi-adj-scan-status').html(
                    `完了: <strong>${res.data.scanned}件</strong>チェック / `
                    + `カード<strong>${tc}個</strong>・マーカー<strong>${tm}個</strong>を ${pwb}件の記事で検出 / `
                    + `連続あり <strong style="color:#dc2626">${scannedPosts.length}件</strong>`
                );
                $('#aipi-adj-summary').text(`${scannedPosts.length} 件の記事で商品カード／マーカーが連続配置されています`);
                render(scannedPosts);
                if (scannedPosts.length) $('#aipi-adj-result').show();
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
                    <tr data-id="${p.id}">
                        <td>${p.id}</td>
                        <td><a href="${editUrl}" target="_blank">${esc(p.title)}</a></td>
                        <td style="text-align:center;color:#dc2626;font-weight:600">${p.adjacent_count}</td>
                        <td><code style="font-size:11px">${esc(p.designs.join(' + '))}</code></td>
                        <td>${esc(p.status)}</td>
                        <td>
                            <button type="button" class="button button-primary button-small aipi-fix-one" data-id="${p.id}">🛠 修正</button>
                            <a href="${editUrl}" target="_blank" class="button button-small">編集</a>
                        </td>
                    </tr>
                `);
            });
            tbody.find('.aipi-fix-one').on('click', function () {
                const id = $(this).data('id');
                if (!confirm(`#${id} の連続カード（2枚目以降）を削除します。\nリビジョンが自動保存されるので元に戻せます。よろしいですか？`)) return;
                fixOne(id, $(this).closest('tr'));
            });
        }

        async function fixOne(postId, row) {
            const fixBtn = row ? row.find('.aipi-fix-one') : null;
            if (fixBtn && fixBtn.length) fixBtn.prop('disabled', true).text('修正中...');
            try {
                const res = await $.post(ajaxUrl(), {
                    action: 'ai_pi_fix_adjacent_cards',
                    nonce: nonce(),
                    post_id: postId,
                });
                if (!res || !res.success) {
                    alert('修正失敗 #' + postId + ': ' + (res && res.data ? res.data : ''));
                    if (fixBtn && fixBtn.length) fixBtn.prop('disabled', false).text('🛠 修正');
                    return false;
                }
                if (row && row.length) {
                    row.css('background-color', '#dcfce7');
                    row.find('td:eq(5)').html(`<span style="color:#16a34a;font-weight:600">✓ 修正済（${res.data.removed_count}枚削除）</span>`);
                }
                return true;
            } catch (e) {
                alert('通信エラー #' + postId + ': ' + (e.responseText || e.statusText));
                if (fixBtn && fixBtn.length) fixBtn.prop('disabled', false).text('🛠 修正');
                return false;
            }
        }

        async function fixAll() {
            if (!scannedPosts.length) { alert('対象なし'); return; }
            if (!confirm(`${scannedPosts.length} 件の記事の連続カード（2枚目以降）を一括削除します。\n各記事でリビジョンが自動保存されるので元に戻せます。\n\n実行しますか？`)) return;
            $('#aipi-adj-fix-all-btn').prop('disabled', true);
            let done = 0, failed = 0;
            for (const p of scannedPosts) {
                $('#aipi-adj-fix-status').text(`修正中... ${done + failed}/${scannedPosts.length}件`);
                const row = $(`tr[data-id="${p.id}"]`);
                const ok = await fixOne(p.id, row.length ? row : null);
                if (ok) done++; else failed++;
            }
            $('#aipi-adj-fix-status').text(`完了: 成功 ${done}件 / 失敗 ${failed}件`);
            $('#aipi-adj-fix-all-btn').prop('disabled', false);
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
    $total_cards = 0;
    $total_markers = 0;
    $posts_with_blocks = 0;
    foreach ($rows as $r) {
        // 全ブロック内訳もカウント（診断用）
        $all_blocks = ai_pi_find_card_blocks($r->post_content);
        if (!empty($all_blocks)) {
            $posts_with_blocks++;
            foreach ($all_blocks as $b) {
                if (($b['type'] ?? 'card') === 'marker') $total_markers++;
                else $total_cards++;
            }
        }

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
        'scanned'           => count($rows),
        'posts'             => $found,
        'total_cards'       => $total_cards,
        'total_markers'     => $total_markers,
        'posts_with_blocks' => $posts_with_blocks,
    ]);
});

/**
 * 検出対象とする「大きいカード」種類。
 * vertical（縦置き1商品）は読者誘導用に意図的に連続させるので除外する。
 * compare（比較表）/ ranking（ランキング型）は1枚に複数商品入る大きいカードで、
 * 連続させる意図はまず無いので「連続=配置バグ」と判定する。
 *
 * proscons / mini / score は現行の生成ロジックで出力されないため除外。
 * 過去に存在した設計だが現在は使用しない（v1.7.48 で確認）。
 */
const AI_PI_HEAVY_DESIGNS = ['compare', 'ranking'];

/**
 * 商品カード（aipi-* で始まる div）と未処理マーカー（<!--ai-product:...-->）の
 * 位置を、ネスト対応で正しく検出する。
 *
 * カードHTML は <div class="aipi-compare"><div class="aipi-compare__inner">...</div></div>
 * のような入れ子構造のため、非greedy regex だと最初の </div> で切れて誤検出する。
 * 開閉タグを数えて正しい終端を見つける。
 *
 * 検出対象（両方を「ブロック」として返す）:
 *   - type='card':   挿入処理済みのカード div
 *   - type='marker': まだ挿入処理されていない <!--ai-product:design[:count]--> マーカー
 *                    （予約投稿の状態でプラグイン実行前の記事も検出対象にするため）
 *
 * Returns: [['start' => int, 'end' => int, 'design' => string, 'type' => 'card'|'marker'], ...]
 * （位置順にソート済み）
 */
function ai_pi_find_card_blocks($content) {
    $blocks = [];
    if (!$content) return $blocks;
    $max_iter = 2000; // 暴走防止

    // (1) カード div を検出（挿入処理済み記事）
    //
    // 旧版は `<div\s+class="(aipi-[a-z]+)...` で最初のクラスだけ見ていたため
    // - vertical/mini/proscons/score: 全カードが `<div class="aipi-card aipi-card--mini">`
    //   のように `aipi-card` で始まるため design='card' に潰れて検出不能
    // - 属性順違い `<div data-x=".." class="aipi-...">` も無視
    // という重大バグがあった。
    //
    // 新版: <div ...> 全部スキャン → 属性中の全 aipi-XXX クラスを抽出 →
    //   - aipi-card--vertical → vertical（mini/proscons/score は現行未使用なので無視）
    //   - aipi-compare / aipi-ranking → そのまま
    //   - その他（__子要素クラス等）は無視
    $offset = 0;
    while ($max_iter-- > 0) {
        if (!preg_match('/<div\b([^>]*)>/i', $content, $m, PREG_OFFSET_CAPTURE, $offset)) {
            break;
        }
        $tag_start = $m[0][1];
        $attrs = $m[1][0];
        $tag_end_pos = $tag_start + strlen($m[0][0]);

        // 現行で生成されるカードは vertical / compare / ranking の3種のみ。
        // proscons / mini / score は出力されないので検出対象から除外。
        $design = ai_pi_classify_card_div($attrs, false);

        if ($design === null) {
            // カード div ではない（普通の div / wp-block-image 等）→ 次へ
            $offset = $tag_end_pos;
            continue;
        }

        // この div の終わりをネスト深度カウントで探す
        $end = ai_pi_find_div_end($content, $tag_end_pos);
        $blocks[] = ['start' => $tag_start, 'end' => $end, 'design' => $design, 'type' => 'card'];
        $offset = $end;
    }

    // (2) 未処理マーカー <!--ai-product:design[:count]--> も検出
    //     予約投稿でまだプラグイン実行前の記事でも連続バグを拾うために必要。
    //     :brand サフィックスは商品深掘り構造で意図的に複数置くため検出対象から除外。
    $marker_re = '/<!--\s*ai-product:([a-z]+)(?::([a-z0-9]+))?\s*-->/i';
    if (preg_match_all($marker_re, $content, $mm, PREG_OFFSET_CAPTURE)) {
        foreach ($mm[0] as $i => $whole) {
            $design = strtolower($mm[1][$i][0]);
            $modifier = isset($mm[2][$i][0]) ? strtolower($mm[2][$i][0]) : '';
            if ($modifier === 'brand') continue; // brand 深掘りは意図配置
            $blocks[] = [
                'start'  => $whole[1],
                'end'    => $whole[1] + strlen($whole[0]),
                'design' => $design,
                'type'   => 'marker',
            ];
        }
    }

    // 位置順でソート（カードとマーカーが混在しても正しく隣接判定できるように）
    usort($blocks, function ($a, $b) {
        return $a['start'] <=> $b['start'];
    });

    return $blocks;
}

/**
 * 記事内の商品カード配置を解析して、heavy デザイン（compare/ranking等）が
 * 連続している箇所を返す。
 *
 * vertical-vertical の連続はユーザーの意図通り → 除外。
 * 「heavy → heavy」「heavy → vertical」「vertical → heavy」のいずれかに
 * 該当する連続だけを「バグ」と判定する。
 *
 * Returns:
 *   adjacent_count: int 連続発生回数
 *   designs:        string[] 連続箇所のカード種類リスト
 */
function ai_pi_find_adjacent_cards($content) {
    if (!$content) return ['adjacent_count' => 0, 'designs' => []];

    $blocks = ai_pi_find_card_blocks($content);
    if (count($blocks) < 2) return ['adjacent_count' => 0, 'designs' => []];

    $adjacent_pairs = [];
    for ($i = 0; $i < count($blocks) - 1; $i++) {
        $a = $blocks[$i]['design'];
        $b = $blocks[$i + 1]['design'];
        $a_heavy = in_array($a, AI_PI_HEAVY_DESIGNS, true);
        $b_heavy = in_array($b, AI_PI_HEAVY_DESIGNS, true);
        if (!$a_heavy && !$b_heavy) continue;

        $between = substr($content, $blocks[$i]['end'], $blocks[$i + 1]['start'] - $blocks[$i]['end']);
        if (ai_pi_is_empty_between($between)) {
            $adjacent_pairs[] = $a . ' → ' . $b;
        }
    }
    return [
        'adjacent_count' => count($adjacent_pairs),
        'designs'        => array_values(array_unique($adjacent_pairs)),
    ];
}

/**
 * AJAX: 連続カードを修正（2枚目以降を削除）
 */
add_action('wp_ajax_ai_pi_fix_adjacent_cards', function () {
    check_ajax_referer('ai_pi_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('権限がありません');
    @set_time_limit(60);

    $post_id = intval($_POST['post_id'] ?? 0);
    if (!$post_id) wp_send_json_error('post_id 不正');

    $post = get_post($post_id);
    if (!$post) wp_send_json_error('記事が見つかりません');

    $result = ai_pi_remove_adjacent_cards($post->post_content);
    if ($result['removed_count'] <= 0) {
        wp_send_json_success(['removed_count' => 0, 'message' => '連続カードなし']);
    }

    $update = wp_update_post([
        'ID'           => $post_id,
        'post_content' => $result['content'],
    ], true);
    if (is_wp_error($update)) wp_send_json_error($update->get_error_message());

    wp_send_json_success([
        'removed_count' => $result['removed_count'],
        'message'       => $result['removed_count'] . '枚削除しました',
    ]);
});

/**
 * 記事内の連続カードのうち、2枚目以降を物理削除する。
 *
 * Returns: [content => string, removed_count => int]
 */
function ai_pi_remove_adjacent_cards($content) {
    if (!$content) return ['content' => $content, 'removed_count' => 0];

    // ネスト対応のカードブロック検出
    $blocks = ai_pi_find_card_blocks($content);
    if (count($blocks) < 2) return ['content' => $content, 'removed_count' => 0];

    // 連続グループのうち「heavy デザイン（compare/ranking等）が絡む連続」だけを
    // バグとみなし、2枚目以降を削除候補にする。
    // vertical-vertical は意図通りの配置なのでスキップ（削除しない）。
    $to_remove = [];
    for ($i = 0; $i < count($blocks) - 1; $i++) {
        $a = $blocks[$i]['design'];
        $b = $blocks[$i + 1]['design'];
        $a_heavy = in_array($a, AI_PI_HEAVY_DESIGNS, true);
        $b_heavy = in_array($b, AI_PI_HEAVY_DESIGNS, true);
        if (!$a_heavy && !$b_heavy) continue; // vertical 同士は触らない

        $between = substr($content, $blocks[$i]['end'], $blocks[$i + 1]['start'] - $blocks[$i]['end']);
        if (ai_pi_is_empty_between($between)) {
            $to_remove[] = $i + 1;
        }
    }
    if (empty($to_remove)) return ['content' => $content, 'removed_count' => 0];

    // 削除する範囲を後ろから処理（位置がズレないよう）
    // 削除範囲は「ブロック開始」から「次のブロック開始の直前」までではなく、
    // ブロックそのものとその直前の空白だけにする（直前のブロックは保持）
    $to_remove_unique = array_values(array_unique($to_remove));
    rsort($to_remove_unique);
    $new_content = $content;
    foreach ($to_remove_unique as $idx) {
        $bstart = $blocks[$idx]['start'];
        $bend   = $blocks[$idx]['end'];
        $is_card = (($blocks[$idx]['type'] ?? 'card') === 'card');
        // 直前の空白行・改行を巻き取って消す
        while ($bstart > 0 && in_array(substr($new_content, $bstart - 1, 1), [" ", "\t", "\n", "\r"], true)) {
            $bstart--;
        }
        // カードは wp:html ブロックで包まれているので、その開閉コメントも一緒に削除する。
        // マーカー（生コメント）は包まれていないのでこの処理はスキップ。
        if ($is_card) {
            if (preg_match('/<!--\s*wp:html\s*-->\s*$/i', substr($new_content, 0, $bstart), $wm)) {
                $bstart -= strlen($wm[0]);
            }
        }
        // 直後の余分な改行も1つ巻き取る
        if (substr($new_content, $bend, 1) === "\n") {
            $bend++;
        }
        // 直後の <!-- /wp:html --> 巻き取り（カードのみ）
        if ($is_card) {
            if (preg_match('/^\s*<!--\s*\/wp:html\s*-->/i', substr($new_content, $bend), $wm)) {
                $bend += strlen($wm[0]);
            }
        }
        // 巻き取り後の直前空白を再度処理
        while ($bend < strlen($new_content) && in_array(substr($new_content, $bend, 1), [" ", "\t", "\n", "\r"], true)) {
            $bend++;
            break; // 1文字だけ
        }
        $new_content = substr($new_content, 0, $bstart) . substr($new_content, $bend);
    }

    return [
        'content'       => $new_content,
        'removed_count' => count($to_remove_unique),
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
