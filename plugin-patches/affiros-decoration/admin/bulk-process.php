<?php
/**
 * 一括処理画面
 */

if (!defined('ABSPATH')) exit;

function ai_deco_render_bulk_page() {
    if (!current_user_can('manage_options')) return;

    $categories = get_categories(['hide_empty' => false]);
    $tags = get_tags(['hide_empty' => false]);
    $settings = get_option('ai_deco_settings', []);
    $default_model = $settings['model'] ?? 'claude-sonnet-4-6';
    $default_level = $settings['decoration_level'] ?? 'standard';
    $models = ai_deco_get_models();
    ?>
    <div class="wrap ai-deco-wrap">
        <h1>AIデコレーション 一括処理</h1>

        <div class="notice notice-warning">
            <p><strong>⚠️ 注意：</strong>一括処理はAPIコストが発生します。最初は少数件で必ずテストしてください。</p>
        </div>

        <div class="ai-deco-bulk-form">
            <h2>対象記事の絞り込み</h2>

            <table class="form-table">
                <tr>
                    <th scope="row">カテゴリ</th>
                    <td>
                        <div class="ai-deco-checkbox-list">
                            <?php foreach ($categories as $cat): ?>
                                <label>
                                    <input type="checkbox" class="ai-deco-cat" value="<?php echo esc_attr($cat->term_id); ?>">
                                    <?php echo esc_html($cat->name); ?>
                                    <span class="ai-deco-count">(<?php echo esc_html($cat->count); ?>)</span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <p class="description">未選択=全カテゴリ対象</p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">タグ</th>
                    <td>
                        <div class="ai-deco-checkbox-list">
                            <?php foreach ($tags as $tag): ?>
                                <label>
                                    <input type="checkbox" class="ai-deco-tag" value="<?php echo esc_attr($tag->term_id); ?>">
                                    <?php echo esc_html($tag->name); ?>
                                    <span class="ai-deco-count">(<?php echo esc_html($tag->count); ?>)</span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <p class="description">未選択=タグ条件なし</p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">処理対象</th>
                    <td>
                        <label><input type="radio" name="ai_deco_filter" value="undecorated" checked> 未装飾の記事のみ</label><br>
                        <label><input type="radio" name="ai_deco_filter" value="warning"> ⚠️要確認の記事のみ（再処理）</label><br>
                        <label><input type="radio" name="ai_deco_filter" value="all"> 全件（装飾済みも再処理）</label>
                    </td>
                </tr>

                <tr>
                    <th scope="row">装飾品質</th>
                    <td>
                        <?php foreach ($models as $key => $m): ?>
                            <label style="display:block;margin:4px 0;">
                                <input type="radio" name="ai_deco_bulk_model" value="<?php echo esc_attr($key); ?>" <?php checked($default_model, $key); ?>>
                                <strong><?php echo esc_html($m['label']); ?></strong>
                                <span style="color:#888;">／ 約<?php echo esc_html($m['cost_yen']); ?>円/記事</span>
                            </label>
                        <?php endforeach; ?>
                    </td>
                </tr>

                <tr>
                    <th scope="row">装飾レベル</th>
                    <td>
                        <label><input type="radio" name="ai_deco_level" value="light" <?php checked($default_level, 'light'); ?>> 軽め</label>&nbsp;&nbsp;
                        <label><input type="radio" name="ai_deco_level" value="standard" <?php checked($default_level, 'standard'); ?>> 標準</label>&nbsp;&nbsp;
                        <label><input type="radio" name="ai_deco_level" value="heavy" <?php checked($default_level, 'heavy'); ?>> 盛り盛り</label>
                    </td>
                </tr>

                <tr>
                    <th scope="row">処理上限</th>
                    <td>
                        <input type="number" id="ai_deco_limit" value="10" min="1" max="500" style="width:80px;">
                        <p class="description">安全のため一度に処理する記事数を制限。最初は5〜10件を推奨</p>
                    </td>
                </tr>
            </table>

            <p>
                <button type="button" class="button ai-deco-count-targets">対象記事を確認</button>
                <button type="button" class="button button-primary ai-deco-start-bulk" disabled>一括処理を開始</button>
            </p>

            <div class="ai-deco-targets-result" style="display:none;">
                <h3>対象記事</h3>
                <div class="ai-deco-targets-summary"></div>
                <div class="ai-deco-targets-list"></div>
            </div>

            <div class="ai-deco-progress" style="display:none;">
                <h3>処理状況</h3>
                <div class="ai-deco-progress-bar">
                    <div class="ai-deco-progress-fill" style="width:0%;">0%</div>
                </div>
                <div class="ai-deco-progress-log"></div>
                <button type="button" class="button ai-deco-stop-bulk">⏹ 中断</button>
            </div>
        </div>
    </div>
    <?php
}
