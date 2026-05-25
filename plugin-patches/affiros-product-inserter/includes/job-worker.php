<?php
/**
 * Affiros プロダクトインサーター ワーカー
 *
 * WP cron で10分ごとに発火し、ジョブキューから3件処理する。
 */

if (!defined('ABSPATH')) exit;

class AI_PI_Worker {
    const TICK_HOOK      = 'ai_pi_worker_tick';
    const SCHEDULE_KEY   = 'ai_pi_every_10min';
    const ITEMS_PER_TICK = 3;
    const LOCK_KEY       = 'ai_pi_worker_lock';
    const LOCK_TTL       = 600;

    public static function init() {
        add_filter('cron_schedules', [self::class, 'register_schedule']);
        add_action(self::TICK_HOOK,  [self::class, 'run']);
        if (!wp_next_scheduled(self::TICK_HOOK)) {
            wp_schedule_event(time() + 60, self::SCHEDULE_KEY, self::TICK_HOOK);
        }
    }

    public static function register_schedule($schedules) {
        if (!isset($schedules[self::SCHEDULE_KEY])) {
            $schedules[self::SCHEDULE_KEY] = [
                'interval' => 600,
                'display'  => __('10分ごと (AI商品挿入)', 'ai-product-inserter'),
            ];
        }
        return $schedules;
    }

    public static function run() {
        AI_PI_Job_Queue::cleanup_old();

        if (get_transient(self::LOCK_KEY)) return;
        set_transient(self::LOCK_KEY, time(), self::LOCK_TTL);

        try {
            $items = AI_PI_Job_Queue::get_next_pending_items(self::ITEMS_PER_TICK);
            foreach ($items as $item) {
                self::process_one($item);
            }
        } catch (\Throwable $e) {
            // 何があってもロックは外す
        } finally {
            delete_transient(self::LOCK_KEY);
        }

        $more = AI_PI_Job_Queue::get_next_pending_items(1);
        if (!empty($more)) {
            wp_schedule_single_event(time() + 30, self::TICK_HOOK);
        }
    }

    private static function process_one($item) {
        $job = AI_PI_Job_Queue::get($item['job_id']);
        if (!$job || in_array($job['status'], ['cancelled', 'completed'], true)) return;
        $options = is_array($job['options'] ?? null) ? $job['options'] : [];
        $post_id = (int)$item['post_id'];

        @set_time_limit(180);

        $mode   = $options['insert_mode'] ?? 'marker';
        $design = $options['card_design'] ?? 'vertical';

        $result = AI_PI_Inserter::insert_into_post($post_id, [
            'insert_mode' => $mode,
            'card_design' => $design,
            'dry_run'     => false,
        ]);

        if (is_wp_error($result)) {
            AI_PI_Job_Queue::mark_item_failed(
                $item['job_id'], $item['item_idx'],
                $result->get_error_message()
            );
            return;
        }

        AI_PI_Job_Queue::mark_item_success($item['job_id'], $item['item_idx']);
    }

    public static function clear_schedule() {
        $timestamp = wp_next_scheduled(self::TICK_HOOK);
        while ($timestamp) {
            wp_unschedule_event($timestamp, self::TICK_HOOK);
            $timestamp = wp_next_scheduled(self::TICK_HOOK);
        }
    }
}
