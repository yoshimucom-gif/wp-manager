<?php
/**
 * Claude Haiku で記事本文から Amazon 検索キーワードを抽出
 *
 * コスト: 1記事あたり ¥0.3 程度 (入力2000トークン + 出力30トークン)
 *
 * 出力形式: 「ブランド名/商品カテゴリ 特徴語1個」の 15〜30文字。
 *   良い例: 「防水スプレー 靴 撥水」
 *   良い例: 「アームカバー UV 冷感」
 *   悪い例: 「おすすめ商品」(汎用すぎ)
 *   悪い例: 「Amazon で買える最高の商品」(ノイズ)
 */

if (!defined('ABSPATH')) exit;

if (!class_exists('Affiros_AI_Keyword_Extractor')) :

class Affiros_AI_Keyword_Extractor {

    private $api_key;
    const MODEL = 'claude-haiku-4-5-20251001';
    const API_URL = 'https://api.anthropic.com/v1/messages';

    public function __construct($config = null) {
        $settings = is_array($config) ? $config : affiros_ai_get_settings();
        $this->api_key = $settings['claude_api_key'] ?? '';
    }

    public function is_configured() {
        return !empty($this->api_key);
    }

    /**
     * 記事本文 + タイトルから商品検索キーワードを抽出
     * @param string $title 記事タイトル
     * @param string $content 記事本文HTML
     * @return string|WP_Error 検索キーワード (15〜30文字目安)
     */
    public function extract($title, $content) {
        if (!$this->is_configured()) {
            return new WP_Error('not_configured', 'Claude API キーが未設定');
        }

        // HTML タグを剥がして最大 3000 文字に切り詰め (Haiku 入力削減)
        $plain = trim(wp_strip_all_tags($content));
        if (mb_strlen($plain) > 3000) $plain = mb_substr($plain, 0, 3000) . '...';

        $prompt = "以下の記事に対して、Amazon 商品検索に使う最適なキーワードを1つ出力してください。\n\n"
            . "## ルール\n"
            . "- 15〜30文字\n"
            . "- 「商品カテゴリ + 特徴語1〜2個」の形式 (例: 「防水スプレー 靴 撥水」)\n"
            . "- ブランド名は含めない (汎用検索したいので)\n"
            . "- 「おすすめ」「人気」「ランキング」等のノイズ語は禁止\n"
            . "- 記事の主題そのものを検索できる語にする\n"
            . "- 出力はキーワード文字列のみ。前置き・引用符・改行を含めない\n\n"
            . "## 悪い例\n"
            . "- 「防水スプレーおすすめ」← 「おすすめ」不要\n"
            . "- 「靴用の防水スプレー最強ランキング」← 助詞・ノイズ多い\n"
            . "- 「Amazon で買える防水スプレー」← ECモール名不要\n\n"
            . "## 良い例\n"
            . "- 「防水スプレー 靴 撥水」\n"
            . "- 「アームカバー UV 冷感」\n"
            . "- 「加湿器 卓上 USB」\n\n"
            . "## 記事タイトル\n"
            . $title . "\n\n"
            . "## 記事本文 (先頭3000字)\n"
            . $plain;

        $response = wp_remote_post(self::API_URL, [
            'timeout' => 30,
            'headers' => [
                'Content-Type'      => 'application/json',
                'x-api-key'         => $this->api_key,
                'anthropic-version' => '2023-06-01',
            ],
            'body' => wp_json_encode([
                'model'      => self::MODEL,
                'max_tokens' => 60,
                'messages'   => [
                    ['role' => 'user', 'content' => $prompt],
                ],
            ]),
        ]);

        if (is_wp_error($response)) return $response;

        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200) {
            $msg = $data['error']['message']
                ?? "Claude API エラー (HTTP {$code})";
            return new WP_Error('claude_error', $msg);
        }

        $text = $data['content'][0]['text'] ?? '';
        $text = trim($text);
        // 改行や引用符が混ざるケースを除去
        $text = preg_replace('/^["「『【]+|["」』】]+$/u', '', $text);
        $text = preg_replace('/[\r\n]+/', ' ', $text);
        $text = trim($text);

        if (mb_strlen($text) < 4 || mb_strlen($text) > 60) {
            return new WP_Error('bad_keyword', "抽出結果が異常 (長さ " . mb_strlen($text) . " 文字): {$text}");
        }

        return $text;
    }
}

endif;
