<?php
/**
 * 商品カードテンプレート: 総合評価（score）
 *
 * @var array $product
 */
if (!defined('ABSPATH')) exit;

$asin = $product['asin'] ?? '';
$source = $product['source'] ?? '';
$display_title = AI_PI_Card_Renderer::get_display_title($product, 90);

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

// 評価軸を product データから推定
// 4軸: 性能 / コスパ / 使いやすさ / 満足度
$base = !empty($product['rating']) ? floatval($product['rating']) : 4.0;
$price = !empty($product['price']) ? intval($product['price']) : 0;
$review_count = !empty($product['review_count']) ? intval($product['review_count']) : 0;

// 性能 ≒ rating
$score_perf = max(1, min(5, $base));

// コスパ: 価格が安いほど高く、価格不明なら base ベース
if ($price >= 50000) {
    $score_cost = max(1, $base - 1.0);
} elseif ($price >= 20000) {
    $score_cost = max(1, $base - 0.5);
} elseif ($price > 0) {
    $score_cost = min(5, $base + 0.3);
} else {
    $score_cost = $base;
}
$score_cost = max(1, min(5, $score_cost));

// 使いやすさ: rating からわずかに下げる（保守的）
$score_usability = max(1, min(5, $base - 0.2));

// 満足度: レビュー数が多いほど安定性UP
if ($review_count >= 1000) {
    $score_satisfaction = min(5, $base + 0.2);
} elseif ($review_count >= 100) {
    $score_satisfaction = $base;
} else {
    $score_satisfaction = max(1, $base - 0.3);
}
$score_satisfaction = max(1, min(5, $score_satisfaction));

$axes = [
    '性能'      => $score_perf,
    'コスパ'    => $score_cost,
    '使いやすさ' => $score_usability,
    '満足度'    => $score_satisfaction,
];
$total = array_sum($axes) / count($axes);

$render_bar = function($val) {
    $val = max(0, min(5, floatval($val)));
    $pct = ($val / 5) * 100;
    ob_start();
    ?>
    <div class="aipi-score__bar">
        <div class="aipi-score__bar-fill" style="width: <?php echo esc_attr($pct); ?>%;"></div>
    </div>
    <span class="aipi-score__num"><?php echo esc_html(number_format($val, 1)); ?></span>
    <?php
    return ob_get_clean();
};
?>
<div class="aipi-card aipi-card--score">
    <div class="aipi-score__head">
        <?php if (!empty($product['image'])): ?>
            <div class="aipi-score__img">
                <a href="<?php echo esc_url($amazon_url ?: $rakuten_url); ?>" target="_blank" rel="nofollow noopener sponsored">
                    <img src="<?php echo esc_url($product['image']); ?>" alt="<?php echo esc_attr($display_title); ?>" loading="lazy">
                </a>
            </div>
        <?php endif; ?>
        <div class="aipi-score__head-body">
            <?php if (!empty($product['brand'])): ?>
                <div class="aipi-score__brand"><?php echo esc_html($product['brand']); ?></div>
            <?php endif; ?>
            <a href="<?php echo esc_url($amazon_url ?: $rakuten_url); ?>" target="_blank" rel="nofollow noopener sponsored" class="aipi-score__title">
                <?php echo esc_html($display_title); ?>
            </a>
            <?php if (!empty($product['price_display'])): ?>
                <div class="aipi-score__price"><?php echo esc_html($product['price_display']); ?></div>
            <?php endif; ?>
        </div>
    </div>

    <div class="aipi-score__axes">
        <?php foreach ($axes as $label => $val): ?>
            <div class="aipi-score__axis">
                <span class="aipi-score__label"><?php echo esc_html($label); ?></span>
                <?php echo $render_bar($val); ?>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="aipi-score__total">
        <span class="aipi-score__total-label">総合評価</span>
        <span class="aipi-score__total-stars">
            <?php
            $full = floor($total);
            for ($s = 1; $s <= 5; $s++) echo $s <= $full ? '★' : '☆';
            ?>
        </span>
        <span class="aipi-score__total-num"><?php echo esc_html(number_format($total, 1)); ?></span>
    </div>

    <div class="aipi-card__buttons">
        <?php if (!empty($amazon_url)): ?>
            <a href="<?php echo esc_url($amazon_url); ?>" target="_blank" rel="nofollow noopener sponsored" class="aipi-btn aipi-btn--amazon">Amazonで見る</a>
        <?php endif; ?>
        <?php if (!empty($rakuten_url)): ?>
            <a href="<?php echo esc_url($rakuten_url); ?>" target="_blank" rel="nofollow noopener sponsored" class="aipi-btn aipi-btn--rakuten">楽天市場で見る</a>
        <?php endif; ?>
    </div>

    <div class="aipi-card__disclaimer">
        <?php echo esc_html(date('Y年n月j日')); ?>時点の情報です（評価は当サイト独自基準）
    </div>
</div>
