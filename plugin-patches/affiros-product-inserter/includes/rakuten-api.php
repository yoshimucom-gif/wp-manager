<?php
/**
 * 楽天市場API連携（IchibaItem Search API - 新エンドポイント v1.9.31）
 *
 * v1.9.31 (2026-07-17): 楽天が 2026-05-14 に旧エンドポイントを廃止したため
 * 全面移行。旧: app.rakuten.co.jp/services/api/IchibaItem/Search/20220601
 * 新: openapi.rakuten.co.jp/ichibams/api/IchibaItem/Search/20260401
 *
 * 変更点:
 * - ドメイン変更: app.rakuten.co.jp → openapi.rakuten.co.jp
 * - パス変更: /services/api/ → /ichibams/api/
 * - バージョン: 20220601 → 20260401
 * - 認証: applicationId のみ → applicationId + accessKey（両方必須）
 * - Origin ヘッダー必須
 *
 * 認証情報の取得: https://webservice.rakuten.co.jp/app/create
 * にて楽天アプリを再登録（旧アプリのままだと accessKey が発行されない）
 */

if (!defined('ABSPATH')) exit;

class AI_PI_Rakuten_API {

    private $app_id;
    private $access_key;   // v1.9.31 新仕様で必須
    private $affiliate_id;

    // v1.9.31: 新エンドポイント
    private $api_url = 'https://openapi.rakuten.co.jp/ichibams/api/IchibaItem/Search/20260401';

    // Origin ヘッダー（新エンドポイントで必須）
    // 楽天アプリの Allowed websites 登録を1つに集約するため、
    // 全プラグイン共通で Affiros9 本体の URL を送る。
    // これにより WordPress サイトを新規追加しても楽天側の再登録は不要。
    const ORIGIN = 'https://wp-manager.onrender.com';

    /**
     * @param array|null $config 指定時はこの配列を設定値として使う（接続テスト用）。
     *                           null なら保存済みオプションを読む。
     */
    public function __construct($config = null) {
        $settings = is_array($config) ? $config : get_option('ai_pi_settings', []);
        $this->app_id       = $settings['rakuten_app_id']       ?? '';
        $this->access_key   = $settings['rakuten_access_key']   ?? '';
        $this->affiliate_id = $settings['rakuten_affiliate_id'] ?? '';
    }

    public function is_configured() {
        // v1.9.31: accessKey も必須
        return !empty($this->app_id) && !empty($this->access_key);
    }

    /**
     * キーワード検索
     */
    public function search($keyword, $hits = 10) {
        if (!$this->is_configured()) {
            return new WP_Error(
                'not_configured',
                '楽天市場APIが未設定（applicationId と accessKey の両方が必要 - v1.9.31 新仕様）'
            );
        }

        $params = [
            'applicationId' => $this->app_id,
            'accessKey'     => $this->access_key,  // v1.9.31 新仕様で必須
            'keyword'       => $keyword,
            'hits'          => min($hits, 30),
            'sort'          => '-reviewAverage',   // レビュー高評価順
            'imageFlag'     => 1,                   // 画像ありのみ
            'availability'  => 1,                   // 在庫ありのみ
            'format'        => 'json',
            'formatVersion' => 2,
        ];

        if (!empty($this->affiliate_id)) {
            $params['affiliateId'] = $this->affiliate_id;
        }

        $url = $this->api_url . '?' . http_build_query($params);

        $response = wp_remote_get($url, [
            'timeout' => 30,
            'headers' => [
                'Origin' => self::ORIGIN,  // v1.9.31: 必須（Affiros9 本体URLに集約）
                'Accept' => 'application/json',
            ],
        ]);

        if (is_wp_error($response)) return $response;

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if ($code !== 200) {
            $msg = $data['error_description']
                ?? $data['error']
                ?? "楽天APIエラー (HTTP {$code})";
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

    /**
     * 接続テスト用
     */
    public function test_connection() {
        return $this->search('テスト', 1);
    }
}
