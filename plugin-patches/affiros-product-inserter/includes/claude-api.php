<?php
/**
 * Claude API連携
 */

if (!defined('ABSPATH')) exit;

class AI_PI_Claude_API {

    private $api_key;
    private $model;
    private $api_url = 'https://api.anthropic.com/v1/messages';

    public function __construct() {
        $settings = get_option('ai_pi_settings', []);
        $this->api_key = $settings['claude_api_key'] ?? '';
        $this->model = $settings['claude_model'] ?? 'claude-sonnet-4-6';
    }

    /**
     * 商品キーワードを抽出
     * @return array|WP_Error keywords配列
     */
    public function extract_keywords($content, $marker_count = 1) {
        $prompt_file = AI_PI_PATH . 'prompts/keyword-extraction.txt';
        $system_prompt = file_get_contents($prompt_file);

        $content_for_ai = $this->prepare_content_with_markers($content);

        $user_message = "以下の記事から商品検索用のキーワードを{$marker_count}個抽出してください。\n"
            . "本文中に [[AI_PRODUCT_MARKER_N]] という識別子があれば、その位置に商品が挿入される予定の場所です。各マーカー周辺の文脈を考慮して多様なキーワードを選んでください。\n"
            . "JSON形式で返してください: {\"keywords\": [\"キーワード1\", \"キーワード2\", ...]}\n\n"
            . "--- 記事本文 ---\n" . $content_for_ai;

        $result = $this->call_api($system_prompt, $user_message);
        if (is_wp_error($result)) return $result;

        $json = $this->parse_json($result['content']);
        if (!$json || !isset($json['keywords'])) {
            return new WP_Error('parse_error', 'キーワード抽出に失敗（JSON解析エラー）');
        }

        return [
            'keywords' => array_slice($json['keywords'], 0, $marker_count),
            'usage' => $result['usage'],
        ];
    }

    /**
     * 候補商品から最適な商品を選定（マーカーモード）
     * @return array|WP_Error
     */
    public function select_products_marker($content, $candidates, $marker_count) {
        $prompt_file = AI_PI_PATH . 'prompts/product-selection-marker.txt';
        $system_prompt = file_get_contents($prompt_file);

        $content_for_ai = $this->prepare_content_with_markers($content);

        $candidates_text = $this->format_candidates($candidates);

        $last_idx = max(0, $marker_count - 1);
        $user_message = "記事本文には [[AI_PRODUCT_MARKER_0]] 〜 [[AI_PRODUCT_MARKER_{$last_idx}]] という識別子で {$marker_count} 箇所のマーカー位置が示されています。\n"
            . "各マーカーの**前後の文脈**を読み、その流れに最も合う商品を候補から1つずつ選定してください。\n\n"
            . "JSON形式で返してください（marker_indexは 0 始まり、識別子の番号と一致させること）:\n"
            . "{\"selections\": [{\"marker_index\": 0, \"product_id\": \"候補ID\", \"reason\": \"選定理由\"}, ...]}\n\n"
            . "--- 記事本文 ---\n" . $content_for_ai
            . "\n\n--- 候補商品 ---\n" . $candidates_text;

        $result = $this->call_api($system_prompt, $user_message);
        if (is_wp_error($result)) return $result;

        $json = $this->parse_json($result['content']);
        if (!$json || !isset($json['selections'])) {
            return new WP_Error('parse_error', '商品選定に失敗（JSON解析エラー）');
        }

        return [
            'selections' => $json['selections'],
            'usage' => $result['usage'],
        ];
    }

    /**
     * ★ 新規追加: 見出し連動マーカーモード用の商品選定
     * 各マーカーが「自分の見出し」と「自分専用の候補リスト」を持つ
     *
     * @param array $marker_data [
     *     0 => ['heading' => '...', 'query' => '...', 'candidates' => [...]],
     *     1 => [...],
     * ]
     * @return array|WP_Error
     */
    public function select_products_per_heading($marker_data) {
        $prompt_file = AI_PI_PATH . 'prompts/product-selection-per-heading.txt';
        $system_prompt = file_get_contents($prompt_file);

        // マーカーごとに構造化したテキストを構築
        $marker_text = '';
        foreach ($marker_data as $idx => $md) {
            $marker_text .= "\n=== マーカー {$idx} ===\n";
            $marker_text .= "見出し: 「{$md['heading']}」\n";
            $marker_text .= "検索クエリ: 「{$md['query']}」\n";
            $marker_text .= "候補商品:\n";

            if (empty($md['candidates'])) {
                $marker_text .= "  （候補なし — このマーカーはスキップしてください）\n";
            } else {
                foreach ($md['candidates'] as $c) {
                    $marker_text .= "  [{$c['id']}] ";
                    $marker_text .= "ソース:{$c['source']} / ";
                    $marker_text .= "タイトル: {$c['title']} / ";
                    $marker_text .= "ブランド: " . ($c['brand'] ?? 'N/A') . " / ";
                    $marker_text .= "価格: ¥" . number_format($c['price'] ?? 0) . " / ";
                    $marker_text .= "レビュー: " . $this->format_review($c) . "\n";
                }
            }
        }

        $marker_count = count($marker_data);
        $user_message = "{$marker_count} 箇所のマーカーがあります。各マーカーには「直前の見出し」と「その見出しを元にAPIで取得した候補商品」が紐づいています。\n"
            . "各マーカーごとに、見出しが指し示す商品に最も合う候補を1つ選定してください。\n"
            . "※ 各マーカーの選定は、**そのマーカー専用の候補リスト**からのみ選ぶこと。\n\n"
            . "JSON形式で返してください:\n"
            . "{\"selections\": [{\"marker_index\": 0, \"product_id\": \"ID\", \"reason\": \"選定理由\"}, ...]}\n"
            . $marker_text;

        $result = $this->call_api($system_prompt, $user_message);
        if (is_wp_error($result)) return $result;

        $json = $this->parse_json($result['content']);
        if (!$json || !isset($json['selections'])) {
            return new WP_Error('parse_error', '商品選定に失敗（JSON解析エラー）');
        }

        return [
            'selections' => $json['selections'],
            'usage' => $result['usage'],
        ];
    }

