<?php
/**
 * 装飾処理の中核ロジック
 */

if (!defined('ABSPATH')) exit;

class Decofmt_Decorator {

    /**
     * 1記事を装飾
     *
     * @param int $post_id
     * @param array $options [
     *     'level' => 'light|standard|heavy',
     *     'model' => 'claude-haiku-4-5-20251001' 等。未指定なら設定のデフォルト
     *     'enable_faq' => bool,
     *     'dry_run' => bool,
     *     'max_retries' => int,      // サーバー内でのリトライ回数（既定0＝1リクエスト1API）
     *     'retry_feedback' => string, // 前回の検証エラー。クライアント再試行時に渡す
     * ]
     *
     * ★v1.0.23: サーバー内リトライは既定で行わない。
     *   以前は PHP 側で最大2回リトライしていたため、1リクエストが
     *   180秒 × 3回 = 最大540秒かかり、XSERVER等のプロキシが 504 Gateway Timeout を
     *   返していた。リトライはブラウザ側が担当し、サーバーは1回だけAPIを叩く。
     */
    public static function decorate_post($post_id, $options = []) {
        $post = get_post($post_id);
        if (!$post) {
            return new WP_Error('post_not_found', '記事が見つかりません');
        }

        $original = $post->post_content;
        if (empty(trim($original))) {
            return new WP_Error('empty_content', '本文が空です');
        }

        $settings = get_option('decofmt_deco_settings', []);
        $level = $options['level'] ?? $settings['decoration_level'] ?? 'standard';
        $enable_faq = $options['enable_faq'] ?? (($settings['enable_faq'] ?? 'no') === 'yes');
        $dry_run = $options['dry_run'] ?? false;

        $model_used = !empty($options['model']) ? $options['model'] : ($settings['model'] ?? DECOFMT_DEFAULT_MODEL);
        // 既定0＝サーバー内でリトライしない（504対策）。呼び出し側が明示すれば従来動作。
        $max_retries    = isset($options['max_retries']) ? max(0, intval($options['max_retries'])) : 0;
        $retry_feedback = (string)($options['retry_feedback'] ?? '');

        // 再装飾時のソース選択：既にバックアップ（真のオリジナル）があるならそれを使う
        $existing_backup = get_post_meta($post_id, '_decofmt_backup', true);
        $source_for_decoration = !empty($existing_backup) ? $existing_backup : $original;

        $api = new Decofmt_Claude_API($model_used);
        // クライアントから前回の検証エラーが渡っていれば、初回から修正指示として与える
        $result = $api->decorate($source_for_decoration, $level, $enable_faq, $retry_feedback);

        if (is_wp_error($result)) {
            self::log_failure($post_id, $result->get_error_message(), $model_used, $level);
            return $result;
        }

        $decorated = $result['content'];

        $validation = Decofmt_Validator::validate($source_for_decoration, $decorated);

        if (!empty($result['truncated'])) {
            $validation['warnings'][] = 'APIの出力が max_tokens で打ち切られた可能性があります（記事が長すぎる）';
            if ($validation['status'] === 'ok') {
                $validation['status'] = 'warning';
            }
        }

        // サーバー内リトライ（既定0＝行わない。max_retries を明示した場合のみ）
        $retry_count = 0;
        while ($validation['status'] === 'error' && $retry_count < $max_retries) {
            $retry_count++;
            $feedback = implode("\n", array_map(fn($e) => '・' . $e, $validation['errors']));
            $result = $api->decorate($source_for_decoration, $level, $enable_faq, $feedback);
            if (is_wp_error($result)) break;
            $decorated = $result['content'];
            $validation = Decofmt_Validator::validate($source_for_decoration, $decorated);
            if (!empty($result['truncated'])) {
                $validation['warnings'][] = 'APIの出力が max_tokens で打ち切られた可能性があります';
                if ($validation['status'] === 'ok') {
                    $validation['status'] = 'warning';
                }
            }
        }

        if ($validation['status'] === 'error') {
            self::log_failure($post_id, '装飾結果が不正：' . implode(' / ', $validation['errors']), $model_used, $level);
            // クライアントが再試行できるよう、モデルに渡すべき修正指示を data に載せて返す
            return new WP_Error(
                'validation_failed',
                '装飾失敗: ' . implode(' / ', $validation['errors']),
                [
                    'validation_errors' => $validation['errors'],
                    'retry_feedback'    => implode("\n", array_map(fn($e) => '・' . $e, $validation['errors'])),
                ]
            );
        }

        if ($dry_run) {
            return [
                'decorated' => $decorated,
                'validation' => $validation,
                'usage' => $result['usage'],
                'retry_count' => $retry_count,
                'model' => $model_used,
                'level' => $level,
            ];
        }

        // バックアップ：初回のみ保存（再装飾時は保護）
        if (empty($existing_backup)) {
            update_post_meta($post_id, '_decofmt_backup', $original);
            update_post_meta($post_id, '_decofmt_backup_at', current_time('mysql'));
        }

        // 投稿更新（save_post 再帰防止フラグ）
        set_transient('decofmt_processing_' . $post_id, 1, 300);
        $updated = wp_update_post([
            'ID' => $post_id,
            'post_content' => $decorated,
        ], true);
        delete_transient('decofmt_processing_' . $post_id);

        if (is_wp_error($updated)) {
            return $updated;
        }

        // メタ情報保存（モデル名も記録）
        update_post_meta($post_id, '_decofmt_decorated', 1);
        update_post_meta($post_id, '_decofmt_decorated_at', current_time('mysql'));
        update_post_meta($post_id, '_decofmt_status', $validation['status']);
        update_post_meta($post_id, '_decofmt_validation', $validation);
        update_post_meta($post_id, '_decofmt_level', $level);
        update_post_meta($post_id, '_decofmt_model', $model_used);
        update_post_meta($post_id, '_decofmt_retry_count', $retry_count);
        update_post_meta($post_id, '_decofmt_usage', $result['usage']);

        self::log_success($post_id, $validation['status'], $result['usage'], $model_used, $level);

        return [
            'success' => true,
            'validation' => $validation,
            'usage' => $result['usage'],
            'retry_count' => $retry_count,
            'model' => $model_used,
            'level' => $level,
        ];
    }

