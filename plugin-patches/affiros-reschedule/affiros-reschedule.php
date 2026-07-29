<?php
/**
 * Plugin Name: Affiros 予約再スケジュール
 * Description: 予約投稿（future）と下書き（draft）の投稿日時を一括で再スケジュールするツール。投稿頻度を1日N件で振り直し可能。下書きは予約投稿に変換される。
 * Version: 1.1.0
 * Author: Affiros
 * License: GPL v2 or later
 * Text Domain: affiros-reschedule
 */

if (!defined('ABSPATH')) exit;

define('AFFIROS_RESCHEDULE_VERSION', '1.1.0');

// 取り消し用に「実行前の状態」を退避する投稿メタ
define('AFFIROS_RESCHEDULE_META_BATCH',  '_ar_batch');
define('AFFIROS_RESCHEDULE_META_STATUS', '_ar_prev_status');
define('AFFIROS_RESCHEDULE_META_DATE',   '_ar_prev_date');
define('AFFIROS_RESCHEDULE_META_GMT',    '_ar_prev_date_gmt');
define('AFFIROS_RESCHEDULE_OPT_BATCH',   'affiros_reschedule_last_batch');

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
 * 扱えるステータス
 */
function affiros_reschedule_allowed_statuses() {
    return ['future', 'draft'];
}

/**
 * メイン画面
 */
