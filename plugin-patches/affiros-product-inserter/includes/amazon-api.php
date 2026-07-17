<?php
/**
 * Amazon Creators API 連携（v3.x LWA OAuth 2.0）
 *
 * v1.9.29 (2026-07-17): Amazon が PA-API v5 を完全終了したため Creators API に移行。
 * - 認証: AWS Signature V4 → OAuth 2.0 (Login with Amazon)
 * - トークンエンドポイント: https://api.amazon.com/auth/o2/token
 * - API エンドポイント: https://creatorsapi.amazon/catalog/v1/
 * - レスポンス形式: PascalCase → lowerCamelCase
 *
 * 認証情報の取得: Amazon Associates Central → Tools → Creators API
 * 要件: アソシエイト承認済 + 直近30日で 10 件以上の適格売上
 *
 * 旧 access_key / secret_key の設定は廃止（削除はしないが使わない）。
 * 新設定: client_id / client_secret / marketplace（既定 www.amazon.co.jp）
 */

if (!defined('ABSPATH')) exit;

class AI_PI_Amazon_API {

    private $client_id;
    private $client_secret;
    private $partner_tag;
    private $marketplace;

    // v1.9.29: エンドポイント
    const TOKEN_URL   = 'https://api.amazon.com/auth/o2/token';
    const API_HOST    = 'creatorsapi.amazon';
    const API_BASE    = 'https://creatorsapi.amazon/catalog/v1';
    const OAUTH_SCOPE = 'creatorsapi::default';

    // トークンキャッシュ用 transient キー
    const TOKEN_CACHE_KEY = 'ai_pi_amazon_creators_token';

    /**
     * @param array|null $config 指定時はこの配列を設定値として使う（接続テスト用）。
     *                           null なら保存済みオプションを読む。
     */
    public function __construct($config = null) {
        $settings = is_array($config) ? $config : get_option('ai_pi_settings', []);
        $this->client_id     = $settings['amazon_creators_client_id']     ?? '';
        $this->client_secret = $settings['amazon_creators_client_secret'] ?? '';
        $this->partner_tag   = $settings['amazon_partner_tag']            ?? '';
        $this->marketplace   = $settings['amazon_marketplace']            ?? 'www.amazon.co.jp';
    }

    public function is_configured() {
        return !empty($this->client_id)
            && !empty($this->client_secret)
            && !empty($this->partner_tag);
    }

    /**
     * OAuth 2.0 access_token を取得（キャッシュ有）
     * @return string|WP_Error
     */
    private function get_access_token() {
        // 接続テスト等で config を渡した場合はキャッシュ無視で新規取得
        // （通常運用はキャッシュ利用）
        $cached = get_transient(self::TOKEN_CACHE_KEY);
        if (!empty($cached) && is_string($cached)) {
            return $cached;
        }

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
        $raw  = wp_remote_retrieve_body($response);
        $data = json_decode($raw, true);

        if ($code !== 200 || empty($data['access_token'])) {
            $msg = $data['error_description']
                ?? ($data['error'] ?? "Amazon OAuth トークン取得失敗 (HTTP {$code})");
            return new WP_Error('amazon_oauth_error', $msg);
        }

        // expires_in - 60秒 バッファでキャッシュ（デフォルト 3600 秒）
        $expires_in = intval($data['expires_in'] ?? 3600);
        $ttl = max(60, $expires_in - 60);
        set_transient(self::TOKEN_CACHE_KEY, $data['access_token'], $ttl);

        return $data['access_token'];
    }

    /**
     * キーワード検索（Creators API SearchItems）
     */
    public function search($keyword, $item_count = 10) {
        if (!$this->is_configured()) {
            return new WP_Error('not_configured', 'Amazon Creators API が未設定（Client ID / Client Secret / Partner Tag）');
        }

        $token = $this->get_access_token();
        if (is_wp_error($token)) return $token;

        // v1.9.30: 公式ドキュメント（api-reference の SearchItems 章）に基づく正しい payload。
        // 学び:
        //   - PA-API v5 の 'Offers' は 'offersV2' に改称された
        //   - partnerType, searchIndex はデフォルトで OK なので省略
        //   - resources 値は lowerCamelCase
        //   - offers.summaries.lowestPrice は Creators API に存在しない
        //     （listings のみ）
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

        $response = wp_remote_post(self::API_BASE . '/searchItems', [
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
                'x-marketplace' => $this->marketplace,
            ],
            'body' => wp_json_encode($payload),
        ]);

        if (is_wp_error($response)) return $response;

        $code = wp_remote_retrieve_response_code($response);
        $raw  = wp_remote_retrieve_body($response);
        $data = json_decode($raw, true);

        // 401 の時はキャッシュされたトークンが失効した可能性 → 一度だけリトライ
        if ($code === 401) {
            delete_transient(self::TOKEN_CACHE_KEY);
            $token2 = $this->get_access_token();
            if (is_wp_error($token2)) return $token2;
            $response = wp_remote_post(self::API_BASE . '/searchItems', [
                'timeout' => 30,
                'headers' => [
                    'Authorization' => 'Bearer ' . $token2,
                    'Content-Type'  => 'application/json',
                    'Accept'        => 'application/json',
                    'x-marketplace' => $this->marketplace,
                ],
                'body' => wp_json_encode($payload),
            ]);
            if (is_wp_error($response)) return $response;
            $code = wp_remote_retrieve_response_code($response);
            $raw  = wp_remote_retrieve_body($response);
            $data = json_decode($raw, true);
        }

        if ($code !== 200) {
            $msg = $data['errors'][0]['message']
                ?? $data['message']
                ?? "Amazon Creators API エラー (HTTP {$code})";
            return new WP_Error('amazon_api_error', $msg);
        }

        return $this->parse_search_results($data);
    }

    /**
     * レスポンス解析（Creators API 公式仕様準拠）
     *
     * v1.9.30 (2026-07-17): 公式ドキュメントに基づく正しい parser。
     * - offers → offersV2
     * - price は listings[0].price.money.amount / displayAmount
     * - detailPageURL の URL は大文字（公式レスポンス例より）
     * - customerReviews は Creators API で提供されない
     */
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

            // 価格は offersV2.listings[0].price.money.amount
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

            // detailPageURL は URL 大文字が公式（fallback で detailPageUrl も見る）
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
                // Creators API はレビュー情報を返さない
                'rating' => 0,
                'review_count' => 0,
                'url' => $detail_url,
                'fetched_at' => current_time('mysql'),
            ];
        }

        return $products;
    }

    /**
     * 接続テスト用: 1件だけ取ってみる
     */
    public function test_connection() {
        return $this->search('テスト', 1);
    }
}
