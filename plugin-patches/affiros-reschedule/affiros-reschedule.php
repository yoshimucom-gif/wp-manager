<?php
/**
 * Plugin Name: Affiros 予約再スケジュール
 * Description: WordPressに予約投稿済み（future）の記事の投稿日時を一括で再スケジュールするツール。投稿頻度を1日N件で振り直し可能。
 * Version: 1.0.0
 * Author: Affiros
 * License: GPL v2 or later
 * Text Domain: affiros-reschedule
 */

if (!defined('ABSPATH')) exit;

define('AFFIROS_RESCHEDULE_VERSION', '1.0.0');

require_once plugin_dir_path(__FILE__) . 'includes/plugin-updater.php';

/**
 * 自動更新サーバー登録。
 * 別ホスト運用時は wp-config.php で AFFIROS_UPDATE_HOST を上書き。
 */
add_action('init', function () {
    $host = defined('AFFIROS_UPDATE_HOST') ? AFFIROS_UPDATE_HOST : 'https://wp-manager.onrender.com';
    new Affiros_Plugin_Updater(__FILE__, rtrim($host, '/') . '/api/plugin-update/reschedule');
});

/**
 * 管理メニュー登録（ツール配下）
 */
add_action('admin_menu', function () {
    add_management_page(
        'Affiros 予約再スケジュール',
        '📅 予約再スケジュール',
        'manage_options',
        'affiros-reschedule',
        'affiros_reschedule_render_page'
    );
});

/**
 * 管理画面 JS 用 nonce 渡し
 */
add_action('admin_enqueue_scripts', function ($hook) {
    if ($hook !== 'tools_page_affiros-reschedule') return;
    wp_localize_script('jquery', 'AffirosReschedule', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce'   => wp_create_nonce('affiros_reschedule_nonce'),
    ]);
});

/**
 * メイン画面
 */
