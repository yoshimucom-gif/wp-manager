<?php
/**
 * 商品カードテンプレート: インラインミニカード（mini）
 *
 * @var array $product
 */
if (!defined('ABSPATH')) exit;

$asin = $product['asin'] ?? '';
$source = $product['source'] ?? '';
$display_title = AI_PI_Card_Renderer::get_display_title($product, 50);

$amazon_url = '';
$rakuten_url = '';

if ($source === 'amazon' && !empty($asin)) {
    $amazon_url = AI_PI_Card_Renderer::build_amazon_url($asin);
    $rakuten_url = AI_PI_Card_Renderer::build_rakuten_search_url($display_title);
} elseif ($source === 'rakuten') {
    $rakuten_url = $product['url'];
    $amazon_url = AI_PI_Card_Renderer::build_amazon_search_url($display_title);
}
if (!empty($product['rakuten_pair']['url'])) {
    $rakuten_url = $product['rakuten_pair']['url'];
}

$primary_url = $amazon_url ?: $rakuten_url;
?>
<span class="aipi-card aipi-card--mini">
    <?php if (!empty($product['image'])): ?>
        <span class="aipi-mini__img">
            <a href="<?php echo esc_url($primary_url); ?>" target="_blank" rel="nofollow noopener sponsored">
                <img src="<?php echo esc_url($product['image']); ?>" alt="<?php echo esc_attr($display_title); ?>" loading="lazy">
            </a>
        </span>
    <?php endif; ?>
    <span class="aipi-mini__body">
        <a href="<?php echo esc_url($primary_url); ?>" target="_blank" rel="nofollow noopener sponsored" class="aipi-mini__title">
            <?php echo esc_html($display_title); ?>
        </a>
        <?php if (!empty($product['price_display'])): ?>
            <span class="aipi-mini__price"><?php echo esc_html($product['price_display']); ?></span>
        <?php endif; ?>
    </span>
    <span class="aipi-mini__buttons">
        <?php if (!empty($amazon_url)): ?>
            <a href="<?php echo esc_url($amazon_url); ?>" target="_blank" rel="nofollow noopener sponsored" class="aipi-btn-mini aipi-btn--amazon">Amazon</a>
        <?php endif; ?>
        <?php if (!empty($rakuten_url)): ?>
            <a href="<?php echo esc_url($rakuten_url); ?>" target="_blank" rel="nofollow noopener sponsored" class="aipi-btn-mini aipi-btn--rakuten">楽天</a>
        <?php endif; ?>
    </span>
</span>
