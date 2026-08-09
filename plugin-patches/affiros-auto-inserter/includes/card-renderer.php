<?php
/**
 * 3商品比較カード (RINKER スタイル)
 *
 * 入力: Amazon商品リスト + 楽天商品リスト (同じキーワードで検索した結果)
 * 出力: 3列比較カード HTML (画像・タイトル・価格・ボタン)
 *
 * ボタン方針 (v0.5.0〜):
 *   Amazon 主軸のカードの楽天ボタンは、常にキーワードの検索結果一覧に飛ばす
 *   (アフィリエイトラッパー付き)。タイトル類似度による「同一商品」マッチングは
 *   日本語だと精度が出ず、別商品に誤誘導する事故が起きたため廃止した。
 */

if (!defined('ABSPATH')) exit;

if (!class_exists('Affiros_AI_Card_Renderer')) :

class Affiros_AI_Card_Renderer {

    /**
     * @param array $amazon_products Amazon商品配列 (3件)
     * @param array $rakuten_products 楽天商品配列 (任意)
     * @param array $meta 追加メタ (keyword, updated_at 等)
     * @return string HTML
     */
    public static function render($amazon_products, $rakuten_products = [], $meta = []) {
        if (empty($amazon_products) && empty($rakuten_products)) return '';

        // Amazon 主軸で組む。Amazon 商品がなければ楽天だけで組む。
        $count = max(1, min(5, intval($meta['count'] ?? 3)));
        $primary = !empty($amazon_products) ? array_slice($amazon_products, 0, $count) : array_slice($rakuten_products, 0, $count);

        $keyword_raw = trim((string)($meta['keyword'] ?? ''));
        $keyword    = esc_html($keyword_raw);
        // 価格・時点表記は規約対応 (v0.15.0) で廃止済み

        // 対応商品がない側のボタンは検索結果一覧に飛ばす (一覧経由でもアフィ成果になる)
        $ctx = self::build_search_urls($keyword_raw, $meta);

        // 見出し = 「{キーワード}」+ 接尾辞 (キーワード先頭・疑問形・根拠不要の主張ゼロ)
        $suffix = trim((string)($meta['card_heading_suffix'] ?? ''));
        if ($suffix === '') $suffix = 'はどれを選ぶ？';
        $heading = $keyword_raw !== '' ? '「' . $keyword_raw . '」' . $suffix : $suffix;

        $html  = '<!-- affiros-ai-card-start -->' . "\n";
        $html .= '<div class="affiros-ai-compare-card" data-affiros-ai="1">' . "\n";
        $html .= '  <div class="affiros-ai-card-head">' . esc_html($heading) . '</div>' . "\n";
        $html .= '  <div class="affiros-ai-card-grid">' . "\n";

        foreach ($primary as $idx => $p) {
            $html .= self::render_one_card($p, $idx + 1, $ctx);
        }

        $html .= '  </div>' . "\n";
        $html .= '</div>' . "\n";
        $html .= '<!-- affiros-ai-card-end -->' . "\n";
        return $html;
    }

    /**
     * キーワードの検索結果一覧URL (アフィリエイト付き) を組み立てる
     * @return array amazon_search_url / rakuten_search_url (キーワード空なら両方 '')
     */
    public static function build_search_urls($keyword_raw, $meta = []) {
        $ctx = ['amazon_search_url' => '', 'rakuten_search_url' => ''];
        if ($keyword_raw === '') return $ctx;

        $tag = trim((string)($meta['amazon_partner_tag'] ?? ''));
        $q = ['k' => $keyword_raw];
        if ($tag !== '') $q['tag'] = $tag;
        $ctx['amazon_search_url'] = 'https://www.amazon.co.jp/s?' . http_build_query($q);

        $raw = 'https://search.rakuten.co.jp/search/mall/' . rawurlencode($keyword_raw) . '/';
        $aff = trim((string)($meta['rakuten_affiliate_id'] ?? ''));
        $ctx['rakuten_search_url'] = $aff !== ''
            ? 'https://hb.afl.rakuten.co.jp/hgc/' . rawurlencode($aff) . '/?pc=' . rawurlencode($raw) . '&m=' . rawurlencode($raw)
            : $raw;
        return $ctx;
    }