    public static function rollback_post($post_id) {
        $backup = get_post_meta($post_id, '_decofmt_backup', true);
        if (empty($backup)) {
            return new WP_Error('no_backup', 'バックアップが見つかりません');
        }

        set_transient('decofmt_processing_' . $post_id, 1, 300);
        $updated = wp_update_post([
            'ID' => $post_id,
            'post_content' => $backup,
        ], true);
        delete_transient('decofmt_processing_' . $post_id);

        if (is_wp_error($updated)) {
            return $updated;
        }

        delete_post_meta($post_id, '_decofmt_decorated');
        delete_post_meta($post_id, '_decofmt_decorated_at');
        delete_post_meta($post_id, '_decofmt_status');
        delete_post_meta($post_id, '_decofmt_validation');
        delete_post_meta($post_id, '_decofmt_level');
        delete_post_meta($post_id, '_decofmt_model');
        delete_post_meta($post_id, '_decofmt_retry_count');
        delete_post_meta($post_id, '_decofmt_usage');

        return ['success' => true];
    }

    private static function log_success($post_id, $status, $usage, $model = '', $level = '') {
        $logs = get_option('decofmt_deco_logs', []);
        $logs[] = [
            'timestamp' => current_time('mysql'),
            'post_id' => $post_id,
            'result' => 'success',
            'status' => $status,
            'model' => $model,
            'level' => $level,
            'input_tokens' => $usage['input_tokens'] ?? 0,
            'output_tokens' => $usage['output_tokens'] ?? 0,
        ];
        if (count($logs) > 500) {
            $logs = array_slice($logs, -500);
        }
        update_option('decofmt_deco_logs', $logs, false);
    }

    private static function log_failure($post_id, $message, $model = '', $level = '') {
        $logs = get_option('decofmt_deco_logs', []);
        $logs[] = [
            'timestamp' => current_time('mysql'),
            'post_id' => $post_id,
            'result' => 'failure',
            'model' => $model,
            'level' => $level,
            'message' => $message,
        ];
        if (count($logs) > 500) {
            $logs = array_slice($logs, -500);
        }
        update_option('decofmt_deco_logs', $logs, false);
    }
}
