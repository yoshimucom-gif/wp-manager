<?php
/**
 * 投稿編集画面のメタボックス（1記事ずつ装飾実行・ロールバック）
 */

if (!defined('ABSPATH')) exit;

add_action('add_meta_boxes', 'ai_deco_add_meta_box');
function ai_deco_add_meta_box() {
    add_meta_box(
        'ai-deco-box',
        '🎨 AIデコレーション',
        'ai_deco_render_meta_box',
        ['post', 'page'],
        'side',
        'high'
    );
}

function ai_deco_render_meta_box($post) {
    $is_decorated = AI_Deco_Post_Meta::is_decorated($post->ID);
    $status = AI_Deco_Post_Meta::get_status($post->ID);
    $is_excluded = AI_Deco_Post_Meta::is_excluded($post->ID);
    $decorated_at = get_post_meta($post->ID, '_ai_deco_decorated_at', true);
    $past_level = get_post_meta($post->ID, '_ai_deco_level', true);
    $past_model = get_post_meta($post->ID, '_ai_deco_model', true);
    $validation = get_post_meta($post->ID, '_ai_deco_validation', true);
    $usage = get_post_meta($post->ID, '_ai_deco_usage', true);

    $settings = get_option('ai_deco_settings', []);
    $default_model = $settings['model'] ?? 'claude-sonnet-4-6';
    $default_level = $settings['decoration_level'] ?? 'standard';
    $models = ai_deco_get_models();
    ?>
    <div class="ai-deco-metabox" data-post-id="<?php echo esc_attr($post->ID); ?>">

        <?php if ($is_decorated): ?>
            <div class="ai-deco-status ai-deco-status--<?php echo esc_attr($status); ?>">
                <?php
                $status_label = [
                    'ok' => '✅ 装飾済み',
                    'warning' => '⚠️ 装飾済み（要確認）',
                    'error' => '❌ 装飾失敗',
                ][$status] ?? '装飾済み';
                ?>
                <strong><?php echo esc_html($status_label); ?></strong>
                <?php if ($decorated_at): ?>
                    <div class="ai-deco-meta">処理日時: <?php echo esc_html($decorated_at); ?></div>
                <?php endif; ?>
                <?php if ($past_model): ?>
                    <div class="ai-deco-meta">使用モデル: <strong><?php echo esc_html(ai_deco_get_model_label($past_model)); ?></strong></div>
                <?php endif; ?>
                <?php if ($past_level): ?>
                    <div class="ai-deco-meta">装飾レベル: <?php echo esc_html($past_level); ?></div>
                <?php endif; ?>

                <?php if (!empty($validation['warnings'])): ?>
                    <ul class="ai-deco-warnings">
                        <?php foreach ($validation['warnings'] as $w): ?>
                            <li><?php echo esc_html($w); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <?php if (!empty($validation['metrics'])): ?>
                    <div class="ai-deco-metrics">
                        文字数: <?php echo esc_html($validation['metrics']['original_length']); ?>
                        → <?php echo esc_html($validation['metrics']['decorated_length']); ?>
                        (<?php echo esc_html(round($validation['metrics']['ratio'] * 100)); ?>%)
                    </div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <p class="ai-deco-status ai-deco-status--none">未装飾</p>
        <?php endif; ?>

        <hr>

        <h4 style="margin:8px 0 4px;font-size:12px;">装飾品質</h4>
        <div class="ai-deco-model-select">
            <?php foreach ($models as $key => $m): ?>
                <label style="display:block;font-size:12px;margin:3px 0;">
                    <input type="radio" name="ai_deco_model" value="<?php echo esc_attr($key); ?>" <?php checked($default_model, $key); ?>>
                    <?php echo esc_html($m['label']); ?>
                    <span style="color:#888;">(約<?php echo esc_html($m['cost_yen']); ?>円)</span>
                </label>
            <?php endforeach; ?>
        </div>

        <h4 style="margin:8px 0 4px;font-size:12px;">装飾レベル</h4>
        <div class="ai-deco-level-select">
            <label style="font-size:12px;margin-right:8px;"><input type="radio" name="ai_deco_level_pick" value="light" <?php checked($default_level, 'light'); ?>> 軽め</label>
            <label style="font-size:12px;margin-right:8px;"><input type="radio" name="ai_deco_level_pick" value="standard" <?php checked($default_level, 'standard'); ?>> 標準</label>
            <label style="font-size:12px;"><input type="radio" name="ai_deco_level_pick" value="heavy" <?php checked($default_level, 'heavy'); ?>> 盛り盛り</label>
        </div>

        <hr>

        <p>
            <label>
                <input type="checkbox" id="ai-deco-dry-run" checked>
                プレビューモード（保存せず装飾結果のみ表示）
            </label>
        </p>

        <p>
            <button type="button" class="button button-primary ai-deco-run">
                <?php echo $is_decorated ? '🔄 再装飾を実行' : '✨ 装飾を実行'; ?>
            </button>
        </p>

        <?php if ($is_decorated && AI_Deco_Post_Meta::has_backup($post->ID)): ?>
            <p>
                <button type="button" class="button ai-deco-rollback">
                    ↩️ 装飾を元に戻す
                </button>
            </p>
        <?php endif; ?>

        <hr>

        <p>
            <label>
                <input type="checkbox" class="ai-deco-exclude" <?php checked($is_excluded); ?>>
                この記事を装飾対象外にする
            </label>
        </p>

        <div class="ai-deco-result" style="display:none;">
            <h4>処理結果</h4>
            <div class="ai-deco-result-body"></div>
        </div>

        <div class="ai-deco-spinner" style="display:none;">
            <span class="spinner is-active" style="float:none;"></span> 装飾処理中...（30秒〜2分程度）
        </div>
    </div>
    <?php
}
