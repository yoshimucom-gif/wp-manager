<?php
/**
 * デザインプレビュー画面
 * 実際の Affiros_AI_Card_Renderer + ダミー商品データで比較カードを描画する。
 * ボタン構成のバリエーション (Amazon+楽天 / Amazonのみ / 楽天のみ) を確認できる。
 */

if (!defined('ABSPATH')) exit;

function affiros_ai_render_preview_page() {
    if (!current_user_can('manage_options')) return;

    $img = function ($label, $bg) {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="300" height="300">'
             . '<rect width="300" height="300" fill="' . $bg . '"/>'
             . '<text x="150" y="158" font-size="28" font-family="sans-serif" fill="#888" text-anchor="middle">' . $label . '</text>'
             . '</svg>';
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    };

    $amazon_products = [
        [
            'source' => 'amazon',
            'title'  => 'ダイソン Dyson V15 Detect コードレスクリーナー サイクロン式 充電式 SV47ABL',
            'brand'  => 'Dyson',
            'image'  => $img('Product A', '#eef2f6'),
            'price_display' => '¥89,800',
            'url'    => '#preview',
        ],
        [
            'source' => 'amazon',
            'title'  => 'シャーク EVOPOWER W30 軽量コードレス掃除機 充電式 ハンディクリーナー',
            'brand'  => 'Shark',
            'image'  => $img('Product B', '#f6f2ee'),
            'price_display' => '¥32,800',
            'url'    => '#preview',
        ],
        [
            'source' => 'amazon',
            'title'  => 'アイリスオーヤマ サイクロンスティッククリーナー IC-SLDCP6 軽量1.4kg',
            'brand'  => 'アイリスオーヤマ',
            'image'  => $img('Product C', '#eef6ef'),
            'price_display' => '¥14,800',
            'url'    => '#preview',
        ],
    ];

    // タイトルの共通トークンで Amazon 各商品にマッチするよう寄せたダミー楽天商品
    $rakuten_products = [
        [
            'source' => 'rakuten',
            'title'  => '【国内正規品】ダイソン Dyson V15 Detect コードレスクリーナー SV47ABL',
            'brand'  => 'Dyson',
            'image'  => $img('Product A', '#eef2f6'),
            'price_display' => '¥88,000',
            'url'    => '#preview',
        ],
        [
            'source' => 'rakuten',
            'title'  => 'シャーク EVOPOWER W30 コードレス ハンディクリーナー 充電式',
            'brand'  => 'Shark',
            'image'  => $img('Product B', '#f6f2ee'),
            'price_display' => '¥31,900',
            'url'    => '#preview',
        ],
        [
            'source' => 'rakuten',
            'title'  => 'アイリスオーヤマ サイクロンスティッククリーナー IC-SLDCP6 スティック掃除機',
            'brand'  => 'アイリスオーヤマ',
            'image'  => $img('Product C', '#eef6ef'),
            'price_display' => '¥13,980',
            'url'    => '#preview',
        ],
    ];

    $settings = affiros_ai_get_settings();
    $meta = [
        'keyword'             => 'コードレス掃除機',
        'card_heading_suffix' => $settings['card_heading_suffix'] ?? '',
    ];
    ?>
    <div class="wrap">
        <h1>🎨 デザインプレビュー</h1>
        <p style="font-size:13px;line-height:1.7">
            実際に記事へ挿入される比較カードの見た目を確認できます（ダミー商品データ使用）。
            マーカー記法は不要 — このカードが「最初のH2直前」「まとめ直後」に自動で入ります。
        </p>

        <h2 style="margin-top:28px">① Amazon 主軸（標準）</h2>
        <p class="description">Amazonボタン＝商品ページ、楽天ボタン＝キーワードの検索結果一覧（アフィリエイトラッパー付き）。タイトル類似での商品マッチングは誤誘導するため行わない。</p>
        <div style="max-width:900px;background:#fff;border:1px solid #ccd0d4;border-radius:4px;padding:8px 24px">
            <?php echo Affiros_AI_Card_Renderer::render($amazon_products, [], $meta); ?>
        </div>

        <h2 style="margin-top:28px">② 楽天主軸（Amazon 未設定 / 0件時）</h2>
        <p class="description">楽天ボタン＝商品ページ、Amazonボタン＝アソシエイトタグ付きの検索結果一覧。</p>
        <div style="max-width:900px;background:#fff;border:1px solid #ccd0d4;border-radius:4px;padding:8px 24px">
            <?php echo Affiros_AI_Card_Renderer::render([], $rakuten_products, $meta); ?>
        </div>

        <p style="margin-top:24px">
            <a href="<?php echo esc_url(admin_url('admin.php?page=affiros-ai-settings')); ?>" class="button">← 設定画面に戻る</a>
        </p>
    </div>
    <?php
}
