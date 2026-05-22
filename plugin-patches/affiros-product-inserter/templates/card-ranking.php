<?php
/**
 * ランキングカードテンプレート
 * @var array $products ランキング商品データ（rank は 1,2,3...にリナンバー済み）
 * @var string $criteria 判断軸の説明
 */
if (!defined('ABSPATH')) exit;
?>
<div class="aipi-ranking">
    <div class="aipi-ranking__header">
        <span class="aipi-ranking__badge">AI選定</span>
        <span class="aipi-ranking__title">この記事でおすすめの商品TOP<?php echo count($products); ?></span>
    </div>

    <?php if (!empty($criteria)): ?>
        <div class="aipi-ranking__criteria">判断軸: <?php echo esc_html($criteria); ?></div>
    <?php endif; ?>

    <?php foreach ($products as $i => $product):
        // rank はリナンバー済みだが、念のため fallback
        $rank = $product['rank'] ?? ($i + 1);
        $asin = $product['asin'] ?? '';
        $source = $product['source'] ?? '';

        $display_title = AI_PI_Card_Renderer::get_display_title($product, 80);

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

        $rank_class = 'aipi-rank--' . ($rank <= 3 ? $rank : 'other');
    ?>
        <div class="aipi-rank-row">
            <div class="aipi-rank-row__rank <?php echo esc_attr($rank_class); ?>">
                <?php echo esc_html($rank); ?>位
            </div>
            <?php if (!empty($product['image'])): ?>
                <div class="aipi-rank-row__img">
                    <a href="<?php echo esc_url($amazon_url ?: $rakuten_url); ?>" target="_blank" rel="nofollow noopener sponsored">
                        <img src="<?php echo esc_url($product['image']); ?>" alt="<?php echo esc_attr($display_title); ?>" loading="lazy">
                    </a>
                </div>
            <?php endif; ?>
            <div class="aipi-rank-row__body">
                <a href="<?php echo esc_url($amazon_url ?: $rakuten_url); ?>" target="_blank" rel="nofollow noopener sponsored" class="aipi-rank-row__title">
                    <?php echo esc_html($display_title); ?>
                </a>
                <?php if (!empty($product['price_display'])): ?>
                    <div class="aipi-rank-row__price"><?php echo esc_html($product['price_display']); ?></div>
                <?php endif; ?>
                <?php if (!empty($product['reason'])): ?>
                    <div class="aipi-rank-row__reason"><?php echo esc_html($product['reason']); ?></div>
                <?php endif; ?>
                <div class="aipi-rank-row__buttons">
                    <?php if (!empty($amazon_url)): ?>
                        <a href="<?php echo esc_url($amazon_url); ?>" target="_blank" rel="nofollow noopener sponsored" class="aipi-btn-mini aipi-btn--amazon">Amazon</a>
                    <?php endif; ?>
                    <?php if (!empty($rakuten_url)): ?>
                        <a href="<?php echo esc_url($rakuten_url); ?>" target="_blank" rel="nofollow noopener sponsored" class="aipi-btn-mini aipi-btn--rakuten">楽天</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <div class="aipi-ranking__disclaimer">
        <?php echo esc_html(date('Y年n月j日')); ?>時点の情報です
    </div>
</div>
