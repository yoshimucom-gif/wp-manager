<?php
/**
 * 設定画面（スリム版）
 *
 * wp-manager 側がマーカーに「位置・デザイン・件数」を全部埋め込むので、
 * このプラグインの UI は API認証情報と運用調整値だけに絞る。
 *
 * 削除した項目（内部的にはデフォルト固定で動作）:
 * - default_insert_mode: 常に 'marker' で動く
 * - default_card_design: マーカーで指定される (vertical / ranking等)
 * - ranking_count:       マーカーで指定される
 * - default_position:    wp-manager が決める
 * - preferred_site:      'both' 固定（v1.7.2 で merge側が Amazon ベースに揃える）
 */
if (!defined('ABSPATH')) exit;

add_action('admin_init', 'ai_pi_register_settings');
function ai_pi_register_settings() {
    register_setting('ai_pi_settings_group', 'ai_pi_settings', [
        'sanitize_callback' => 'ai_pi_sanitize_settings',
    ]);
}

function ai_pi_sanitize_settings($input) {
    // 既存設定を維持しつつ、UIで触れる項目だけ更新する。
    $existing = get_option('ai_pi_settings', []);
    $output = is_array($existing) ? $existing : [];

    // API系（UIで編集可）
    $output['claude_api_key']      = sanitize_text_field($input['claude_api_key'] ?? ($existing['claude_api_key'] ?? ''));
    $output['claude_model']        = sanitize_text_field($input['claude_model'] ?? ($existing['claude_model'] ?? 'claude-haiku-4-5-20251001'));
    $output['amazon_access_key']   = sanitize_text_field($input['amazon_access_key'] ?? ($existing['amazon_access_key'] ?? ''));
    $output['amazon_secret_key']   = sanitize_text_field($input['amazon_secret_key'] ?? ($existing['amazon_secret_key'] ?? ''));
    $output['amazon_partner_tag']  = sanitize_text_field($input['amazon_partner_tag'] ?? ($existing['amazon_partner_tag'] ?? ''));
    $output['rakuten_app_id']      = sanitize_text_field($input['rakuten_app_id'] ?? ($existing['rakuten_app_id'] ?? ''));
    $output['rakuten_affiliate_id']= sanitize_text_field($input['rakuten_affiliate_id'] ?? ($existing['rakuten_affiliate_id'] ?? ''));

    // 運用調整値（UIで編集可）
    $output['candidates_per_keyword'] = max(5, min(30, intval($input['candidates_per_keyword'] ?? ($existing['candidates_per_keyword'] ?? 10))));
    $output['enable_24h_refresh']     = ($input['enable_24h_refresh'] ?? ($existing['enable_24h_refresh'] ?? 'no')) === 'yes' ? 'yes' : 'no';

    // 自動挿入（v1.9.17〜）
    $output['auto_insert_enabled']         = ($input['auto_insert_enabled'] ?? ($existing['auto_insert_enabled'] ?? 'no')) === 'yes' ? 'yes' : 'no';
    $output['auto_insert_delay_minutes']   = max(0, min(60, intval($input['auto_insert_delay_minutes'] ?? ($existing['auto_insert_delay_minutes'] ?? 5))));

    // 内部固定（UIから消したが値は持っておく）
    $output['default_insert_mode']  = 'marker';
    $output['default_card_design']  = $existing['default_card_design']  ?? 'vertical';
    $output['ranking_count']        = isset($existing['ranking_count']) ? intval($existing['ranking_count']) : 3;
    $output['default_position']     = $existing['default_position']     ?? 'bottom';
    $output['preferred_site']       = 'both'; // v1.7.2 で merge 側が Amazon ベースに統合する

    return $output;
}

