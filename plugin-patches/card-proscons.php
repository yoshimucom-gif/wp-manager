<?php
/**
 * 商品カードテンプレート: Pros/Cons（proscons）
 *
 * @var array $product
 */
if (!defined('ABSPATH')) exit;

$asin = $product['asin'] ?? '';
$source = $product['source'] ?? '';
$display_title = AI_PI_Card_Renderer::get_display_title($product, 90);

// 直リンのみ採用（検索URLは CVR を落とすので出さない）
$amazon_url = '';
$rakuten_url = '';
$yahoo_url = '';

if ($source === 'amazon' && !empty($asin)) {
    $amazon_url = AI_PI_Card_Renderer::build_amazon_url($asin);
    if (!empty($product['rakuten_pair']['url'])) {
        $rakuten_url = $product['rakuten_pair']['url'];
    }
} elseif ($source === 'rakuten') {
    $rakuten_url = $product['url'];
}

// Pros: 商品の features / bullet_points / description から自動生成
$pros = [];
if (!empty($product['features']) && is_array($product['features'])) {
    foreach (array_slice($product['features'], 0, 3) as $f) {
        $f = wp_strip_all_tags($f);
        if (mb_strlen($f) > 60) $f = mb_substr($f, 0, 58) . '…';
        if (!empty(trim($f))) $pros[] = $f;
    }
}
if (empty($pros) && !empty($product['description'])) {
    // description を句点で分割して先頭2つ
    $sentences = preg_split('/[。．\n]+/u', $product['description']);
    foreach (array_slice($sentences, 0, 2) as $s) {
        $s = trim(wp_strip_all_tags($s));
        if (mb_strlen($s) > 60) $s = mb_substr($s, 0, 58) . '…';
        if (!empty($s)) $pros[] = $s;
    }
}
if (empty($pros)) {
    $pros = ['多くのユーザーから高評価を獲得', '幅広いシーンで活躍する仕様', '信頼性の高いメーカー製品'];
}

// Cons: 価格・評価から汎用テンプレで生成（あくまでサンプル文）
$cons = [];
$rating = !empty($product['rating']) ? floatval($product['rating']) : 0;
$price_raw = !empty($product['price']) ? intval($product['price']) : 0;
if ($price_raw >= 30000) {
    $cons[] = '同カテゴリ内では価格が高めの設定';
}
if ($rating > 0 && $rating < 4.0) {
    $cons[] = '一部レビューで使用感に意見が分かれる';
}
if (empty($cons)) {
    $cons = ['用途によっては機能が多すぎる場合あり', '人気のため在庫変動が大きい'];
}
?>
<div class="aipi-card aipi-card--proscons">
    <div class="aipi-proscons__head">
        <?php if (!empty($product['image'])): ?>
            <div class="aipi-proscons__img">
                <a href="<?php echo esc_url($amazon_url ?: $rakuten_url); ?>" target="_blank" rel="nofollow noopener sponsored">
                    <img src="<?php echo esc_url($product['image']); ?>" alt="<?php echo esc_attr($display_title); ?>" loading="lazy">
                </a>
            </div>
        <?php endif; ?>
        <div class="aipi-proscons__head-body">
            <?php if (!empty($product['brand'])): ?>
                <div class="aipi-proscons__brand"><?php echo esc_html($product['brand']); ?></div>
            <?php endif; ?>
            <a href="<?php echo esc_url($amazon_url ?: $rakuten_url); ?>" target="_blank" rel="nofollow noopener sponsored" class="aipi-proscons__title">
                <?php echo esc_html($display_title); ?>
            </a>
            <?php
            $has_rating = $rating > 0;
            $has_price  = !empty($product['price_display']);
            ?>
            <?php if ($has_rating || $has_price): ?>
                <div class="aipi-proscons__meta">
                    <?php if ($has_rating): $full = floor($rating); ?>
                        <span class="aipi-proscons__rating">
                            <span class="aipi-stars"><?php for ($s = 1; $s <= 5; $s++) echo $s <= $full ? '★' : '☆'; ?></span>
                            <span class="aipi-rating-num"><?php echo esc_html(number_format($rating, 1)); ?></span>
                            <?php if (!empty($product['review_count'])): ?>
                                <span class="aipi-review-count">（<?php echo esc_html(number_format($product['review_count'])); ?>件）</span>
                            <?php endif; ?>
                        </span>
                    <?php endif; ?>
                    <?php if ($has_price): ?>
                        <span class="aipi-proscons__price"><?php echo esc_html($product['price_display']); ?></span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="aipi-proscons__lists">
        <div class="aipi-proscons__list aipi-proscons__list--pros">
            <div class="aipi-proscons__list-title">こんな点が好評</div>
            <ul>
                <?php foreach ($pros as $p): ?>
                    <li><?php echo esc_html($p); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <div class="aipi-proscons__list aipi-proscons__list--cons">
            <div class="aipi-proscons__list-title">気をつけたい点</div>
            <ul>
                <?php foreach ($cons as $c): ?>
                    <li><?php echo esc_html($c); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>

    <div class="aipi-card__buttons">
        <?php if (!empty($amazon_url)): ?>
            <a href="<?php echo esc_url($amazon_url); ?>" target="_blank" rel="nofollow noopener sponsored" class="aipi-btn aipi-btn--amazon">Amazonで見る</a>
        <?php endif; ?>
        <?php if (!empty($rakuten_url)): ?>
            <a href="<?php echo esc_url($rakuten_url); ?>" target="_blank" rel="nofollow noopener sponsored" class="aipi-btn aipi-btn--rakuten">楽天市場で見る</a>
        <?php endif; ?>
        <?php if (!empty($yahoo_url)): ?>
            <a href="<?php echo esc_url($yahoo_url); ?>" target="_blank" rel="nofollow noopener sponsored" class="aipi-btn aipi-btn--yahoo">Yahoo!</a>
        <?php endif; ?>
    </div>

    <?php
    $fetched_at = $product['fetched_at'] ?? '';
    if ($fetched_at): ?>
        <div class="aipi-card__disclaimer"><?php echo esc_html(date('Y年n月j日', strtotime($fetched_at))); ?>時点の情報です</div>
    <?php endif; ?>
</div>
