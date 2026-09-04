<?php
/**
 * Claude API連携モジュール（装飾）
 */

if (!defined('ABSPATH')) exit;

class Decofmt_Claude_API {

    private $api_key;
    private $model;
    private $api_url = 'https://api.anthropic.com/v1/messages';

    /**
     * @param string|null $model 明示的に使うモデル名。null なら設定画面のデフォルトを使う
     */
    public function __construct($model = null) {
        $settings = get_option('decofmt_deco_settings', []);
        $this->api_key = $settings['api_key'] ?? '';
        $this->model = !empty($model) ? $model : ($settings['model'] ?? DECOFMT_DEFAULT_MODEL);
    }

    public function get_model() {
        return $this->model;
    }

    /**
     * @param string $retry_feedback 再試行時に直前の検証エラーを渡すと、修正指示としてプロンプトに加える
     */
    public function decorate($content, $level = 'standard', $enable_faq = false, $retry_feedback = '') {
        if (empty($this->api_key)) {
            return new WP_Error('no_api_key', 'Claude APIキーが設定されていません');
        }

        $system_prompt = $this->build_system_prompt($level, $enable_faq);

        $instruction = "以下の記事をDBPテーマのGutenbergブロックで装飾してください。装飾済みの本文のみを返してください。前置きや説明、コードブロックの囲い（```）は不要です。";
        if (!empty($retry_feedback)) {
            $instruction .= "\n\n⚠️【再生成】前回の装飾結果は以下の理由で不正と判定されました。今回は必ず修正してください：\n"
                . $retry_feedback
                . "\nGutenbergブロックの開始 <!-- wp:xxx --> と終了 <!-- /wp:xxx --> を必ず1対1で対応させ、<div> の開閉とブロック属性JSONの構文を厳密に守ってください。";
        }
        $user_message = $instruction . "\n\n---\n\n" . $content;

        // v1.0.24: max_tokens を記事の長さから算出する（従来は 32000 固定）。
        //   Anthropic のレート制限（OTPM: 出力トークン毎分）は「実際に生成した量」ではなく
        //   **max_tokens で予約した量**を消費する。32000 固定だと 3並列で 96,000 トークン/分を
        //   予約することになり、上限に当たって待たされ → 240秒でも足りずタイムアウト（cURL 28）
        //   していた。装飾は「元の本文＋装飾タグ」なので、入力の 2.5 倍あれば十分足りる。
        //   日本語は概ね 1文字 ≒ 1トークン。
        $input_chars = mb_strlen($content);
        $max_tokens  = (int) min(32000, max(4000, ceil($input_chars * 2.5)));
        $max_tokens  = (int) apply_filters('decofmt_max_tokens', $max_tokens, $input_chars, $this->model);

        $body = [
            'model' => $this->model,
            'max_tokens' => $max_tokens,
            'system' => $system_prompt,
            'messages' => [
                ['role' => 'user', 'content' => $user_message],
            ],
        ];

        // v1.0.23: 300 → 240秒。
        //   v1.0.17 で 180→300 に上げたが、当時はサーバー側で最大3回リトライしていたため
        //   1リクエストが最大900秒に達し、XSERVER等のプロキシに 504 Gateway Timeout で
        //   切られていた。リトライはブラウザ側に移したので1リクエスト＝API1回になったが、
        //   共有サーバーのプロキシ上限（多くは300秒前後）に対して余裕を持たせるため240秒とする。
        //   タイムアウトしてもブラウザ側が自動で再試行するので、短めでも取りこぼさない。
        //   環境に合わせて wp-config.php 等から調整可: add_filter('decofmt_api_timeout', fn() => 200);
        $timeout = (int) apply_filters('decofmt_api_timeout', 240);
        $response = wp_remote_post($this->api_url, [
            'timeout' => max(30, min(300, $timeout)),
            'headers' => [
                'Content-Type' => 'application/json',
                'x-api-key' => $this->api_key,
                'anthropic-version' => '2023-06-01',
            ],
            'body' => wp_json_encode($body),
        ]);

        if (is_wp_error($response)) {
            // タイムアウト時により分かりやすいメッセージを付与
            $msg = $response->get_error_message();
            if (stripos($msg, 'timed out') !== false || stripos($msg, 'operation timeout') !== false) {
                return new WP_Error('api_timeout',
                    'Claude APIへの接続がタイムアウトしました（' . $msg . '）。'
                    . '対処: ①記事が長すぎる場合は分割して装飾、②Haikuなど軽量モデルに切替、③時間を置いて再実行。');
            }
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
        $prompt_file = DECOFMT_PATH . 'prompts/system-' . $level . '.txt';
        if (!file_exists($prompt_file)) {
            $prompt_file = DECOFMT_PATH . 'prompts/system-standard.txt';
        }
        $base = file_get_contents($prompt_file);

        if ($enable_faq) {
            $base .= "\n\n【追加指示】\n記事の末尾に、本文の内容から導けるFAQブロックを2〜3問追加してください。";
        }

        return $base;
    }
}
