<?php
/**
 * 3商品比較カード (RINKER スタイル)
 *
 * 入力: Amazon商品リスト + 楽天商品リスト (同じキーワードで検索した結果)
 * 出力: 3列比較カード HTML (画像・タイトル・価格・ボタン)
 *
 * マッチング戦略:
 *   Amazon の上位3件を主軸に。同じキーワードで楽天も検索し、
 *   タイトル類似度で対応する楽天商品を各カードに紐付ける。
 *   楽天の対応商品が見つからなければ Amazon ボタンのみ。
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
        $secondary_map = !empty($amazon_products) ? self::map_rakuten_to_amazon($primary, $rakuten_products) : [];

        $keyword_raw = trim((string)($meta['keyword'] ?? ''));
        $keyword    = esc_html($keyword_raw);
        $updated_at = esc_html($meta['updated_at'] ?? current_time('mysql'));

        // 対応商品がない側のボタンは検索結果一覧に飛ばす (一覧経由でもアフィ成果になる)
        $ctx = ['amazon_search_url' => '', 'rakuten_search_url' => ''];
        if ($keyword_raw !== '') {
            $tag = trim((string)($meta['amazon_partner_tag'] ?? ''));
            $q = ['k' => $keyword_raw];
            if ($tag !== '') $q['tag'] = $tag;
            $ctx['amazon_search_url'] = 'https://www.amazon.co.jp/s?' . http_build_query($q);

            $raw = 'https://search.rakuten.co.jp/search/mall/' . rawurlencode($keyword_raw) . '/';
            $aff = trim((string)($meta['rakuten_affiliate_id'] ?? ''));
            $ctx['rakuten_search_url'] = $aff !== ''
                ? 'https://hb.afl.rakuten.co.jp/hgc/' . rawurlencode($aff) . '/?pc=' . rawurlencode($raw) . '&m=' . rawurlencode($raw)
                : $raw;
        }

        $html  = '<!-- affiros-ai-card-start -->' . "\n";
        $html .= '<div class="affiros-ai-compare-card" data-affiros-ai="1">' . "\n";
        $html .= '  <div class="affiros-ai-card-head">おすすめ商品比較' . ($keyword ? ' <span class="affiros-ai-kw">「' . $keyword . '」で厳選</span>' : '') . '</div>' . "\n";
        $html .= '  <div class="affiros-ai-card-grid">' . "\n";

        foreach ($primary as $idx => $p) {
            $rakuten_partner = $secondary_map[$idx] ?? null;
            $html .= self::render_one_card($p, $rakuten_partner, $idx + 1, $ctx);
        }

        $html .= '  </div>' . "\n";
        $html .= '  <div class="affiros-ai-card-foot"><small>' . $updated_at . ' 時点。価格・在庫は変動します。</small></div>' . "\n";
        $html .= '</div>' . "\n";
        $html .= '<!-- affiros-ai-card-end -->' . "\n";
        return $html;
    }

    /**
     * 各カード(1商品)のHTML
     * 全カードに Amazon / 楽天 の2ボタンを必ず並べる (高さ・位置を揃えるため)。
     * 対応商品がない側は検索結果一覧へのリンクにフォールバック。
     * @param array $primary Amazon or 楽天の商品
     * @param array|null $rakuten_partner primary が Amazon の時、対応する楽天
     * @param int $rank
     * @param array $ctx amazon_search_url / rakuten_search_url
     */
    private static function render_one_card($primary, $rakuten_partner, $rank, $ctx = []) {
        $title = esc_html(mb_substr($primary['title'] ?? '', 0, 60));
        $image = esc_url($primary['image'] ?? '');
        $price = esc_html($primary['price_display'] ?? '');
        $brand = esc_html($primary['brand'] ?? '');
        $primary_url = esc_url($primary['url'] ?? '');
        $is_amazon = ($primary['source'] ?? '') === 'amazon';

        if ($is_amazon) {
            $amazon_url  = $primary_url;
            $rakuten_url = ($rakuten_partner && !empty($rakuten_partner['url']))
                ? esc_url($rakuten_partner['url'])
                : esc_url($ctx['rakuten_search_url'] ?? '');
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
        if ($price) {
            $html .= '      <div class="affiros-ai-price">' . $price . '</div>' . "\n";
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
     * Amazon 各商品に楽天の似た商品を紐付ける。タイトルの共通語トークンで簡易マッチ。
     * 完璧なマッチングは不要 — マッチしなければ Amazon ボタンだけ表示すればいい。
     */
    private static function map_rakuten_to_amazon($amazon_products, $rakuten_products) {
        $map = [];
        if (empty($rakuten_products)) return $map;

        $used_rakuten = [];
        foreach ($amazon_products as $i => $a) {
            $a_tokens = self::tokenize($a['title'] ?? '');
            $best_score = 0;
            $best_j = -1;
            foreach ($rakuten_products as $j => $r) {
                if (in_array($j, $used_rakuten, true)) continue;
                $r_tokens = self::tokenize($r['title'] ?? '');
                $score = count(array_intersect($a_tokens, $r_tokens));
                if ($score > $best_score) {
                    $best_score = $score;
                    $best_j = $j;
                }
            }
            if ($best_score >= 2 && $best_j >= 0) {
                $map[$i] = $rakuten_products[$best_j];
                $used_rakuten[] = $best_j;
            }
        }
        return $map;
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
