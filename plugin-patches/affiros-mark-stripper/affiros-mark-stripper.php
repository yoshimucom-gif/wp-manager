<?php
/**
 * Plugin Name: Affiros 黄色マーカー削除
 * Description: 過去に AI 装飾で挿入された <mark>...</mark> 黄色マーカーを WP投稿から一括削除するツール。中身は残してタグだけ剥がす。publish/future/draft/private が対象。
 * Version: 1.0.0
 * Author: Affiros
 * License: GPL v2 or later
 * Text Domain: affiros-mark-stripper
 */

if (!defined('ABSPATH')) exit;

define('AFFIROS_MARK_STRIPPER_VERSION', '1.0.0');

require_once plugin_dir_path(__FILE__) . 'includes/plugin-updater.php';

add_action('init', function () {
    $host = defined('AFFIROS_UPDATE_HOST') ? AFFIROS_UPDATE_HOST : 'https://wp-manager.onrender.com';
    new Affiros_Plugin_Updater(__FILE__, rtrim($host, '/') . '/api/plugin-update/mark-stripper');
});

add_action('admin_menu', function () {
    add_management_page(
        'Affiros 黄色マーカー削除',
        '🟡 黄色マーカー削除',
        'manage_options',
        'affiros-mark-stripper',
        'affiros_mark_stripper_render_page'
    );
});

add_action('admin_enqueue_scripts', function ($hook) {
    if ($hook !== 'tools_page_affiros-mark-stripper') return;
    wp_localize_script('jquery', 'AffirosMarkStripper', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce'   => wp_create_nonce('affiros_mark_stripper_nonce'),
    ]);
});

/**
 * 投稿本文から <mark ...>...</mark> を中身だけ残してタグ削除。
 * 副作用なし純粋関数。
 *
 * Returns: ['content' => string, 'removed' => int]
 */
function affiros_mark_stripper_strip($content) {
    if (!$content) return ['content' => $content, 'removed' => 0];
    $count = 0;
    $new = preg_replace_callback(
        '#<mark\b[^>]*>([\s\S]*?)</mark>#i',
        function ($m) use (&$count) {
            $count++;
            return $m[1];
        },
        $content
    );
    return ['content' => $new, 'removed' => $count];
}

