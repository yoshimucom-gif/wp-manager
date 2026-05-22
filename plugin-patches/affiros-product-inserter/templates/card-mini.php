<?php
/**
 * 商品カードテンプレート: ミニ（mini）
 *
 * 軽量・コンパクトな1行カード。本文中で商品にさりげなく触れる位置に差し込む用途。
 * @var array $product 商品データ
 */
if (!defined('ABSPATH')) exit;

$asin = $product['asin'] ?? '';
$source = $product['source'] ?? '';
$display_title = AI_PI_Card_Renderer::get_display_title($product, 70);

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
?>
<div class="aipi-card aipi-card--mini">
    <?php if (!empty($product['image'])): ?>
        <a class="aipi-mini__img" href="<?php echo esc_url($primary_url); ?>" target="_blank" rel="nofollow noopener sponsored">
            <img src="<?php echo esc_url($product['image']); ?>" alt="<?php echo esc_attr($display_title); ?>" loading="lazy">
        </a>
    <?php endif; ?>

    <div class="aipi-mini__body">
        <a class="aipi-mini__title" href="<?php echo esc_url($primary_url); ?>" target="_blank" rel="nofollow noopener sponsored">
            <?php echo esc_html($display_title); ?>
        </a>
        <?php if (!empty($product['price_display'])): ?>
            <span class="aipi-mini__price"><?php echo esc_html($product['price_display']); ?></span>
        <?php endif; ?>
    </div>

    <div class="aipi-mini__buttons">
        <?php if (!empty($amazon_url)): ?>
            <a href="<?php echo esc_url($amazon_url); ?>" target="_blank" rel="nofollow noopener sponsored" class="aipi-btn-mini aipi-btn--amazon">Amazon</a>
        <?php endif; ?>
        <?php if (!empty($rakuten_url)): ?>
            <a href="<?php echo esc_url($rakuten_url); ?>" target="_blank" rel="nofollow noopener sponsored" class="aipi-btn-mini aipi-btn--rakuten">楽天</a>
        <?php endif; ?>
    </div>
</div>
