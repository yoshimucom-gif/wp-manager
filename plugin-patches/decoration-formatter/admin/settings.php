<?php
/**
 * 設定画面（AI装飾 + 段落整形を1画面に統合、フォームは2つ）
 */

if (!defined('ABSPATH')) exit;

add_action('admin_init', 'decofmt_register_settings');
function decofmt_register_settings() {
    register_setting('decofmt_deco_group', 'decofmt_deco_settings', [
        'sanitize_callback' => 'decofmt_deco_sanitize',
    ]);
    register_setting('decofmt_fmt_group', 'decofmt_fmt_settings', [
        'sanitize_callback' => 'decofmt_fmt_sanitize',
    ]);
}

function decofmt_deco_sanitize($input) {
    $output = [];
    $output['api_key'] = sanitize_text_field($input['api_key'] ?? '');

    $allowed_models = array_keys(decofmt_get_models());
    $output['model'] = in_array($input['model'] ?? '', $allowed_models, true)
        ? $input['model']
        : DECOFMT_DEFAULT_MODEL;

    $output['decoration_level'] = in_array($input['decoration_level'] ?? '', ['light', 'standard', 'heavy'])
        ? $input['decoration_level']
        : 'standard';
    $output['enable_faq'] = ($input['enable_faq'] ?? '') === 'yes' ? 'yes' : 'no';
    $output['auto_decorate_on_save'] = ($input['auto_decorate_on_save'] ?? '') === 'yes' ? 'yes' : 'no';
    return $output;
}

