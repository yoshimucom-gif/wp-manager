<?php
/**
 * 商品カードテンプレート: スコア（score）
 *
 * 評価スコアを大きく見せる1商品カード。「この商品の評価は？」に答える位置に置く用途。
 * レビューが取得できない商品（rating=0）ではスコア表示を省き、通常の商品カードとして描画する。
 * @var array $product 商品データ
 */
if (!defined('ABSPATH')) exit;

$asin = $product['asin'] ?? '';
$source = $product['source'] ?? '';
$display_title = AI_PI_Card_Renderer::get_display_title($product, 90);

// ハイブリッド: 直リン優先、無ければ検索URLフォールバック
$amazon_url = '';
$rakuten_url = '';

if ($source === 'amazon' && !empty($asin)) {
    $amazon_url = AI_PI_Card_Renderer::build_amazon_url($asin);
    $rakuten_url = !empty($product['rakuten_pair']['url'])
        ? $product['rakuten_pair']['url']
        : AI_PI_Card_Renderer::build_rakuten_search_url($display_title);
} elseif ($source === 'rakuten') {
    $rakuten_url = $product['url'];
    $amazon_url = !empty($product['amazon_pair']['asin'])
        ? AI_PI_Card_Renderer::build_amazon_url($product['amazon_pair']['asin'])
        : AI_PI_Card_Renderer::build_amazon_search_url($display_title);
}

$primary_url = $amazon_url ?: $rakuten_url;

$rating = !empty($product['rating']) ? floatval($product['rating']) : 0;
$review_count = !empty($product['review_count']) ? intval($product['review_count']) : 0;
$has_score = $rating > 0;
$score_pct = $has_score ? min(100, ($rating / 5) * 100) : 0;
?>
<div class="aipi-card aipi-card--score">
    <div class="aipi-score__main">
        <?php if (!empty($product['image'])): ?>
            <a class="aipi-score__img" href="<?php echo esc_url($primary_url); ?>" target="_blank" rel="nofollow noopener sponsored">
                <img src="<?php echo esc_url($product['image']); ?>" alt="<?php echo esc_attr($display_title); ?>" loading="lazy">
            </a>
        <?php endif; ?>

        <div class="aipi-score__body">
            <?php if (!empty($product['brand'])): ?>
                <div class="aipi-score__brand"><?php echo esc_html($product['brand']); ?></div>
            <?php endif; ?>
            <a class="aipi-score__title" href="<?php echo esc_url($primary_url); ?>" target="_blank" rel="nofollow noopener sponsored">
                <?php echo esc_html($display_title); ?>
            </a>
            <?php if (!empty($product['price_display'])): ?>
                <div class="aipi-score__price"><?php echo esc_html($product['price_display']); ?></div>
            <?php endif; ?>
        </div>

        <?php if ($has_score): ?>
            <div class="aipi-score__badge">
                <div class="aipi-score__num"><?php echo esc_html(number_format($rating, 1)); ?></div>
                <div class="aipi-score__max">/ 5.0</div>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($has_score): ?>
        <div class="aipi-score__gauge">
            <div class="aipi-score__bar"><span style="width:<?php echo esc_attr($score_pct); ?>%;"></span></div>
            <div class="aipi-score__stars-row">
                <span class="aipi-stars"><?php $full = floor($rating); for ($s = 1; $s <= 5; $s++) echo $s <= $full ? '★' : '☆'; ?></span>
                <?php if ($review_count > 0): ?>
                    <span class="aipi-review-count">（<?php echo esc_html(number_format($review_count)); ?>件のレビュー）</span>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="aipi-card__buttons">
        <?php if (!empty($amazon_url)): ?>
            <a href="<?php echo esc_url($amazon_url); ?>" target="_blank" rel="nofollow noopener sponsored" class="aipi-btn aipi-btn--amazon">Amazonで見る</a>
        <?php endif; ?>
        <?php if (!empty($rakuten_url)): ?>
            <a href="<?php echo esc_url($rakuten_url); ?>" target="_blank" rel="nofollow noopener sponsored" class="aipi-btn aipi-btn--rakuten">楽天市場で見る</a>
        <?php endif; ?>
    </div>

    <?php
    $fetched_at = $product['fetched_at'] ?? '';
    if ($fetched_at): ?>
        <div class="aipi-card__disclaimer"><?php echo esc_html(date('Y年n月j日', strtotime($fetched_at))); ?>時点の情報です</div>
    <?php endif; ?>
</div>
