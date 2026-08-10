<?php
/**
 * カード／マーカー一括削除ページ
 *
 * このプラグインが記事に入れたもの——挿入済みの商品カード、入れそこねて
 * 残っているマーカー（<!--ai-product:…-->）、退避コメント
 * （<!-- ai-product-uninserted:… -->）——を、期間・カテゴリ・タグ・
 * ステータス・種類で絞り込んで一括削除する。
 *
 * 安全機構（この順で守る）:
 *   1. スキャン（DB 無変更）で対象記事と内訳を確認 → そのあと実行の2段階
 *   2. 実行直前の post_content を _ai_pi_del_backup に退避し、行ごとに
 *      「元に戻す」で1クリック復元できる
 *   3. 本文更新は wp_update_post 経由なので WP のリビジョンにも残る
 *   4. 退避データは日次 cron が30日で自動削除（postmeta の肥大化防止）
 *
 * 削除後の扱いは2択:
 *   - 完全削除     : カードを消して何も残さない
 *   - マーカーに戻す: カードを <!--ai-product:design:count--> に差し戻す。
 *                     あとで「一括処理」から入れ直せる状態になる。
 */

if (!defined('ABSPATH')) exit;

/**
 * 削除ツールが扱うカード／マーカーのデザイン種別。
 * mini / proscons / score は現行の生成ロジックでは出力されないが、
 * 過去に挿入されたカードが本番に残っているので削除対象には含める。
 */
const AI_PI_DEL_DESIGNS = ['vertical', 'ranking', 'compare', 'mini', 'proscons', 'score'];

/** 削除でクリアする挿入関連のメタキー（カードが1枚も残らなかった時だけ） */
const AI_PI_DEL_INSERT_META = [
    '_ai_pi_inserted', '_ai_pi_inserted_at', '_ai_pi_products', '_ai_pi_total_usage',
    '_ai_pi_status', '_ai_pi_expired', '_ai_pi_mode', '_ai_pi_design', '_ai_pi_position',
    '_ai_pi_backup', '_ai_pi_backup_at', '_ai_pi_residual_markers',
    '_ai_pi_brand_match_count', '_ai_pi_brand_mismatch_count', '_ai_pi_brand_mismatch_details',
];

/* ===========================================================================
 * 検出
 * ======================================================================== */

/**
 * 記事本文から削除対象ブロックを検出する。
 *
 * 返すブロックの type:
 *   card       … 挿入済みの商品カード div
 *   marker     … 未処理マーカー <!--ai-product…-->
 *                （Gutenberg にエスケープされた &lt;!--ai-product…--&gt; も含む。
 *                  こちらは読者に文字として見えているので優先度が高い）
 *   uninserted … 挿入に失敗して退避された <!-- ai-product-uninserted:… -->
 *
 * @param string $content
 * @param array  $types   ['card','marker','uninserted'] のうち検出したいもの
 * @param array  $designs 空 = すべて。指定した場合、デザインが特定できない
 *                        マーカー（<!--ai-product--> のような素のもの）は対象外。
 * @return array [['start'=>int,'end'=>int,'type'=>string,'design'=>string,'html'=>string], …]
 *               位置順・重複なし
 */
