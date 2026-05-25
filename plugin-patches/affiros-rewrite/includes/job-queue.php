<?php
/**
 * Affiros リライト ジョブキュー
 *
 * 一括リライトをバックグラウンド処理するための永続キュー。
 * WP options に JSON 構造で保存。ワーカー(Affiros_Rewrite_Worker)が
 * 10分ごとに3件ずつ処理する。
 *
 * ジョブ構造:
 *   [job_id => [
 *     'id' => string,
 *     'status' => queued|processing|completed|failed|cancelled,
 *     'created_at' => unix timestamp,
 *     'completed_at' => unix|null,
 *     'options' => [/* run() opts */],
 *     'items' => [{post_id, status, retry_count, error, completed_at}, ...],
 *     'stats' => {total, done, success, failed},
 *   ]]
 */

if (!defined('ABSPATH')) exit;

class Affiros_Rewrite_Job_Queue {
    const OPTION_KEY   = 'affiros_rewrite_jobs_v1';
    const MAX_RETRY    = 3;
    const CLEANUP_DAYS = 30;

    /** 新規ジョブを作成し job_id を返す */
    public static function create_job($post_ids, $options = []) {
        $jobs = self::all();
        $job_id = 'job_' . substr(md5(uniqid('', true)), 0, 10);
        $items = [];
        foreach ($post_ids as $pid) {
            $pid = (int)$pid;
            if ($pid <= 0) continue;
            $items[] = [
                'post_id'      => $pid,
                'post_title'   => get_the_title($pid),
                'status'       => 'pending',
                'retry_count'  => 0,
                'error'        => null,
                'completed_at' => null,
            ];
        }
        if (!$items) return null;

        $jobs[$job_id] = [
            'id'           => $job_id,
            'status'       => 'queued',
            'created_at'   => time(),
            'completed_at' => null,
            'options'      => is_array($options) ? $options : [],
            'items'        => $items,
            'stats'        => [
                'total'   => count($items),
                'done'    => 0,
                'success' => 0,
                'failed'  => 0,
            ],
        ];
        self::save_all($jobs);
        return $job_id;
    }

    public static function all() {
        $data = get_option(self::OPTION_KEY, []);
        return is_array($data) ? $data : [];
    }

    public static function get($job_id) {
        $jobs = self::all();
        return isset($jobs[$job_id]) ? $jobs[$job_id] : null;
    }

    /** ジョブを新しい順に並べて返す */
    public static function list_sorted() {
        $jobs = self::all();
        uasort($jobs, function ($a, $b) {
            return ($b['created_at'] ?? 0) - ($a['created_at'] ?? 0);
        });
        return $jobs;
    }

    /** 処理待ちのアイテムを最大 $limit 件取得 */
    public static function get_next_pending_items($limit = 3) {
        $jobs = self::all();
        $found = [];
        foreach ($jobs as $job_id => $job) {
            if (in_array($job['status'], ['completed', 'cancelled'], true)) continue;
            foreach ($job['items'] as $idx => $item) {
                if ($item['status'] === 'pending' && (int)$item['retry_count'] < self::MAX_RETRY) {
                    $found[] = [
                        'job_id'   => $job_id,
                        'item_idx' => $idx,
                        'post_id'  => (int)$item['post_id'],
                    ];
                    if (count($found) >= $limit) return $found;
                }
            }
        }
        return $found;
    }

    public static function mark_item_success($job_id, $item_idx) {
        return self::update($job_id, function ($job) use ($item_idx) {
            if (!isset($job['items'][$item_idx])) return $job;
            $job['items'][$item_idx]['status']       = 'success';
            $job['items'][$item_idx]['completed_at'] = time();
            $job['stats']['done']++;
            $job['stats']['success']++;
            return self::finalize_if_done($job);
        });
    }

    public static function mark_item_failed($job_id, $item_idx, $error_message) {
        return self::update($job_id, function ($job) use ($item_idx, $error_message) {
            if (!isset($job['items'][$item_idx])) return $job;
            $job['items'][$item_idx]['retry_count']++;
            $job['items'][$item_idx]['error'] = (string)$error_message;
            if ((int)$job['items'][$item_idx]['retry_count'] >= self::MAX_RETRY) {
                $job['items'][$item_idx]['status']       = 'failed';
                $job['items'][$item_idx]['completed_at'] = time();
                $job['stats']['done']++;
                $job['stats']['failed']++;
            }
            return self::finalize_if_done($job);
        });
    }

    public static function cancel($job_id) {
        return self::update($job_id, function ($job) {
            if (in_array($job['status'], ['completed', 'cancelled'], true)) return $job;
            $job['status']       = 'cancelled';
            $job['completed_at'] = time();
            return $job;
        });
    }

    public static function delete($job_id) {
        $jobs = self::all();
        if (!isset($jobs[$job_id])) return false;
        unset($jobs[$job_id]);
        self::save_all($jobs);
        return true;
    }

    /** 30日以上前に完了/中断/失敗したジョブを削除 */
    public static function cleanup_old() {
        $jobs = self::all();
        $cutoff = time() - (self::CLEANUP_DAYS * DAY_IN_SECONDS);
        $kept = [];
        $changed = false;
        foreach ($jobs as $id => $job) {
            $is_done = in_array($job['status'] ?? '', ['completed', 'cancelled', 'failed'], true);
            $ts = (int)($job['completed_at'] ?? $job['created_at'] ?? 0);
            if ($is_done && $ts && $ts < $cutoff) { $changed = true; continue; }
            $kept[$id] = $job;
        }
        if ($changed) self::save_all($kept);
    }

    private static function update($job_id, callable $mutator) {
        $jobs = self::all();
        if (!isset($jobs[$job_id])) return false;
        $jobs[$job_id] = call_user_func($mutator, $jobs[$job_id]);
        self::save_all($jobs);
        return true;
    }

    private static function save_all($jobs) {
        // autoload=false で大きくなっても他クエリへの影響を抑える
        update_option(self::OPTION_KEY, $jobs, false);
    }

    private static function finalize_if_done($job) {
        if ((int)$job['stats']['done'] >= (int)$job['stats']['total']) {
            $job['status']       = (int)$job['stats']['failed'] === (int)$job['stats']['total'] ? 'failed' : 'completed';
            $job['completed_at'] = time();
        } elseif ($job['status'] === 'queued') {
            $job['status'] = 'processing';
        }
        return $job;
    }
}
