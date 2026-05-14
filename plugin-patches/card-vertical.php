<?php
/**
 * 商品カードテンプレート: 縦置きリンカー風（A型）
 * @var array $product 商品データ
 */
if (!defined('ABSPATH')) exit;

$asin = $product['asin'] ?? '';
$item_code = $product['item_code'] ?? '';
$source = $product['source'] ?? '';

// 表示用タイトル（楽天の販促ノイズ除去・長さ制限済み）
$display_title = AI_PI_Card_Renderer::get_display_title($product, 90);

// 商品ページへの「直リン」のみ採用する方針（検索URLは CVR が大きく落ちるため出さない）
$amazon_url = '';
$rakuten_url = '';
$yahoo_url = '';

if ($source === 'amazon' && !empty($asin)) {
    $amazon_url = AI_PI_Card_Renderer::build_amazon_url($asin);
    // 楽天ペア（同一商品の楽天版）がある場合のみ楽天ボタンを出す
    if (!empty($product['rakuten_pair']['url'])) {
        $rakuten_url = $product['rakuten_pair']['url'];
    }
} elseif ($source === 'rakuten') {
    $rakuten_url = $product['url'];
    // Amazon側に同一商品の直リンが無いため、検索URLは出さない
}
?>
<div class="aipi-card aipi-card--vertical">
    <div class="aipi-card__inner">
        <div class="aipi-card__img">
            <?php if (!empty($product['image'])): ?>
                <a href="<?php echo esc_url($amazon_url ?: $rakuten_url); ?>" target="_blank" rel="nofollow noopener sponsored">
                    <img src="<?php echo esc_url($product['image']); ?>" alt="<?php echo esc_attr($display_title); ?>" loading="lazy">
                </a>
            <?php endif; ?>
        </div>
        <div class="aipi-card__body">
            <?php if (!empty($product['brand'])): ?>
                <div class="aipi-card__brand"><?php echo esc_html($product['brand']); ?></div>
            <?php endif; ?>

            <a href="<?php echo esc_url($amazon_url ?: $rakuten_url); ?>" target="_blank" rel="nofollow noopener sponsored" class="aipi-card__title">
                <?php echo esc_html($display_title); ?>
            </a>

            <?php
            $has_rating = !empty($product['rating']) && $product['rating'] > 0;
            $has_price  = !empty($product['price_display']);
            ?>
            <?php if ($has_rating || $has_price): ?>
                <div class="aipi-card__meta">
                    <?php if ($has_rating):
                        $rating = floatval($product['rating']);
                        $full = floor($rating);
                    ?>
                        <span class="aipi-card__rating">
                            <span class="aipi-stars"><?php for ($i = 1; $i <= 5; $i++) { echo $i <= $full ? '★' : '☆'; } ?></span>
                            <span class="aipi-rating-num"><?php echo esc_html(number_format($rating, 1)); ?></span>
                            <?php if (!empty($product['review_count'])): ?>
                                <span class="aipi-review-count">（<?php echo esc_html(number_format($product['review_count'])); ?>件）</span>
                            <?php endif; ?>
                        </span>
                    <?php endif; ?>
                    <?php if ($has_price): ?>
                        <span class="aipi-card__price"><?php echo esc_html($product['price_display']); ?></span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="aipi-card__buttons">
                <?php if (!empty($amazon_url)): ?>
                    <a href="<?php echo esc_url($amazon_url); ?>" target="_blank" rel="nofollow noopener sponsored" class="aipi-btn aipi-btn--amazon">Amazon</a>
                <?php endif; ?>
                <?php if (!empty($rakuten_url)): ?>
                    <a href="<?php echo esc_url($rakuten_url); ?>" target="_blank" rel="nofollow noopener sponsored" class="aipi-btn aipi-btn--rakuten">楽天市場</a>
                <?php endif; ?>
                <?php if (!empty($yahoo_url)): ?>
                    <a href="<?php echo esc_url($yahoo_url); ?>" target="_blank" rel="nofollow noopener sponsored" class="aipi-btn aipi-btn--yahoo">Yahoo!</a>
                <?php endif; ?>
            </div>

            <?php
            $fetched_at = $product['fetched_at'] ?? '';
            if ($fetched_at):
                $date_display = date('Y年n月j日', strtotime($fetched_at));
            ?>
                <div class="aipi-card__disclaimer"><?php echo esc_html($date_display); ?>時点の情報です</div>
            <?php endif; ?>
        </div>
    </div>
</div>
