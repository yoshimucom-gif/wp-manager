<?php
/**
 * Claude API ラッパー（自動リトライ付き）
 *
 * affiros-rewrite の Affiros_Rewrite_Claude_API と同じ設計。
 * カテゴリー分類はリクエスト・レスポンスとも小さいため timeout は短め。
 */

if (!defined('ABSPATH')) {
    exit;
}

class Affiros_Cat_Claude_API {

    /** 一時的なエラー（時間を置いて再試行する）の HTTP ステータス */
    const RETRYABLE_CODES = [429, 500, 502, 503, 529];

    /** API 呼び出しの最大試行回数（初回 + リトライ） */
    const MAX_ATTEMPTS = 3;

    private $api_key;
    private $model;
    private $endpoint = 'https://api.anthropic.com/v1/messages';
    private $version = '2023-06-01';

    public function __construct() {
        $settings = affiros_cat_get_settings();
        $this->api_key = $settings['claude_api_key'] ?? '';
        $this->model = $settings['claude_model'] ?? 'claude-haiku-4-5-20251001';
    }

    public function is_configured() {
        return !empty($this->api_key);
    }

    /**
     * メッセージ送信
     *
     * 過負荷(529)・レート制限(429)・サーバエラー(5xx)・通信エラーは、
     * 時間を置いて自動的に再試行する（最大 MAX_ATTEMPTS 回）。
     *
     * @param string $prompt
     * @param int $max_tokens
     * @return array|WP_Error  成功時 ['text' => string, 'usage' => array, ...]
     */
    public function complete($prompt, $max_tokens = 400) {
        if (!$this->is_configured()) {
            return new WP_Error('not_configured', 'Claude API キーが未設定です。設定画面で入力してください。');
        }

        $body = [
            'model' => $this->model,
            'max_tokens' => $max_tokens,
            'messages' => [
                ['role' => 'user', 'content' => $prompt],
            ],
        ];
        $request_args = [
            'timeout' => 60,
            'headers' => [
                'x-api-key' => $this->api_key,
                'anthropic-version' => $this->version,
                'content-type' => 'application/json',
            ],
            'body' => wp_json_encode($body),
        ];

        $last_error = null;
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $response = wp_remote_post($this->endpoint, $request_args);

            // 通信エラー（タイムアウト・DNS等）も一時的なものとして再試行
            if (is_wp_error($response)) {
                $last_error = $response;
                if ($attempt < self::MAX_ATTEMPTS) {
                    sleep($attempt * 2);
                    continue;
                }
                return $response;
            }

            $code = wp_remote_retrieve_response_code($response);
            $body_str = wp_remote_retrieve_body($response);
            $data = json_decode($body_str, true);

            if ($code === 200) {
                $text = '';
                if (!empty($data['content']) && is_array($data['content'])) {
                    foreach ($data['content'] as $block) {
                        if (($block['type'] ?? '') === 'text') {
                            $text .= $block['text'] ?? '';
                        }
                    }
                }
                return [
                    'text' => $text,
                    'usage' => $data['usage'] ?? [],
                    'model' => $data['model'] ?? $this->model,
                    'stop_reason' => $data['stop_reason'] ?? '',
                ];
            }

            // 過負荷・レート制限・サーバエラーは時間を置いて再試行
            $msg = $data['error']['message'] ?? "Claude API エラー (HTTP {$code})";
            $last_error = new WP_Error('claude_api_error', $msg);
            if (in_array($code, self::RETRYABLE_CODES, true) && $attempt < self::MAX_ATTEMPTS) {
                $retry_after = (int) wp_remote_retrieve_header($response, 'retry-after');
                sleep($retry_after > 0 ? min($retry_after, 10) : $attempt * 2);
                continue;
            }
            return $last_error;
        }

        return $last_error ?: new WP_Error('claude_api_error', 'Claude API エラー');
    }
}
