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
}