function affiros_reschedule_render_page() {
    if (!current_user_can('manage_options')) return;

    $categories = get_categories(['hide_empty' => false, 'orderby' => 'name']);
    $future_total = wp_count_posts()->future ?? 0;

    $default_start = date('Y-m-d\T09:00', strtotime('+1 day'));
    ?>
    <div class="wrap">
        <h1>📅 予約再スケジュール</h1>
        <p style="font-size:13px;line-height:1.7">
            WordPress に <strong>予約投稿済み（future ステータス）</strong>の記事の投稿日時を、
            一括で再スケジュールします。投稿頻度（1日N件）を変えたいときに使います。<br>
            現在の予約記事数: <strong><?php echo esc_html($future_total); ?>件</strong>
        </p>

        <div style="background:#fffbeb;border:1px solid #fbbf24;padding:12px;margin:16px 0;border-radius:4px;font-size:13px;line-height:1.7">
            <strong>⚠️ 仕様</strong>
            <ul style="margin:6px 0 0 20px">
                <li>対象は <code>post_status = future</code>（予約投稿）のみ。公開済み（publish）/下書き（draft）は触らない</li>
                <li>並び順は<strong>現在の予約日時順</strong>を維持。早い順に再スケジュール</li>
                <li>投稿時刻は <strong>9:00〜21:00</strong> を「1日N件」で等分（1件なら12:00、2件なら9:00/21:00、3件なら9:00/15:00/21:00 …）</li>
                <li>各記事で<strong>リビジョンが自動保存される</strong>ので、編集画面の「リビジョン」から元の予約日時に戻せる</li>
            </ul>
        </div>

        <div class="card" style="padding:20px;margin:20px 0;max-width:900px">
            <h2 style="margin-top:0">1. 対象記事を絞る（カテゴリフィルタ）</h2>
            <p class="description"><strong>未選択</strong>なら全 future 記事が対象。</p>
            <div style="max-height:240px;overflow-y:auto;border:1px solid #ddd;padding:10px;background:#fafafa">
                <?php if (empty($categories)): ?>
                    <em style="color:#888">カテゴリがありません</em>
                <?php else: foreach ($categories as $cat): ?>
                    <label style="display:inline-block;margin:2px 12px 2px 0">
                        <input type="checkbox" class="ar-cat" value="<?php echo esc_attr($cat->term_id); ?>">
                        <?php echo esc_html($cat->name); ?>
                        <span style="color:#888">(<?php echo esc_html($cat->count); ?>)</span>
                    </label>
                <?php endforeach; endif; ?>
            </div>

            <h2 style="margin-top:24px">2. スケジュール設定</h2>
            <table class="form-table">
                <tr>
                    <th>開始日時</th>
                    <td>
                        <input type="datetime-local" id="ar-start" value="<?php echo esc_attr($default_start); ?>">
                        <p class="description">この日時以降に再スケジュールを開始</p>
                    </td>
                </tr>
                <tr>
                    <th>1日あたり投稿数</th>
                    <td>
                        <input type="number" id="ar-per-day" value="3" min="1" max="20" style="width:80px">
                        <span style="margin-left:8px;color:#888;font-size:12px">件/日（1〜20）</span>
                        <p class="description">9:00〜21:00 の12時間を N 等分。例: 5件なら 9:00 / 12:00 / 15:00 / 18:00 / 21:00</p>
                    </td>
                </tr>
                <tr>
                    <th>投稿時刻に揺らぎ</th>
                    <td>
                        ±<input type="number" id="ar-jitter" value="10" min="0" max="30" style="width:60px">分
                        <p class="description">「毎日9時ピッタリ」のボット感を回避（0なら無効・正時固定）</p>
                    </td>
                </tr>
            </table>

            <p>
                <button type="button" id="ar-preview-btn" class="button button-primary">📋 プレビュー（実行前確認）</button>
                <span id="ar-preview-status" style="margin-left:12px;color:#666;font-size:13px"></span>
            </p>
        </div>

        <div id="ar-preview-result" style="display:none;background:#f0f9ff;border:1px solid #38bdf8;padding:16px;border-radius:6px;max-width:1100px">
            <h3 style="margin-top:0">プレビュー（まだ書き換えていません）</h3>
            <p><strong id="ar-preview-count">0</strong> 件の予約記事を、以下のスケジュールで再設定します。</p>
            <div style="max-height:500px;overflow-y:auto;border:1px solid #ddd">
                <table class="wp-list-table widefat striped">
                    <thead>
                        <tr style="position:sticky;top:0;background:#f0f0f1;z-index:1">
                            <th style="width:60px">ID</th>
                            <th>タイトル</th>
                            <th style="width:180px">現在の予約日時</th>
                            <th style="width:180px;color:#0073aa">→ 新しい予約日時</th>
                        </tr>
                    </thead>
                    <tbody id="ar-preview-tbody"></tbody>
                </table>
            </div>
            <p style="margin:16px 0 0">
                <button type="button" id="ar-execute-btn" class="button button-primary button-large" style="background:#16a34a;border-color:#15803d">🚀 再スケジュール実行</button>
                <span id="ar-execute-status" style="margin-left:12px;font-size:13px"></span>
            </p>
        </div>
    </div>

    <script>
    (function ($) {
        function ajaxUrl() { return (window.AffirosReschedule && AffirosReschedule.ajaxUrl) || (typeof ajaxurl !== 'undefined' ? ajaxurl : '/wp-admin/admin-ajax.php'); }
        function nonce()   { return (window.AffirosReschedule && AffirosReschedule.nonce) || ''; }
        let previewData = null;

        $('#ar-preview-btn').on('click', async function () {
            const cats = $('.ar-cat:checked').map((_, el) => el.value).get();
            const start = $('#ar-start').val();
            const perDay = parseInt($('#ar-per-day').val()) || 3;
            const jitter = parseInt($('#ar-jitter').val()) || 0;

            if (!start) { alert('開始日時を入力してください'); return; }

            $(this).prop('disabled', true).text('プレビュー生成中...');
            $('#ar-preview-status').text('');
            try {
                const res = await $.post(ajaxUrl(), {
                    action: 'ar_preview',
                    nonce: nonce(),
                    categories: cats,
                    start: start,
                    per_day: perDay,
                    jitter: jitter,
                });
                if (!res.success) {
                    alert('失敗: ' + (res.data || ''));
                    return;
                }
                previewData = res.data;
                if (!previewData.posts.length) {
                    $('#ar-preview-result').hide();
                    $('#ar-preview-status').html('<span style="color:#888">対象記事なし（カテゴリフィルタを外すか、予約投稿があるか確認してください）</span>');
                    return;
                }
                $('#ar-preview-count').text(previewData.posts.length);
                const tbody = $('#ar-preview-tbody').empty();
                previewData.posts.forEach(p => {
                    tbody.append(`
                        <tr>
                            <td>${p.id}</td>
                            <td>${esc(p.title)}</td>
                            <td style="font-family:monospace;font-size:12px;color:#666">${esc(p.current_date)}</td>
                            <td style="font-family:monospace;font-size:12px;color:#0073aa;font-weight:600">${esc(p.new_date)}</td>
                        </tr>
                    `);
                });
                $('#ar-preview-result').show();
                $('#ar-execute-status').text('');
                $('#ar-execute-btn').prop('disabled', false);
            } catch (e) {
                alert('通信エラー: ' + (e.responseText || e.statusText));
            } finally {
                $(this).prop('disabled', false).text('📋 プレビュー（実行前確認）');
            }
        });

        $('#ar-execute-btn').on('click', async function () {
            if (!previewData || !previewData.posts.length) { alert('プレビューを先に実行してください'); return; }
            if (!confirm(`${previewData.posts.length} 件の予約記事を再スケジュールします。\n\n各記事でリビジョンが自動保存されるので元に戻せます。\n\n実行しますか？`)) return;
            $(this).prop('disabled', true);
            $('#ar-execute-status').html('<span style="color:#666">実行中...</span>');
            try {
                const res = await $.post(ajaxUrl(), {
                    action: 'ar_execute',
                    nonce: nonce(),
                    schedule: JSON.stringify(previewData.posts.map(p => ({
                        id: p.id, new_date: p.new_date, new_gmt: p.new_gmt
                    }))),
                });
                if (!res.success) {
                    $('#ar-execute-status').html('<span style="color:#dc2626">失敗: ' + esc(res.data || '') + '</span>');
                    $(this).prop('disabled', false);
                    return;
                }
                const d = res.data;
                let msg = `<span style="color:#16a34a;font-weight:600">✓ 成功 ${d.updated}件</span>`;
                if (d.failed) {
                    msg += ` <span style="color:#dc2626">/ 失敗 ${d.failed}件</span>`;
                    if (d.errors && d.errors.length) {
                        msg += '<br><small style="color:#666">エラー例: ' + d.errors.map(esc).join(' / ') + '</small>';
                    }
                }
                $('#ar-execute-status').html(msg);
            } catch (e) {
                $('#ar-execute-status').html('<span style="color:#dc2626">通信エラー</span>');
                $(this).prop('disabled', false);
            }
        });

        function esc(s) { return String(s == null ? '' : s).replace(/[<>&"]/g, c => ({'<':'&lt;','>':'&gt;','&':'&amp;','"':'&quot;'}[c])); }
    })(jQuery);
    </script>
    <?php
}

/**
 * 投稿時刻スロットを 9:00〜21:00 の範囲で N 等分。
 * 1件なら 12:00、2件なら 9:00/21:00、3件なら 9:00/15:00/21:00、...
 */
function affiros_reschedule_generate_slots($per_day) {
    if ($per_day <= 1) return ['12:00'];
    $start_min = 9 * 60;
    $end_min   = 21 * 60;
    $range     = $end_min - $start_min; // 720分
    $slots = [];
    for ($i = 0; $i < $per_day; $i++) {
        $abs_min = $start_min + intval(round($range * $i / ($per_day - 1)));
        $h = intval($abs_min / 60);
        $m = $abs_min % 60;
        $slots[] = sprintf('%02d:%02d', $h, $m);
    }
    return $slots;
}

/**
 * AJAX: プレビュー生成（DB には書き込まない）
 */
add_action('wp_ajax_ar_preview', function () {
    check_ajax_referer('affiros_reschedule_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('権限なし');
    @set_time_limit(60);

    $cats   = array_filter(array_map('intval', (array)($_POST['categories'] ?? [])));
    $start  = sanitize_text_field($_POST['start'] ?? '');
    $perDay = max(1, min(20, intval($_POST['per_day'] ?? 3)));
    $jitter = max(0, min(60, intval($_POST['jitter'] ?? 0)));

    $start_ts = strtotime($start);
    if (!$start || !$start_ts) wp_send_json_error('開始日時が不正です');

    // future 記事を「現在の予約日時順」で取得
    $args = [
        'post_type'      => 'post',
        'post_status'    => 'future',
        'orderby'        => 'date',
        'order'          => 'ASC',
        'posts_per_page' => -1,
        'fields'         => 'ids',
    ];
    if (!empty($cats)) {
        $args['category__in'] = $cats;
    }
    $ids = get_posts($args);

    if (empty($ids)) {
        wp_send_json_success(['posts' => []]);
    }

    $slots = affiros_reschedule_generate_slots($perDay);
    $posts = [];
    foreach ($ids as $i => $id) {
        $day_offset = intval($i / $perDay);
        $slot_idx   = $i % $perDay;
        list($h, $m) = explode(':', $slots[$slot_idx]);
        $base_day  = strtotime("+{$day_offset} day", $start_ts);
        $base      = strtotime(date('Y-m-d', $base_day) . " {$h}:{$m}:00");
        if ($jitter > 0) {
            $delta = mt_rand(-$jitter, $jitter) * 60;
            $base += $delta;
        }
        $new_local = date('Y-m-d H:i:s', $base);
        $new_gmt   = get_gmt_from_date($new_local);
        $p = get_post($id);
        $posts[] = [
            'id'           => intval($id),
            'title'        => $p ? $p->post_title : '',
            'current_date' => $p ? $p->post_date : '',
            'new_date'     => $new_local,
            'new_gmt'      => $new_gmt,
        ];
    }

    wp_send_json_success(['posts' => $posts]);
});

/**
 * AJAX: 実行（DB書き込み）
 */
add_action('wp_ajax_ar_execute', function () {
    check_ajax_referer('affiros_reschedule_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('権限なし');
    @set_time_limit(300);

    $schedule_raw = wp_unslash($_POST['schedule'] ?? '[]');
    $schedule = json_decode($schedule_raw, true);
    if (!is_array($schedule)) wp_send_json_error('スケジュールデータが不正');

    $updated = 0;
    $failed  = 0;
    $errors  = [];
    foreach ($schedule as $item) {
        $id       = intval($item['id'] ?? 0);
        $new_date = sanitize_text_field($item['new_date'] ?? '');
        $new_gmt  = sanitize_text_field($item['new_gmt'] ?? '');
        if (!$id || !$new_date || !$new_gmt) {
            $failed++;
            continue;
        }
        // 対象が future のままか念のため再確認
        $p = get_post($id);
        if (!$p || $p->post_status !== 'future') {
            $failed++;
            $errors[] = "#{$id} は future ではない({$p->post_status})ためスキップ";
            continue;
        }
        // edit_date => true が無いと post_date 変更が無視されるバグがあるので必須
        $res = wp_update_post([
            'ID'            => $id,
            'post_date'     => $new_date,
            'post_date_gmt' => $new_gmt,
            'edit_date'     => true,
            'post_status'   => 'future', // 念のため future 固定で再保存
        ], true);
        if (is_wp_error($res)) {
            $failed++;
            $errors[] = "#{$id}: " . $res->get_error_message();
        } else {
            $updated++;
        }
    }

    wp_send_json_success([
        'updated' => $updated,
        'failed'  => $failed,
        'errors'  => array_slice($errors, 0, 5),
    ]);
});