function ai_pi_del_find_blocks($content, $types = ['card', 'marker', 'uninserted'], $designs = []) {
    $blocks = [];
    if (!$content) return $blocks;

    $want = function ($type) use ($types) {
        return in_array($type, $types, true);
    };
    // 種類を指定した場合、デザイン不明（default）は巻き添えにしない。
    $design_ok = function ($design) use ($designs) {
        if (empty($designs)) return true;
        if ($design === 'default') return false;
        return in_array($design, $designs, true);
    };

    // --- (1) 挿入済みカード div ---
    if ($want('card')) {
        $offset = 0;
        $guard  = 2000;
        while ($guard-- > 0) {
            if (!preg_match('/<div\b([^>]*)>/i', $content, $m, PREG_OFFSET_CAPTURE, $offset)) break;
            $tag_start   = $m[0][1];
            $tag_end_pos = $tag_start + strlen($m[0][0]);

            $design = ai_pi_classify_card_div($m[1][0], true);
            if ($design === null) {
                $offset = $tag_end_pos;
                continue;
            }

            $end = ai_pi_find_div_end($content, $tag_end_pos);
            if ($design_ok($design)) {
                $blocks[] = [
                    'start'  => $tag_start,
                    'end'    => $end,
                    'type'   => 'card',
                    'design' => $design,
                    'html'   => substr($content, $tag_start, $end - $tag_start),
                ];
            }
            $offset = $end;
        }
    }

    // --- (2) 退避コメント <!-- ai-product-uninserted:design[:mod] --> ---
    if ($want('uninserted')) {
        if (preg_match_all('/<!--\s*ai-product-uninserted:([a-z0-9:_-]*)\s*-->/i', $content, $mm, PREG_OFFSET_CAPTURE)) {
            foreach ($mm[0] as $i => $whole) {
                $tag    = strtolower($mm[1][$i][0]);
                $design = $tag !== '' ? strtok($tag, ':') : 'default';
                if (!in_array($design, AI_PI_DEL_DESIGNS, true)) $design = 'default';
                if (!$design_ok($design)) continue;
                $blocks[] = [
                    'start'  => $whole[1],
                    'end'    => $whole[1] + strlen($whole[0]),
                    'type'   => 'uninserted',
                    'design' => $design,
                ];
            }
        }
    }

    // --- (3) 未処理マーカー ---
    if ($want('marker')) {
        // 3-a) Gutenberg にエスケープされたマーカー。
        //      「カスタムHTML」以外のブロックに書かれると &lt;!--ai-product…--&gt; として
        //      保存され、読者にはただの文字列として表示されてしまう。
        //      包んでいるブロック（wp:code / wp:preformatted / 段落）ごと1ブロックとして扱う。
        $escaped_patterns = [
            '/<!--\s*wp:code\s*-->\s*<pre[^>]*>\s*<code[^>]*>\s*&lt;!--\s*ai-product([\s\S]*?)--&gt;\s*<\/code>\s*<\/pre>\s*<!--\s*\/wp:code\s*-->/i',
            '/<!--\s*wp:preformatted\s*-->\s*<pre[^>]*>\s*&lt;!--\s*ai-product([\s\S]*?)--&gt;\s*<\/pre>\s*<!--\s*\/wp:preformatted\s*-->/i',
            '/<!--\s*wp:paragraph\s*-->\s*<p[^>]*>\s*&lt;!--\s*ai-product([\s\S]*?)--&gt;\s*<\/p>\s*<!--\s*\/wp:paragraph\s*-->/i',
            '/<pre[^>]*>\s*<code[^>]*>\s*&lt;!--\s*ai-product([\s\S]*?)--&gt;\s*<\/code>\s*<\/pre>/i',
            '/<p[^>]*>\s*&lt;!--\s*ai-product([\s\S]*?)--&gt;\s*<\/p>/i',
            '/&lt;!--\s*ai-product([\s\S]*?)--&gt;/i',
        ];
        foreach ($escaped_patterns as $re) {
            if (!preg_match_all($re, $content, $mm, PREG_OFFSET_CAPTURE)) continue;
            foreach ($mm[0] as $i => $whole) {
                $design = ai_pi_del_design_from_suffix($mm[1][$i][0]);
                if (!$design_ok($design)) continue;
                $blocks[] = [
                    'start'  => $whole[1],
                    'end'    => $whole[1] + strlen($whole[0]),
                    'type'   => 'marker',
                    'design' => $design,
                ];
            }
        }

        // 3-b) 通常のマーカー。素の <!--ai-product--> も :brand 付きも対象。
        $marker_re = '/<!--\s*ai-product(?::([a-z]+)(?::([a-z0-9]+))?)?\s*-->/i';
        if (preg_match_all($marker_re, $content, $mm, PREG_OFFSET_CAPTURE)) {
            foreach ($mm[0] as $i => $whole) {
                $design = !empty($mm[1][$i][0]) ? strtolower($mm[1][$i][0]) : 'default';
                if (!in_array($design, AI_PI_DEL_DESIGNS, true)) $design = 'default';
                if (!$design_ok($design)) continue;
                $blocks[] = [
                    'start'  => $whole[1],
                    'end'    => $whole[1] + strlen($whole[0]),
                    'type'   => 'marker',
                    'design' => $design,
                ];
            }
        }
    }

    if (count($blocks) < 2) return $blocks;

    // 位置順（同じ開始位置なら外側＝長い方を優先）に並べ、重なりを捨てる。
    // 「wp:code ブロックごと」と「その中のエスケープ済みマーカー」のように
    // 内包関係が出るため、外側だけを残す。
    usort($blocks, function ($a, $b) {
        if ($a['start'] !== $b['start']) return $a['start'] <=> $b['start'];
        return $b['end'] <=> $a['end'];
    });

    $accepted = [];
    $cursor   = -1;
    foreach ($blocks as $b) {
        if ($b['start'] < $cursor) continue;
        $accepted[] = $b;
        $cursor     = $b['end'];
    }
    return $accepted;
}

/**
 * マーカーの ":design:modifier" 部分からデザイン名を取り出す。
 * 判定できなければ 'default'。
 */
function ai_pi_del_design_from_suffix($suffix) {
    if (preg_match('/^:([a-z]+)/i', trim($suffix), $m)) {
        $design = strtolower($m[1]);
        if (in_array($design, AI_PI_DEL_DESIGNS, true)) return $design;
    }
    return 'default';
}

/* ===========================================================================
 * 削除の実行（純粋関数：DB を触らない）
 * ======================================================================== */

/**
 * 本文から対象ブロックを削除した結果を返す。
 *
 * @param string $content
 * @param array  $opts ['types'=>[], 'designs'=>[], 'restore'=>bool]
 * @return array ['content'=>string, 'removed'=>['card'=>int,'marker'=>int,'uninserted'=>int], 'designs'=>string[]]
 */
