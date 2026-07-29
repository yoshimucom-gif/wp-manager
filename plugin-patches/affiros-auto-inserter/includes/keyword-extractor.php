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

        $text = $this->call_api($prompt, 60);
        if (is_wp_error($text)) return $text;
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

    /**
     * 検索結果の関連性フィルタ (v0.10.0)
     *
     * Amazonの検索は字面一致なので「スーツ用コート」で防虫カバーや洋服カバーが
     * 返ってくる (商品名に スーツ/コート/用 が全部含まれるため)。
     * 商品名リストを Haiku に渡し「キーワードのカテゴリそのものの商品」だけ残す。
     *
     * @param string $keyword 検索キーワード
     * @param string $article_title 記事タイトル (文脈用)
     * @param array $products 商品配列
     * @return array 通過した商品のみ。API未設定・エラー時は元のまま返す (挿入は止めない)
     */
    public function filter_relevant($keyword, $article_title, $products) {
        if (!$this->is_configured() || count($products) === 0) return $products;

        $lines = [];
        foreach (array_values($products) as $i => $p) {
            $lines[] = ($i + 1) . '. ' . mb_substr((string)($p['title'] ?? ''), 0, 80);
        }

        $prompt = "記事の商品カードに載せる商品の検品をしてください。\n\n"
            . "## 記事タイトル\n{$article_title}\n\n"
            . "## 検索キーワード\n{$keyword}\n\n"
            . "## 判定ルール\n"
            . "- キーワードが指す商品カテゴリ**そのもの**である商品だけを合格にする\n"
            . "- そのカテゴリの「カバー・ケース・収納用品・防虫剤・掃除用品・付属品・関連グッズ」は不合格\n"
            . "  (字面が似ていても別商品。例: キーワード「スーツ用コート」→ チェスターコートは合格、\n"
            . "   「スーツ・コート用 防虫カバー」「洋服カバー」は不合格)\n"
            . "- 迷ったら不合格にする (間違った商品を載せる方が機会損失より害が大きい)\n\n"
            . "## 商品リスト\n"
            . implode("\n", $lines) . "\n\n"
            . "## 出力形式\n"
            . "合格した商品の番号だけをJSON配列で。例: [1,3,5]。全滅なら []。他の文字は出力しない";

        $text = $this->call_api($prompt, 100);
        if (is_wp_error($text)) return $products; // 検品失敗時は素通し (挿入自体は止めない)

        if (!preg_match('/\[[\d,\s]*\]/', $text, $m)) return $products;
        $keep = json_decode($m[0], true);
        if (!is_array($keep)) return $products;

        $values = array_values($products);
        $filtered = [];
        foreach ($keep as $n) {
            $idx = intval($n) - 1;
            if (isset($values[$idx])) $filtered[] = $values[$idx];
        }
        return $filtered;
    }

    /** Claude API 呼び出し共通部。成功時はテキスト、失敗時は WP_Error */
    private function call_api($prompt, $max_tokens) {
        $response = wp_remote_post(self::API_URL, [
            'timeout' => 30,
            'headers' => [
                'Content-Type'      => 'application/json',
                'x-api-key'         => $this->api_key,
                'anthropic-version' => '2023-06-01',
            ],
            'body' => wp_json_encode([
                'model'      => self::MODEL,
                'max_tokens' => intval($max_tokens),
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

        return trim($data['content'][0]['text'] ?? '');
    }
}

endif;
