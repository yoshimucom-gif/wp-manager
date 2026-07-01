<?php
/**
 * 自動商品挿入
 *
 * 予約投稿が公開された瞬間（または手動公開）に、マーカー入りの記事へ
 * 商品カードを自動挿入する。Cron 経由の非同期実行で公開アクション自体は
 * ブロックしない。
 *
 * 仕組み:
 *   1. transition_post_status フックで future→publish / draft→publish を検出
 *   2. マーカーありかつ未挿入の記事だったら WP Cron に「N分後」で single event 登録
 *   3. cron が発火したら AI_PI_Inserter::run($post_id) を実行
 *      （手動「商品挿入を実行」ボタンと完全に同じロジック＝品質が落ちない）
 *
 * 失敗時:
 *   - 既存挙動と同じ（マーカー残置 + verify_and_neutralize で raw マーカー隠蔽）
 *   - 自動リトライは無し（手動再実行で対応する想定）
 */

if (!defined('ABSPATH')) exit;

/**
 * 公開遷移をフック。
 * future → publish も draft → publish も両方拾うので、予約投稿でも
 * その場手動公開でも自動挿入のトリガーになる。
 */
add_action('transition_post_status', 'ai_pi_auto_insert_on_publish', 10, 3);

function ai_pi_auto_insert_on_publish($new_status, $old_status, $post) {
    if ($new_status !== 'publish') return;
    if ($old_status === 'publish') return; // すでに公開済みの保存（同期 update など）は無視
    if (!$post || $post->post_type !== 'post') return;

    $settings = get_option('ai_pi_settings', []);
    if (empty($settings['auto_insert_enabled']) || $settings['auto_insert_enabled'] !== 'yes') return;

    // マーカーチェック（エスケープされたマーカーも検出する。挿入時は
    // AI_PI_Inserter 側の rescue_escaped_markers が拾ってくれる）
    if (!preg_match('/(?:<|&lt;)!--\s*ai-product/i', (string)$post->post_content)) return;

    // 既に挿入済みなら skip（手動で実行された記事を二重処理しない）
    if (get_post_meta($post->ID, '_ai_pi_inserted', true)) return;

    // 遅延設定（デフォルト 5分後）
    $delay_min = isset($settings['auto_insert_delay_minutes'])
        ? max(0, min(60, intval($settings['auto_insert_delay_minutes'])))
        : 5;
    $run_at = time() + ($delay_min * 60);

    // 重複登録防止
    if (wp_next_scheduled('ai_pi_auto_insert_event', [$post->ID])) return;

    wp_schedule_single_event($run_at, 'ai_pi_auto_insert_event', [$post->ID]);
}

/**
 * WP Cron ハンドラ。
 * 手動の「商品挿入を実行」ボタンと同じ AI_PI_Inserter::run() を呼ぶだけ。
 * 品質を担保するために独自ロジックは入れない。
 */
add_action('ai_pi_auto_insert_event', 'ai_pi_run_auto_insert', 10, 1);

function ai_pi_run_auto_insert($post_id) {
    $post_id = intval($post_id);
    if (!$post_id) return;

    $post = get_post($post_id);
    if (!$post) return;
    if ($post->post_status !== 'publish') return;

    // 二重挿入防止: 別経路（手動実行）で先に処理された場合は何もしない
    if (get_post_meta($post_id, '_ai_pi_inserted', true)) return;

    // マーカーが残っているか念のため再確認（rescue 対象も含む）
    if (!preg_match('/(?:<|&lt;)!--\s*ai-product/i', (string)$post->post_content)) return;

    // 長時間タスクなので時間制限を緩める
    @set_time_limit(120);

    // 手動実行と完全に同じ挙動（オプション空＝設定の default_* が効く）
    $result = AI_PI_Inserter::run($post_id, []);

    if (is_wp_error($result)) {
        // ログ用にメタへ最終エラーを記録（管理画面で診断できるように）
        update_post_meta($post_id, '_ai_pi_auto_insert_last_error', $result->get_error_message());
        update_post_meta($post_id, '_ai_pi_auto_insert_last_error_at', current_time('mysql'));
        error_log('[ai-pi auto-insert] post_id=' . $post_id . ' failed: ' . $result->get_error_message());
        return;
    }

    update_post_meta($post_id, '_ai_pi_auto_insert_at', current_time('mysql'));
    delete_post_meta($post_id, '_ai_pi_auto_insert_last_error');
    delete_post_meta($post_id, '_ai_pi_auto_insert_last_error_at');
}

/**
 * プラグイン無効化時の cron イベント掃除（メイン本体から呼ばれる）
 */