function ai_pi_del_apply($content, $opts) {
    $types   = $opts['types']   ?? ['card'];
    $designs = $opts['designs'] ?? [];
    $restore = !empty($opts['restore']);

    $removed       = ['card' => 0, 'marker' => 0, 'uninserted' => 0];
    $designs_found = [];

    $blocks = ai_pi_del_find_blocks($content, $types, $designs);
    if (empty($blocks)) {
        return ['content' => $content, 'removed' => $removed, 'designs' => []];
    }

    // 後ろから消す（前を消すと後ろのオフセットがずれるため）
    $new = $content;

    // 直前の空白・改行を巻き取るヘルパー。
    // 前の段落との間隔（\n\n）は「後ろ側」に残っているものを使うので、
    // 前側は全部食べてしまってよい。
    $eat_space_before = function ($pos) use (&$new) {
        while ($pos > 0 && in_array(substr($new, $pos - 1, 1), [' ', "\t", "\n", "\r"], true)) {
            $pos--;
        }
        return $pos;
    };

    for ($i = count($blocks) - 1; $i >= 0; $i--) {
        $b     = $blocks[$i];
        $start = $eat_space_before($b['start']);
        $end   = $b['end'];

        // 包んでいる <!-- wp:html --> … <!-- /wp:html --> があれば一緒に消す。
        // 実際に接している時だけ巻き取るので、包まれていないマーカーでも安全。
        if (preg_match('/<!--\s*wp:html\s*-->\s*$/i', substr($new, 0, $start), $wm)) {
            $start = $eat_space_before($start - strlen($wm[0]));
        }
        if (preg_match('/^\s*<!--\s*\/wp:html\s*-->/i', substr($new, $end, 64), $wm)) {
            $end += strlen($wm[0]);
        }

        // カードを「マーカーに戻す」場合だけ、消した場所にマーカーを置く
        $replacement = ($b['type'] === 'card' && $restore)
            ? "\n\n" . ai_pi_del_marker_for($b)
            : '';

        $new = substr($new, 0, $start) . $replacement . substr($new, $end);

        $removed[$b['type']]++;
        $designs_found[] = $b['design'];
    }

    return [
        'content' => $new,
        'removed' => $removed,
        'designs' => array_values(array_unique($designs_found)),
    ];
}

/**
 * カードHTMLから、入れ直し用のマーカー（wp:html ブロック入り）を組み立てる。
 * ranking / compare は中に入っている商品数を数えて :N を復元する。
 */
function ai_pi_del_marker_for($block) {
    $design = $block['design'];
    $html   = $block['html'] ?? '';
    $count  = 0;

    if ($design === 'ranking') {
        $count = substr_count($html, 'aipi-rank-row');
    } elseif ($design === 'compare') {
        // thead の見出し行が1つあるので差し引く
        $count = max(0, preg_match_all('/<tr\b/i', $html) - 1);
    }

    $marker = ($count > 1)
        ? "<!--ai-product:{$design}:{$count}-->"
        : "<!--ai-product:{$design}-->";

    return "<!-- wp:html -->\n{$marker}\n<!-- /wp:html -->";
}

/* ===========================================================================
 * DB 操作
 * ======================================================================== */

/**
 * 1記事に削除を適用する。バックアップを取ってから本文を更新する。
 *
 * @return array|WP_Error
 */
function ai_pi_del_apply_to_post($post_id, $opts) {
    $post = get_post($post_id);
    if (!$post) return new WP_Error('not_found', '記事が見つかりません');

    $res   = ai_pi_del_apply($post->post_content, $opts);
    $total = array_sum($res['removed']);
    if ($total <= 0) {
        return ['removed' => $res['removed'], 'total' => 0, 'skipped' => true];
    }

    // ── 消す前に必ず退避する ──
    update_post_meta($post_id, '_ai_pi_del_backup', $post->post_content);
    update_post_meta($post_id, '_ai_pi_del_backup_at', current_time('mysql'));

    $upd = wp_update_post([
        'ID'           => $post_id,
        'post_content' => $res['content'],
    ], true);
    if (is_wp_error($upd)) return $upd;

    ai_pi_del_cleanup_meta($post_id, $res);

    return ['removed' => $res['removed'], 'total' => $total, 'skipped' => false];
}

/**
 * 削除の結果に合わせて挿入関連メタを整理する。
 *
 * カードが1枚も残らなかった記事は「未挿入」に戻す。
 * ここを掃除しないと、カードが無いのに _ai_pi_inserted が立ったままになり、
 * 再挿入時に「バックアップ（＝古いマーカー入り本文）」が source として
 * 使われてしまい、消したはずのカードが復活する。
 */
function ai_pi_del_cleanup_meta($post_id, $res) {
    if ($res['removed']['card'] > 0) {
        $remaining = ai_pi_del_find_blocks($res['content'], ['card'], []);
        if (empty($remaining)) {
            foreach (AI_PI_DEL_INSERT_META as $key) {
                delete_post_meta($post_id, $key);
            }
        }
    }
    if ($res['removed']['uninserted'] > 0) {
        delete_post_meta($post_id, '_ai_pi_residual_markers');
    }
    delete_transient('ai_pi_residual_count_publish');
}

