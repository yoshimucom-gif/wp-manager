<?php
/**
 * 設定画面
 */

if (!defined('ABSPATH')) exit;

/**
 * 設定保存
 */
add_action('admin_post_affiros_rewrite_save_settings', function () {
    if (!current_user_can('manage_options')) {
        wp_die('権限がありません');
    }
    check_admin_referer('affiros_rewrite_save_settings');

    $input = $_POST['affiros_rewrite'] ?? [];
    $current = affiros_rewrite_get_settings();

    $new = [
        'claude_api_key' => trim((string)($input['claude_api_key'] ?? $current['claude_api_key'])),
        'claude_model' => sanitize_text_field($input['claude_model'] ?? $current['claude_model']),
        'rewrite_mode' => sanitize_text_field($input['rewrite_mode'] ?? 'seo'),
        'emphasis_level' => sanitize_text_field($input['emphasis_level'] ?? 'standard'),
        'tone' => sanitize_text_field($input['tone'] ?? 'natural'),
        'target_chars' => max(0, intval($input['target_chars'] ?? 0)),
        'tolerance_percent' => max(0, min(50, intval($input['tolerance_percent'] ?? 10))),
    ];
    if (in_array($new['rewrite_mode'], ['seo', 'readability', 'freshness']) === false) {
        $new['rewrite_mode'] = 'seo';
    }
    if (in_array($new['emphasis_level'], ['light', 'standard', 'strong']) === false) {
        $new['emphasis_level'] = 'standard';
    }
    if (in_array($new['tone'], ['natural', 'professional', 'casual']) === false) {
        $new['tone'] = 'natural';
    }
    update_option(AFFIROS_REWRITE_OPTION_KEY, $new);

    wp_safe_redirect(add_query_arg([
        'page' => 'affiros-rewrite-settings',
        'saved' => '1',
    ], admin_url('admin.php')));
    exit;
});

/**
 * 設定画面レンダリング
 */
function affiros_rewrite_render_settings_page() {
    if (!current_user_can('manage_options')) return;
    $settings = affiros_rewrite_get_settings();
    $masked_key = '';
    if ($settings['claude_api_key']) {
        $key = $settings['claude_api_key'];
        $masked_key = substr($key, 0, 8) . str_repeat('•', max(0, strlen($key) - 12)) . substr($key, -4);
    }
    ?>
    <div class="wrap affiros-wrap">
        <h1>Affiros リライト 設定</h1>

        <?php if (!empty($_GET['saved'])): ?>
            <div class="notice notice-success is-dismissible"><p>設定を保存しました。</p></div>
        <?php endif; ?>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('affiros_rewrite_save_settings'); ?>
            <input type="hidden" name="action" value="affiros_rewrite_save_settings">

            <h2>// API設定</h2>
            <table class="form-table">
                <tr>
                    <th><label for="claude_api_key">Claude APIキー</label></th>
                    <td>
                        <input
                            type="password"
                            id="claude_api_key"
                            name="affiros_rewrite[claude_api_key]"
                            class="regular-text"
                            placeholder="<?php echo esc_attr($masked_key ?: 'sk-ant-...'); ?>"
                            autocomplete="off"
                        >
                        <p class="description">
                            空欄のまま保存すると既存のキーが維持されます。<br>
                            <a href="https://console.anthropic.com/" target="_blank" rel="noopener">Anthropic Console</a> で発行してください。
                        </p>
                    </td>
                </tr>
                <tr>
                    <th><label for="claude_model">Claude モデル</label></th>
                    <td>
                        <select id="claude_model" name="affiros_rewrite[claude_model]">
                            <option value="claude-sonnet-4-5-20250929" <?php selected($settings['claude_model'], 'claude-sonnet-4-5-20250929'); ?>>Claude Sonnet 4.5（推奨）</option>
                            <option value="claude-opus-4-1-20250805" <?php selected($settings['claude_model'], 'claude-opus-4-1-20250805'); ?>>Claude Opus 4.1（最高品質・高コスト）</option>
                            <option value="claude-3-5-haiku-20241022" <?php selected($settings['claude_model'], 'claude-3-5-haiku-20241022'); ?>>Claude Haiku 3.5（低コスト・速度優先）</option>
                        </select>
                    </td>
                </tr>
            </table>

            <h2>// リライト デフォルト設定</h2>
            <p class="description">リライト実行画面で個別に上書きできます。ここはデフォルト値の指定。</p>
            <table class="form-table">
                <tr>
                    <th>リライトモード</th>
                    <td>
                        <label><input type="radio" name="affiros_rewrite[rewrite_mode]" value="seo" <?php checked($settings['rewrite_mode'], 'seo'); ?>> <strong>SEO強化</strong>（検索ニーズに沿った再構成）</label><br>
                        <label><input type="radio" name="affiros_rewrite[rewrite_mode]" value="readability" <?php checked($settings['rewrite_mode'], 'readability'); ?>> <strong>読みやすさ重視</strong>（段落・改行の改善）</label><br>
                        <label><input type="radio" name="affiros_rewrite[rewrite_mode]" value="freshness" <?php checked($settings['rewrite_mode'], 'freshness'); ?>> <strong>鮮度更新</strong>（時系列情報の最新化）</label>
                    </td>
                </tr>
                <tr>
                    <th>強調レベル</th>
                    <td>
                        <select name="affiros_rewrite[emphasis_level]">
                            <option value="light" <?php selected($settings['emphasis_level'], 'light'); ?>>軽い強調</option>
                            <option value="standard" <?php selected($settings['emphasis_level'], 'standard'); ?>>標準強調</option>
                            <option value="strong" <?php selected($settings['emphasis_level'], 'strong'); ?>>強い強調</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th>文体</th>
                    <td>
                        <select name="affiros_rewrite[tone]">
                            <option value="natural" <?php selected($settings['tone'], 'natural'); ?>>自然で読みやすい</option>
                            <option value="professional" <?php selected($settings['tone'], 'professional'); ?>>専門的・フォーマル</option>
                            <option value="casual" <?php selected($settings['tone'], 'casual'); ?>>カジュアル・親しみ</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="target_chars">目標文字数（0=元記事に合わせる）</label></th>
                    <td>
                        <input type="number" id="target_chars" name="affiros_rewrite[target_chars]" value="<?php echo esc_attr($settings['target_chars']); ?>" min="0" step="100" class="small-text"> 文字
                    </td>
                </tr>
                <tr>
                    <th><label for="tolerance_percent">許容範囲</label></th>
                    <td>
                        <input type="number" id="tolerance_percent" name="affiros_rewrite[tolerance_percent]" value="<?php echo esc_attr($settings['tolerance_percent']); ?>" min="0" max="50" step="1" class="small-text"> %
                    </td>
                </tr>
            </table>

            <p class="submit">
                <button type="submit" class="button button-primary">設定を保存</button>
            </p>
        </form>
    </div>
    <?php
}
