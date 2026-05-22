<?php
/**
 * 楽天市場API連携（IchibaItem Search API）
 * https://webservice.rakuten.co.jp/documentation/ichiba-item-search
 */

if (!defined('ABSPATH')) exit;

class AI_PI_Rakuten_API {

    private $app_id;
    private $affiliate_id;
    private $api_url = 'https://app.rakuten.co.jp/services/api/IchibaItem/Search/20220601';

    /**
     * @param array|null $config 指定時はこの配列を設定値として使う（接続テスト用）。
     *                           null なら保存済みオプションを読む。
     */
    public function __construct($config = null) {
        $settings = is_array($config) ? $config : get_option('ai_pi_settings', []);
        $this->app_id = $settings['rakuten_app_id'] ?? '';
        $this->affiliate_id = $settings['rakuten_affiliate_id'] ?? '';
    }

    public function is_configured() {
        return !empty($this->app_id);
    }

    /**
     * キーワード検索
     */
    public function search($keyword, $hits = 10) {
        if (!$this->is_configured()) {
            return new WP_Error('not_configured', '楽天市場APIが未設定');
        }

        $params = [
            'applicationId' => $this->app_id,
            'keyword' => $keyword,
            'hits' => min($hits, 30),
            'sort' => '-reviewAverage', // レビュー高評価順
            'imageFlag' => 1, // 画像ありのみ
            'availability' => 1, // 在庫ありのみ
            'format' => 'json',
            'formatVersion' => 2,
        ];

        if (!empty($this->affiliate_id)) {
            $params['affiliateId'] = $this->affiliate_id;
        }

        $url = $this->api_url . '?' . http_build_query($params);

        $response = wp_remote_get($url, [
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) return $response;

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if ($code !== 200) {
            $msg = $data['error_description'] ?? "楽天APIエラー (HTTP {$code})";
            return new WP_Error('rakuten_api_error', $msg);
        }

        return $this->parse_search_results($data);
    }

    /**
     * レスポンス解析
     */
    private function parse_search_results($data) {
        $items = $data['Items'] ?? [];
        $products = [];

        foreach ($items as $item) {
            $item_code = $item['itemCode'] ?? '';
            if (empty($item_code)) continue;

            $title = $item['itemName'] ?? '';
            $brand = $item['shopName'] ?? '';
            $price = $item['itemPrice'] ?? 0;
            $image = $item['mediumImageUrls'][0]['imageUrl']
                ?? $item['mediumImageUrls'][0]
                ?? '';

            // 画像URLのサイズ調整（_ex=128x128 → _ex=300x300）
            if (is_string($image)) {
                $image = preg_replace('/_ex=\d+x\d+/', '_ex=300x300', $image);
            } else {
                $image = '';
            }

            $rating = $item['reviewAverage'] ?? 0;
            $review_count = $item['reviewCount'] ?? 0;
            $url = $item['itemUrl'] ?? $item['affiliateUrl'] ?? '';

            $products[] = [
                'source' => 'rakuten',
                'id' => 'R_' . md5($item_code),
                'item_code' => $item_code,
                'title' => $title,
                'brand' => $brand,
                'price' => floatval($price),
                'price_display' => '¥' . number_format($price),
                'image' => $image,
                'rating' => floatval($rating),
                'review_count' => intval($review_count),
                'url' => $url,
                'fetched_at' => current_time('mysql'),
            ];
        }

        return $products;
    }
}
