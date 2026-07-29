<?php
/**
 * 楽天市場 IchibaItem Search API (2026-05〜 新エンドポイント)
 *
 * 認証: applicationId + accessKey (両方必須)
 * エンドポイント: openapi.rakuten.co.jp/ichibams/api/IchibaItem/Search/20260401
 * Origin ヘッダー必須 (Affiros9 本体URLで統一)
 */

if (!defined('ABSPATH')) exit;

if (!class_exists('Affiros_AI_Rakuten_API')) :

class Affiros_AI_Rakuten_API {

    private $app_id;
    private $access_key;
    private $affiliate_id;

    private $api_url = 'https://openapi.rakuten.co.jp/ichibams/api/IchibaItem/Search/20260401';
    const ORIGIN = 'https://wp-manager.onrender.com';

    public function __construct($config = null) {
        $settings = is_array($config) ? $config : affiros_ai_get_settings();
        $this->app_id       = $settings['rakuten_app_id']       ?? '';
        $this->access_key   = $settings['rakuten_access_key']   ?? '';
        $this->affiliate_id = $settings['rakuten_affiliate_id'] ?? '';
    }

    public function is_configured() {
        return !empty($this->app_id) && !empty($this->access_key);
    }

    public function search($keyword, $hits = 3) {
        if (!$this->is_configured()) {
            return new WP_Error('not_configured', '楽天市場APIが未設定 (applicationId と accessKey の両方が必要)');
        }
        $params = [
            'applicationId' => $this->app_id,
            'accessKey'     => $this->access_key,
            'keyword'       => $keyword,
            'hits'          => min($hits, 30),
            'sort'          => '-reviewAverage',
            'imageFlag'     => 1,
            'availability'  => 1,
            'format'        => 'json',
            'formatVersion' => 2,
        ];
        if (!empty($this->affiliate_id)) $params['affiliateId'] = $this->affiliate_id;

        $url = $this->api_url . '?' . http_build_query($params);
        $response = wp_remote_get($url, [
            'timeout' => 30,
            'headers' => ['Origin' => self::ORIGIN, 'Accept' => 'application/json'],
        ]);
        if (is_wp_error($response)) return $response;

        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if ($code !== 200) {
            $msg = $data['error_description']
                ?? $data['error']
                ?? "楽天APIエラー (HTTP {$code})";
            return new WP_Error('rakuten_api_error', $msg);
        }
        return $this->parse_search_results($data);
    }

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
            if (is_string($image)) {
                $image = preg_replace('/_ex=\d+x\d+/', '_ex=300x300', $image);
            } else {
                $image = '';
            }
            // アフィリエイトURLを最優先 (itemUrl は素のリンクで成果にならない)
            $url = !empty($item['affiliateUrl']) ? $item['affiliateUrl'] : ($item['itemUrl'] ?? '');

            $products[] = [
                'source' => 'rakuten',
                'id' => 'R_' . md5($item_code),
                'item_code' => $item_code,
                'title' => $title,
                'brand' => $brand,
                'price' => floatval($price),
                'price_display' => '¥' . number_format($price),
                'image' => $image,
                'url' => $url,
                // 楽天はレビューデータが取れる (Amazonは取れない)。品質判定と表示に使う
                'review_count' => intval($item['reviewCount'] ?? 0),
                'review_avg'   => floatval($item['reviewAverage'] ?? 0),
                'fetched_at' => current_time('mysql'),
            ];
        }
        return $products;
    }

    public function test_connection() {
        return $this->search('テスト', 1);
    }
}

endif;
