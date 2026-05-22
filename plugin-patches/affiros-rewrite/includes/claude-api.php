<?php
/**
 * Claude API ラッパー
 */

if (!defined('ABSPATH')) exit;

class Affiros_Rewrite_Claude_API {

    private $api_key;
    private $model;
    private $endpoint = 'https://api.anthropic.com/v1/messages';
    private $version = '2023-06-01';

    public function __construct() {
        $settings = affiros_rewrite_get_settings();
        $this->api_key = $settings['claude_api_key'] ?? '';
        $this->model = $settings['claude_model'] ?? 'claude-sonnet-4-6';
    }

    public function is_configured() {
        return !empty($this->api_key);
    }

    /**
     * メッセージ送信
     *
     * @param string $prompt
     * @param int $max_tokens
     * @return array|WP_Error
     */
    public function complete($prompt, $max_tokens = 8000) {
        if (!$this->is_configured()) {
            return new WP_Error('not_configured', 'Claude APIキーが未設定です。設定画面で入力してください。');
        }

        $body = [
            'model' => $this->model,
            'max_tokens' => $max_tokens,
            'messages' => [
                ['role' => 'user', 'content' => $prompt],
            ],
        ];

        $response = wp_remote_post($this->endpoint, [
            'timeout' => 180,
            'headers' => [
                'x-api-key' => $this->api_key,
                'anthropic-version' => $this->version,
                'content-type' => 'application/json',
            ],
            'body' => wp_json_encode($body),
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body_str = wp_remote_retrieve_body($response);
        $data = json_decode($body_str, true);

        if ($code !== 200) {
            $msg = $data['error']['message'] ?? "Claude APIエラー (HTTP {$code})";
            return new WP_Error('claude_api_error', $msg);
        }

        // テキスト抽出
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
}
