<?php
/**
 * オートインサーター 設定画面
 */

if (!defined('ABSPATH')) exit;

add_action('admin_init', function () {
    register_setting('affiros_ai_group', AFFIROS_AI_OPTION_KEY, [
        'sanitize_callback' => 'affiros_ai_sanitize_settings',
    ]);
});

function affiros_ai_sanitize_settings($input) {
    $existing = get_option(AFFIROS_AI_OPTION_KEY, []);
    $output = is_array($existing) ? $existing : [];

    $secret_keys = ['claude_api_key', 'amazon_client_id', 'amazon_client_secret',
                    'rakuten_app_id', 'rakuten_access_key'];
    foreach ($secret_keys as $k) {
        $val = sanitize_text_field($input[$k] ?? '');
        // 空でない場合のみ更新 (マスク値でも空でも上書きしないため)
        if ($val !== '') $output[$k] = $val;
    }

    $plain_keys = ['amazon_partner_tag', 'amazon_marketplace', 'rakuten_affiliate_id'];
    foreach ($plain_keys as $k) {
        $output[$k] = sanitize_text_field($input[$k] ?? '');
    }

    $output['insert_before_first_h2'] = ($input['insert_before_first_h2'] ?? 'no') === 'yes' ? 'yes' : 'no';
    $output['insert_after_matome']    = ($input['insert_after_matome']    ?? 'no') === 'yes' ? 'yes' : 'no';
    $output['products_count']         = max(1, min(5, intval($input['products_count'] ?? 3)));
    $output['target_statuses']        = sanitize_text_field($input['target_statuses'] ?? 'publish,future,draft');

    $output['skip_ranking_articles']  = ($input['skip_ranking_articles'] ?? 'no') === 'yes' ? 'yes' : 'no';
    $output['ranking_title_patterns'] = sanitize_textarea_field($input['ranking_title_patterns'] ?? '');

    $output['auto_on_publish']        = ($input['auto_on_publish'] ?? 'no') === 'yes' ? 'yes' : 'no';
    $output['cron_refresh']           = ($input['cron_refresh']    ?? 'no') === 'yes' ? 'yes' : 'no';

    return $output;
}