function affiros_reschedule_render_page() {
    if (!current_user_can('manage_options')) return;

    $categories   = get_categories(['hide_empty' => false, 'orderby' => 'name']);
    $counts       = wp_count_posts();
    $future_total = $counts->future ?? 0;
    $draft_total  = $counts->draft ?? 0;
    $has_undo     = (bool) get_option(AFFIROS_RESCHEDULE_OPT_BATCH);

    $default_start = date('Y-m-d\T09:00', current_time('timestamp') + DAY_IN_SECONDS);
    ?>
    <div class="wrap">
        <h1>📅 予約再スケジュール</h1>
        <p style="font-size:13px;line-height:1.7">
            <strong>予約投稿（future）</strong>と<strong>下書き（draft）</strong>の投稿日時を、一括で再スケジュールします。
            投稿頻度（1日N件）を変えたいときに使います。<br>
            現在の予約記事: <strong><?php echo esc_html($future_total); ?>件</strong>
            ／ 下書き: <strong><?php echo esc_html($draft_total); ?>件</strong>
        </p>

        <div style="background:#fef2f2;border:1px solid #f87171;padding:12px;margin:16px 0;border-radius:4px;font-size:13px;line-height:1.7">
            <strong>⚠️ 下書きを対象に含めるときの注意</strong>
            <ul style="margin:6px 0 0 20px">
                <li>下書きは<strong>予約投稿（future）に変換されます</strong>。設定した日時が来ると<strong>自動で公開されます</strong></li>
                <li>書きかけ・公開したくない下書きが混ざっていないか、必ず<strong>プレビューで一覧を確認</strong>してください</li>
                <li>カテゴリフィルタで対象を絞り込むことを強く推奨します</li>
            </ul>
        </div>

        <div style="background:#fffbeb;border:1px solid #fbbf24;padding:12px;margin:16px 0;border-radius:4px;font-size:13px;line-height:1.7">
            <strong>⚠️ 仕様</strong>
            <ul style="margin:6px 0 0 20px">
                <li>公開済み（publish）とゴミ箱の記事は一切触らない</li>
                <li>並び順は<strong>予約投稿（現在の予約日時順）→ 下書き（作成日時順）</strong>。この順に早い日時から割り当て</li>
                <li>投稿時刻は <strong>9:00〜21:00</strong> を「1日N件」で等分（1件なら12:00、2件なら9:00/21:00、3件なら9:00/15:00/21:00 …）</li>
                <li><strong>過去の日時は絶対に割り当てません</strong>（即時公開の事故防止）。開始日時より前の時間帯スロットは自動でスキップされます</li>
                <li>実行前の日時とステータスは投稿メタに退避され、下の「取り消し」で<strong>直前の実行を丸ごと元に戻せます</strong></li>
            </ul>
        </div>

        <div class="card" style="padding:20px;margin:20px 0;max-width:900px">
            <h2 style="margin-top:0">1. 対象ステータス</h2>
            <p>
                <label style="margin-right:20px">
                    <input type="checkbox" class="ar-status" value="future" checked>
                    予約投稿（future）<span style="color:#888">（<?php echo esc_html($future_total); ?>件）</span>
                </label>
                <label>
                    <input type="checkbox" class="ar-status" value="draft" checked>
                    下書き（draft）<span style="color:#888">（<?php echo esc_html($draft_total); ?>件）</span>
                    <span style="color:#dc2626;font-size:12px">→ 予約投稿に変換されます</span>
                </label>
            </p>

            <h2 style="margin-top:24px">2. 対象記事を絞る（カテゴリフィルタ）</h2>
            <p class="description"><strong>未選択</strong>なら全記事が対象。</p>
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

            <h2 style="margin-top:24px">3. スケジュール設定</h2>
            <table class="form-table">
                <tr>
                    <th>開始日時</th>
                    <td>
                        <input type="datetime-local" id="ar-start" value="<?php echo esc_attr($default_start); ?>">
                        <p class="description">この日時以降に再スケジュールを開始（<strong>過去の日時は指定できません</strong>）</p>
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
            <p>
                <strong id="ar-preview-count">0</strong> 件を以下のスケジュールで再設定します。
                <span id="ar-preview-breakdown" style="color:#666"></span>
            </p>
            <div style="max-height:500px;overflow-y:auto;border:1px solid #ddd">
                <table class="wp-list-table widefat striped">
                    <thead>
                        <tr style="position:sticky;top:0;background:#f0f0f1;z-index:1">
                            <th style="width:60px">ID</th>
                            <th style="width:90px">現ステータス</th>
                            <th>タイトル</th>
                            <th style="width:170px">現在の日時</th>
                            <th style="width:170px;color:#0073aa">→ 新しい予約日時</th>
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

        <div class="card" style="padding:20px;margin:20px 0;max-width:900px">
            <h2 style="margin-top:0">↩️ 直前の実行を取り消す</h2>
            <p class="description">
                最後に実行した再スケジュールを、日時もステータスも実行前の状態に戻します（下書きは下書きに戻ります）。<br>
                取り消せるのは<strong>直近1回分</strong>のみです。
            </p>
            <p>
                <button type="button" id="ar-undo-btn" class="button" <?php disabled(!$has_undo); ?>>
                    <?php echo $has_undo ? '直前の実行を取り消す' : '取り消せる実行履歴がありません'; ?>
                </button>
                <span id="ar-undo-status" style="margin-left:12px;font-size:13px"></span>
            </p>
        </div>
    </div>

    <script>
    (function ($) {
        function ajaxUrl() { return (window.AffirosReschedule && AffirosReschedule.ajaxUrl) || (typeof ajaxurl !== 'undefined' ? ajaxurl : '/wp-admin/admin-ajax.php'); }
        function nonce()   { return (window.AffirosReschedule && AffirosReschedule.nonce) || ''; }
        let previewData = null;

        const STATUS_LABEL = { future: '予約', draft: '下書き' };

        $('#ar-preview-btn').on('click', async function () {
            const statuses = $('.ar-status:checked').map((_, el) => el.value).get();
            const cats = $('.ar-cat:checked').map((_, el) => el.value).get();
            const start = $('#ar-start').val();
            const perDay = parseInt($('#ar-per-day').val()) || 3;
            const jitter = parseInt($('#ar-jitter').val()) || 0;

            if (!statuses.length) { alert('対象ステータスを1つ以上選んでください'); return; }
            if (!start) { alert('開始日時を入力してください'); return; }

            $(this).prop('disabled', true).text('プレビュー生成中...');
            $('#ar-preview-status').text('');
            try {
                const res = await $.post(ajaxUrl(), {
                    action: 'ar_preview',
                    nonce: nonce(),
                    statuses: statuses,
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
                    $('#ar-preview-status').html('<span style="color:#888">対象記事なし（カテゴリフィルタを外すか、対象ステータスの記事があるか確認してください）</span>');
                    return;
                }
                const drafts = previewData.posts.filter(p => p.current_status === 'draft').length;
                $('#ar-preview-count').text(previewData.posts.length);
                $('#ar-preview-breakdown').html(
                    drafts ? `（うち下書き <strong style="color:#dc2626">${drafts}件</strong> が予約投稿に変換され、その日時に自動公開されます）` : ''
                );
                if (previewData.jitter_used < previewData.jitter_request) {
                    $('#ar-preview-status').html(
                        `<span style="color:#b45309">揺らぎは ±${previewData.jitter_used}分 に自動調整されました（投稿間隔が狭く、±${previewData.jitter_request}分では記事の順序が入れ替わるため）</span>`
                    );
                } else {
                    $('#ar-preview-status').text('');
                }
                const tbody = $('#ar-preview-tbody').empty();
                previewData.posts.forEach(p => {
                    const isDraft = p.current_status === 'draft';
                    tbody.append(`
                        <tr>
                            <td>${p.id}</td>
                            <td><span style="font-size:11px;padding:2px 6px;border-radius:3px;background:${isDraft ? '#fee2e2' : '#e0f2fe'};color:${isDraft ? '#991b1b' : '#075985'}">${STATUS_LABEL[p.current_status] || esc(p.current_status)}</span></td>
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
            const drafts = previewData.posts.filter(p => p.current_status === 'draft').length;
            let msg = `${previewData.posts.length} 件を再スケジュールします。\n\n`;
            if (drafts) msg += `⚠️ うち下書き ${drafts} 件が予約投稿になり、設定日時に自動公開されます。\n\n`;
            msg += '実行後、「直前の実行を取り消す」で元に戻せます。\n\n実行しますか？';
            if (!confirm(msg)) return;

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
                let out = `<span style="color:#16a34a;font-weight:600">✓ 成功 ${d.updated}件</span>`;
                if (d.converted) out += ` <span style="color:#666">（下書き→予約 ${d.converted}件）</span>`;
                if (d.failed) {
                    out += ` <span style="color:#dc2626">/ 失敗 ${d.failed}件</span>`;
                    if (d.errors && d.errors.length) {
                        out += '<br><small style="color:#666">エラー例: ' + d.errors.map(esc).join(' / ') + '</small>';
                    }
                }
                $('#ar-execute-status').html(out);
                if (d.updated > 0) {
                    $('#ar-undo-btn').prop('disabled', false).text('直前の実行を取り消す');
                }
            } catch (e) {
                $('#ar-execute-status').html('<span style="color:#dc2626">通信エラー</span>');
                $(this).prop('disabled', false);
            }
        });

        $('#ar-undo-btn').on('click', async function () {
            if (!confirm('直前の再スケジュールを取り消し、日時とステータスを実行前の状態に戻します。\n\n実行しますか？')) return;
            $(this).prop('disabled', true);
            $('#ar-undo-status').html('<span style="color:#666">取り消し中...</span>');
            try {
                const res = await $.post(ajaxUrl(), { action: 'ar_undo', nonce: nonce() });
                if (!res.success) {
                    $('#ar-undo-status').html('<span style="color:#dc2626">失敗: ' + esc(res.data || '') + '</span>');
                    $(this).prop('disabled', false);
                    return;
                }
                const d = res.data;
                let out = `<span style="color:#16a34a;font-weight:600">✓ ${d.restored}件を元に戻しました</span>`;
                if (d.failed) out += ` <span style="color:#dc2626">/ 失敗 ${d.failed}件</span>`;
                $('#ar-undo-status').html(out);
                $(this).text('取り消せる実行履歴がありません');
            } catch (e) {
                $('#ar-undo-status').html('<span style="color:#dc2626">通信エラー</span>');
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
 * 揺らぎ幅をスロット間隔に収まる範囲へ丸める。
 *
 * 1日20件なら隣接スロットは約38分しか離れていないため、±30分の揺らぎを
 * そのまま足すと前後の記事の日時が逆転・重複する。隣接スロットの最小間隔の
 * 半分未満に抑えることで、揺らぎを足しても順序が入れ替わらないことを保証する。
 */
function affiros_reschedule_clamp_jitter($jitter, $slots) {
    if ($jitter <= 0) return 0;
    if (count($slots) < 2) return min($jitter, 60);

    $min_gap = PHP_INT_MAX;
    for ($i = 1; $i < count($slots); $i++) {
        list($ph, $pm) = array_map('intval', explode(':', $slots[$i - 1]));
        list($ch, $cm) = array_map('intval', explode(':', $slots[$i]));
        $gap = ($ch * 60 + $cm) - ($ph * 60 + $pm);
        if ($gap < $min_gap) $min_gap = $gap;
    }
    $max_jitter = intval(($min_gap - 1) / 2);
    return max(0, min($jitter, $max_jitter));
}

/**
 * $count 件分の投稿日時（サイトローカル時刻の Unix timestamp）を生成する。
 *
 * - 開始日時より前の時間帯スロット（例: 開始が 20:00 なのに初日の 9:00 枠）は使わない
 * - 揺らぎを足した結果が現在時刻以前になるスロットも使わない（即時公開の事故防止）
 * - 常に単調増加。プレビューの並び順どおりの公開順を保証する
 *
 * WordPress は PHP のデフォルトタイムゾーンを UTC に固定するため、
 * date()/strtotime() で組み立てた値は「サイトローカルの壁時計を UTC として読んだ epoch」になる。
 * 現在時刻との比較は同じ基準を返す current_time('timestamp') と行うこと（time() ではズレる）。
 */
function affiros_reschedule_build_timestamps($count, $start_ts, $per_day, $jitter) {
    $slots  = affiros_reschedule_generate_slots($per_day);
    $jitter = affiros_reschedule_clamp_jitter($jitter, $slots);
    $now    = current_time('timestamp');
    $out    = [];
    $day    = 0;

    while (count($out) < $count && $day < 3650) {
        $day_str = date('Y-m-d', strtotime("+{$day} day", $start_ts));
        foreach ($slots as $slot) {
            if (count($out) >= $count) break;
            $ts = strtotime("{$day_str} {$slot}:00");
            if (!$ts || $ts < $start_ts) continue;          // 開始日時より前のスロットは飛ばす
            if ($jitter > 0) $ts += mt_rand(-$jitter, $jitter) * 60;
            if ($ts <= $now) continue;                       // 過去日時は絶対に作らない
            $last = end($out);
            if ($last !== false && $ts <= $last) $ts = $last + 60; // 単調増加を保証（保険）
            $out[] = $ts;
        }
        $day++;
    }
    return $out;
}

/**
 * AJAX: プレビュー生成（DB には書き込まない）
 */
add_action('wp_ajax_ar_preview', function () {
    check_ajax_referer('affiros_reschedule_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('権限なし');
    @set_time_limit(60);

    $statuses = array_values(array_intersect(
        array_map('sanitize_key', (array)($_POST['statuses'] ?? [])),
        affiros_reschedule_allowed_statuses()
    ));
    if (empty($statuses)) wp_send_json_error('対象ステータスを1つ以上選んでください');

    $cats   = array_filter(array_map('intval', (array)($_POST['categories'] ?? [])));
    $start  = sanitize_text_field($_POST['start'] ?? '');
    $perDay = max(1, min(20, intval($_POST['per_day'] ?? 3)));
    $jitter = max(0, min(60, intval($_POST['jitter'] ?? 0)));

    $start_ts = $start ? strtotime($start) : false;
    if (!$start_ts) wp_send_json_error('開始日時が不正です');
    if ($start_ts <= current_time('timestamp')) {
        wp_send_json_error('開始日時が過去です。現在より後の日時を指定してください（過去日時を指定すると記事が即時公開されてしまいます）');
    }

    // future（現在の予約日時順）→ draft（作成日時順）の順に並べる
    $ids = [];
    foreach (affiros_reschedule_allowed_statuses() as $st) {
        if (!in_array($st, $statuses, true)) continue;
        $args = [
            'post_type'           => 'post',
            'post_status'         => $st,
            'orderby'             => 'date',
            'order'               => 'ASC',
            'posts_per_page'      => -1,
            'fields'              => 'ids',
            'ignore_sticky_posts' => true,
        ];
        if (!empty($cats)) {
            $args['category__in'] = $cats;
        }
        $ids = array_merge($ids, get_posts($args));
    }

    if (empty($ids)) {
        wp_send_json_success(['posts' => []]);
    }

    $timestamps = affiros_reschedule_build_timestamps(count($ids), $start_ts, $perDay, $jitter);
    if (count($timestamps) < count($ids)) {
        wp_send_json_error('スケジュールの生成に失敗しました（対象件数が多すぎます）');
    }

    $posts = [];
    foreach ($ids as $i => $id) {
        $p = get_post($id);
        if (!$p) continue;
        $new_local = date('Y-m-d H:i:s', $timestamps[$i]);
        $posts[] = [
            'id'             => intval($id),
            'title'          => $p->post_title,
            'current_status' => $p->post_status,
            'current_date'   => $p->post_date,
            'new_date'       => $new_local,
            'new_gmt'        => get_gmt_from_date($new_local),
        ];
    }

    wp_send_json_success([
        'posts'          => $posts,
        'jitter_request' => $jitter,
        'jitter_used'    => affiros_reschedule_clamp_jitter($jitter, affiros_reschedule_generate_slots($perDay)),
    ]);
});

/**
 * AJAX: 実行（DB書き込み）
 *
 * future / draft のどちらも future として保存する（draft は予約投稿に変換）。
 * 実行前の post_date / post_status は投稿メタに退避し、ar_undo で戻せるようにする。
 */
add_action('wp_ajax_ar_execute', function () {
    check_ajax_referer('affiros_reschedule_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('権限なし');
    @set_time_limit(300);

    $schedule_raw = wp_unslash($_POST['schedule'] ?? '[]');
    $schedule = json_decode($schedule_raw, true);
    if (!is_array($schedule) || empty($schedule)) wp_send_json_error('スケジュールデータが不正');

    // 直近1回分のみ取り消し可能にするため、前回分の退避メタは破棄する
    delete_post_meta_by_key(AFFIROS_RESCHEDULE_META_BATCH);
    delete_post_meta_by_key(AFFIROS_RESCHEDULE_META_STATUS);
    delete_post_meta_by_key(AFFIROS_RESCHEDULE_META_DATE);
    delete_post_meta_by_key(AFFIROS_RESCHEDULE_META_GMT);
    $batch = uniqid('ar', true);

    $now       = current_time('timestamp');
    $updated   = 0;
    $converted = 0;
    $failed    = 0;
    $errors    = [];

    foreach ($schedule as $item) {
        $id       = intval($item['id'] ?? 0);
        $new_date = sanitize_text_field($item['new_date'] ?? '');
        $new_gmt  = sanitize_text_field($item['new_gmt'] ?? '');
        if (!$id || !$new_date || !$new_gmt) {
            $failed++;
            continue;
        }

        $p = get_post($id);
        if (!$p) {
            $failed++;
            $errors[] = "#{$id} は存在しません";
            continue;
        }
        // 対象が future / draft のままか念のため再確認（プレビュー後に公開された等）
        if (!in_array($p->post_status, affiros_reschedule_allowed_statuses(), true)) {
            $failed++;
            $errors[] = "#{$id} は future/draft ではない({$p->post_status})ためスキップ";
            continue;
        }
        // プレビューから時間が経ち、新しい日時が過去になっていたら即時公開されるのでスキップ
        $new_ts = strtotime($new_date);
        if (!$new_ts || $new_ts <= $now) {
            $failed++;
            $errors[] = "#{$id} の新しい日時が過去のためスキップ（プレビューし直してください）";
            continue;
        }

        $was_draft = ($p->post_status === 'draft');

        // 取り消し用に実行前の状態を退避
        update_post_meta($id, AFFIROS_RESCHEDULE_META_STATUS, $p->post_status);
        update_post_meta($id, AFFIROS_RESCHEDULE_META_DATE,   $p->post_date);
        update_post_meta($id, AFFIROS_RESCHEDULE_META_GMT,    $p->post_date_gmt);
        update_post_meta($id, AFFIROS_RESCHEDULE_META_BATCH,  $batch);

        // edit_date => true が無いと post_date 変更が無視されるので必須
        $res = wp_update_post([
            'ID'            => $id,
            'post_date'     => $new_date,
            'post_date_gmt' => $new_gmt,
            'edit_date'     => true,
            'post_status'   => 'future', // draft もここで予約投稿に変換
        ], true);

        if (is_wp_error($res)) {
            $failed++;
            $errors[] = "#{$id}: " . $res->get_error_message();
            delete_post_meta($id, AFFIROS_RESCHEDULE_META_STATUS);
            delete_post_meta($id, AFFIROS_RESCHEDULE_META_DATE);
            delete_post_meta($id, AFFIROS_RESCHEDULE_META_GMT);
            delete_post_meta($id, AFFIROS_RESCHEDULE_META_BATCH);
        } else {
            $updated++;
            if ($was_draft) $converted++;
        }
    }

    if ($updated > 0) {
        update_option(AFFIROS_RESCHEDULE_OPT_BATCH, $batch, false);
    }

    wp_send_json_success([
        'updated'   => $updated,
        'converted' => $converted,
        'failed'    => $failed,
        'errors'    => array_slice($errors, 0, 5),
    ]);
});

/**
 * AJAX: 直前の実行を取り消す（日時・ステータスを実行前に戻す）
 */
add_action('wp_ajax_ar_undo', function () {
    check_ajax_referer('affiros_reschedule_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('権限なし');
    @set_time_limit(300);

    $batch = get_option(AFFIROS_RESCHEDULE_OPT_BATCH);
    if (!$batch) wp_send_json_error('取り消せる実行履歴がありません');

    $ids = get_posts([
        'post_type'           => 'post',
        'post_status'         => 'any',
        'posts_per_page'      => -1,
        'fields'              => 'ids',
        'ignore_sticky_posts' => true,
        'meta_key'            => AFFIROS_RESCHEDULE_META_BATCH,
        'meta_value'          => $batch,
    ]);
    if (empty($ids)) {
        delete_option(AFFIROS_RESCHEDULE_OPT_BATCH);
        wp_send_json_error('取り消し対象が見つかりませんでした');
    }

    $restored = 0;
    $failed   = 0;
    foreach ($ids as $id) {
        $prev_status = get_post_meta($id, AFFIROS_RESCHEDULE_META_STATUS, true);
        $prev_date   = get_post_meta($id, AFFIROS_RESCHEDULE_META_DATE, true);
        $prev_gmt    = get_post_meta($id, AFFIROS_RESCHEDULE_META_GMT, true);
        if (!$prev_status || !$prev_date) {
            $failed++;
            continue;
        }

        $res = wp_update_post([
            'ID'            => $id,
            'post_date'     => $prev_date,
            'post_date_gmt' => $prev_gmt ?: get_gmt_from_date($prev_date),
            'edit_date'     => true,
            'post_status'   => $prev_status,
        ], true);

        if (is_wp_error($res)) {
            $failed++;
            continue;
        }
        $restored++;
        delete_post_meta($id, AFFIROS_RESCHEDULE_META_STATUS);
        delete_post_meta($id, AFFIROS_RESCHEDULE_META_DATE);
        delete_post_meta($id, AFFIROS_RESCHEDULE_META_GMT);
        delete_post_meta($id, AFFIROS_RESCHEDULE_META_BATCH);
    }

    if ($failed === 0) {
        delete_option(AFFIROS_RESCHEDULE_OPT_BATCH);
    }

    wp_send_json_success([
        'restored' => $restored,
        'failed'   => $failed,
    ]);
});