function affiros_mark_stripper_render_page() {
    if (!current_user_can('manage_options')) return;

    $categories = get_categories(['hide_empty' => false, 'orderby' => 'name']);
    ?>
    <div class="wrap">
        <h1>🟡 黄色マーカー削除</h1>
        <p style="font-size:13px;line-height:1.7">
            過去に AI 装飾で挿入された <code>&lt;mark&gt;...&lt;/mark&gt;</code> 黄色マーカーを記事から一括削除します。<br>
            <strong>中身のテキストは残します</strong>（タグだけ剥がす）。リビジョン自動保存で元に戻せます。
        </p>

        <div style="background:#fffbeb;border:1px solid #fbbf24;padding:12px;margin:16px 0;border-radius:4px;font-size:13px;line-height:1.7">
            <strong>⚠️ 仕様</strong>
            <ul style="margin:6px 0 0 20px">
                <li>削除対象: <code>&lt;mark&gt;text&lt;/mark&gt;</code> 形式すべて（属性ありも含む。例: <code>&lt;mark style="..." class="..."&gt;</code>）</li>
                <li>残るもの: タグ内の<strong>テキストはそのまま</strong>残る（情報損失なし）</li>
                <li>対象記事: <strong>publish / future / draft / private</strong>（公開・予約・下書き・非公開すべて）</li>
                <li>各記事リビジョン自動保存 → 編集画面の「リビジョン」から元に戻せる</li>
                <li>公開済み記事を更新すると「最終更新日」が今日に変わる場合あり（テーマ依存。post_date は触らない）</li>
            </ul>
        </div>

        <div class="card" style="padding:20px;margin:20px 0;max-width:900px">
            <h2 style="margin-top:0">対象を絞る</h2>
            <div style="margin:8px 0">
                <strong>ステータス:</strong>
                <?php foreach (['publish'=>'公開済み','future'=>'予約投稿','draft'=>'下書き','private'=>'非公開'] as $st=>$label): ?>
                    <label style="margin-right:12px">
                        <input type="checkbox" class="ms-status" value="<?php echo esc_attr($st); ?>" checked>
                        <?php echo esc_html($label); ?>
                    </label>
                <?php endforeach; ?>
            </div>
            <div style="margin:14px 0 8px">
                <strong>カテゴリ（未選択=全件）:</strong>
            </div>
            <div style="max-height:200px;overflow-y:auto;border:1px solid #ddd;padding:10px;background:#fafafa">
                <?php if (empty($categories)): ?>
                    <em style="color:#888">カテゴリがありません</em>
                <?php else: foreach ($categories as $cat): ?>
                    <label style="display:inline-block;margin:2px 12px 2px 0">
                        <input type="checkbox" class="ms-cat" value="<?php echo esc_attr($cat->term_id); ?>">
                        <?php echo esc_html($cat->name); ?>
                        <span style="color:#888">(<?php echo esc_html($cat->count); ?>)</span>
                    </label>
                <?php endforeach; endif; ?>
            </div>
            <p style="margin-top:16px">
                <button type="button" id="ms-scan-btn" class="button button-primary">🔍 スキャン（マーカー残存を検出）</button>
                <span id="ms-scan-status" style="margin-left:12px;color:#666;font-size:13px"></span>
            </p>
        </div>

        <div id="ms-result" style="display:none;background:#f0f9ff;border:1px solid #38bdf8;padding:16px;border-radius:6px;max-width:1100px">
            <h3 style="margin-top:0">スキャン結果</h3>
            <p><strong id="ms-result-count">0</strong> 件の記事で <code>&lt;mark&gt;</code> マーカーを検出。合計 <strong id="ms-result-marker-count">0</strong> 個。</p>
            <div style="max-height:500px;overflow-y:auto;border:1px solid #ddd">
                <table class="wp-list-table widefat striped">
                    <thead>
                        <tr style="position:sticky;top:0;background:#f0f0f1;z-index:1">
                            <th style="width:60px">ID</th>
                            <th>タイトル</th>
                            <th style="width:90px">ステータス</th>
                            <th style="width:100px">マーカー数</th>
                            <th style="width:140px">アクション</th>
                        </tr>
                    </thead>
                    <tbody id="ms-result-tbody"></tbody>
                </table>
            </div>
            <p style="margin:16px 0 0">
                <button type="button" id="ms-fix-all-btn" class="button button-primary button-large" style="background:#16a34a;border-color:#15803d">🚀 全件 一括削除</button>
                <span id="ms-fix-status" style="margin-left:12px;font-size:13px"></span>
            </p>
        </div>
    </div>

    <script>
    (function ($) {
        function ajaxUrl() { return (window.AffirosMarkStripper && AffirosMarkStripper.ajaxUrl) || (typeof ajaxurl !== 'undefined' ? ajaxurl : '/wp-admin/admin-ajax.php'); }
        function nonce()   { return (window.AffirosMarkStripper && AffirosMarkStripper.nonce) || ''; }
        let scannedPosts = [];

        $('#ms-scan-btn').on('click', scan);
        $('#ms-fix-all-btn').on('click', fixAll);

        async function scan() {
            const statuses = $('.ms-status:checked').map((_, el) => el.value).get();
            const cats     = $('.ms-cat:checked').map((_, el) => el.value).get();
            if (!statuses.length) { alert('ステータスを1つ以上選んでください'); return; }

            $('#ms-scan-btn').prop('disabled', true).text('スキャン中...');
            $('#ms-result').hide();
            $('#ms-scan-status').text('');
            try {
                const res = await $.post(ajaxUrl(), {
                    action: 'ms_scan',
                    nonce: nonce(),
                    statuses: statuses,
                    categories: cats,
                });
                if (!res.success) { alert('失敗: ' + (res.data || '')); return; }
                scannedPosts = res.data.posts || [];
                const totalMarkers = scannedPosts.reduce((s, p) => s + (p.marker_count || 0), 0);
                $('#ms-scan-status').html(`完了: <strong>${res.data.scanned}件</strong>チェック / マーカーあり <strong style="color:#dc2626">${scannedPosts.length}件</strong>`);
                $('#ms-result-count').text(scannedPosts.length);
                $('#ms-result-marker-count').text(totalMarkers);
                const tbody = $('#ms-result-tbody').empty();
                scannedPosts.forEach(p => {
                    const editUrl = `${location.origin}/wp-admin/post.php?post=${p.id}&action=edit`;
                    tbody.append(`
                        <tr data-id="${p.id}">
                            <td>${p.id}</td>
                            <td><a href="${editUrl}" target="_blank">${esc(p.title)}</a></td>
                            <td><code style="font-size:11px">${esc(p.status)}</code></td>
                            <td style="text-align:center;color:#dc2626;font-weight:600">${p.marker_count}</td>
                            <td>
                                <button type="button" class="button button-small ms-fix-one" data-id="${p.id}">🟡 削除</button>
                            </td>
                        </tr>
                    `);
                });
                tbody.find('.ms-fix-one').on('click', function () {
                    const id = $(this).data('id');
                    fixOne(id, $(this).closest('tr'));
                });
                if (scannedPosts.length) $('#ms-result').show();
            } catch (e) {
                alert('通信エラー: ' + (e.responseText || e.statusText));
            } finally {
                $('#ms-scan-btn').prop('disabled', false).text('🔍 スキャン（マーカー残存を検出）');
            }
        }

        async function fixOne(postId, row) {
            const btn = row ? row.find('.ms-fix-one') : null;
            if (btn && btn.length) btn.prop('disabled', true).text('削除中...');
            try {
                const res = await $.post(ajaxUrl(), {
                    action: 'ms_fix',
                    nonce: nonce(),
                    post_id: postId,
                });
                if (!res.success) {
                    alert('失敗 #' + postId + ': ' + (res.data || ''));
                    if (btn && btn.length) btn.prop('disabled', false).text('🟡 削除');
                    return false;
                }
                if (row && row.length) {
                    row.css('background-color', '#dcfce7');
                    row.find('td:eq(4)').html(`<span style="color:#16a34a;font-weight:600">✓ ${res.data.removed}個削除</span>`);
                }
                return true;
            } catch (e) {
                alert('通信エラー #' + postId + ': ' + (e.responseText || e.statusText));
                if (btn && btn.length) btn.prop('disabled', false).text('🟡 削除');
                return false;
            }
        }

        async function fixAll() {
            if (!scannedPosts.length) { alert('対象なし'); return; }
            if (!confirm(`${scannedPosts.length} 件の記事から <mark> マーカーを削除します。\n各記事でリビジョン自動保存されるため元に戻せます。\n\n実行しますか？`)) return;
            $('#ms-fix-all-btn').prop('disabled', true);
            let done = 0, failed = 0;
            for (const p of scannedPosts) {
                $('#ms-fix-status').text(`削除中... ${done + failed}/${scannedPosts.length}件`);
                const row = $(`tr[data-id="${p.id}"]`);
                const ok = await fixOne(p.id, row.length ? row : null);
                if (ok) done++; else failed++;
            }
            $('#ms-fix-status').html(`<span style="color:#16a34a;font-weight:600">完了: 成功 ${done}件 / 失敗 ${failed}件</span>`);
            $('#ms-fix-all-btn').prop('disabled', false);
        }

        function esc(s) { return String(s == null ? '' : s).replace(/[<>&"]/g, c => ({'<':'&lt;','>':'&gt;','&':'&amp;','"':'&quot;'}[c])); }
    })(jQuery);
    </script>
    <?php
}

/**
 * AJAX: スキャン（DB変更なし）
 *
 * post_content に "<mark" を含むものだけ LIKE で絞ってから、
 * 各記事で正規表現マッチして件数を数える。
 */
add_action('wp_ajax_ms_scan', function () {
    check_ajax_referer('affiros_mark_stripper_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('権限なし');
    @set_time_limit(120);

    $statuses = array_filter(array_map('sanitize_text_field', (array)($_POST['statuses'] ?? [])));
    $statuses = array_values(array_intersect($statuses, ['publish', 'future', 'draft', 'private']));
    if (empty($statuses)) $statuses = ['publish', 'future', 'draft', 'private'];
    $cats = array_filter(array_map('intval', (array)($_POST['categories'] ?? [])));

    global $wpdb;
    $status_in = "'" . implode("','", array_map('esc_sql', $statuses)) . "'";
    $sql = $wpdb->prepare(
        "SELECT ID, post_title, post_status, post_content
         FROM {$wpdb->posts}
         WHERE post_type = 'post'
           AND post_status IN ({$status_in})
           AND post_content LIKE %s
         ORDER BY ID DESC",
        '%<mark%'
    );
    $rows = $wpdb->get_results($sql);

    $found = [];
    $scanned = 0;
    foreach ($rows as $r) {
        $scanned++;
        if (!empty($cats)) {
            $post_cats = wp_get_post_categories($r->ID);
            if (!array_intersect($cats, $post_cats)) continue;
        }
        $result = affiros_mark_stripper_strip($r->post_content);
        if ($result['removed'] <= 0) continue;
        $found[] = [
            'id'           => (int)$r->ID,
            'title'        => $r->post_title,
            'status'       => $r->post_status,
            'marker_count' => (int)$result['removed'],
        ];
    }

    wp_send_json_success([
        'scanned' => $scanned,
        'posts'   => $found,
    ]);
});

/**
 * AJAX: 個別削除（DB書き込み）
 */
add_action('wp_ajax_ms_fix', function () {
    check_ajax_referer('affiros_mark_stripper_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('権限なし');
    @set_time_limit(60);

    $post_id = intval($_POST['post_id'] ?? 0);
    if (!$post_id) wp_send_json_error('post_id 不正');
    $p = get_post($post_id);
    if (!$p) wp_send_json_error('記事が見つかりません');

    $result = affiros_mark_stripper_strip($p->post_content);
    if ($result['removed'] <= 0) {
        wp_send_json_success(['removed' => 0, 'message' => 'マーカーなし']);
    }
    $upd = wp_update_post([
        'ID'           => $post_id,
        'post_content' => $result['content'],
    ], true);
    if (is_wp_error($upd)) wp_send_json_error($upd->get_error_message());

    wp_send_json_success(['removed' => $result['removed']]);
});
