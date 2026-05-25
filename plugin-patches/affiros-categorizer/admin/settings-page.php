<?php
/**
 * 設定画面
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 設定保存
 */
add_action('admin_post_affiros_cat_save_settings', function () {
    if (!current_user_can('manage_options')) {
        wp_die('権限がありません');
    }
    check_admin_referer('affiros_cat_save_settings');

    $input = $_POST['affiros_cat'] ?? [];
    $current = affiros_cat_get_settings();

    // パスワード欄は空のときも '' を送信するため、空欄なら既存キーを維持する。
    $submitted_key = trim((string) ($input['claude_api_key'] ?? ''));
    if (defined('AFFIROS_CATEGORIZER_API_KEY') && AFFIROS_CATEGORIZER_API_KEY) {
        // wp-config.php 定数で管理しているときは DB にキーを保存しない
        $new_api_key = '';
    } else {
        $new_api_key = $submitted_key !== '' ? $submitted_key : $current['claude_api_key'];
    }

    $model = sanitize_text_field($input['claude_model'] ?? '');
    $allowed_models = ['claude-haiku-4-5-20251001', 'claude-sonnet-4-6', 'claude-opus-4-7'];
    if (!in_array($model, $allowed_models, true)) {
        $model = 'claude-haiku-4-5-20251001';
    }

    $new = [
        'claude_api_key'  => $new_api_key,
        'claude_model'    => $model,
        'site_context'    => sanitize_textarea_field($input['site_context'] ?? ''),
        'auto_on_publish' => !empty($input['auto_on_publish']) ? 1 : 0,
        'overwrite'       => (($input['overwrite'] ?? 'empty') === 'always') ? 'always' : 'empty',
    ];
    update_option(AFFIROS_CAT_OPTION_KEY, $new);

    wp_safe_redirect(add_query_arg([
        'page'  => 'affiros-categorizer-settings',
        'saved' => '1',
    ], admin_url('admin.php')));
    exit;
});

/**
 * 設定画面レンダリング
 */
