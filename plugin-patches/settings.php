<?php
/**
 * 設定画面
 */
if (!defined('ABSPATH')) exit;

add_action('admin_init', 'ai_pi_register_settings');
function ai_pi_register_settings() {
    register_setting('ai_pi_settings_group', 'ai_pi_settings', [
        'sanitize_callback' => 'ai_pi_sanitize_settings',
    ]);
}

function ai_pi_sanitize_settings($input) {
    $output = [];
    $output['claude_api_key'] = sanitize_text_field($input['claude_api_key'] ?? '');
    $output['claude_model'] = sanitize_text_field($input['claude_model'] ?? 'claude-sonnet-4-6');
    $output['amazon_access_key'] = sanitize_text_field($input['amazon_access_key'] ?? '');
    $output['amazon_secret_key'] = sanitize_text_field($input['amazon_secret_key'] ?? '');
    $output['amazon_partner_tag'] = sanitize_text_field($input['amazon_partner_tag'] ?? '');
    $output['rakuten_app_id'] = sanitize_text_field($input['rakuten_app_id'] ?? '');
    $output['rakuten_affiliate_id'] = sanitize_text_field($input['rakuten_affiliate_id'] ?? '');

    $valid_modes = ['marker', 'marker_per_heading', 'auto'];
    $output['default_insert_mode'] = in_array($input['default_insert_mode'] ?? '', $valid_modes)
        ? $input['default_insert_mode'] : 'marker';

    $output['default_card_design'] = in_array($input['default_card_design'] ?? '', ['vertical', 'horizontal', 'ranking'])
        ? $input['default_card_design'] : 'vertical';

    $valid_positions = ['top', 'before_first_h2', 'after_first_h2', 'before_last_h2', 'after_last_h2', 'bottom'];
    $output['default_position'] = in_array($input['default_position'] ?? '', $valid_positions)
        ? $input['default_position'] : 'bottom';

    $output['products_per_marker'] = intval($input['products_per_marker'] ?? 1);
    $output['ranking_count'] = max(1, min(10, intval($input['ranking_count'] ?? 3)));
    $output['candidates_per_keyword'] = max(5, min(30, intval($input['candidates_per_keyword'] ?? 10)));

    $output['preferred_site'] = in_array($input['preferred_site'] ?? '', ['amazon', 'rakuten', 'both'])
        ? $input['preferred_site'] : 'both';

    $output['enable_24h_refresh'] = ($input['enable_24h_refresh'] ?? 'no') === 'yes' ? 'yes' : 'no';

    return $output;
}

