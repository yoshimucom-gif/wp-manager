<?php
/**
 * Claude API連携モジュール
 */

if (!defined('ABSPATH')) exit;

class AI_Deco_Claude_API {

    private $api_key;
    private $model;
    private $api_url = 'https://api.anthropic.com/v1/messages';

    /**
     * @param string|null $model 明示的に使うモデル名。null なら設定画面のデフォルトを使う
     */
    public function __construct($model = null) {
        $settings = get_option('ai_deco_settings', []);
        $this->api_key = $settings['api_key'] ?? '';
        $this->model = !empty($model) ? $model : ($settings['model'] ?? 'claude-sonnet-4-6');
    }

    public function get_model() {
        return $this->model;
    }

    public function decorate($content, $level = 'standard', $enable_faq = false) {
        if (empty($this->api_key)) {
            return new WP_Error('no_api_key', 'Claude APIキーが設定されていません');
        }

        $system_prompt = $this->build_system_prompt($level, $enable_faq);
        $user_message = "以下の記事をDBPテーマのGutenbergブロックで装飾してください。装飾済みの本文のみを返してください。前置きや説明、コードブロックの囲い（```）は不要です。\n\n---\n\n" . $content;

        $body = [
            'model' => $this->model,
            'max_tokens' => 32000,
            'system' => $system_prompt,
            'messages' => [
                ['role' => 'user', 'content' => $user_message],
            ],
        ];

        $response = wp_remote_post($this->api_url, [
            'timeout' => 180,
            'headers' => [
                'Content-Type' => 'application/json',
                'x-api-key' => $this->api_key,
                'anthropic-version' => '2023-06-01',
            ],
            'body' => wp_json_encode($body),
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body_raw = wp_remote_retrieve_body($response);
        $data = json_decode($body_raw, true);

        if ($code !== 200) {
            $error_msg = $data['error']['message'] ?? 'APIエラー（HTTP ' . $code . '）';
            return new WP_Error('api_error', $error_msg);
        }

        if (empty($data['content'][0]['text'])) {
            return new WP_Error('empty_response', 'APIから空のレスポンスが返されました');
        }

        $decorated = $data['content'][0]['text'];

        // コードフェンス除去（先頭・末尾とも、html/gutenberg等の言語指定にも対応）
        $decorated = preg_replace('/\A\s*```(?:html|gutenberg|wp)?\s*\n?/i', '', $decorated);
        $decorated = preg_replace('/\n?```\s*\z/', '', $decorated);
        $decorated = trim($decorated);

        $truncated = ($data['stop_reason'] ?? '') === 'max_tokens';

        return [
            'content' => $decorated,
            'usage' => $data['usage'] ?? [],
            'truncated' => $truncated,
            'model' => $this->model,
        ];
    }

    private function build_system_prompt($level, $enable_faq) {
        $prompt_file = AI_DECO_PATH . 'prompts/system-' . $level . '.txt';
        if (!file_exists($prompt_file)) {
            $prompt_file = AI_DECO_PATH . 'prompts/system-standard.txt';
        }
        $base = file_get_contents($prompt_file);

        if ($enable_faq) {
            $base .= "\n\n【追加指示】\n記事の末尾に、本文の内容から導けるFAQブロックを2〜3問追加してください。";
        }

        return $base;
    }
}
