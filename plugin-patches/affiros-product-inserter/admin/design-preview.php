<?php
/**
 * デザインプレビュー画面
 * 全カードデザインを実際のテンプレート + ダミー商品データで描画する
 */
if (!defined('ABSPATH')) exit;

function ai_pi_render_preview_page() {
    if (!current_user_can('manage_options')) return;

    // 共通のダミー features（proscons の Pros 自動生成用）
    $features_sample_a = [
        '業界トップクラスの吸引力で大粒のゴミから微細なホコリまで一気に吸引',
        'LCDディスプレイにバッテリー残量と検知粒子量をリアルタイム表示',
        '付属ノズルが豊富でカーペット・フローリング・隙間まで対応',
    ];
    $features_sample_b = [
        '本体重量1.5kg台と軽量で長時間使っても腕が疲れにくい',
        'バッテリー1回の充電で約40分の連続使用が可能',
        '価格と性能のバランスが良くコスパに優れる',
    ];

    // ダミー商品データ（実テンプレートと同じ構造）
    $sample_product_a = [
        'id' => 'A_DUMMY001',
        'source' => 'amazon',
        'asin' => 'B0XXXXXXX1',
        'title' => 'ダイソン Dyson V15 Detect コードレスクリーナー サイクロン式 充電式 SV47ABL',
        'brand' => 'Dyson',
        'image' => 'https://via.placeholder.com/300x300/eeeeee/666666?text=Product+A',
        'price' => 89800,
        'price_display' => '¥89,800',
        'rating' => 4.5,
        'review_count' => 1234,
        'features' => $features_sample_a,
        'fetched_at' => current_time('mysql'),
    ];

    $sample_product_b = [
        'id' => 'R_DUMMY002',
        'source' => 'rakuten',
        'url' => '#preview',
        'title' => 'シャーク EVOPOWER W30 軽量コードレス掃除機 充電式 ハンディクリーナー',
        'brand' => 'Shark',
        'image' => 'https://via.placeholder.com/300x300/eeeeee/666666?text=Product+B',
        'price' => 32800,
        'price_display' => '¥32,800',
        'rating' => 4.3,
        'review_count' => 568,
        'features' => $features_sample_b,
        'fetched_at' => current_time('mysql'),
    ];

    $sample_product_c = [
        'id' => 'A_DUMMY003',
        'source' => 'amazon',
        'asin' => 'B0XXXXXXX3',
        'title' => 'アイリスオーヤマ サイクロンスティッククリーナー IC-SLDCP6 軽量1.4kg',
        'brand' => 'アイリスオーヤマ',
        'image' => 'https://via.placeholder.com/300x300/eeeeee/666666?text=Product+C',
        'price' => 14800,
        'price_display' => '¥14,800',
        'rating' => 4.2,
        'review_count' => 2456,
        'fetched_at' => current_time('mysql'),
    ];

    $multi_products = [
        array_merge($sample_product_a, ['rank' => 1, 'reason' => '吸引力・付属ノズルの充実度ともにトップクラス']),
        array_merge($sample_product_b, ['rank' => 2, 'reason' => '軽量で扱いやすく、価格と性能のバランスが優秀']),
        array_merge($sample_product_c, ['rank' => 3, 'reason' => '低価格帯ながらサイクロン式で吸引力も合格点']),
    ];

    $criteria_sample = '軽さ・吸引力・コスパの総合バランスで評価';
    ?>
    <div class="wrap aipi-wrap aipi-preview-wrap">
        <h1>🎨 デザインプレビュー</h1>
        <p>各カードデザインの実際の見た目を確認できます（ダミー商品データ使用）。マーカー記法を本文に書くだけで該当デザインに置換されます。</p>

        <style>
        .aipi-preview-card {
            background: #fff;
            padding: 24px;
            margin: 20px 0;
            border: 1px solid #dcdcde;
            border-radius: 4px;
        }
        .aipi-preview-card h2 {
            margin-top: 0;
            padding-bottom: 8px;
            border-bottom: 2px solid #2271b1;
            color: #2271b1;
        }
        .aipi-preview-card__marker {
            display: inline-block;
            margin-left: 8px;
            font-family: Consolas, Menlo, monospace;
            font-size: 11px;
            background: #2271b1;
            color: #fff;
            padding: 2px 8px;
            border-radius: 3px;
            vertical-align: middle;
        }
        .aipi-preview-card__desc {
            color: #50575e;
            font-size: 13px;
            margin-bottom: 16px;
            line-height: 1.6;
        }
        .aipi-preview-tag {
            display: inline-block;
            padding: 2px 8px;
            margin-right: 6px;
            background: #f0f0f1;
            border-radius: 3px;
            font-size: 11px;
            color: #50575e;
        }
        .aipi-preview-tag--seo { background: #d4edff; color: #0a4a7e; }
        .aipi-preview-tag--cvr { background: #ffe4d4; color: #8e4a1e; }
        .aipi-preview-tag--versatile { background: #00a32a22; color: #00a32a; }
        .aipi-preview-render-area {
            padding: 20px;
            background: #f6f7f7;
            border: 1px dashed #dcdcde;
            border-radius: 3px;
        }
        .aipi-usecase-table th { background: #f6f7f7; }
        .aipi-usecase-table code {
            background: #f0f0f1;
            padding: 1px 4px;
            border-radius: 2px;
            font-size: 11px;
        }
        </style>

        <?php
        $frontend_css_url = AI_PI_URL . 'assets/frontend.css';
        ?>
        <link rel="stylesheet" href="<?php echo esc_url($frontend_css_url . '?ver=' . AI_PI_VERSION); ?>">

        <!-- ① 縦置きカード -->
        <div class="aipi-preview-card">
            <h2>① 縦置きカード（vertical）<span class="aipi-preview-card__marker">&lt;!--ai-product:vertical--&gt;</span></h2>
            <p class="aipi-preview-card__desc">
                <span class="aipi-preview-tag aipi-preview-tag--versatile">万能・主力</span>
                画像・ブランド・評価・価格・2ボタン（Amazon/楽天）をコンパクトに表示。SEO記事の主軸として、各H3直下や本文中の商品言及位置に多用する。
            </p>
            <div class="aipi-preview-render-area">
                <?php echo AI_PI_Card_Renderer::render($sample_product_a, 'vertical'); ?>
            </div>
        </div>

        <!-- ② 比較表 -->
        <div class="aipi-preview-card">
            <h2>② 比較表（compare）<span class="aipi-preview-card__marker">&lt;!--ai-product:compare:3--&gt;</span></h2>
            <p class="aipi-preview-card__desc">
                <span class="aipi-preview-tag aipi-preview-tag--seo">SEO強化</span>
                上位N商品を真のHTMLテーブルで並べる。検索結果のFeatured Snippet（強調スニペット）に乗りやすく、読者にとっても一覧性が高い。ランキング記事の冒頭やまとめ前への配置がおすすめ。
            </p>
            <div class="aipi-preview-render-area">
                <?php echo AI_PI_Card_Renderer::render_compare($multi_products); ?>
            </div>
        </div>

        <!-- ③ ランキング -->
        <div class="aipi-preview-card">
            <h2>③ ランキングカード（ranking）<span class="aipi-preview-card__marker">&lt;!--ai-product:ranking:3--&gt;</span></h2>
            <p class="aipi-preview-card__desc">
                <span class="aipi-preview-tag aipi-preview-tag--cvr">最終提示</span>
                判断軸付きでTOP3〜10を順位表示。「結局おすすめは？」という読者の疑問への回答として、まとめH2の直後に置くのが鉄板。
            </p>
            <div class="aipi-preview-render-area">
                <?php echo AI_PI_Card_Renderer::render_ranking($multi_products, $criteria_sample); ?>
            </div>
        </div>

        <hr style="margin: 40px 0;">

        <h2>📍 デザインの使い分け早見表</h2>
        <table class="widefat striped aipi-usecase-table" style="max-width:1000px;">
            <thead>
                <tr>
                    <th style="width: 110px;">デザイン</th>
                    <th style="width: 200px;">マーカー記法</th>
                    <th>強み</th>
                    <th>典型的な配置</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><strong>vertical</strong></td>
                    <td><code>&lt;!--ai-product:vertical--&gt;</code></td>
                    <td>万能。SEO記事の主軸</td>
                    <td>各H3直下、本文中の商品言及位置（1記事に5〜10個）</td>
                </tr>
                <tr>
                    <td><strong>compare</strong></td>
                    <td><code>&lt;!--ai-product:compare:N--&gt;</code></td>
                    <td>SEO（表構造化データ）+ 一目で比較</td>
                    <td>ランキング記事の冒頭、まとめH2の前（1記事に1〜2個）</td>
                </tr>
                <tr>
                    <td><strong>ranking</strong></td>
                    <td><code>&lt;!--ai-product:ranking:N--&gt;</code></td>
                    <td>「結局どれ？」への最終提示</td>
                    <td>まとめH2の直後（1記事に1個）</td>
                </tr>
            </tbody>
        </table>

        <h2 style="margin-top: 30px;">📝 記事種別ごとの推奨組み合わせ</h2>
        <table class="widefat striped aipi-usecase-table" style="max-width:1000px;">
            <thead>
                <tr>
                    <th style="width: 130px;">記事種別</th>
                    <th>マーカー構成例</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><strong>ランキング記事</strong></td>
                    <td>
                        冒頭 → <code>&lt;!--ai-product:compare:5--&gt;</code>（概要一覧）<br>
                        各H3直下 → <code>&lt;!--ai-product:vertical--&gt;</code>（個別解説）<br>
                        まとめ後 → <code>&lt;!--ai-product:ranking:3--&gt;</code>（結論TOP3）
                    </td>
                </tr>
                <tr>
                    <td><strong>ブランド/商標記事</strong></td>
                    <td>
                        最初のH2直後 → <code>&lt;!--ai-product:vertical--&gt;</code>（主役紹介）<br>
                        まとめ前 → <code>&lt;!--ai-product:vertical--&gt;</code>（CTA強化）
                    </td>
                </tr>
                <tr>
                    <td><strong>コラム記事</strong></td>
                    <td>
                        最初のH2前 → <code>&lt;!--ai-product:vertical--&gt;</code> ×2（読者誘導）<br>
                        まとめ後 → <code>&lt;!--ai-product:ranking:3--&gt;</code>（「結局どれが良い？」）
                    </td>
                </tr>
            </tbody>
        </table>

        <p style="margin-top: 30px; color: #50575e; font-size: 13px;">
            💡 <strong>Affiros9 連携時:</strong> マーカーは記事生成時に自動で挿入されます。挿入位置のルールは Affiros9 側の <code>DEFAULT_CARD_INSERTION_PATTERNS</code> で定義。
        </p>

        <p style="margin-top: 30px;">
            <a href="<?php echo esc_url(admin_url('admin.php?page=ai-product-inserter-settings')); ?>" class="button">← 設定画面に戻る</a>
        </p>
    </div>
    <?php
}