function ai_pi_render_settings_page() {
    if (!current_user_can('manage_options')) return;
    $settings = get_option('ai_pi_settings', []);

    // 旧設定の自動マイグレーション（auto_top3_position → default_position）
    if (empty($settings['default_position']) && !empty($settings['auto_top3_position'])) {
        $settings['default_position'] = $settings['auto_top3_position'];
    }

    $preview_url = admin_url('admin.php?page=ai-product-inserter-preview');
    ?>
    <div class="wrap aipi-wrap">
        <h1>AIプロダクトインサーター 設定</h1>

        <?php if (isset($_GET['settings-updated'])): ?>
            <div class="notice notice-success is-dismissible"><p>設定を保存しました。</p></div>
        <?php endif; ?>

        <form method="post" action="options.php">
            <?php settings_fields('ai_pi_settings_group'); ?>

            <h2>API設定</h2>
            <table class="form-table">
                <tr>
                    <th><label>Claude APIキー</label></th>
                    <td>
                        <input type="password" name="ai_pi_settings[claude_api_key]" value="<?php echo esc_attr($settings['claude_api_key'] ?? ''); ?>" class="regular-text" autocomplete="off">
                        <p class="description">Anthropic Consoleで発行</p>
                    </td>
                </tr>
                <tr>
                    <th><label>Claudeモデル</label></th>
                    <td>
                        <select name="ai_pi_settings[claude_model]">
                            <option value="claude-opus-4-7" <?php selected($settings['claude_model'] ?? '', 'claude-opus-4-7'); ?>>Claude Opus 4.7（最高品質）</option>
                            <option value="claude-sonnet-4-6" <?php selected($settings['claude_model'] ?? '', 'claude-sonnet-4-6'); ?>>Claude Sonnet 4.6（推奨）</option>
                            <option value="claude-haiku-4-5-20251001" <?php selected($settings['claude_model'] ?? '', 'claude-haiku-4-5-20251001'); ?>>Claude Haiku 4.5（最安）</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label>Amazon Access Key</label></th>
                    <td><input type="password" name="ai_pi_settings[amazon_access_key]" value="<?php echo esc_attr($settings['amazon_access_key'] ?? ''); ?>" class="regular-text" autocomplete="off"></td>
                </tr>
                <tr>
                    <th><label>Amazon Secret Key</label></th>
                    <td><input type="password" name="ai_pi_settings[amazon_secret_key]" value="<?php echo esc_attr($settings['amazon_secret_key'] ?? ''); ?>" class="regular-text" autocomplete="off"></td>
                </tr>
                <tr>
                    <th><label>Amazon アソシエイトタグ</label></th>
                    <td>
                        <input type="text" name="ai_pi_settings[amazon_partner_tag]" value="<?php echo esc_attr($settings['amazon_partner_tag'] ?? ''); ?>" class="regular-text">
                        <p class="description">例: yourname-22</p>
                    </td>
                </tr>
                <tr>
                    <th><label>楽天 アプリID</label></th>
                    <td>
                        <input type="text" name="ai_pi_settings[rakuten_app_id]" value="<?php echo esc_attr($settings['rakuten_app_id'] ?? ''); ?>" class="regular-text">
                        <p class="description"><a href="https://webservice.rakuten.co.jp/" target="_blank">楽天ウェブサービス</a>で取得</p>
                    </td>
                </tr>
                <tr>
                    <th><label>楽天 アフィリエイトID</label></th>
                    <td><input type="text" name="ai_pi_settings[rakuten_affiliate_id]" value="<?php echo esc_attr($settings['rakuten_affiliate_id'] ?? ''); ?>" class="regular-text"></td>
                </tr>
            </table>

            <h2>挿入動作（3軸で独立指定）</h2>

            <h3>① 挿入方式（どこを挿入位置の起点にするか）</h3>
            <table class="form-table">
                <tr>
                    <th>方式</th>
                    <td>
                        <label><input type="radio" name="ai_pi_settings[default_insert_mode]" value="marker" <?php checked($settings['default_insert_mode'] ?? '', 'marker'); ?>> <strong>マーカー方式</strong>　<code>&lt;!--ai-product--&gt;</code> の位置に挿入（記事全体の文脈から関連商品を選定）</label><br>
                        <label><input type="radio" name="ai_pi_settings[default_insert_mode]" value="marker_per_heading" <?php checked($settings['default_insert_mode'] ?? '', 'marker_per_heading'); ?>> <strong>見出し連動マーカー方式</strong> ⭐　マーカー直前のH2/H3から商品名を抽出して個別検索（5選記事向け）</label><br>
                        <label><input type="radio" name="ai_pi_settings[default_insert_mode]" value="auto" <?php checked($settings['default_insert_mode'] ?? '', 'auto'); ?>> <strong>自動配置</strong>　マーカー不要、③で指定した位置に自動挿入</label>
                        <p class="description">投稿ごとに編集画面のメタボックスから切り替え可能</p>
                    </td>
                </tr>
            </table>

            <h3>② デザイン　<a href="<?php echo esc_url($preview_url); ?>" target="_blank" class="button button-secondary">🎨 デザインプレビューを開く</a></h3>
            <table class="form-table">
                <tr>
                    <th>カードデザイン</th>
                    <td>
                        <label><input type="radio" name="ai_pi_settings[default_card_design]" value="vertical" <?php checked($settings['default_card_design'] ?? '', 'vertical'); ?>> 縦置きカード（画像大・3ボタン）</label><br>
                        <label><input type="radio" name="ai_pi_settings[default_card_design]" value="horizontal" <?php checked($settings['default_card_design'] ?? '', 'horizontal'); ?>> 横長カード（軽量・記事に馴染む）</label><br>
                        <label><input type="radio" name="ai_pi_settings[default_card_design]" value="ranking" <?php checked($settings['default_card_design'] ?? '', 'ranking'); ?>> ランキングカード（複数商品を1ブロックで表示）</label>
                        <p class="description">プレビューボタンで実際の見た目を確認できます</p>
                    </td>
                </tr>
                <tr>
                    <th><label>ランキング件数</label></th>
                    <td>
                        <input type="number" name="ai_pi_settings[ranking_count]" value="<?php echo esc_attr($settings['ranking_count'] ?? 3); ?>" min="1" max="10" style="width:80px;">
                        <p class="description">ランキングカードでの商品数（TOP3〜TOP10）</p>
                    </td>
                </tr>
            </table>

            <h3>③ 挿入位置（自動配置モードのときのみ使用）</h3>
            <table class="form-table">
                <tr>
                    <th>位置</th>
                    <td>
                        <label><input type="radio" name="ai_pi_settings[default_position]" value="top" <?php checked($settings['default_position'] ?? 'bottom', 'top'); ?>> 記事冒頭</label><br>
                        <label><input type="radio" name="ai_pi_settings[default_position]" value="before_first_h2" <?php checked($settings['default_position'] ?? '', 'before_first_h2'); ?>> 最初のH2見出しの直前</label><br>
                        <label><input type="radio" name="ai_pi_settings[default_position]" value="after_first_h2" <?php checked($settings['default_position'] ?? '', 'after_first_h2'); ?>> 最初のH2見出しの直後</label><br>
                        <label><input type="radio" name="ai_pi_settings[default_position]" value="before_last_h2" <?php checked($settings['default_position'] ?? '', 'before_last_h2'); ?>> 最後のH2見出しの直前</label><br>
                        <label><input type="radio" name="ai_pi_settings[default_position]" value="after_last_h2" <?php checked($settings['default_position'] ?? '', 'after_last_h2'); ?>> 最後のH2見出しの直後</label><br>
                        <label><input type="radio" name="ai_pi_settings[default_position]" value="bottom" <?php checked($settings['default_position'] ?? 'bottom', 'bottom'); ?>> 記事末尾</label>
                        <p class="description">マーカー方式・見出し連動マーカー方式では無視されます（マーカー位置に挿入）</p>
                    </td>
                </tr>
            </table>

            <h2>その他</h2>
            <table class="form-table">
                <tr>
                    <th><label>候補商品取得数</label></th>
                    <td>
                        <input type="number" name="ai_pi_settings[candidates_per_keyword]" value="<?php echo esc_attr($settings['candidates_per_keyword'] ?? 10); ?>" min="5" max="30" style="width:80px;">
                        <p class="description">1キーワード/見出しあたりの取得候補数（Amazon・楽天それぞれから）</p>
                    </td>
                </tr>
                <tr>
                    <th>優先サイト</th>
                    <td>
                        <label><input type="radio" name="ai_pi_settings[preferred_site]" value="both" <?php checked($settings['preferred_site'] ?? '', 'both'); ?>> Amazon + 楽天両方</label><br>
                        <label><input type="radio" name="ai_pi_settings[preferred_site]" value="amazon" <?php checked($settings['preferred_site'] ?? '', 'amazon'); ?>> Amazonのみ</label><br>
                        <label><input type="radio" name="ai_pi_settings[preferred_site]" value="rakuten" <?php checked($settings['preferred_site'] ?? '', 'rakuten'); ?>> 楽天のみ</label>
                    </td>
                </tr>
                <tr>
                    <th>24時間ルール</th>
                    <td>
                        <label><input type="checkbox" name="ai_pi_settings[enable_24h_refresh]" value="yes" <?php checked($settings['enable_24h_refresh'] ?? '', 'yes'); ?>> 24時間経過した商品データに期限切れフラグを立てる</label>
                        <p class="description">⚠️ Amazon PA-APIの規約：取得から24時間以内に表示すること</p>
                    </td>
                </tr>
            </table>

            <?php submit_button(); ?>
        </form>

        <hr>

        <h2>🔬 Amazon API デバッグ情報</h2>
        <?php
        $debug_sample = get_transient('ai_pi_last_amazon_raw_sample');
        if ($debug_sample && is_array($debug_sample)):
        ?>
            <p style="color:#666;font-size:12px;">直近の Amazon API レスポンスの先頭商品（30分有効）</p>
            <table class="form-table" style="background:#f6f7f7;padding:10px;">
                <tr><th>検索キーワード</th><td><code><?php echo esc_html($debug_sample['keyword'] ?? ''); ?></code></td></tr>
                <tr><th>ASIN</th><td><code><?php echo esc_html($debug_sample['asin'] ?? ''); ?></code></td></tr>
                <tr><th>Offers フィールドあり</th><td><?php echo !empty($debug_sample['has_offers']) ? '✅' : '❌'; ?></td></tr>
                <tr><th>Offers.Listings あり</th><td><?php echo !empty($debug_sample['has_listings']) ? '✅' : '❌（価格が取れない原因）'; ?></td></tr>
                <tr><th>Offers.Summaries あり</th><td><?php echo !empty($debug_sample['has_summaries']) ? '✅' : '❌'; ?></td></tr>
                <tr><th>CustomerReviews あり</th><td><?php echo !empty($debug_sample['has_reviews']) ? '✅' : '❌（レビューが取れない原因）'; ?></td></tr>
                <tr><th>取得時刻</th><td><?php echo esc_html($debug_sample['fetched_at'] ?? ''); ?></td></tr>
            </table>
            <details style="margin-top:10px;">
                <summary>📋 raw JSON（クリックで展開）</summary>
                <pre style="background:#1e1e1e;color:#dcdcdc;padding:10px;font-size:11px;max-height:400px;overflow:auto;white-space:pre-wrap;word-break:break-all;"><?php echo esc_html($debug_sample['raw_first_item'] ?? ''); ?></pre>
            </details>
        <?php else: ?>
            <p style="color:#999;">まだ Amazon API へのリクエストが実行されていません。記事で「商品挿入を実行」または「再挿入を実行」を押すと、ここに直近レスポンスが表示されます。</p>
        <?php endif; ?>

        <hr>

        <h2>使い方</h2>

        <h3>① マーカー方式（記事全体の文脈から選定）</h3>
        <p>本文に <code>&lt;!--ai-product--&gt;</code> を置くと、その位置にAIが記事全体の文脈に合う商品を挿入します。マーカー数=挿入数。</p>

        <h3>② 見出し連動マーカー方式 ⭐（5選記事・比較記事向け）</h3>
        <p>各見出しに商品名（例「第1位 ダイソン V15」）を入れ、見出しの後に <code>&lt;!--ai-product--&gt;</code> を置きます。プラグインが見出しから装飾を自動除去して個別検索 → 指名商品をピンポイントで挿入。</p>

        <h3>③ 自動配置（マーカー不要）</h3>
        <p>記事本文だけあればOK。指定したデザイン・指定した位置にAIが選んだ商品を挿入します。デザイン「ランキングカード」を選べばTOP3〜10、それ以外は1商品のみ。</p>
    </div>
    <?php
}
