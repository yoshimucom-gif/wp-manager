<?php
/**
 * 商品カードHTMLレンダリング
 */

if (!defined('ABSPATH')) exit;

class AI_PI_Card_Renderer {

    /**
     * 商品カードHTMLを生成
     */
    public static function render($product, $design = 'vertical') {
        $template_file = AI_PI_PATH . 'templates/card-' . $design . '.php';
        if (!file_exists($template_file)) {
            $template_file = AI_PI_PATH . 'templates/card-vertical.php';
        }

        ob_start();
        include $template_file;
        return ob_get_clean();
    }

    /**
     * ランキングカードHTMLを生成
     */
    public static function render_ranking($products, $criteria = '') {
        $template_file = AI_PI_PATH . 'templates/card-ranking.php';

        ob_start();
        include $template_file;
        return ob_get_clean();
    }

    /**
     * 比較表HTMLを生成
     */
    public static function render_compare($products) {
        $template_file = AI_PI_PATH . 'templates/card-compare.php';

        ob_start();
        include $template_file;
        return ob_get_clean();
    }

    /**
     * Amazonリンクをアフィリエイト形式に変換（ASIN直リンク）
     */
    public static function build_amazon_url($asin, $partner_tag = '') {
        if (empty($partner_tag)) {
            $settings = get_option('ai_pi_settings', []);
            $partner_tag = $settings['amazon_partner_tag'] ?? '';
        }
        $url = "https://www.amazon.co.jp/dp/{$asin}/";
        if (!empty($partner_tag)) {
            $url .= '?tag=' . urlencode($partner_tag);
        }
        return $url;
    }

    /**
     * Amazon検索URLにアソシエイトタグを付与
     */
    public static function build_amazon_search_url($keyword, $partner_tag = '') {
        if (empty($partner_tag)) {
            $settings = get_option('ai_pi_settings', []);
            $partner_tag = $settings['amazon_partner_tag'] ?? '';
        }
        $url = 'https://www.amazon.co.jp/s?k=' . urlencode($keyword);
        if (!empty($partner_tag)) {
            $url .= '&tag=' . urlencode($partner_tag);
        }
        return $url;
    }

    /**
     * 楽天検索リンク生成（フォールバック用）
     */
    public static function build_rakuten_search_url($keyword) {
        $settings = get_option('ai_pi_settings', []);
        $affiliate_id = $settings['rakuten_affiliate_id'] ?? '';

        $url = 'https://search.rakuten.co.jp/search/mall/' . urlencode($keyword) . '/';

        if (!empty($affiliate_id)) {
            $url = 'https://hb.afl.rakuten.co.jp/hgc/' . $affiliate_id . '/?pc=' . urlencode($url);
        }

        return $url;
    }

    /**
     * Yahoo検索リンク
     */
    public static function build_yahoo_search_url($keyword) {
        return 'https://shopping.yahoo.co.jp/search?p=' . urlencode($keyword);
    }

    /**
     * ★ v1.2.0新規: 表示用のタイトル取得（楽天は販促ノイズ除去済みを使用、長さ制限あり）
     */
    public static function get_display_title($product, $max_length = 80) {
        $title = $product['title'] ?? '';

        // 楽天はProduct_Selectorで既にクリーン済み（title_rawに原文保持）
        // 念のため未クリーン版が入っていた場合に備えて再クリーニング
        if (($product['source'] ?? '') === 'rakuten') {
            $title = AI_PI_Product_Selector::clean_rakuten_title($title);
        }

        // 長さ制限
        if (mb_strlen($title) > $max_length) {
            $title = mb_substr($title, 0, $max_length) . '...';
        }

        return $title;
    }
}