    /**
     * ランキングTOP3/N選定
     */
    public function select_products_ranking($content, $candidates, $count = 3) {
        $prompt_file = AI_PI_PATH . 'prompts/product-selection-ranking.txt';
        $system_prompt = file_get_contents($prompt_file);

        $candidates_text = $this->format_candidates($candidates);

        $content_for_ai = wp_strip_all_tags($content);

        $user_message = "記事本文に最適な商品TOP{$count}をランキング形式で選定してください。\n"
            . "記事のテーマ・論調から判断軸（コスパ/性能/軽さ等）を決定し、その軸で順位を付けてください。\n\n"
            . "JSON形式で返してください:\n"
            . "{\"ranking_criteria\": \"判断軸の説明\", \"ranking\": [{\"rank\": 1, \"product_id\": \"商品ID\", \"reason\": \"選定理由\"}, ...]}\n\n"
            . "--- 記事本文 ---\n" . $content_for_ai
            . "\n\n--- 候補商品 ---\n" . $candidates_text;

        $result = $this->call_api($system_prompt, $user_message);
        if (is_wp_error($result)) return $result;

        $json = $this->parse_json($result['content']);
        if (!$json || !isset($json['ranking'])) {
            return new WP_Error('parse_error', 'ランキング選定に失敗（JSON解析エラー）');
        }

        return [
            'criteria' => $json['ranking_criteria'] ?? '',
            'ranking' => array_slice($json['ranking'], 0, $count),
            'usage' => $result['usage'],
        ];
    }

    /**
     * 本文中の <!--ai-product--> をAIが認識可能な識別子へ置換した上で
     * HTMLタグ・コメントを除去する
     */
    private function prepare_content_with_markers($content) {
        $idx = 0;
        $content = preg_replace_callback(
            '/<!--\s*ai-product(?::[a-z]+(?::\d+)?)?\s*-->/i',
            function() use (&$idx) {
                return '[[AI_PRODUCT_MARKER_' . ($idx++) . ']]';
            },
            $content
        );
        return wp_strip_all_tags($content);
    }

    /**
     * 候補商品をテキスト形式に整形
     */
    private function format_candidates($candidates) {
        $text = '';
        foreach ($candidates as $i => $c) {
            $text .= "[{$c['id']}] ";
            $text .= "ソース:{$c['source']} / ";
            $text .= "タイトル: {$c['title']} / ";
            $text .= "ブランド: " . ($c['brand'] ?? 'N/A') . " / ";
            $text .= "価格: ¥" . number_format($c['price'] ?? 0) . " / ";
            $text .= "レビュー: " . $this->format_review($c) . "\n";
        }
        return $text;
    }

    /**
     * 候補のレビュー表記を整形
     *
     * Amazon PA-API は CustomerReviews を返さないため Amazon 単独商品の
     * review_count は常に 0 になる。これを「0件＝低品質」とAIに誤読させないよう、
     * レビュー実数が無い候補は「データなし」と明示する。
     */
    private function format_review($c) {
        $count = intval($c['review_count'] ?? 0);
        if ($count > 0) {
            return '評価' . number_format(floatval($c['rating'] ?? 0), 1) . ' / ' . number_format($count) . '件';
        }
        return 'データなし';
    }

    /**
     * Claude APIを呼び出し
     */
    private function call_api($system_prompt, $user_message) {
        if (empty($this->api_key)) {
            return new WP_Error('no_api_key', 'Claude APIキーが未設定');
        }

        $body = [
            'model' => $this->model,
            'max_tokens' => 4000,
            'system' => $system_prompt,
            'messages' => [
                ['role' => 'user', 'content' => $user_message],
            ],
        ];

        $response = wp_remote_post($this->api_url, [
            'timeout' => 90,
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
            $msg = $data['error']['message'] ?? "Claude APIエラー (HTTP {$code})";
            return new WP_Error('api_error', $msg);
        }

        if (empty($data['content'][0]['text'])) {
            return new WP_Error('empty_response', 'Claude APIから空のレスポンス');
        }

        return [
            'content' => $data['content'][0]['text'],
            'usage' => $data['usage'] ?? [],
        ];
    }

    /**
     * JSON解析（コードブロック対応）
     */
    private function parse_json($text) {
        $text = preg_replace('/^```json\s*\n?/m', '', $text);
        $text = preg_replace('/^```\s*\n?/m', '', $text);
        $text = trim($text);

        if (preg_match('/\{.*\}/s', $text, $m)) {
            $text = $m[0];
        }

        return json_decode($text, true);
    }
}
