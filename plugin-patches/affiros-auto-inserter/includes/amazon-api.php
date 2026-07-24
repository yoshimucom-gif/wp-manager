<?php
/**
 * Amazon Creators API (LWA OAuth 2.0) 連携
 *
 * affiros-product-inserter の実装をベースにコピー。
 * クラス名を Affiros_AI_Amazon_API に変更（competition防止）。
 *
 * 認証情報の取得: Amazon Associates Central → Tools → Creators API
 * 要件: アソシエイト承認済 + 直近30日で 10 件以上の適格売上
 */

if (!defined('ABSPATH')) exit;

if (!class_exists('Affiros_AI_Amazon_API')) :

class Affiros_AI_Amazon_API {

    private $client_id;
    private $client_secret;
    private $partner_tag;
    private $marketplace;

    const TOKEN_URL   = 'https://api.amazon.com/auth/o2/token';
    const API_BASE    = 'https://creatorsapi.amazon/catalog/v1';
    const OAUTH_SCOPE = 'creatorsapi::default';
    const TOKEN_CACHE_KEY = 'affiros_ai_amazon_token';

    public function __construct($config = null) {
        $settings = is_array($config) ? $config : affiros_ai_get_settings();
        $this->client_id     = $settings['amazon_client_id']     ?? '';
        $this->client_secret = $settings['amazon_client_secret'] ?? '';
        $this->partner_tag   = $settings['amazon_partner_tag']   ?? '';
        $this->marketplace   = $settings['amazon_marketplace']   ?? 'www.amazon.co.jp';
    }

    public function is_configured() {
        return !empty($this->client_id)
            && !empty($this->client_secret)
            && !empty($this->partner_tag);
    }

    private function get_access_token() {
        $cached = get_transient(self::TOKEN_CACHE_KEY);
        if (!empty($cached) && is_string($cached)) return $cached;

        $body = wp_json_encode([
            'grant_type'    => 'client_credentials',
            'client_id'     => $this->client_id,
            'client_secret' => $this->client_secret,
            'scope'         => self::OAUTH_SCOPE,
        ]);

        $response = wp_remote_post(self::TOKEN_URL, [
            'timeout' => 15,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ],
            'body' => $body,
        ]);

        if (is_wp_error($response)) return $response;

        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200 || empty($data['access_token'])) {
            $msg = $data['error_description']
                ?? ($data['error'] ?? "Amazon OAuth トークン取得失敗 (HTTP {$code})");
            return new WP_Error('amazon_oauth_error', $msg);
        }

        $expires_in = intval($data['expires_in'] ?? 3600);
        set_transient(self::TOKEN_CACHE_KEY, $data['access_token'], max(60, $expires_in - 60));
        return $data['access_token'];
    }

    /**
     * キーワードで商品検索 → 上位N件を配列で返す
     * @param string $keyword
     * @param int $item_count 最大10
     * @return array|WP_Error
     */
    public function search($keyword, $item_count = 3) {
        if (!$this->is_configured()) {
            return new WP_Error('not_configured', 'Amazon Creators API が未設定 (Client ID / Client Secret / Partner Tag)');
        }
        $token = $this->get_access_token();
        if (is_wp_error($token)) return $token;

        $payload = [
            'keywords'   => (string)$keyword,
            'itemCount'  => min(10, max(1, intval($item_count))),
            'partnerTag' => $this->partner_tag,
            'resources'  => [
                'images.primary.medium',
                'images.primary.large',
                'itemInfo.title',
                'itemInfo.byLineInfo',
                'itemInfo.features',
                'offersV2.listings.price',
            ],
        ];

        $response = $this->post_with_retry($token, '/searchItems', $payload);
        if (is_wp_error($response)) return $response;

        return $this->parse_search_results($response);
    }

    private function post_with_retry($token, $endpoint, $payload) {
        $do_post = function ($tok) use ($endpoint, $payload) {
            return wp_remote_post(self::API_BASE . $endpoint, [
                'timeout' => 30,
                'headers' => [
                    'Authorization' => 'Bearer ' . $tok,
                    'Content-Type'  => 'application/json',
                    'Accept'        => 'application/json',
                    'x-marketplace' => $this->marketplace,
                ],
                'body' => wp_json_encode($payload),
            ]);
        };
        $response = $do_post($token);
        if (is_wp_error($response)) return $response;
        $code = wp_remote_retrieve_response_code($response);
        if ($code === 401) {
            delete_transient(self::TOKEN_CACHE_KEY);
            $token2 = $this->get_access_token();
            if (is_wp_error($token2)) return $token2;
            $response = $do_post($token2);
            if (is_wp_error($response)) return $response;
            $code = wp_remote_retrieve_response_code($response);
        }
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if ($code !== 200) {
            $msg = $data['errors'][0]['message']
                ?? $data['message']
                ?? "Amazon Creators API エラー (HTTP {$code})";
            return new WP_Error('amazon_api_error', $msg);
        }
        return $data;
    }

    private function parse_search_results($data) {
        $items = $data['searchResult']['items'] ?? [];
        $products = [];

        foreach ($items as $item) {
            $asin = $item['asin'] ?? '';
            if (empty($asin)) continue;

            $title = $item['itemInfo']['title']['displayValue'] ?? '';
            $brand = $item['itemInfo']['byLineInfo']['brand']['displayValue']
                ?? $item['itemInfo']['byLineInfo']['manufacturer']['displayValue']
                ?? '';

            $price_amount = 0;
            $price_display = '';
            $listings = $item['offersV2']['listings'] ?? [];
            if (!empty($listings[0]['price']['money'])) {
                $money = $listings[0]['price']['money'];
                $price_amount = $money['amount'] ?? 0;
                $price_display = $money['displayAmount'] ?? '';
            }

            $image_medium = $item['images']['primary']['medium']['url'] ?? '';
            $image_large  = $item['images']['primary']['large']['url'] ?? '';

            $detail_url = $item['detailPageURL']
                ?? $item['detailPageUrl']
                ?? '';

            $products[] = [
                'source' => 'amazon',
                'id' => 'A_' . $asin,
                'asin' => $asin,
                'title' => $title,
                'brand' => $brand,
                'price' => floatval($price_amount),
                'price_display' => $price_display ?: ($price_amount > 0 ? ('¥' . number_format($price_amount)) : ''),
                'image' => $image_large ?: $image_medium,
                'url' => $detail_url,
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