function affiros_cat_render_settings_page() {
    if (!current_user_can('manage_options')) {
        return;
    }
    $settings = affiros_cat_get_settings();
    $key_from_constant = defined('AFFIROS_CATEGORIZER_API_KEY') && AFFIROS_CATEGORIZER_API_KEY;
    $masked_key = '';
    if ($settings['claude_api_key']) {
        $key = $settings['claude_api_key'];
        $masked_key = substr($key, 0, 8) . str_repeat('•', max(0, strlen($key) - 12)) . substr($key, -4);
    }
    $terms = Affiros_Cat_Classifier::get_target_terms();
    ?>
    <div class="wrap affiros-cat-wrap">
        <h1>Affiros カテゴライザー 設定</h1>

        <?php if (!empty($_GET['saved'])): ?>
            <div class="notice notice-success is-dismissible"><p>設定を保存しました。</p></div>
        <?php endif; ?>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('affiros_cat_save_settings'); ?>
            <input type="hidden" name="action" value="affiros_cat_save_settings">

            <h2>// API 設定</h2>
            <table class="form-table">
                <tr>
                    <th><label for="claude_api_key">Claude API キー</label></th>
                    <td>
                        <?php if ($key_from_constant): ?>
                            <p style="margin:0 0 6px;">
                                <strong style="color:#0a7a2f;">✓ wp-config.php で設定済み</strong>
                                <code><?php echo esc_html($masked_key); ?></code>
                            </p>
                            <p class="description">
                                <code>wp-config.php</code> の <code>AFFIROS_CATEGORIZER_API_KEY</code> 定数が使われています。<br>
                                この方式ならプラグインの更新・再インストール・削除でもキーは消えません。<br>
                                変更する場合は <code>wp-config.php</code> を直接編集してください。
                            </p>
                        <?php else: ?>
                            <input
                                type="password"
                                id="claude_api_key"
                                name="affiros_cat[claude_api_key]"
                                class="regular-text"
                                placeholder="<?php echo esc_attr($masked_key ?: 'sk-ant-...'); ?>"
                                autocomplete="off"
                            >
                            <p class="description">
                                空欄のまま保存すると既存のキーが維持されます。<br>
                                <a href="https://console.anthropic.com/" target="_blank" rel="noopener">Anthropic Console</a> で発行してください。
                            </p>
                            <p class="description" style="margin-top:8px;padding:8px 10px;background:#f0f6fc;border-left:3px solid #2271b1;">
                                💡 <strong>キーを絶対に消したくない場合</strong>は、<code>wp-config.php</code> に次の行を追加してください。<br>
                                <code>define('AFFIROS_CATEGORIZER_API_KEY', 'sk-ant-xxxxx');</code>
                            </p>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th><label for="claude_model">Claude モデル</label></th>
                    <td>
                        <select id="claude_model" name="affiros_cat[claude_model]">
                            <option value="claude-haiku-4-5-20251001" <?php selected($settings['claude_model'], 'claude-haiku-4-5-20251001'); ?>>Claude Haiku 4.5（推奨・低コスト・分類に十分）</option>
                            <option value="claude-sonnet-4-6" <?php selected($settings['claude_model'], 'claude-sonnet-4-6'); ?>>Claude Sonnet 4.6（高精度）</option>
                            <option value="claude-opus-4-7" <?php selected($settings['claude_model'], 'claude-opus-4-7'); ?>>Claude Opus 4.7（最高精度・高コスト）</option>
                        </select>
                        <p class="description">カテゴリー分類は単純なタスクのため、通常は Haiku で十分です。</p>
                    </td>
                </tr>
            </table>

            <h2>// 分類の動作</h2>
            <table class="form-table">
                <tr>
                    <th><label for="site_context">サイトの説明（任意）</label></th>
                    <td>
                        <textarea id="site_context" name="affiros_cat[site_context]" rows="4" class="large-text" placeholder="例：このサイトは家庭菜園の初心者向けメディアです。読者は野菜づくりを始めたばかりの個人です。"><?php echo esc_textarea($settings['site_context']); ?></textarea>
                        <p class="description">サイトのジャンルや読者層を書くと、判定の精度が上がります。空欄でも動作します。</p>
                    </td>
                </tr>
                <tr>
                    <th>公開時の自動分類</th>
                    <td>
                        <label>
                            <input type="checkbox" name="affiros_cat[auto_on_publish]" value="1" <?php checked($settings['auto_on_publish'], 1); ?>>
                            記事が公開されたときに自動でカテゴリーを判定する
                        </label>
                        <p class="description">オフにすると、一括分類画面・投稿編集画面のボタンからの手動分類のみになります。</p>
                    </td>
                </tr>
                <tr>
                    <th>上書きの扱い（自動分類時）</th>
                    <td>
                        <label><input type="radio" name="affiros_cat[overwrite]" value="empty" <?php checked($settings['overwrite'], 'empty'); ?>> <strong>カテゴリー未設定の記事だけ</strong>分類する（既存のカテゴリーは尊重）</label><br>
                        <label><input type="radio" name="affiros_cat[overwrite]" value="always" <?php checked($settings['overwrite'], 'always'); ?>> <strong>常に</strong> AI の判定で上書きする</label>
                        <p class="description">この設定は「公開時の自動分類」にのみ適用されます。手動分類・一括分類は常に上書きします。</p>
                    </td>
                </tr>
            </table>

            <p class="submit">
                <button type="submit" class="button button-primary">設定を保存</button>
            </p>
        </form>

        <hr>
        <h2>判定に使われるカテゴリー（<?php echo count($terms); ?>件）</h2>
        <p class="description">
            このプラグインはサイトの<strong>実際のカテゴリー</strong>を読み取って判定します。
            各カテゴリーの<strong>「説明」欄</strong>（投稿 → カテゴリー で編集）を埋めると、AI の判定精度が大きく向上します。
        </p>
        <?php if ($terms): ?>
            <ul class="affiros-cat-category-list">
                <?php foreach ($terms as $t): ?>
                    <li>
                        <strong><?php echo esc_html($t->name); ?></strong>
                        <?php if (trim($t->description) !== ''): ?>
                            — <?php echo esc_html($t->description); ?>
                        <?php else: ?>
                            — <span class="affiros-cat-missing">（説明が未記入）</span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p class="affiros-cat-missing">判定対象のカテゴリーがありません。先にカテゴリーを作成してください。</p>
        <?php endif; ?>
    </div>
    <?php
}
