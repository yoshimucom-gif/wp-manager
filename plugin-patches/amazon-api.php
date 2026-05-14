<?php
/**
 * Amazon PA-API v5 連携
 * https://webservices.amazon.co.jp/paapi5/
 */

if (!defined('ABSPATH')) exit;

class AI_PI_Amazon_API {

    private $access_key;
    private $secret_key;
    private $partner_tag;
    private $host = 'webservices.amazon.co.jp';
    private $region = 'us-west-2';
    private $marketplace = 'www.amazon.co.jp';

    public function __construct() {
        $settings = get_option('ai_pi_settings', []);
        $this->access_key = $settings['amazon_access_key'] ?? '';
        $this->secret_key = $settings['amazon_secret_key'] ?? '';
        $this->partner_tag = $settings['amazon_partner_tag'] ?? '';
    }

    public function is_configured() {
        return !empty($this->access_key) && !empty($this->secret_key) && !empty($this->partner_tag);
    }

    /**
     * キーワード検索
     */
    public function search($keyword, $item_count = 10) {
        if (!$this->is_configured()) {
            return new WP_Error('not_configured', 'Amazon PA-APIが未設定');
        }

        // CustomerReviews は 2020 年以降ほとんどのアカウントで制限されており、
        // 含めると Offers ごとレスポンスから消える既知バグがあるため除外する。
        $payload = [
            'Keywords' => $keyword,
            'Resources' => [
                'Images.Primary.Large',
                'Images.Primary.Medium',
                'ItemInfo.Title',
                'ItemInfo.ByLineInfo',
                'ItemInfo.Features',
                'Offers.Listings.Price',
                'Offers.Listings.SavingBasis',
                'Offers.Listings.Availability.Message',
                'Offers.Summaries.LowestPrice',
                'Offers.Summaries.OfferCount',
            ],
            'PartnerTag' => $this->partner_tag,
            'PartnerType' => 'Associates',
            'Marketplace' => $this->marketplace,
            'ItemCount' => min($item_count, 10),
        ];

        $result = $this->call_api('SearchItems', $payload);
        if (is_wp_error($result)) return $result;

        // デバッグ: 最初の商品の生レスポンスを保存（設定画面で表示用）
        $first_item = $result['SearchResult']['Items'][0] ?? null;
        if ($first_item) {
            set_transient('ai_pi_last_amazon_raw_sample', [
                'keyword' => $keyword,
                'asin' => $first_item['ASIN'] ?? '',
                'has_offers' => isset($first_item['Offers']),
                'has_listings' => isset($first_item['Offers']['Listings']),
                'has_summaries' => isset($first_item['Offers']['Summaries']),
                'has_reviews' => isset($first_item['CustomerReviews']),
                'raw_first_item' => wp_json_encode($first_item, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
                'fetched_at' => current_time('mysql'),
            ], 30 * MINUTE_IN_SECONDS);
        }

        return $this->parse_search_results($result);
    }

    /**
     * ASIN直接取得
     */
    public function get_items($asins) {
        if (!$this->is_configured()) {
            return new WP_Error('not_configured', 'Amazon PA-APIが未設定');
        }

        $asins = is_array($asins) ? $asins : [$asins];
        $asins = array_slice($asins, 0, 10);

        // CustomerReviews は制限により Offers を巻き込んで消えるため除外
        $payload = [
            'ItemIds' => $asins,
            'Resources' => [
                'Images.Primary.Large',
                'ItemInfo.Title',
                'ItemInfo.ByLineInfo',
                'Offers.Listings.Price',
                'Offers.Summaries.LowestPrice',
            ],
            'PartnerTag' => $this->partner_tag,
            'PartnerType' => 'Associates',
            'Marketplace' => $this->marketplace,
        ];

        $result = $this->call_api('GetItems', $payload);
        if (is_wp_error($result)) return $result;

        return $this->parse_search_results($result);
    }

    /**
     * PA-API v5を呼び出し（AWS Signature V4）
     */
    private function call_api($operation, $payload) {
        $path = '/paapi5/' . strtolower($operation);
        $target = "com.amazon.paapi5.v1.ProductAdvertisingAPIv1." . $operation;

        $payload_json = wp_json_encode($payload);
        $now = gmdate('Ymd\THis\Z');
        $today = gmdate('Ymd');

        $headers = [
            'host' => $this->host,
            'content-type' => 'application/json; charset=utf-8',
            'x-amz-date' => $now,
            'x-amz-target' => $target,
            'content-encoding' => 'amz-1.0',
        ];

        // Canonical request
        ksort($headers);
        $canonical_headers = '';
        $signed_headers_list = [];
        foreach ($headers as $k => $v) {
            $canonical_headers .= $k . ':' . $v . "\n";
            $signed_headers_list[] = $k;
        }
        $signed_headers = implode(';', $signed_headers_list);

        $payload_hash = hash('sha256', $payload_json);
        $canonical_request = "POST\n{$path}\n\n{$canonical_headers}\n{$signed_headers}\n{$payload_hash}";

        // String to sign
        $credential_scope = "{$today}/{$this->region}/ProductAdvertisingAPI/aws4_request";
        $string_to_sign = "AWS4-HMAC-SHA256\n{$now}\n{$credential_scope}\n" . hash('sha256', $canonical_request);

        // Signature
        $k_date = hash_hmac('sha256', $today, 'AWS4' . $this->secret_key, true);
        $k_region = hash_hmac('sha256', $this->region, $k_date, true);
        $k_service = hash_hmac('sha256', 'ProductAdvertisingAPI', $k_region, true);
        $k_signing = hash_hmac('sha256', 'aws4_request', $k_service, true);
        $signature = hash_hmac('sha256', $string_to_sign, $k_signing);

        $authorization = "AWS4-HMAC-SHA256 Credential={$this->access_key}/{$credential_scope}, SignedHeaders={$signed_headers}, Signature={$signature}";

        $request_headers = [
            'Host' => $this->host,
            'Content-Type' => 'application/json; charset=utf-8',
            'X-Amz-Date' => $now,
            'X-Amz-Target' => $target,
            'Content-Encoding' => 'amz-1.0',
            'Authorization' => $authorization,
        ];

        $response = wp_remote_post('https://' . $this->host . $path, [
            'timeout' => 30,
            'headers' => $request_headers,
            'body' => $payload_json,
        ]);

        if (is_wp_error($response)) return $response;

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if ($code !== 200) {
            $msg = $data['Errors'][0]['Message'] ?? "Amazon PA-APIエラー (HTTP {$code})";
            return new WP_Error('amazon_api_error', $msg);
        }

        return $data;
    }

    /**
     * レスポンス解析
     */
    private function parse_search_results($data) {
        $items = $data['SearchResult']['Items'] ?? $data['ItemsResult']['Items'] ?? [];
        $products = [];

        foreach ($items as $item) {
            $asin = $item['ASIN'] ?? '';
            if (empty($asin)) continue;

            $title = $item['ItemInfo']['Title']['DisplayValue'] ?? '';
            $brand = $item['ItemInfo']['ByLineInfo']['Brand']['DisplayValue']
                ?? $item['ItemInfo']['ByLineInfo']['Manufacturer']['DisplayValue']
                ?? '';
            // 価格抽出: Listings → Summaries → SavingBasis の順にフォールバック
            $price_amount = 0;
            $price_display = '';
            if (!empty($item['Offers']['Listings'][0]['Price']['Amount'])) {
                $price_amount = $item['Offers']['Listings'][0]['Price']['Amount'];
                $price_display = $item['Offers']['Listings'][0]['Price']['DisplayAmount'] ?? '';
            } elseif (!empty($item['Offers']['Summaries'][0]['LowestPrice']['Amount'])) {
                $price_amount = $item['Offers']['Summaries'][0]['LowestPrice']['Amount'];
                $price_display = $item['Offers']['Summaries'][0]['LowestPrice']['DisplayAmount'] ?? '';
            } elseif (!empty($item['Offers']['Listings'][0]['SavingBasis']['Amount'])) {
                $price_amount = $item['Offers']['Listings'][0]['SavingBasis']['Amount'];
                $price_display = $item['Offers']['Listings'][0]['SavingBasis']['DisplayAmount'] ?? '';
            }
            $image_large = $item['Images']['Primary']['Large']['URL'] ?? '';
            $image_medium = $item['Images']['Primary']['Medium']['URL'] ?? '';
            $rating = $item['CustomerReviews']['StarRating']['Value'] ?? 0;
            $review_count = $item['CustomerReviews']['Count']['Value'] ?? 0;
            $detail_url = $item['DetailPageURL'] ?? '';

            $products[] = [
                'source' => 'amazon',
                'id' => 'A_' . $asin,
                'asin' => $asin,
                'title' => $title,
                'brand' => $brand,
                'price' => floatval($price_amount),
                // 価格が取得できない場合は空文字（テンプレ側で非表示）
                'price_display' => $price_display ?: ($price_amount > 0 ? ('¥' . number_format($price_amount)) : ''),
                'image' => $image_large ?: $image_medium,
                'rating' => floatval($rating),
                'review_count' => intval($review_count),
                'url' => $detail_url,
                'fetched_at' => current_time('mysql'),
            ];
        }

        return $products;
    }
}