/**
 * 削除前バックアップを古いものから掃除する（日次 cron から呼ぶ）。
 * post_content 丸ごとを postmeta に持つので放置すると DB が膨らむ。
 *
 * @param int $days この日数より古いバックアップを削除
 * @return int 削除件数
 */
function ai_pi_del_prune_backups($days = 30) {
    global $wpdb;
    $threshold = date('Y-m-d H:i:s', current_time('timestamp') - ($days * DAY_IN_SECONDS));

    $ids = $wpdb->get_col($wpdb->prepare(
        "SELECT post_id FROM {$wpdb->postmeta}
         WHERE meta_key = '_ai_pi_del_backup_at' AND meta_value < %s",
        $threshold
    ));

    foreach ($ids as $pid) {
        delete_post_meta($pid, '_ai_pi_del_backup');
        delete_post_meta($pid, '_ai_pi_del_backup_at');
    }
    return count($ids);
}

/* ===========================================================================
 * AJAX
 * ======================================================================== */

/** POST から削除条件を組み立てる（サニタイズ込み） */
function ai_pi_del_opts_from_post() {
    $types = array_values(array_intersect(
        array_map('sanitize_text_field', (array) ($_POST['types'] ?? [])),
        ['card', 'marker', 'uninserted']
    ));
    if (empty($types)) $types = ['card'];

    $designs = array_values(array_intersect(
        array_map('sanitize_text_field', (array) ($_POST['designs'] ?? [])),
        AI_PI_DEL_DESIGNS
    ));

    return [
        'types'   => $types,
        'designs' => $designs,
        'restore' => (($_POST['restore'] ?? '') === '1'),
    ];
}

/** Y-m-d 形式だけ通す */
function ai_pi_del_clean_date($value) {
    $value = sanitize_text_field($value);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : '';
}

/**
 * AJAX: スキャン（DB は一切変更しない）
 */
add_action('wp_ajax_ai_pi_del_scan', function () {
    check_ajax_referer('ai_pi_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('権限がありません');
    @set_time_limit(300);

    $opts = ai_pi_del_opts_from_post();

    $statuses = array_values(array_intersect(
        array_map('sanitize_text_field', (array) ($_POST['statuses'] ?? [])),
        ['publish', 'future', 'draft', 'pending', 'private']
    ));
    if (empty($statuses)) $statuses = ['publish', 'future', 'draft', 'pending', 'private'];

    $cats = array_values(array_filter(array_map('intval', (array) ($_POST['categories'] ?? []))));
    $tags = array_values(array_filter(array_map('intval', (array) ($_POST['tags'] ?? []))));

    $date_basis = sanitize_text_field($_POST['date_basis'] ?? 'post_date');
    if (!in_array($date_basis, ['post_date', 'inserted_at'], true)) $date_basis = 'post_date';
    $date_from = ai_pi_del_clean_date($_POST['date_from'] ?? '');
    $date_to   = ai_pi_del_clean_date($_POST['date_to'] ?? '');

    global $wpdb;
    $status_in = "'" . implode("','", array_map('esc_sql', $statuses)) . "'";

    // カード（aipi-）かマーカー系（ai-product）を含む記事だけを SQL で先に絞る。
    // 全記事を PHP で走査すると数千件規模でタイムアウトするため。
    $where  = "post_type = 'post' AND post_status IN ({$status_in})"
            . " AND (post_content LIKE %s OR post_content LIKE %s)";
    $params = ['%aipi-%', '%ai-product%'];

    if ($date_basis === 'post_date') {
        if ($date_from !== '') { $where .= ' AND post_date >= %s'; $params[] = $date_from . ' 00:00:00'; }
        if ($date_to   !== '') { $where .= ' AND post_date <= %s'; $params[] = $date_to   . ' 23:59:59'; }
    }

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT ID, post_title, post_status, post_date, post_content
         FROM {$wpdb->posts}
         WHERE {$where}
         ORDER BY post_date DESC",
        $params
    ));

    $found   = [];
    $scanned = 0;
    $totals  = ['card' => 0, 'marker' => 0, 'uninserted' => 0];

    foreach ($rows as $r) {
        $scanned++;

        if (!empty($cats)) {
            if (!array_intersect($cats, wp_get_post_categories($r->ID))) continue;
        }
        if (!empty($tags)) {
            if (!array_intersect($tags, wp_get_post_tags($r->ID, ['fields' => 'ids']))) continue;
        }

        // 挿入日ベースの期間指定は post_date では絞れないのでここで判定
        if ($date_basis === 'inserted_at' && ($date_from !== '' || $date_to !== '')) {
            $inserted_at = get_post_meta($r->ID, '_ai_pi_inserted_at', true);
            if (!$inserted_at) continue;
            $day = substr($inserted_at, 0, 10);
            if ($date_from !== '' && $day < $date_from) continue;
            if ($date_to   !== '' && $day > $date_to)   continue;
        }

        $blocks = ai_pi_del_find_blocks($r->post_content, $opts['types'], $opts['designs']);
        if (empty($blocks)) continue;

        $counts  = ['card' => 0, 'marker' => 0, 'uninserted' => 0];
        $designs = [];
        foreach ($blocks as $b) {
            $counts[$b['type']]++;
            $designs[] = $b['design'];
        }
        foreach ($counts as $k => $v) $totals[$k] += $v;

        $found[] = [
            'id'      => (int) $r->ID,
            'title'   => $r->post_title,
            'status'  => $r->post_status,
            'date'    => substr($r->post_date, 0, 10),
            'counts'  => $counts,
            'total'   => array_sum($counts),
            'designs' => array_values(array_unique($designs)),
        ];
    }

    wp_send_json_success([
        'scanned' => $scanned,
        'posts'   => $found,
        'totals'  => $totals,
    ]);
});