function ai_pi_render_settings_page() {
    if (!current_user_can('manage_options')) return;
    $settings = get_option('ai_pi_settings', []);
    $preview_url = admin_url('admin.php?page=ai-product-inserter-preview');
    ?>
    <div class="wrap aipi-wrap">
        <h1>AIプロダクトインサーター 設定</h1>

        <?php if (isset($_GET['settings-updated'])): ?>
            <div class="notice notice-success is-dismissible"><p>設定を保存しました。</p></div>
        <?php endif; ?>

        <div class="notice notice-info" style="padding:10px 14px">
            <p style="margin:0">
                記事本文の <code>&lt;!--ai-product--&gt;</code> マーカー位置に商品カードを自動挿入します。<br>
                <strong>カードの位置・デザイン・件数は wp-manager 側のマーカーで指定済み</strong>なので、
                このプラグインで触る項目は API認証情報と運用調整だけです。
            </p>
        </div>

        <form method="post" action="options.php">
            <?php settings_fields('ai_pi_settings_group'); ?>

            <h2>API設定</h2>
            <table class="form-table">
                <tr>
                    <th><label>Claude APIキー</label></th>
                    <td>
                        <input type="password" name="ai_pi_settings[claude_api_key]" value="<?php echo esc_attr($settings['claude_api_key'] ?? ''); ?>" class="regular-text" autocomplete="off">
                        <p class="description">Anthropic Console で発行</p>
                    </td>
                </tr>
                <tr>
                    <th><label>Claudeモデル</label></th>
                    <td>
                        <select name="ai_pi_settings[claude_model]">
                            <option value="claude-haiku-4-5-20251001" <?php selected($settings['claude_model'] ?? '', 'claude-haiku-4-5-20251001'); ?>>Claude Haiku 4.5（推奨・最安）</option>
                            <option value="claude-sonnet-4-6" <?php selected($settings['claude_model'] ?? '', 'claude-sonnet-4-6'); ?>>Claude Sonnet 4.6（高品質）</option>
                            <option value="claude-opus-4-7" <?php selected($settings['claude_model'] ?? '', 'claude-opus-4-7'); ?>>Claude Opus 4.7（最高品質・割高）</option>
                        </select>
                        <p class="description">商品選定タスクは Haiku で十分な精度が出ます。Sonnet/Opus は記事のニュアンス読解が特に重要なときだけ。</p>
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

            <p>
                <button type="button" class="button button-secondary aipi-test-credentials">🔌 接続テスト（API有効性チェック）</button>
                <span class="spinner aipi-test-spinner" style="float:none;display:none;margin:0 0 0 6px;"></span>
            </p>
            <p class="description">入力中の値で実際に各APIへ接続し、キーが有効か確認します（保存前でも実行可）。Claude と Amazon は少量のAPIコールが発生します。</p>
            <div class="aipi-test-results" style="display:none;"></div>

            <h2>運用調整</h2>
            <table class="form-table">
                <tr>
                    <th><label>候補商品取得数</label></th>
                    <td>
                        <input type="number" name="ai_pi_settings[candidates_per_keyword]" value="<?php echo esc_attr($settings['candidates_per_keyword'] ?? 10); ?>" min="5" max="30" style="width:80px;">
                        <p class="description">1キーワード/見出しあたりに取得する商品候補数（Amazon・楽天それぞれから）。多いほど精度↑だがAPIコール↑。</p>
                    </td>
                </tr>
                <tr>
                    <th>24時間ルール</th>
                    <td>
                        <label><input type="checkbox" name="ai_pi_settings[enable_24h_refresh]" value="yes" <?php checked($settings['enable_24h_refresh'] ?? '', 'yes'); ?>> 24時間経過した商品データに期限切れフラグを立てる</label>
                        <p class="description">⚠️ Amazon PA-APIの規約：取得から24時間以内に表示する必要があります</p>
                    </td>
                </tr>
            </table>

            <h2>🤖 自動挿入</h2>
            <table class="form-table">
                <tr>
                    <th>自動挿入を有効化</th>
                    <td>
                        <label><input type="checkbox" name="ai_pi_settings[auto_insert_enabled]" value="yes" <?php checked($settings['auto_insert_enabled'] ?? '', 'yes'); ?>> 公開された記事に自動で商品カードを挿入する</label>
                        <p class="description">
                            予約投稿が公開された瞬間（または手動公開時）に、マーカー入りの記事へ自動で挿入されます。<br>
                            手動の「商品挿入を実行」ボタンと<strong>完全に同じロジック</strong>で動くので、品質は変わりません。<br>
                            <strong>対象</strong>: マーカー入り（<code>&lt;!--ai-product:...--&gt;</code>）かつ未挿入の post タイプ記事。<br>
                            <strong>失敗時</strong>: マーカーは残置されます（手動で再実行可）。post_meta <code>_ai_pi_auto_insert_last_error</code> にエラー内容を記録。
                        </p>
                    </td>
                </tr>
                <tr>
                    <th>遅延実行（分）</th>
                    <td>
                        <input type="number" name="ai_pi_settings[auto_insert_delay_minutes]" value="<?php echo esc_attr($settings['auto_insert_delay_minutes'] ?? 5); ?>" min="0" max="60" style="width:80px;"> 分後に実行
                        <p class="description">
                            公開アクション自体をブロックしないため、WP Cron 経由で N 分後に非同期実行します。<br>
                            <strong>0</strong>: 即時実行（公開処理が 5〜30秒遅延します）<br>
                            <strong>5</strong>（推奨）: 5分後に挿入<br>
                            ※ WP Cron はサイトへのアクセスで発火するため、低トラフィックなサイトでは遅延がさらに伸びることがあります。
                        </p>
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
        <p>wp-manager で記事を生成すると、記事本文に <code>&lt;!--ai-product:vertical--&gt;</code> や <code>&lt;!--ai-product:ranking:3--&gt;</code> のようなマーカーが自動で埋め込まれます。記事をWordPressに投稿後、編集画面のメタボックスから <strong>「商品挿入を実行」</strong>を押すと、マーカー位置に商品カードが描画されます。</p>
        <p style="font-size:12px;color:#666;">※ マーカー1個 = 商品カード1個。位置・デザイン・件数は wp-manager 側の <code>insert_card_markers()</code> で決定されます。</p>
        <p><a href="<?php echo esc_url($preview_url); ?>" class="button">🎨 デザインプレビューを開く</a></p>
    </div>
    <?php
}