function ai_pi_clear_auto_insert_crons() {
    // single event は post_id 引数別に登録される。全部当てに行く API は無いので、
    // 直近のキューを舐めて掃除する。
    $crons = _get_cron_array();
    if (!is_array($crons)) return;
    foreach ($crons as $timestamp => $hooks) {
        if (!isset($hooks['ai_pi_auto_insert_event'])) continue;
        foreach ($hooks['ai_pi_auto_insert_event'] as $key => $event) {
            $args = $event['args'] ?? [];
            wp_unschedule_event($timestamp, 'ai_pi_auto_insert_event', $args);
        }
    }
    // hourly スキャン cron も掃除
    $ts = wp_next_scheduled('ai_pi_auto_scan_event');
    if ($ts) {
        wp_unschedule_event($ts, 'ai_pi_auto_scan_event');
    }
}

// ─────────────────────────────────────────────────────────────
// 毎時スキャン cron (v1.9.26 で追加)
//
// 背景: transition_post_status フックは「WP Cron が動いた瞬間」しか発火しない。
//   アクセスが少ないサイトだと WP Cron 自体が遅延し、予約投稿の自動公開すら
//   遅れる → 自動挿入が発動しないケースがあった。
//
// 対策: 毎時 1 回、「published + マーカーあり + 未処理」の記事をスキャンして
//   自動的に AI_PI_Inserter::run() で処理する。取りこぼしゼロ保証。
//
// 動作条件:
//   - auto_insert_enabled === 'yes' （既存のトグルを流用）
//   - 1回のスキャンで処理する上限は auto_scan_limit (デフォルト 10、1〜50)
//     上限超過分は次のスキャンで処理される
//
// WP Cron が動かないと本 cron も動かないので、サーバー cron で
// wp-cron.php を定期起動しておくと確実（毎分推奨）。
// ─────────────────────────────────────────────────────────────

add_action('init', 'ai_pi_register_hourly_scan_cron');
function ai_pi_register_hourly_scan_cron() {
    // 未登録なら初回スケジュール（プラグイン更新で新機能が入った場合に自動登録）
    if (!wp_next_scheduled('ai_pi_auto_scan_event')) {
        wp_schedule_event(time() + 60, 'hourly', 'ai_pi_auto_scan_event');
    }
}

add_action('ai_pi_auto_scan_event', 'ai_pi_run_hourly_scan');

function ai_pi_run_hourly_scan() {
    $settings = get_option('ai_pi_settings', []);
    if (empty($settings['auto_insert_enabled']) || $settings['auto_insert_enabled'] !== 'yes') {
        return;
    }
    $limit = max(1, min(50, intval($settings['auto_scan_limit'] ?? 10)));

    global $wpdb;
    // published で マーカーあり (raw or entity-encoded) で 未処理・除外なし の記事
    $sql = $wpdb->prepare(
        "SELECT p.ID FROM {$wpdb->posts} p
         WHERE p.post_type = 'post'
           AND p.post_status = 'publish'
           AND (p.post_content LIKE %s OR p.post_content LIKE %s)
           AND NOT EXISTS (
             SELECT 1 FROM {$wpdb->postmeta} pm
             WHERE pm.post_id = p.ID
               AND pm.meta_key = '_ai_pi_inserted'
               AND pm.meta_value = '1'
           )
           AND NOT EXISTS (
             SELECT 1 FROM {$wpdb->postmeta} pm2
             WHERE pm2.post_id = p.ID
               AND pm2.meta_key = '_ai_pi_excluded'
           )
         ORDER BY p.ID DESC
         LIMIT %d",
        '%<!--%ai-product%',
        '%&lt;!--%ai-product%',
        $limit
    );
    $ids = $wpdb->get_col($sql);

    if (empty($ids)) {
        // 実行実績を残す（UI で「最終スキャン日時」を出すため）
        update_option('ai_pi_last_scan_at', current_time('mysql'));
        update_option('ai_pi_last_scan_processed', 0);
        return;
    }

    @set_time_limit(300);
    $processed = 0;
    foreach ($ids as $id) {
        $result = AI_PI_Inserter::run(intval($id), []);
        if (!is_wp_error($result)) {
            $processed++;
        } else {
            error_log('[ai-pi hourly-scan] post_id=' . $id . ' failed: ' . $result->get_error_message());
        }
    }
    update_option('ai_pi_last_scan_at', current_time('mysql'));
    update_option('ai_pi_last_scan_processed', $processed);
}

/**
 * 「今すぐスキャン実行」ボタン用 AJAX ハンドラ。
 * cron を待たずに hourly scan と同じ処理を即時起動できる。
 */
add_action('wp_ajax_ai_pi_manual_scan', function () {
    check_ajax_referer('ai_pi_manual_scan_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('権限がありません');
    }
    ai_pi_run_hourly_scan();
    $last_at = get_option('ai_pi_last_scan_at', '');
    $processed = intval(get_option('ai_pi_last_scan_processed', 0));
    wp_send_json_success([
        'last_scan_at' => $last_at,
        'processed' => $processed,
    ]);
});
