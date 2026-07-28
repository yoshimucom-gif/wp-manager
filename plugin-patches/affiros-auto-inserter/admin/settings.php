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
        $val = trim(sanitize_text_field($input[$k] ?? ''));
        // 空・マスク値 (*のみ) は既存値を保持。実際の値が入力された時だけ更新
        if ($val !== '' && !preg_match('/^\*+$/', $val)) $output[$k] = $val;
        // 過去のバグでマスク値が保存されてしまっている場合は破棄 (invalid x-api-key の原因)
        if (isset($output[$k]) && preg_match('/^\*+$/', (string)$output[$k])) unset($output[$k]);
    }

    $plain_keys = ['amazon_partner_tag', 'amazon_marketplace', 'rakuten_affiliate_id'];
    foreach ($plain_keys as $k) {
        $output[$k] = sanitize_text_field($input[$k] ?? '');
    }

    $output['insert_before_first_h2'] = ($input['insert_before_first_h2'] ?? 'no') === 'yes' ? 'yes' : 'no';
    $output['insert_after_matome']    = ($input['insert_after_matome']    ?? 'no') === 'yes' ? 'yes' : 'no';
    $output['products_count']         = max(1, min(5, intval($input['products_count'] ?? 3)));
    $output['target_statuses']        = sanitize_text_field($input['target_statuses'] ?? 'publish,future,draft');

    // 見出し文言。空で保存されたら既定に戻す
    $defaults = affiros_ai_default_settings();
    foreach (['card_heading', 'side_heading'] as $k) {
        $val = trim(sanitize_text_field($input[$k] ?? ''));
        $output[$k] = $val !== '' ? $val : $defaults[$k];
    }

    $output['skip_ranking_articles']  = ($input['skip_ranking_articles'] ?? 'no') === 'yes' ? 'yes' : 'no';
    $output['ranking_title_patterns'] = sanitize_textarea_field($input['ranking_title_patterns'] ?? '');

    $output['auto_on_publish']        = ($input['auto_on_publish'] ?? 'no') === 'yes' ? 'yes' : 'no';
    // v0.7.0 で週次リフレッシュ廃止。旧設定が残っていたら捨てる
    unset($output['cron_refresh']);

    return $output;
}

// 秘密キーが「本物の値で」設定済みか (過去バグで保存されたマスク値は未設定扱い)
function affiros_ai_secret_is_set($val) {
    return $val !== '' && !preg_match('/^\*+$/', (string)$val);
}

