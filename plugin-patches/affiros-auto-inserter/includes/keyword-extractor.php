<?php
/**
 * Claude Haiku で記事本文から Amazon 検索キーワードを抽出
 *
 * コスト: 1記事あたり ¥0.3 程度 (入力2000トークン + 出力30トークン)
 *
 * 出力形式: 記事の主題である商品カテゴリ名 (+ 必要な場合のみ属性語1個)。
 *   良い例: 「数珠掛け」(ニッチ商品は単体で)
 *   良い例: 「防水スプレー 靴」
 *   悪い例: 「数珠掛け 保管 収納」(用途語で別商品=数珠袋に流れる)
 *   悪い例: 「おすすめ商品」(汎用すぎ)
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
            . "## 最重要ルール\n"
            . "- キーワードの核は「記事の主題である商品カテゴリ名」そのもの。まずそれ単体を検討する\n"
            . "- 語を足すのは、同じ商品の絞り込みに必要な場合だけ (素材・サイズ・方式など属性語を最大1個)\n"
            . "- **用途語・目的語は絶対に禁止** (保管/収納/整理/持ち運び/掃除/対策/プレゼント 等)。\n"
            . "  用途語を足すと検索が別カテゴリの商品に流れる。例: 「数珠掛け 保管 収納」で検索すると\n"
            . "  数珠掛けではなく数珠袋がヒットしてしまう。正解は「数珠掛け」単体\n"
            . "- ニッチな商品ほど語数を減らす。迷ったら商品カテゴリ名だけにする\n\n"
            . "## その他のルール\n"
            . "- ブランド名は含めない (汎用検索したいので)\n"
            . "- 「おすすめ」「人気」「ランキング」等のノイズ語は禁止\n"
            . "- 出力はキーワード文字列のみ。前置き・引用符・改行を含めない\n\n"
            . "## 悪い例\n"
            . "- 「数珠掛け 保管 収納」← 用途語のせいで数珠袋など別商品がヒットする。「数珠掛け」が正解\n"
            . "- 「防水スプレーおすすめ」← 「おすすめ」不要\n"
            . "- 「靴用の防水スプレー最強ランキング」← 助詞・ノイズ多い\n"
            . "- 「Amazon で買える防水スプレー」← ECモール名不要\n\n"
            . "## 良い例\n"
            . "- 「数珠掛け」(ニッチ商品はカテゴリ名単体)\n"
            . "- 「防水スプレー 靴」(属性語1個で同一商品を絞り込み)\n"
            . "- 「加湿器 卓上」\n\n"
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

        // 短い商品カテゴリ名単体 (「枕」「数珠」等) も許容する
        if (mb_strlen($text) < 2 || mb_strlen($text) > 60) {
            return new WP_Error('bad_keyword', "抽出結果が異常 (長さ " . mb_strlen($text) . " 文字): {$text}");
        }

        return $text;
    }
}

endif;