/**
 * AJAX: 削除実行（最大20件ずつ）
 */
add_action('wp_ajax_ai_pi_del_run', function () {
    check_ajax_referer('ai_pi_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('権限がありません');
    @set_time_limit(300);

    $opts     = ai_pi_del_opts_from_post();
    $post_ids = array_slice(
        array_values(array_filter(array_map('intval', (array) ($_POST['post_ids'] ?? [])))),
        0, 20
    );
    if (empty($post_ids)) wp_send_json_error('対象記事が指定されていません');

    $results = [];
    foreach ($post_ids as $pid) {
        $res = ai_pi_del_apply_to_post($pid, $opts);
        if (is_wp_error($res)) {
            $results[] = ['post_id' => $pid, 'ok' => false, 'message' => $res->get_error_message()];
            continue;
        }
        $results[] = [
            'post_id' => $pid,
            'ok'      => true,
            'total'   => $res['total'],
            'removed' => $res['removed'],
            'message' => $res['skipped'] ? '対象なし（スキップ）' : '',
        ];
    }

    wp_send_json_success(['results' => $results]);
});

/**
 * AJAX: 直前の削除を元に戻す
 */
add_action('wp_ajax_ai_pi_del_undo', function () {
    check_ajax_referer('ai_pi_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('権限がありません');

    $post_id = intval($_POST['post_id'] ?? 0);
    if (!$post_id) wp_send_json_error('post_id 不正');

    $backup = get_post_meta($post_id, '_ai_pi_del_backup', true);
    if (empty($backup)) wp_send_json_error('この記事のバックアップは残っていません');

    $upd = wp_update_post(['ID' => $post_id, 'post_content' => $backup], true);
    if (is_wp_error($upd)) wp_send_json_error($upd->get_error_message());

    // カードが戻ったなら「挿入済み」フラグも立て直す（削除時に消しているため）
    if (!empty(ai_pi_del_find_blocks($backup, ['card'], []))) {
        update_post_meta($post_id, '_ai_pi_inserted', 1);
    }

    delete_post_meta($post_id, '_ai_pi_del_backup');
    delete_post_meta($post_id, '_ai_pi_del_backup_at');
    delete_transient('ai_pi_residual_count_publish');

    wp_send_json_success(['restored' => true]);
});

/* ===========================================================================
 * 画面
 * ======================================================================== */

function ai_pi_render_bulk_delete_page() {
    if (!current_user_can('manage_options')) return;

    $categories = get_categories(['hide_empty' => false, 'orderby' => 'name']);
    $tags       = get_tags(['hide_empty' => false, 'orderby' => 'name']);

    $design_labels = [
        'vertical' => '縦置き（vertical）',
        'ranking'  => 'ランキング（ranking）',
        'compare'  => '比較表（compare）',
        'mini'     => '旧・ミニ（mini）',
        'proscons' => '旧・良い点悪い点（proscons）',
        'score'    => '旧・スコア（score）',
    ];
    ?>
    <div class="wrap">
        <h1>🗑 カード／マーカー一括削除</h1>
        <p style="font-size:13px;line-height:1.7">
            このプラグインが記事に入れた<strong>商品カード</strong>と、入れそこねて残っている
            <strong>マーカー</strong>を、期間・カテゴリ・タグ・ステータスで絞り込んで一括削除します。<br>
            <strong>スキャンでは何も変更しません。</strong>内訳を確認してから実行してください。
        </p>

        <div style="background:#fef2f2;border:1px solid #fca5a5;padding:12px;margin:16px 0;border-radius:4px;font-size:13px;line-height:1.7">
            <strong>⚠️ 実行前に必ず読む</strong>
            <ul style="margin:6px 0 0 20px">
                <li>本文を書き換えます。<strong>削除前の本文は記事ごとに退避</strong>され、一覧の「元に戻す」で1クリック復元できます（退避は30日で自動削除）</li>
                <li>リビジョンも自動保存されるので、編集画面の「リビジョン」からも戻せます</li>
                <li>公開済み記事を更新すると「最終更新日」が今日に変わる場合があります（テーマ依存。<code>post_date</code> は触りません）</li>
                <li>カードを削除すると挿入済みフラグも解除され、その記事は「未挿入」に戻ります</li>
            </ul>
        </div>

        <div class="card" style="padding:20px;margin:20px 0;max-width:1000px">
            <h2 style="margin-top:0">1. 何を削除するか</h2>

            <table class="form-table">
                <tr>
                    <th>削除対象</th>
                    <td>
                        <label style="display:block;margin-bottom:6px">
                            <input type="checkbox" class="del-type" value="card" checked>
                            <strong>挿入済みの商品カード</strong>
                            <span style="color:#666">（記事に表示されている商品カード本体）</span>
                        </label>
                        <label style="display:block;margin-bottom:6px">
                            <input type="checkbox" class="del-type" value="marker">
                            <strong>未処理マーカー</strong>
                            <span style="color:#666">（<code>&lt;!--ai-product:…--&gt;</code>／エスケープされて文字として見えているものも含む）</span>
                        </label>
                        <label style="display:block">
                            <input type="checkbox" class="del-type" value="uninserted">
                            <strong>退避コメント</strong>
                            <span style="color:#666">（挿入に失敗して <code>&lt;!-- ai-product-uninserted:… --&gt;</code> になったもの）</span>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th>種類（未選択＝すべて）</th>
                    <td>
                        <?php foreach ($design_labels as $key => $label): ?>
                            <label style="display:inline-block;margin:2px 14px 2px 0">
                                <input type="checkbox" class="del-design" value="<?php echo esc_attr($key); ?>">
                                <?php echo esc_html($label); ?>
                            </label>
                        <?php endforeach; ?>
                        <p class="description">
                            種類を1つ以上選ぶと、その種類だけを削除します。
                            デザイン指定のない素のマーカー（<code>&lt;!--ai-product--&gt;</code>）は
                            <strong>未選択のときだけ</strong>対象になります。
                        </p>
                    </td>
                </tr>
                <tr>
                    <th>削除後の扱い</th>
                    <td>
                        <label style="display:block;margin-bottom:6px">
                            <input type="radio" name="del_restore" value="0" checked>
                            <strong>完全に削除する</strong>
                            <span style="color:#666">（カードもマーカーも残さない）</span>
                        </label>
                        <label style="display:block">
                            <input type="radio" name="del_restore" value="1">
                            <strong>カードをマーカーに戻す</strong>
                            <span style="color:#666">（<code>&lt;!--ai-product:ranking:5--&gt;</code> の形に差し戻し、あとで入れ直せる状態にする）</span>
                        </label>
                    </td>
                </tr>
            </table>

            <h2>2. どの記事を対象にするか</h2>

            <table class="form-table">
                <tr>
                    <th>期間</th>
                    <td>
                        <select id="del-date-basis">
                            <option value="post_date">投稿日</option>
                            <option value="inserted_at">商品挿入日</option>
                        </select>
                        <input type="date" id="del-date-from" style="margin-left:8px">
                        〜
                        <input type="date" id="del-date-to">
                        <p class="description">両方空なら全期間。片方だけの指定も可。</p>
                    </td>
                </tr>
                <tr>
                    <th>ステータス</th>
                    <td>
                        <?php foreach (['publish'=>'公開済み','future'=>'予約投稿','draft'=>'下書き','pending'=>'レビュー待ち','private'=>'非公開'] as $st => $label): ?>
                            <label style="margin-right:14px">
                                <input type="checkbox" class="del-status" value="<?php echo esc_attr($st); ?>" checked>
                                <?php echo esc_html($label); ?>
                            </label>
                        <?php endforeach; ?>
                    </td>
                </tr>
                <tr>
                    <th>カテゴリ（未選択＝全件）</th>
                    <td>
                        <div style="max-height:170px;overflow-y:auto;border:1px solid #ddd;padding:10px;background:#fafafa">
                            <?php if (empty($categories)): ?>
                                <em style="color:#888">カテゴリがありません</em>
                            <?php else: foreach ($categories as $cat): ?>
                                <label style="display:inline-block;margin:2px 12px 2px 0">
                                    <input type="checkbox" class="del-cat" value="<?php echo esc_attr($cat->term_id); ?>">
                                    <?php echo esc_html($cat->name); ?>
                                    <span style="color:#888">(<?php echo esc_html($cat->count); ?>)</span>
                                </label>
                            <?php endforeach; endif; ?>
                        </div>
                    </td>
                </tr>
                <tr>
                    <th>タグ（未選択＝全件）</th>
                    <td>
                        <div style="max-height:140px;overflow-y:auto;border:1px solid #ddd;padding:10px;background:#fafafa">
                            <?php if (empty($tags)): ?>
                                <em style="color:#888">タグがありません</em>
                            <?php else: foreach ($tags as $tag): ?>
                                <label style="display:inline-block;margin:2px 12px 2px 0">
                                    <input type="checkbox" class="del-tag" value="<?php echo esc_attr($tag->term_id); ?>">
                                    <?php echo esc_html($tag->name); ?>
                                    <span style="color:#888">(<?php echo esc_html($tag->count); ?>)</span>
                                </label>
                            <?php endforeach; endif; ?>
                        </div>
                    </td>
                </tr>
            </table>

            <p style="margin-top:16px">
                <button type="button" id="del-scan-btn" class="button button-primary button-large">🔍 スキャン（変更しません）</button>
                <span id="del-scan-status" style="margin-left:12px;color:#666;font-size:13px"></span>
            </p>
        </div>

        <div id="del-result" style="display:none;background:#f8fafc;border:1px solid #94a3b8;padding:16px;border-radius:6px;max-width:1100px">
            <h3 style="margin-top:0">スキャン結果</h3>
            <p id="del-summary" style="font-size:14px"></p>

            <div style="max-height:460px;overflow-y:auto;border:1px solid #ddd;background:#fff">
                <table class="wp-list-table widefat striped">
                    <thead>
                        <tr style="position:sticky;top:0;background:#f0f0f1;z-index:1">
                            <th style="width:60px">ID</th>
                            <th>タイトル</th>
                            <th style="width:100px">投稿日</th>
                            <th style="width:80px">状態</th>
                            <th style="width:190px">内訳</th>
                            <th style="width:150px">結果</th>
                        </tr>
                    </thead>
                    <tbody id="del-tbody"></tbody>
                </table>
            </div>

            <p style="margin:16px 0 0">
                <button type="button" id="del-run-btn" class="button button-primary button-large" style="background:#dc2626;border-color:#b91c1c">🗑 全件 削除を実行</button>
                <button type="button" id="del-stop-btn" class="button" style="display:none">⏹ 中断</button>
                <span id="del-run-status" style="margin-left:12px;font-size:13px"></span>
            </p>
        </div>
    </div>

    <script>
    (function ($) {
        // aiPI はフッター出力なので、呼び出し時に都度 window から読む
        function ajaxUrl() { return (window.aiPI && aiPI.ajaxUrl) || (typeof ajaxurl !== 'undefined' ? ajaxurl : '/wp-admin/admin-ajax.php'); }
        function nonce()   { return (window.aiPI && aiPI.nonce) || ''; }

        const TYPE_LABEL = { card: 'カード', marker: 'マーカー', uninserted: '退避コメント' };
        let scanned = [];
        let stopped = false;

        function conditions() {
            return {
                types:      $('.del-type:checked').map((_, el) => el.value).get(),
                designs:    $('.del-design:checked').map((_, el) => el.value).get(),
                restore:    $('input[name="del_restore"]:checked').val(),
                statuses:   $('.del-status:checked').map((_, el) => el.value).get(),
                categories: $('.del-cat:checked').map((_, el) => el.value).get(),
                tags:       $('.del-tag:checked').map((_, el) => el.value).get(),
                date_basis: $('#del-date-basis').val(),
                date_from:  $('#del-date-from').val(),
                date_to:    $('#del-date-to').val(),
            };
        }

        $('#del-scan-btn').on('click', scan);
        $('#del-run-btn').on('click', runAll);
        $('#del-stop-btn').on('click', function () { stopped = true; $(this).prop('disabled', true).text('中断中...'); });

        async function scan() {
            const cond = conditions();
            if (!cond.types.length)    { alert('削除対象を1つ以上選んでください'); return; }
            if (!cond.statuses.length) { alert('ステータスを1つ以上選んでください'); return; }

            $('#del-scan-btn').prop('disabled', true).text('スキャン中...');
            $('#del-result').hide();
            $('#del-scan-status').text('');
            try {
                const res = await $.post(ajaxUrl(), $.extend({ action: 'ai_pi_del_scan', nonce: nonce() }, cond));
                if (!res || !res.success) { alert('スキャン失敗: ' + ((res && res.data) || '')); return; }

                scanned = res.data.posts || [];
                const t = res.data.totals || {};
                const parts = Object.keys(TYPE_LABEL)
                    .filter(k => (t[k] || 0) > 0)
                    .map(k => `${TYPE_LABEL[k]} <strong>${t[k]}</strong> 個`);

                $('#del-scan-status').html(
                    `完了: <strong>${res.data.scanned}件</strong>チェック / 該当 <strong style="color:#dc2626">${scanned.length}件</strong>`
                );
                $('#del-summary').html(
                    scanned.length
                        ? `<strong>${scanned.length}</strong> 件の記事から ${parts.join(' ／ ') || '0 個'} を削除します。`
                        : '該当する記事はありませんでした。'
                );

                const tbody = $('#del-tbody').empty();
                scanned.forEach(p => {
                    const editUrl = `${location.origin}/wp-admin/post.php?post=${p.id}&action=edit`;
                    const breakdown = Object.keys(TYPE_LABEL)
                        .filter(k => (p.counts[k] || 0) > 0)
                        .map(k => `${TYPE_LABEL[k]}×${p.counts[k]}`)
                        .join(' / ');
                    tbody.append(`
                        <tr data-id="${p.id}">
                            <td>${p.id}</td>
                            <td><a href="${editUrl}" target="_blank">${esc(p.title)}</a><br>
                                <code style="font-size:11px;color:#666">${esc(p.designs.join(', '))}</code></td>
                            <td style="font-size:12px">${esc(p.date)}</td>
                            <td><code style="font-size:11px">${esc(p.status)}</code></td>
                            <td style="font-size:12px;color:#dc2626;font-weight:600">${esc(breakdown)}</td>
                            <td class="del-cell">—</td>
                        </tr>
                    `);
                });
                $('#del-result').show();
                $('#del-run-btn').prop('disabled', scanned.length === 0);
            } catch (e) {
                alert('通信エラー: ' + (e.responseText || e.statusText));
            } finally {
                $('#del-scan-btn').prop('disabled', false).text('🔍 スキャン（変更しません）');
            }
        }

        async function runAll() {
            if (!scanned.length) { alert('対象なし'); return; }
            const cond = conditions();
            const modeText = cond.restore === '1'
                ? 'カードはマーカー（<!--ai-product:…-->）に戻します。'
                : '完全に削除します（マーカーも残しません）。';
            const total = scanned.reduce((s, p) => s + (p.total || 0), 0);

            if (!confirm(
                `${scanned.length} 件の記事から 合計 ${total} 個を削除します。\n`
                + `${modeText}\n\n`
                + `削除前の本文は記事ごとに退避され、一覧の「元に戻す」で復元できます。\n\n`
                + `実行しますか？`
            )) return;

            stopped = false;
            $('#del-run-btn').prop('disabled', true);
            $('#del-stop-btn').show().prop('disabled', false).text('⏹ 中断');

            const ids = scanned.map(p => p.id);
            const CHUNK = 10;
            let done = 0, failed = 0;

            for (let i = 0; i < ids.length; i += CHUNK) {
                if (stopped) break;
                const chunk = ids.slice(i, i + CHUNK);
                $('#del-run-status').text(`削除中... ${done + failed}/${ids.length}件`);
                try {
                    const res = await $.post(ajaxUrl(), $.extend({
                        action: 'ai_pi_del_run',
                        nonce: nonce(),
                        post_ids: chunk,
                    }, cond));
                    if (!res || !res.success) {
                        failed += chunk.length;
                        chunk.forEach(id => markRow(id, false, (res && res.data) || '失敗'));
                        continue;
                    }
                    (res.data.results || []).forEach(r => {
                        if (r.ok) { done++; markRow(r.post_id, true, r.message || `${r.total}個削除`, r.total > 0); }
                        else      { failed++; markRow(r.post_id, false, r.message || '失敗'); }
                    });
                } catch (e) {
                    failed += chunk.length;
                    chunk.forEach(id => markRow(id, false, '通信エラー'));
                }
            }

            $('#del-stop-btn').hide();
            $('#del-run-btn').prop('disabled', false);
            $('#del-run-status').html(
                `<span style="color:${failed ? '#b91c1c' : '#16a34a'};font-weight:600">`
                + `${stopped ? '中断しました。' : '完了。'} 成功 ${done}件 / 失敗 ${failed}件</span>`
            );
        }

        // undoable = 実際に削除してバックアップが取られた行だけ「元に戻す」を出す
        function markRow(postId, ok, message, undoable) {
            const row = $(`tr[data-id="${postId}"]`);
            if (!row.length) return;
            row.css('background-color', ok ? '#dcfce7' : '#fee2e2');
            const cell = row.find('.del-cell').empty();
            cell.append(`<span style="color:${ok ? '#16a34a' : '#b91c1c'};font-weight:600;font-size:12px">${esc(message)}</span>`);
            if (ok && undoable) {
                cell.append(`<br><button type="button" class="button button-small del-undo" data-id="${postId}" style="margin-top:4px">↩ 元に戻す</button>`);
            }
        }

        $('#del-tbody').on('click', '.del-undo', async function () {
            const btn = $(this);
            const id  = btn.data('id');
            btn.prop('disabled', true).text('復元中...');
            try {
                const res = await $.post(ajaxUrl(), { action: 'ai_pi_del_undo', nonce: nonce(), post_id: id });
                if (!res || !res.success) {
                    alert('復元失敗 #' + id + ': ' + ((res && res.data) || ''));
                    btn.prop('disabled', false).text('↩ 元に戻す');
                    return;
                }
                const row = $(`tr[data-id="${id}"]`);
                row.css('background-color', '#fefce8');
                row.find('.del-cell').html('<span style="color:#a16207;font-weight:600;font-size:12px">↩ 復元済み</span>');
            } catch (e) {
                alert('通信エラー #' + id + ': ' + (e.responseText || e.statusText));
                btn.prop('disabled', false).text('↩ 元に戻す');
            }
        });

        function esc(s) {
            return String(s == null ? '' : s).replace(/[<>&"]/g, c => ({'<':'&lt;','>':'&gt;','&':'&amp;','"':'&quot;'}[c]));
        }
    })(jQuery);
    </script>
    <?php
}
