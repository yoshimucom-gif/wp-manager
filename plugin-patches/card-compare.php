<?php
/**
 * 商品カードテンプレート: 比較表（compare）
 *
 * @var array $products 比較対象商品の配列（rank 1〜N）
 */
if (!defined('ABSPATH')) exit;
?>
<div class="aipi-compare">
    <div class="aipi-compare__header">
        <span class="aipi-compare__badge">AI比較</span>
        <span class="aipi-compare__title">おすすめ商品 比較表</span>
    </div>

    <div class="aipi-compare__scroll">
        <table class="aipi-compare__table">
            <thead>
                <tr>
                    <th>順位</th>
                    <th>商品</th>
                    <th>価格</th>
                    <th>評価</th>
                    <th>購入</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($products as $i => $product):
                    $rank = $product['rank'] ?? ($i + 1);
                    $asin = $product['asin'] ?? '';
                    $source = $product['source'] ?? '';

                    $display_title = AI_PI_Card_Renderer::get_display_title($product, 60);

                    // 直リンのみ採用（検索URLは CVR を落とすので出さない）
                    $amazon_url = '';
                    $rakuten_url = '';

                    if ($source === 'amazon' && !empty($asin)) {
                        $amazon_url = AI_PI_Card_Renderer::build_amazon_url($asin);
                        if (!empty($product['rakuten_pair']['url'])) {
                            $rakuten_url = $product['rakuten_pair']['url'];
                        }
                    } elseif ($source === 'rakuten') {
                        $rakuten_url = $product['url'];
                    }

                    $primary_url = $amazon_url ?: $rakuten_url;
                    $rank_class = 'aipi-rank--' . ($rank <= 3 ? $rank : 'other');
                    $rating = !empty($product['rating']) ? floatval($product['rating']) : 0;
                ?>
                    <tr>
                        <td class="aipi-compare__rank-cell">
                            <span class="aipi-compare__rank-badge <?php echo esc_attr($rank_class); ?>"><?php echo esc_html($rank); ?>位</span>
                        </td>
                        <td class="aipi-compare__product-cell">
                            <?php if (!empty($product['image'])): ?>
                                <div class="aipi-compare__product-img">
                                    <a href="<?php echo esc_url($primary_url); ?>" target="_blank" rel="nofollow noopener sponsored">
                                        <img src="<?php echo esc_url($product['image']); ?>" alt="<?php echo esc_attr($display_title); ?>" loading="lazy">
                                    </a>
                                </div>
                            <?php endif; ?>
                            <a href="<?php echo esc_url($primary_url); ?>" target="_blank" rel="nofollow noopener sponsored" class="aipi-compare__product-name">
                                <?php echo esc_html($display_title); ?>
                            </a>
                        </td>
                        <td class="aipi-compare__price-cell">
                            <?php echo !empty($product['price_display']) ? esc_html($product['price_display']) : '—'; ?>
                        </td>
                        <td class="aipi-compare__rating-cell">
                            <?php if ($rating > 0): ?>
                                <div class="aipi-compare__rating-stars">
                                    <?php
                                    $full = floor($rating);
                                    for ($s = 1; $s <= 5; $s++) {
                                        echo $s <= $full ? '★' : '☆';
                                    }
                                    ?>
                                </div>
                                <div class="aipi-compare__rating-num"><?php echo esc_html(number_format($rating, 1)); ?></div>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td class="aipi-compare__buy-cell">
                            <?php if (!empty($amazon_url)): ?>
                                <a href="<?php echo esc_url($amazon_url); ?>" target="_blank" rel="nofollow noopener sponsored" class="aipi-btn-mini aipi-btn--amazon">Amazon</a>
                            <?php endif; ?>
                            <?php if (!empty($rakuten_url)): ?>
                                <a href="<?php echo esc_url($rakuten_url); ?>" target="_blank" rel="nofollow noopener sponsored" class="aipi-btn-mini aipi-btn--rakuten">楽天</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="aipi-compare__disclaimer">
        <?php echo esc_html(date('Y年n月j日')); ?>時点の情報です
    </div>
</div>
