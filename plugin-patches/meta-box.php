<?php
/**
 * 投稿編集画面のメタボックス
 */
if (!defined('ABSPATH')) exit;

add_action('add_meta_boxes', 'ai_pi_add_meta_box');
function ai_pi_add_meta_box() {
    add_meta_box(
        'ai-pi-box',
        '🛒 AI商品挿入',
        'ai_pi_render_meta_box',
        ['post', 'page'],
        'side',
        'high'
    );
}

function ai_pi_render_meta_box($post) {
    $settings = get_option('ai_pi_settings', []);
    $is_inserted = AI_PI_Post_Meta::is_inserted($post->ID);
    $is_excluded = AI_PI_Post_Meta::is_excluded($post->ID);
    $inserted_at = AI_PI_Post_Meta::get_inserted_at($post->ID);
    $mode = get_post_meta($post->ID, '_ai_pi_mode', true);
    $design = get_post_meta($post->ID, '_ai_pi_design', true);
    $position = get_post_meta($post->ID, '_ai_pi_position', true);
    $products = AI_PI_Post_Meta::get_products($post->ID);
    $is_expired = get_post_meta($post->ID, '_ai_pi_expired', true);

    // 新マーカー syntax (<!--ai-product:design[:count]-->) も含めてカウント
    $marker_count = preg_match_all('/<!--\s*ai-product(?::[a-z]+(?::\d+)?)?\s*-->/i', $post->post_content);
    $detected_headings = ai_pi_metabox_detect_marker_headings($post->post_content, $post->post_title);

    $default_mode = $settings['default_insert_mode'] ?? 'marker';
    $default_design = $settings['default_card_design'] ?? 'vertical';
    $default_position = $settings['default_position'] ?? $settings['auto_top3_position'] ?? 'bottom';
    $preview_url = admin_url('admin.php?page=ai-product-inserter-preview');
    ?>
    <div class="aipi-metabox" data-post-id="<?php echo esc_attr($post->ID); ?>">

        <?php if ($is_inserted): ?>
            <div class="aipi-status aipi-status--inserted">
                <strong>✅ 商品挿入済み</strong>
                <?php if ($is_expired): ?>
                    <span class="aipi-expired-tag">⚠️ 24h経過</span>
                <?php endif; ?>
                <div class="aipi-meta">処理日時: <?php echo esc_html($inserted_at); ?></div>
                <div class="aipi-meta">方式: <?php echo esc_html($mode); ?> / デザイン: <?php echo esc_html($design); ?><?php if ($position) echo ' / 位置: ' . esc_html($position); ?></div>
                <div class="aipi-meta">挿入商品: <?php echo count($products); ?>個</div>
            </div>
        <?php else: ?>
            <div class="aipi-status aipi-status--none">未挿入</div>
        <?php endif; ?>

        <hr>

        <div class="aipi-marker-info">
            <strong>マーカー検出:</strong> <?php echo intval($marker_count); ?>個
            <?php if ($marker_count === 0): ?>
                <p class="description">マーカー方式で使う場合は本文に <code>&lt;!--ai-product--&gt;</code> を挿入</p>
            <?php endif; ?>
        </div>

        <?php if ($marker_count > 0 && !empty($detected_headings)): ?>
            <div class="aipi-headings-preview" style="margin-top:8px;padding:8px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:3px;font-size:11px;">
                <strong>各マーカー直前の見出し:</strong>
                <ol style="margin:4px 0 0 18px;padding:0;">
                    <?php foreach ($detected_headings as $h): ?>
                        <li><?php echo esc_html(mb_substr($h, 0, 40)); ?><?php if (mb_strlen($h) > 40) echo '...'; ?></li>
                    <?php endforeach; ?>
                </ol>
            </div>
        <?php endif; ?>

        <hr>

        <p><label><strong>① 挿入方式</strong></label></p>
        <p class="aipi-mode-radios">
            <label><input type="radio" name="aipi_mode" value="marker" <?php checked($default_mode, 'marker'); ?>> マーカー方式</label><br>
            <label><input type="radio" name="aipi_mode" value="marker_per_heading" <?php checked($default_mode, 'marker_per_heading'); ?>> 見出し連動マーカー ⭐</label><br>
            <label><input type="radio" name="aipi_mode" value="auto" <?php checked($default_mode, 'auto'); ?>> 自動配置</label>
        </p>

        <p>
            <label><strong>② デザイン</strong></label>
            <a href="<?php echo esc_url($preview_url); ?>" target="_blank" style="font-size:11px;margin-left:8px;">🎨 プレビュー</a>
        </p>
        <p>
            <label><input type="radio" name="aipi_design" value="vertical" <?php checked($default_design, 'vertical'); ?>> 縦置きカード</label><br>
            <label><input type="radio" name="aipi_design" value="horizontal" <?php checked($default_design, 'horizontal'); ?>> 横長カード</label><br>
            <label><input type="radio" name="aipi_design" value="ranking" <?php checked($default_design, 'ranking'); ?>> ランキングカード</label>
        </p>

        <div class="aipi-position-section" style="<?php echo $default_mode === 'auto' ? '' : 'display:none;'; ?>">
            <p><label><strong>③ 挿入位置</strong>（自動配置のみ）</label></p>
            <p>
                <label><input type="radio" name="aipi_position" value="top" <?php checked($default_position, 'top'); ?>> 記事冒頭</label><br>
                <label><input type="radio" name="aipi_position" value="before_first_h2" <?php checked($default_position, 'before_first_h2'); ?>> 最初のH2の直前</label><br>
                <label><input type="radio" name="aipi_position" value="after_first_h2" <?php checked($default_position, 'after_first_h2'); ?>> 最初のH2の直後</label><br>
                <label><input type="radio" name="aipi_position" value="before_last_h2" <?php checked($default_position, 'before_last_h2'); ?>> 最後のH2の直前</label><br>
                <label><input type="radio" name="aipi_position" value="after_last_h2" <?php checked($default_position, 'after_last_h2'); ?>> 最後のH2の直後</label><br>
                <label><input type="radio" name="aipi_position" value="bottom" <?php checked($default_position, 'bottom'); ?>> 記事末尾</label>
            </p>
        </div>

        <hr>

        <p>
            <label>
                <input type="checkbox" id="aipi-dry-run" checked>
                プレビューモード（保存せず結果のみ表示）
            </label>
        </p>

        <p>
            <button type="button" class="button button-primary aipi-run">
                <?php echo $is_inserted ? '🔄 再挿入を実行' : '🛒 商品挿入を実行'; ?>
            </button>
        </p>

        <?php if ($is_inserted && AI_PI_Post_Meta::has_backup($post->ID)): ?>
            <p>
                <button type="button" class="button aipi-rollback">↩️ 挿入を元に戻す</button>
            </p>
        <?php endif; ?>

        <hr>

        <p>
            <label>
                <input type="checkbox" class="aipi-exclude" <?php checked($is_excluded); ?>>
                この記事を対象外にする
            </label>
        </p>

        <div class="aipi-spinner" style="display:none;">
            <span class="spinner is-active" style="float:none;"></span> 処理中...（30秒〜2分）
        </div>

        <div class="aipi-result" style="display:none;">
            <h4>処理結果</h4>
            <div class="aipi-result-body"></div>
        </div>

        <?php if ($is_inserted && !empty($products)): ?>
            <hr>
            <p><strong>挿入された商品</strong></p>
            <ul class="aipi-product-list">
                <?php foreach ($products as $p): ?>
                    <li>
                        <?php if (!empty($p['rank'])): ?><strong><?php echo esc_html($p['rank']); ?>位</strong> <?php endif; ?>
                        <?php echo esc_html(mb_substr($p['title'], 0, 50)); ?>...
                        <span class="aipi-source-tag aipi-source-tag--<?php echo esc_attr($p['source']); ?>"><?php echo esc_html($p['source']); ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

    </div>

    <script>
    (function($) {
        // 自動配置モード選択時のみ「挿入位置」セクションを表示
        $(document).on('change', '.aipi-mode-radios input[name="aipi_mode"]', function() {
            var mode = $(this).val();
            if (mode === 'auto') {
                $('.aipi-position-section').slideDown(150);
            } else {
                $('.aipi-position-section').slideUp(150);
            }
        });
    })(jQuery);
    </script>
    <?php
}

/**
 * メタボックスのプレビュー用: 各マーカー直前のH2/H3を抽出
 */
function ai_pi_metabox_detect_marker_headings($content, $fallback) {
    preg_match_all(
        '/(<h([234])[^>]*>(.*?)<\/h\2>)|(<!--\s*ai-product(?::[a-z]+(?::\d+)?)?\s*-->)/is',
        $content,
        $matches,
        PREG_SET_ORDER
    );

    $current = $fallback;
    $pairs = [];
    foreach ($matches as $m) {
        if (!empty($m[1])) {
            $text = trim(wp_strip_all_tags($m[3] ?? ''));
            if (!empty($text)) $current = $text;
        } elseif (isset($m[4]) && $m[4] !== '') {
            $pairs[] = $current;
        }
    }
    return $pairs;
}