    /**
     * サイドバー用: 商品1件のコンパクトカード
     * ショートコード [affiros_ai_top] から呼ばれる。in-content カードとは違い
     * post_content には保存されない動的出力なので start/end マーカーは付けない。
     * @param array $product 商品1件
     * @param array $meta keyword / title(見出し) / amazon_partner_tag / rakuten_affiliate_id
     */
    public static function render_single($product, $meta = []) {
        if (empty($product) || !is_array($product)) return '';

        $keyword_raw = trim((string)($meta['keyword'] ?? ''));
        $ctx = self::build_search_urls($keyword_raw, $meta);

        // 見出し = 「{キーワード}」+ 接尾辞 (title は接尾辞。'' なら見出しなし)
        $suffix = trim((string)($meta['title'] ?? ''));
        $heading = '';
        if ($suffix !== '') {
            $heading = $keyword_raw !== '' ? '「' . $keyword_raw . '」' . $suffix : $suffix;
        }
        $title = esc_html(mb_substr($product['title'] ?? '', 0, 60));
        $image = esc_url($product['image'] ?? '');
        $product_url = esc_url($product['url'] ?? '');
        $is_amazon = ($product['source'] ?? '') === 'amazon';

        if ($is_amazon) {
            $amazon_url  = $product_url;
            $rakuten_url = esc_url($ctx['rakuten_search_url']);
        } else {
            $rakuten_url = $product_url;
            $amazon_url  = esc_url($ctx['amazon_search_url']);
        }

        $html  = '<div class="affiros-ai-side-card">' . "\n";
        if ($heading !== '') {
            $html .= '  <div class="affiros-ai-side-head">' . esc_html($heading) . '</div>' . "\n";
        }
        if ($image) {
            $html .= '  <div class="affiros-ai-img"><a href="' . $product_url . '" target="_blank" rel="nofollow noopener sponsored"><img src="' . $image . '" alt="' . $title . '" loading="lazy"></a></div>' . "\n";
        }
        $html .= '  <div class="affiros-ai-title"><a href="' . $product_url . '" target="_blank" rel="nofollow noopener sponsored">' . $title . '</a></div>' . "\n";
        $rating_html = self::rating_html($product);
        if ($rating_html) {
            $html .= '  ' . $rating_html . "\n";
        }
        $html .= '  <div class="affiros-ai-btns">' . "\n";
        if ($amazon_url) {
            $html .= '    <a href="' . $amazon_url . '" target="_blank" rel="nofollow noopener sponsored" class="affiros-ai-btn affiros-ai-btn-amazon">Amazonで見る</a>' . "\n";
        }
        if ($rakuten_url) {
            $html .= '    <a href="' . $rakuten_url . '" target="_blank" rel="nofollow noopener sponsored" class="affiros-ai-btn affiros-ai-btn-rakuten">楽天市場で見る</a>' . "\n";
        }
        $html .= '  </div>' . "\n";
        $html .= '</div>' . "\n";
        return $html;
    }

    /**
     * 各カード(1商品)のHTML
     * 全カードに Amazon / 楽天 の2ボタンを必ず並べる (高さ・位置を揃えるため)。
     * 主軸でない側のボタンは検索結果一覧へのリンク (誤商品への誘導をしない)。
     * @param array $primary Amazon or 楽天の商品
     * @param int $rank
     * @param array $ctx amazon_search_url / rakuten_search_url
     */
    private static function render_one_card($primary, $rank, $ctx = []) {
        $title = esc_html(mb_substr($primary['title'] ?? '', 0, 60));
        $image = esc_url($primary['image'] ?? '');
        $brand = esc_html($primary['brand'] ?? '');
        $primary_url = esc_url($primary['url'] ?? '');
        $is_amazon = ($primary['source'] ?? '') === 'amazon';

        if ($is_amazon) {
            $amazon_url  = $primary_url;
            $rakuten_url = esc_url($ctx['rakuten_search_url'] ?? '');
        } else {
            $rakuten_url = $primary_url;
            $amazon_url  = esc_url($ctx['amazon_search_url'] ?? '');
        }

        $primary_btn = $amazon_url
            ? '<a href="' . $amazon_url . '" target="_blank" rel="nofollow noopener sponsored" class="affiros-ai-btn affiros-ai-btn-amazon">Amazonで見る</a>'
            : '';
        $secondary_btn = $rakuten_url
            ? '<a href="' . $rakuten_url . '" target="_blank" rel="nofollow noopener sponsored" class="affiros-ai-btn affiros-ai-btn-rakuten">楽天市場で見る</a>'
            : '';

        $html  = '    <div class="affiros-ai-item">' . "\n";
        $html .= '      <div class="affiros-ai-rank">' . intval($rank) . '</div>' . "\n";
        if ($image) {
            $html .= '      <div class="affiros-ai-img"><a href="' . $primary_url . '" target="_blank" rel="nofollow noopener sponsored"><img src="' . $image . '" alt="' . $title . '" loading="lazy"></a></div>' . "\n";
        }
        $html .= '      <div class="affiros-ai-title"><a href="' . $primary_url . '" target="_blank" rel="nofollow noopener sponsored">' . $title . '</a></div>' . "\n";
        if ($brand) {
            $html .= '      <div class="affiros-ai-brand">' . $brand . '</div>' . "\n";
        }
        $rating_html = self::rating_html($primary);
        if ($rating_html) {
            $html .= '      ' . $rating_html . "\n";
        }
        $html .= '      <div class="affiros-ai-btns">' . "\n";
        $html .= '        ' . $primary_btn . "\n";
        if ($secondary_btn) {
            $html .= '        ' . $secondary_btn . "\n";
        }
        $html .= '      </div>' . "\n";
        $html .= '    </div>' . "\n";
        return $html;
    }

    /**
     * レビュー表示 (★4.3 (123件))。楽天商品のみデータがある。
     * Amazon は Creators API がレビューを返さないため表示なし。
     */
    private static function rating_html($product) {
        $avg = floatval($product['review_avg'] ?? 0);
        $cnt = intval($product['review_count'] ?? 0);
        if ($avg <= 0 || $cnt <= 0) return '';
        return '<div class="affiros-ai-rating">★' . esc_html(number_format($avg, 1))
            . ' <span>(' . esc_html(number_format($cnt)) . '件)</span></div>';
    }

    public static function tokenize($str) {
        $str = mb_strtolower((string)$str);
        // 記号を空白に
        $str = preg_replace('/[「」【】\[\]\(\)（）,、。・\/\-_]+/u', ' ', $str);
        $parts = preg_split('/\s+/u', trim($str));
        // 2文字未満/長すぎるトークンは除外 (ノイズ削減)
        return array_values(array_filter($parts, function ($t) {
            return mb_strlen($t) >= 2 && mb_strlen($t) <= 20;
        }));
    }
}

endif;