function decofmt_render_settings_page() {
    if (!current_user_can('manage_options')) return;
    $deco = get_option('decofmt_deco_settings', []);
    $fmt = decofmt_fmt_get_settings();
    $models = decofmt_get_models();
    $current_model = $deco['model'] ?? DECOFMT_DEFAULT_MODEL;
    ?>
    <div class="wrap decofmt-wrap">
        <h1>装飾・整形 設定</h1>

        <?php if (isset($_GET['settings-updated'])): ?>
            <div class="notice notice-success is-dismissible"><p>設定を保存しました。</p></div>
        <?php endif; ?>

        <p style="font-size:13px;color:#666;">
            AI装飾（Claude APIによる自動装飾）と段落整形（機械的な段落分割・見出し昇格）を1画面で管理します。
            それぞれ独立して保存できます。
        </p>

        <!-- ============================== AI装飾 ============================== -->
        <h2 style="margin-top:32px;padding-bottom:6px;border-bottom:2px solid #2271b1;">🎨 AI装飾の設定</h2>

        <form method="post" action="options.php">
            <?php settings_fields('decofmt_deco_group'); ?>

            <table class="form-table">
                <tr>
                    <th scope="row"><label for="api_key">Claude APIキー</label></th>
                    <td>
                        <input type="password" id="api_key" name="decofmt_deco_settings[api_key]"
                               value="<?php echo esc_attr($deco['api_key'] ?? ''); ?>"
                               class="regular-text" autocomplete="off">
                        <label style="margin-left:8px;font-size:12px;user-select:none;cursor:pointer;">
                            <input type="checkbox" id="api_key_toggle"
                                onchange="document.getElementById('api_key').type = this.checked ? 'text' : 'password';">
                            👁 表示
                        </label>
                        <p class="description">Anthropic Consoleで発行したAPIキーを入力</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">デフォルトの装飾品質</th>
                    <td>
                        <?php foreach ($models as $key => $m): ?>
                            <label style="display:block;margin:6px 0;">
                                <input type="radio" name="decofmt_deco_settings[model]" value="<?php echo esc_attr($key); ?>" <?php checked($current_model, $key); ?>>
                                <strong><?php echo esc_html($m['label']); ?></strong>
                                <span style="color:#666;">／ 約<?php echo esc_html($m['cost_yen']); ?>円/記事</span>
                                <br><span style="margin-left:24px;color:#888;font-size:12px;"><?php echo esc_html($m['description']); ?></span>
                            </label>
                        <?php endforeach; ?>
                        <p class="description">投稿編集画面や一括処理画面では、装飾実行時にここで選んだ品質がデフォルトになります</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">デフォルトの装飾レベル</th>
                    <td>
                        <label><input type="radio" name="decofmt_deco_settings[decoration_level]" value="light"
                            <?php checked($deco['decoration_level'] ?? '', 'light'); ?>> 軽め（マーカー＋ボックス少々）</label><br>
                        <label><input type="radio" name="decofmt_deco_settings[decoration_level]" value="standard"
                            <?php checked($deco['decoration_level'] ?? 'standard', 'standard'); ?>> 標準（バランス重視）</label><br>
                        <label><input type="radio" name="decofmt_deco_settings[decoration_level]" value="heavy"
                            <?php checked($deco['decoration_level'] ?? '', 'heavy'); ?>> 盛り盛り（全装飾フル活用）</label>
                        <p class="description">装飾の量。装飾実行時に変更可。さらに細かく調整したい場合は <code>prompts/system-*.txt</code> を直接編集</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">FAQ自動生成</th>
                    <td>
                        <label><input type="checkbox" name="decofmt_deco_settings[enable_faq]" value="yes"
                            <?php checked($deco['enable_faq'] ?? '', 'yes'); ?>> 記事末尾にFAQブロックを自動生成</label>
                    </td>
                </tr>
                <tr>
                    <th scope="row">投稿保存時の自動装飾</th>
                    <td>
                        <label><input type="checkbox" name="decofmt_deco_settings[auto_decorate_on_save]" value="yes"
                            <?php checked($deco['auto_decorate_on_save'] ?? '', 'yes'); ?>> 投稿保存時に未装飾なら自動実行</label>
                        <p class="description">
                            ⚠️ チェックすると<strong>公開済み記事を更新するたびに装飾APIが走ります</strong>（コスト発生）。<br>
                            通常はオフ推奨。
                        </p>
                    </td>
                </tr>
            </table>

            <?php submit_button('AI装飾の設定を保存'); ?>
        </form>

        <!-- ============================== 段落整形 ============================== -->
        <h2 style="margin-top:48px;padding-bottom:6px;border-bottom:2px solid #2271b1;">📝 段落整形の設定</h2>

        <form method="post" action="options.php">
            <?php settings_fields('decofmt_fmt_group'); ?>

            <table class="form-table">
                <tr>
                    <th>段落の最小文字数（分割対象判定）</th>
                    <td>
                        <input type="number" name="decofmt_fmt_settings[min_paragraph_chars]" value="<?php echo esc_attr($fmt['min_paragraph_chars']); ?>" min="80" max="1000" style="width:80px"> 字以上を「長い」と判定して分割対象に
                        <p class="description">既定 200。これ未満の段落は触らない。</p>
                    </td>
                </tr>
                <tr>
                    <th>1文の最小文字数（分割粒度）</th>
                    <td>
                        <input type="number" name="decofmt_fmt_settings[min_sentence_chars]" value="<?php echo esc_attr($fmt['min_sentence_chars']); ?>" min="20" max="500" style="width:80px"> 字以上で1段落を区切る
                        <p class="description">既定 60。これ未満は前の文に結合する。細切れになりすぎないよう守る値。</p>
                    </td>
                </tr>
                <tr>
                    <th>強制分割しきい値</th>
                    <td>
                        <input type="number" name="decofmt_fmt_settings[force_split_chars]" value="<?php echo esc_attr($fmt['force_split_chars']); ?>" min="120" max="2000" style="width:80px"> 字超は読点でも強制分割
                        <p class="description">既定 300。句点も接続詞も無い超長文を救う最終手段。</p>
                    </td>
                </tr>
                <tr>
                    <th>接続詞リスト（前で改行）</th>
                    <td>
                        <textarea name="decofmt_fmt_settings[connectors]" rows="8" style="width:400px;font-family:monospace"><?php echo esc_textarea($fmt['connectors']); ?></textarea>
                        <p class="description">1行1個。これらの直前で改行する。読点までセットで書く（例: <code>また、</code>）。</p>
                    </td>
                </tr>
                <tr>
                    <th>句読点の正規化</th>
                    <td><label><input type="checkbox" name="decofmt_fmt_settings[normalize_punctuation]" value="yes" <?php checked($fmt['normalize_punctuation'], 'yes'); ?>> 「。。」→「。」のような連続句読点を正規化</label></td>
                </tr>
                <tr>
                    <th>見出し前後の余白</th>
                    <td><label><input type="checkbox" name="decofmt_fmt_settings[add_heading_spacing]" value="yes" <?php checked($fmt['add_heading_spacing'], 'yes'); ?>> H2/H3 の直前に空段落を入れて視覚的余白を確保</label></td>
                </tr>
                <tr>
                    <th>画像・表前後の余白</th>
                    <td><label><input type="checkbox" name="decofmt_fmt_settings[add_media_spacing]" value="yes" <?php checked($fmt['add_media_spacing'], 'yes'); ?>> 画像・表・ギャラリーの前に空段落を入れる</label></td>
                </tr>
                <tr>
                    <th>見出しっぽい段落を昇格</th>
                    <td>
                        <label><input type="checkbox" name="decofmt_fmt_settings[promote_headings]" value="yes" <?php checked($fmt['promote_headings'] ?? 'yes', 'yes'); ?>> 「ポイント3：xxx」「ステップ1：xxx」「【xxx】yyy」等を見出しに変換</label>
                        <p class="description">単に太字や囲みボックスで表示されてる「ポイントN」「ステップN」などを正しい h タグにします。</p>
                    </td>
                </tr>
                <tr>
                    <th>昇格先の見出しレベル</th>
                    <td>
                        <select name="decofmt_fmt_settings[heading_level]">
                            <option value="3" <?php selected($fmt['heading_level'] ?? '4', '3'); ?>>H3</option>
                            <option value="4" <?php selected($fmt['heading_level'] ?? '4', '4'); ?>>H4（推奨）</option>
                            <option value="5" <?php selected($fmt['heading_level'] ?? '4', '5'); ?>>H5</option>
                        </select>
                        <p class="description">H2 配下のサブ見出しとして使うので H4 推奨。</p>
                    </td>
                </tr>
                <tr>
                    <th>段落の最大文字数（見出し候補判定）</th>
                    <td>
                        <input type="number" name="decofmt_fmt_settings[heading_max_chars]" value="<?php echo esc_attr($fmt['heading_max_chars'] ?? 60); ?>" min="20" max="200" style="width:80px"> 字以下
                        <p class="description">この文字数を超える段落は「文章」として昇格対象外。既定 60。</p>
                    </td>
                </tr>
                <tr>
                    <th>見出しパターン（正規表現）</th>
                    <td>
                        <textarea name="decofmt_fmt_settings[heading_patterns]" rows="10" style="width:480px;font-family:monospace"><?php echo esc_textarea($fmt['heading_patterns'] ?? ''); ?></textarea>
                        <p class="description">1行1パターン。各パターンに一致する段落を見出しに昇格。<code>\\d+</code> で数字、<code>[：:]</code> で全角/半角コロン。`^` 始まりでなければ自動で行頭マッチを付与。<br>例: <code>ポイント\\d+[：:]</code> → 「ポイント3：形状と...」にマッチ。</p>
                    </td>
                </tr>
                <tr>
                    <th>strong+コロンの &lt;li&gt; を見出し+段落に分割</th>
                    <td>
                        <label><input type="checkbox" name="decofmt_fmt_settings[split_strong_label_list]" value="yes" <?php checked($fmt['split_strong_label_list'] ?? 'yes', 'yes'); ?>> 各 <code>&lt;li&gt;&lt;strong&gt;ラベル&lt;/strong&gt;：長い説明文&lt;/li&gt;</code> を「見出し + 段落」に分解</label>
                        <p class="description">
                            読みづらい「太字ラベル＋コロン＋長文」のリスト形式を、ラベルを見出し（親見出しレベル+1）に格上げして、説明文を段落にする。
                        </p>
                    </td>
                </tr>
                <tr>
                    <th>分割対象とする説明文の最小文字数</th>
                    <td>
                        <input type="number" name="decofmt_fmt_settings[split_min_content_chars]" value="<?php echo esc_attr($fmt['split_min_content_chars'] ?? 25); ?>" min="10" max="500" style="width:80px"> 字超
                        <p class="description">コロン直後の説明文がこの文字数を超える時だけ分割対象。既定 25 字。</p>
                    </td>
                </tr>
                <tr>
                    <th>1文ごとに改行する</th>
                    <td>
                        <label><input type="checkbox" name="decofmt_fmt_settings[one_sentence_per_paragraph]" value="yes" <?php checked($fmt['one_sentence_per_paragraph'] ?? 'no', 'yes'); ?>> 句点（。！？）2個以上の段落を、全部句点で分割する</label>
                        <p class="description">
                            ⚡ <strong>強力モード</strong>：字数閾値・接続詞・sentence_chars を全部無視して、1文＝1段落にする。<br>
                            会話体・箇条書きベースの記事など、細かく改行したい時に。<br>
                            通常記事では読みづらくなる可能性があるので、まず1〜2記事で試してから一括適用推奨。<br>
                            💡 この項目は
                            <a href="<?php echo esc_url(admin_url('admin.php?page=decofmt-fmt-bulk')); ?>">段落整形（一括）</a>
                            の画面上部でも切り替えられます（処理前にモードを確認できます）。
                        </p>
                    </td>
                </tr>
                <tr>
                    <th>保存時に自動整形（hook）</th>
                    <td>
                        <label><input type="checkbox" name="decofmt_fmt_settings[auto_on_save]" value="yes" <?php checked($fmt['auto_on_save'], 'yes'); ?>> 投稿保存時に自動で整形する</label>
                        <p class="description">⚠️ ONにすると今後の保存全てに効くので、まず手動一括で挙動確認してからONを推奨。</p>
                    </td>
                </tr>
                <tr>
                    <th>対象ステータス</th>
                    <td>
                        <input type="text" name="decofmt_fmt_settings[target_statuses]" value="<?php echo esc_attr($fmt['target_statuses']); ?>" style="width:280px">
                        <p class="description">既定 <code>publish,future,draft</code>。カンマ区切り。</p>
                    </td>
                </tr>
            </table>

            <?php submit_button('段落整形の設定を保存'); ?>
        </form>
    </div>
    <?php
}