function affiros_ai_render_settings_page() {
    if (!current_user_can('manage_options')) return;
    $settings = affiros_ai_get_settings();
    ?>
    <div class="wrap">
        <h1>⚙️ Affiros オートインサーター 設定</h1>
        <p style="font-size:13px;line-height:1.7">
            Claude Haiku が本文からキーワードを抽出し、Amazon + 楽天から商品3件を「最初のH2直前」「まとめ直後」に自動挿入します。
            マーカー配置不要・記事本体は無関係なので商品差し替え可能・ランキング記事は自動判定してスキップします。
        </p>

        <form method="post" action="options.php">
            <?php settings_fields('affiros_ai_group'); ?>

            <h2>① Claude API</h2>
            <table class="form-table">
                <tr>
                    <th>Claude API キー</th>
                    <td>
                        <input type="password" name="<?php echo AFFIROS_AI_OPTION_KEY; ?>[claude_api_key]" value="<?php echo esc_attr($settings['claude_api_key'] ? str_repeat('*', 20) : ''); ?>" class="regular-text" autocomplete="off">
                        <p class="description">
                            記事本文から検索キーワードを抽出する用。Haiku 使用でコスト 1記事あたり ¥0.3 程度。
                            <br>入力欄が空だと保存時に既存値を保持。値を更新する場合は上書き入力。
                        </p>
                    </td>
                </tr>
            </table>

            <h2>② Amazon Creators API</h2>
            <table class="form-table">
                <tr>
                    <th>Client ID</th>
                    <td><input type="password" name="<?php echo AFFIROS_AI_OPTION_KEY; ?>[amazon_client_id]" value="<?php echo esc_attr($settings['amazon_client_id'] ? str_repeat('*', 20) : ''); ?>" class="regular-text" autocomplete="off"></td>
                </tr>
                <tr>
                    <th>Client Secret</th>
                    <td><input type="password" name="<?php echo AFFIROS_AI_OPTION_KEY; ?>[amazon_client_secret]" value="<?php echo esc_attr($settings['amazon_client_secret'] ? str_repeat('*', 20) : ''); ?>" class="regular-text" autocomplete="off"></td>
                </tr>
                <tr>
                    <th>Partner Tag (アソシエイトID)</th>
                    <td><input type="text" name="<?php echo AFFIROS_AI_OPTION_KEY; ?>[amazon_partner_tag]" value="<?php echo esc_attr($settings['amazon_partner_tag']); ?>" class="regular-text"></td>
                </tr>
                <tr>
                    <th>Marketplace</th>
                    <td><input type="text" name="<?php echo AFFIROS_AI_OPTION_KEY; ?>[amazon_marketplace]" value="<?php echo esc_attr($settings['amazon_marketplace']); ?>" class="regular-text"><p class="description">既定: <code>www.amazon.co.jp</code></p></td>
                </tr>
            </table>

            <h2>③ 楽天市場API</h2>
            <table class="form-table">
                <tr>
                    <th>アプリID</th>
                    <td><input type="password" name="<?php echo AFFIROS_AI_OPTION_KEY; ?>[rakuten_app_id]" value="<?php echo esc_attr($settings['rakuten_app_id'] ? str_repeat('*', 20) : ''); ?>" class="regular-text" autocomplete="off"></td>
                </tr>
                <tr>
                    <th>アクセスキー</th>
                    <td><input type="password" name="<?php echo AFFIROS_AI_OPTION_KEY; ?>[rakuten_access_key]" value="<?php echo esc_attr($settings['rakuten_access_key'] ? str_repeat('*', 20) : ''); ?>" class="regular-text" autocomplete="off"><p class="description">2026-05〜 の新仕様。「アプリID + アクセスキー」両方必須。</p></td>
                </tr>
                <tr>
                    <th>アフィリエイトID</th>
                    <td><input type="text" name="<?php echo AFFIROS_AI_OPTION_KEY; ?>[rakuten_affiliate_id]" value="<?php echo esc_attr($settings['rakuten_affiliate_id']); ?>" class="regular-text"></td>
                </tr>
            </table>

            <h2>④ 挿入設定</h2>
            <table class="form-table">
                <tr>
                    <th>挿入位置</th>
                    <td>
                        <label><input type="checkbox" name="<?php echo AFFIROS_AI_OPTION_KEY; ?>[insert_before_first_h2]" value="yes" <?php checked($settings['insert_before_first_h2'], 'yes'); ?>> 最初のH2の直前 (リード直後)</label><br>
                        <label><input type="checkbox" name="<?php echo AFFIROS_AI_OPTION_KEY; ?>[insert_after_matome]" value="yes" <?php checked($settings['insert_after_matome'], 'yes'); ?>> 「まとめ」H2 の直後</label>
                        <p class="description">両方 ON で1記事に2枚。片方だけでも OK。</p>
                    </td>
                </tr>
                <tr>
                    <th>表示商品数</th>
                    <td>
                        <input type="number" name="<?php echo AFFIROS_AI_OPTION_KEY; ?>[products_count]" value="<?php echo esc_attr($settings['products_count']); ?>" min="1" max="5" style="width:80px"> 件
                    </td>
                </tr>
                <tr>
                    <th>対象ステータス</th>
                    <td>
                        <input type="text" name="<?php echo AFFIROS_AI_OPTION_KEY; ?>[target_statuses]" value="<?php echo esc_attr($settings['target_statuses']); ?>" class="regular-text">
                        <p class="description">カンマ区切り。既定 <code>publish,future,draft</code></p>
                    </td>
                </tr>
            </table>

            <h2>⑤ ランキング記事判定 (自動挿入対象外)</h2>
            <table class="form-table">
                <tr>
                    <th>ランキング記事はスキップ</th>
                    <td><label><input type="checkbox" name="<?php echo AFFIROS_AI_OPTION_KEY; ?>[skip_ranking_articles]" value="yes" <?php checked($settings['skip_ranking_articles'], 'yes'); ?>> タイトルパターンに一致する記事は挿入しない</label></td>
                </tr>
                <tr>
                    <th>タイトルパターン (正規表現)</th>
                    <td>
                        <textarea name="<?php echo AFFIROS_AI_OPTION_KEY; ?>[ranking_title_patterns]" rows="6" style="width:480px;font-family:monospace"><?php echo esc_textarea($settings['ranking_title_patterns']); ?></textarea>
                        <p class="description">1行1パターン。既定: 「選」「ランキング」「おすすめN位」「ベストN」。<br>手動除外は各記事の編集画面メタボックスから。</p>
                    </td>
                </tr>
            </table>

            <h2>⑥ 自動化</h2>
            <table class="form-table">
                <tr>
                    <th>公開時に自動挿入</th>
                    <td><label><input type="checkbox" name="<?php echo AFFIROS_AI_OPTION_KEY; ?>[auto_on_publish]" value="yes" <?php checked($settings['auto_on_publish'], 'yes'); ?>> 記事公開時に自動で商品カードを挿入する</label><p class="description">公開の60秒後に WP Cron 経由で実行 (公開自体を遅らせない)</p></td>
                </tr>
                <tr>
                    <th>週次リフレッシュ</th>
                    <td><label><input type="checkbox" name="<?php echo AFFIROS_AI_OPTION_KEY; ?>[cron_refresh] " value="yes" <?php checked($settings['cron_refresh'], 'yes'); ?>> 週1回、既存記事の商品情報を再取得して価格・在庫を最新化</label></td>
                </tr>
            </table>

            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}
