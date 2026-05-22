<?php
/**
 * 設定画面
 */

if (!defined('ABSPATH')) exit;

add_action('admin_init', 'ai_deco_register_settings');
function ai_deco_register_settings() {
    register_setting('ai_deco_settings_group', 'ai_deco_settings', [
        'sanitize_callback' => 'ai_deco_sanitize_settings',
    ]);
}

function ai_deco_sanitize_settings($input) {
    $output = [];

    // APIキー：設定画面ではマスク表示し、空欄のまま保存されたら既存キーを維持する
    $existing = get_option('ai_deco_settings', []);
    $submitted_key = sanitize_text_field($input['api_key'] ?? '');
    $output['api_key'] = $submitted_key !== '' ? $submitted_key : ($existing['api_key'] ?? '');

    $allowed_models = array_keys(ai_deco_get_models());
    $output['model'] = in_array($input['model'] ?? '', $allowed_models, true)
        ? $input['model']
        : 'claude-sonnet-4-6';

    $output['decoration_level'] = in_array($input['decoration_level'] ?? '', ['light', 'standard', 'heavy'])
        ? $input['decoration_level']
        : 'standard';
    $output['enable_faq'] = ($input['enable_faq'] ?? '') === 'yes' ? 'yes' : 'no';
    $output['auto_decorate_on_save'] = ($input['auto_decorate_on_save'] ?? '') === 'yes' ? 'yes' : 'no';
    return $output;
}

function ai_deco_render_settings_page() {
    if (!current_user_can('manage_options')) return;
    $settings = get_option('ai_deco_settings', []);
    $models = ai_deco_get_models();
    $current_model = $settings['model'] ?? 'claude-sonnet-4-6';
    ?>
    <div class="wrap ai-deco-wrap">
        <h1>AIデコレーション 設定</h1>

        <?php if (isset($_GET['settings-updated'])): ?>
            <div class="notice notice-success is-dismissible"><p>設定を保存しました。</p></div>
        <?php endif; ?>

        <form method="post" action="options.php">
            <?php settings_fields('ai_deco_settings_group'); ?>

            <table class="form-table">
                <tr>
                    <th scope="row"><label for="api_key">Claude APIキー</label></th>
                    <td>
                        <?php
                        $saved_key = $settings['api_key'] ?? '';
                        $has_key = $saved_key !== '';
                        $key_hint = $has_key
                            ? '保存済み ••••' . substr($saved_key, -4) . '（変更する場合のみ入力）'
                            : 'sk-ant-... を入力';
                        ?>
                        <input type="password" id="api_key" name="ai_deco_settings[api_key]"
                               value="" class="regular-text" autocomplete="off"
                               placeholder="<?php echo esc_attr($key_hint); ?>">
                        <p class="description">
                            Anthropic Consoleで発行したAPIキーを入力。
                            <?php if ($has_key): ?><strong>空欄のまま保存すると現在のキーが維持されます。</strong><?php endif; ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">デフォルトの装飾品質</th>
                    <td>
                        <?php foreach ($models as $key => $m): ?>
                            <label style="display:block;margin:6px 0;">
                                <input type="radio" name="ai_deco_settings[model]" value="<?php echo esc_attr($key); ?>" <?php checked($current_model, $key); ?>>
                                <strong><?php echo esc_html($m['label']); ?></strong>
                                <span style="color:#666;">／ 約<?php echo esc_html($m['cost_yen']); ?>円/記事</span>
                                <br><span style="margin-left:24px;color:#888;font-size:12px;"><?php echo esc_html($m['description']); ?></span>
                            </label>
                        <?php endforeach; ?>
                        <p class="description">投稿編集画面や一括処理画面では、装飾実行時にここで選んだ品質がデフォルトになります（場面ごとに変更可）</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">デフォルトの装飾レベル</th>
                    <td>
                        <label><input type="radio" name="ai_deco_settings[decoration_level]" value="light"
                            <?php checked($settings['decoration_level'] ?? '', 'light'); ?>> 軽め（マーカー＋ボックス少々）</label><br>
                        <label><input type="radio" name="ai_deco_settings[decoration_level]" value="standard"
                            <?php checked($settings['decoration_level'] ?? 'standard', 'standard'); ?>> 標準（バランス重視）</label><br>
                        <label><input type="radio" name="ai_deco_settings[decoration_level]" value="heavy"
                            <?php checked($settings['decoration_level'] ?? '', 'heavy'); ?>> 盛り盛り（全装飾フル活用）</label>
                        <p class="description">装飾の量。装飾実行時に変更可。さらに細かく調整したい場合は <code>prompts/system-*.txt</code> を直接編集</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">FAQ自動生成</th>
                    <td>
                        <label><input type="checkbox" name="ai_deco_settings[enable_faq]" value="yes"
                            <?php checked($settings['enable_faq'] ?? '', 'yes'); ?>> 記事末尾にFAQブロックを自動生成</label>
                    </td>
                </tr>
                <tr>
                    <th scope="row">投稿保存時の自動装飾</th>
                    <td>
                        <label><input type="checkbox" name="ai_deco_settings[auto_decorate_on_save]" value="yes"
                            <?php checked($settings['auto_decorate_on_save'] ?? '', 'yes'); ?>> 投稿保存時に未装飾なら自動実行</label>
                        <p class="description">
                            ⚠️ 注意：チェックすると<strong>公開済み記事を更新するたびに装飾APIが走ります</strong>（コスト発生）。<br>
                            ・AJAX/REST API経由（ブロックエディタの自動保存など）は対象外<br>
                            ・装飾済みフラグが立っている記事は再実行されません<br>
                            ・除外フラグが立った記事もスキップされます<br>
                            通常はオフ推奨。
                        </p>
                    </td>
                </tr>
            </table>

            <?php submit_button(); ?>
        </form>

        <hr>

        <h2>目安単価の前提</h2>
        <p style="color:#666;">
            上の単価は標準的な3,000字記事 × 装飾レベル「標準」での1記事あたり試算（1USD=155円換算）。<br>
            実際のコストは記事の長さ・装飾レベル・リトライ回数で変動します。<strong>処理ログ</strong>で実際の使用トークン数を確認できます。
        </p>

        <h2>装飾量を増やすには</h2>
        <ul style="list-style:disc; padding-left:24px;">
            <li><strong>かんたんな方法</strong>：装飾レベルを <code>盛り盛り</code> に切り替える</li>
            <li><strong>細かく調整</strong>：<code>prompts/system-heavy.txt</code> のルール数値を増やす</li>
        </ul>

        <h2>装飾の崩れを確認するには</h2>
        <ul style="list-style:disc; padding-left:24px;">
            <li><strong>投稿編集画面のメタボックス</strong>：✅/⚠️/❌、警告メッセージ、文字数比率</li>
            <li><strong>処理ログ</strong>：全件の処理結果一覧（モデル・レベル・トークン数も表示）</li>
            <li><strong>一括処理画面</strong>：「⚠️要確認の記事のみ」フィルタで再処理対象を絞り込み</li>
            <li><strong>フロント表示</strong>：実際のページを開いてビジュアル確認（最終チェック）</li>
        </ul>
    </div>
    <?php
}