// 秘密キー入力欄: 値は絶対に出力しない。placeholder で設定状態だけ伝える
function affiros_ai_secret_field($key, $settings) {
    $is_set = affiros_ai_secret_is_set($settings[$key] ?? '');
    printf(
        '<input type="password" name="%s[%s]" value="" placeholder="%s" class="regular-text" autocomplete="new-password"> %s',
        esc_attr(AFFIROS_AI_OPTION_KEY),
        esc_attr($key),
        $is_set ? '設定済み（変更する場合のみ入力）' : '未設定',
        $is_set ? '<span style="color:#00a32a">✓ 設定済み</span>' : '<span style="color:#d63638">未設定</span>'
    );
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
                        <?php affiros_ai_secret_field('claude_api_key', $settings); ?>
                        <p class="description">
                            記事本文から検索キーワードを抽出する用。Haiku 使用でコスト 1記事あたり ¥0.3 程度。
                            <br>入力欄が空のまま保存すると既存値を保持。値を更新する場合だけ入力。
                        </p>
                    </td>
                </tr>
            </table>

            <h2>② Amazon Creators API</h2>
            <table class="form-table">
                <tr>
                    <th>Client ID</th>
                    <td><?php affiros_ai_secret_field('amazon_client_id', $settings); ?></td>
                </tr>
                <tr>
                    <th>Client Secret</th>
                    <td><?php affiros_ai_secret_field('amazon_client_secret', $settings); ?></td>
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
                    <td><?php affiros_ai_secret_field('rakuten_app_id', $settings); ?></td>
                </tr>
                <tr>
                    <th>アクセスキー</th>
                    <td><?php affiros_ai_secret_field('rakuten_access_key', $settings); ?><p class="description">2026-05〜 の新仕様。「アプリID + アクセスキー」両方必須。</p></td>
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
                        <label><input type="checkbox" name="<?php echo AFFIROS_AI_OPTION_KEY; ?>[insert_after_matome]" value="yes" <?php checked($settings['insert_after_matome'], 'yes'); ?>> 「まとめ」H2見出しの直下 (まとめ本文の前)</label>
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
                <tr>
                    <th>記事内カードの見出し</th>
                    <td>
                        <input type="text" name="<?php echo AFFIROS_AI_OPTION_KEY; ?>[card_heading]" value="<?php echo esc_attr($settings['card_heading']); ?>" class="regular-text" placeholder="おすすめ商品比較">
                        <p class="description">例: <code>超売れ筋のおすすめTOP3</code>。空で保存すると既定「おすすめ商品比較」に戻る。<br>表示時に差し替える方式なので、保存すれば既存記事にも即反映（再挿入不要）。</p>
                    </td>
                </tr>
                <tr>
                    <th>サイドバーカードの見出し</th>
                    <td>
                        <input type="text" name="<?php echo AFFIROS_AI_OPTION_KEY; ?>[side_heading]" value="<?php echo esc_attr($settings['side_heading']); ?>" class="regular-text" placeholder="この記事のイチオシ">
                        <p class="description">ショートコード <code>[affiros_ai_top]</code> の見出し。こちらは動的表示なので保存すれば即反映。<br><code>title="..."</code> 属性を書いた場合はそちらが優先。</p>
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
            </table>

            <?php submit_button(); ?>
        </form>

        <h2>⑦ サイドバー用ショートコード</h2>
        <div style="max-width:680px;background:#fff;border:1px solid #ccd0d4;border-radius:4px;padding:16px 20px;font-size:13px;line-height:1.9">
            <p style="margin-top:0">
                表示中の記事に挿入済みの商品をコンパクトカード（画像・商品名・価格・Amazon/楽天ボタン）で表示します。<br>
                <strong>外観 → ウィジェット</strong> でサイドバーに「ショートコード」ブロックを追加して、以下を書くだけ。設置は1回、記事ごとに自動でその記事の商品に切り替わります。
            </p>
            <table class="widefat striped" style="max-width:640px">
                <thead><tr><th style="width:280px">書き方</th><th>動作</th></tr></thead>
                <tbody>
                    <tr><td><code>[affiros_ai_top]</code></td><td>その記事の1位商品を表示</td></tr>
                    <tr><td><code>[affiros_ai_top rank="2"]</code></td><td>2位を表示（1位の下にもう1ブロック置けば2枚並ぶ）</td></tr>
                    <tr><td><code>[affiros_ai_top title="今日のイチオシ"]</code></td><td>見出しを個別指定（既定は上の「サイドバーカードの見出し」設定値）</td></tr>
                    <tr><td><code>[affiros_ai_top title=""]</code></td><td>見出しなし</td></tr>
                </tbody>
            </table>
            <p style="margin-bottom:0" class="description">
                商品データはこのプラグインが記事に挿入した時のキャッシュを読むだけ（API・AI呼び出しゼロ、表示速度に影響なし）。<br>
                データがないページ（トップ・固定ページ・アーカイブ・未挿入記事・ランキング記事）では何も出力しないので、全ページ共通のサイドバーに置いて安全です。<br>
                ボタンの飛び先は記事内カードと同じ（主軸=商品ページ、他方=検索一覧のアフィリエイトリンク）。記事のカードを再挿入すればサイドバーも自動で追従します。
            </p>
        </div>
    </div>
    <?php
}
