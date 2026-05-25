<?php
/**
 * Affiros リライト ワーカー
 *
 * WP cron で10分ごとに発火し、ジョブキューから3件処理する。
 * 画面を閉じていてもバックグラウンドで進行する。
 *
 * 注: WP cron はサイトアクセスで発火するため、トラフィックが少ないサイトは
 *     サーバー側のシステム cron で wp-cron.php を定期的に叩くことを推奨。
 */

if (!defined('ABSPATH')) exit;

class Affiros_Rewrite_Worker {
    const TICK_HOOK     = 'affiros_rewrite_worker_tick';
    const SCHEDULE_KEY  = 'affiros_rewrite_every_10min';
    const ITEMS_PER_TICK = 3;
    const LOCK_KEY      = 'affiros_rewrite_worker_lock';
    const LOCK_TTL      = 600; // 10分

    public static function init() {
        // 10分間隔のカスタムスケジュール
        add_filter('cron_schedules', [self::class, 'register_schedule']);
        add_action(self::TICK_HOOK,  [self::class, 'run']);

        // スケジュール未登録なら登録
        if (!wp_next_scheduled(self::TICK_HOOK)) {
            wp_schedule_event(time() + 60, self::SCHEDULE_KEY, self::TICK_HOOK);
        }
    }

    public static function register_schedule($schedules) {
        if (!isset($schedules[self::SCHEDULE_KEY])) {
            $schedules[self::SCHEDULE_KEY] = [
                'interval' => 600,
                'display'  => __('10分ごと (Affiros リライト)', 'affiros-rewrite'),
            ];
        }
        return $schedules;
    }

    /** ワーカー本体 */
    public static function run() {
        Affiros_Rewrite_Job_Queue::cleanup_old();

        // 同時実行ロック
        if (get_transient(self::LOCK_KEY)) return;
        set_transient(self::LOCK_KEY, time(), self::LOCK_TTL);

        try {
            $items = Affiros_Rewrite_Job_Queue::get_next_pending_items(self::ITEMS_PER_TICK);
            foreach ($items as $item) {
                self::process_one($item);
            }
        } catch (\Throwable $e) {
            // 何があってもロックは外す
        } finally {
            delete_transient(self::LOCK_KEY);
        }

        // 次にまだ pending があるなら 30秒後に再発火（待ちが長すぎる時のため）
        $more = Affiros_Rewrite_Job_Queue::get_next_pending_items(1);
        if (!empty($more)) {
            wp_schedule_single_event(time() + 30, self::TICK_HOOK);
        }
    }

    private static function process_one($item) {
        $job = Affiros_Rewrite_Job_Queue::get($item['job_id']);
        if (!$job || in_array($job['status'], ['cancelled', 'completed'], true)) return;
        $options = is_array($job['options'] ?? null) ? $job['options'] : [];
        $post_id = (int)$item['post_id'];

        // PHP 実行時間を伸ばす
        @set_time_limit(180);

        // リライト実行
        $result = Affiros_Rewrite_Engine::run($post_id, $options);
        if (is_wp_error($result)) {
            Affiros_Rewrite_Job_Queue::mark_item_failed(
                $item['job_id'], $item['item_idx'],
                $result->get_error_message()
            );
            return;
        }

        // WP 投稿に保存
        $save = Affiros_Rewrite_Post_Fetcher::update_post(
            $post_id,
            $result['rewritten_content'] ?? '',
            $result['rewritten_title'] ?? null
        );
        if (is_wp_error($save)) {
            Affiros_Rewrite_Job_Queue::mark_item_failed(
                $item['job_id'], $item['item_idx'],
                $save->get_error_message()
            );
            return;
        }

        Affiros_Rewrite_Job_Queue::mark_item_success($item['job_id'], $item['item_idx']);
    }

    /** プラグイン deactivate 時に cron を解除する用 */
    public static function clear_schedule() {
        $timestamp = wp_next_scheduled(self::TICK_HOOK);
        while ($timestamp) {
            wp_unschedule_event($timestamp, self::TICK_HOOK);
            $timestamp = wp_next_scheduled(self::TICK_HOOK);
        }
    }
}
