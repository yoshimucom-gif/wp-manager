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

    // 見出し接尾辞。空で保存されたら既定に戻す
    $defaults = affiros_ai_default_settings();
    foreach (['card_heading_suffix', 'side_heading_suffix'] as $k) {
        $val = trim(sanitize_text_field($input[$k] ?? ''));
        $output[$k] = $val !== '' ? $val : $defaults[$k];
    }
    // v0.16.0 で廃止した旧見出し設定を掃除
    unset($output['card_heading'], $output['side_heading']);

    $output['skip_ranking_articles']  = ($input['skip_ranking_articles'] ?? 'no') === 'yes' ? 'yes' : 'no';
    $output['ranking_title_patterns'] = sanitize_textarea_field($input['ranking_title_patterns'] ?? '');

    // 除外カテゴリー/タグ (チェック0個 = 除外なし、なので毎回上書き)
    $output['exclude_category_ids'] = array_values(array_filter(array_map('intval', (array)($input['exclude_category_ids'] ?? []))));
    $output['exclude_tags']         = sanitize_text_field($input['exclude_tags'] ?? '');

    $output['auto_on_publish']        = ($input['auto_on_publish'] ?? 'no') === 'yes' ? 'yes' : 'no';
    $output['monthly_refresh']        = ($input['monthly_refresh'] ?? 'no') === 'yes' ? 'yes' : 'no';

    // セール表示 (v0.17.0)。URL空なら既定 (ke-ysセールハブ) に戻す
    $output['sale_display']  = ($input['sale_display'] ?? 'no') === 'yes' ? 'yes' : 'no';
    $sale_url = trim(sanitize_text_field($input['sale_feed_url'] ?? ''));
    $output['sale_feed_url'] = $sale_url !== '' ? esc_url_raw($sale_url) : $defaults['sale_feed_url'];
    // v0.7.0 で週次リフレッシュ廃止。旧設定が残っていたら捨てる
    unset($output['cron_refresh']);

    return $output;
}

// 秘密キー入力欄: 実値を password type (●●●) で表示し「表示」ボタンで確認できる
// (product-inserter v1.9.27 と同じ方式。実値の再送信なのでマスク値上書き事故は起きない)
function affiros_ai_secret_field($key, $settings) {
    $val = (string)($settings[$key] ?? '');
    printf(
        '<span class="affiros-ai-secret-wrap"><input type="password" name="%s[%s]" value="%s" class="regular-text affiros-ai-secret" autocomplete="off"><button type="button" class="button affiros-ai-secret-toggle">表示</button></span>%s',
        esc_attr(AFFIROS_AI_OPTION_KEY),
        esc_attr($key),
        esc_attr($val),
        $val === '' ? ' <span style="color:#d63638">未設定</span>' : ''
    );
}

function affiros_ai_render_settings_page() {
    if (!current_user_can('manage_options')) return;
    $settings = affiros_ai_get_settings();
    ?>
    <div class="wrap">
        <style>
        .affiros-ai-secret-wrap { display: inline-flex; gap: 6px; align-items: center; }
        .affiros-ai-secret-wrap .affiros-ai-secret { flex: 1; }
        .affiros-ai-secret-wrap .affiros-ai-secret-toggle { flex-shrink: 0; min-width: 56px; }
        </style>
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
                            キーワード抽出＋商品のAI検品用。コストは1記事あたり約¥0.5
                            （内訳: 抽出¥0.3＋検品¥0.1〜0.2。検品全滅→キーワード再抽出が発動した記事のみ約¥1.0〜1.4）。
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
                    <th>記事内カード見出しの接尾辞</th>
                    <td>
                        「AIキーワード」<input type="text" name="<?php echo AFFIROS_AI_OPTION_KEY; ?>[card_heading_suffix]" value="<?php echo esc_attr($settings['card_heading_suffix'] ?? 'はどれを選ぶ？'); ?>" class="regular-text" placeholder="はどれを選ぶ？">
                        <p class="description">見出しは <strong>「キーワード」＋この文言</strong> で表示（例: 「フェルトシール」はどれを選ぶ？）。<br>空で保存すると既定「はどれを選ぶ？」に戻る。表示時差し替え方式なので既存記事にも即反映（再挿入不要）。<br>⚠️ 「売れ筋」「ランキング」「No.1」「厳選」等の根拠を示せない語は景表法リスクがあるため使わない。</p>
                    </td>
                </tr>
                <tr>
                    <th>サイドバー見出しの接尾辞</th>
                    <td>
                        「AIキーワード」<input type="text" name="<?php echo AFFIROS_AI_OPTION_KEY; ?>[side_heading_suffix]" value="<?php echo esc_attr($settings['side_heading_suffix'] ?? 'で迷ったらこれ'); ?>" class="regular-text" placeholder="で迷ったらこれ">
                        <p class="description">ショートコード <code>[affiros_ai_top]</code> の見出し（例: 「フェルトシール」で迷ったらこれ）。動的表示なので保存すれば即反映。<br><code>title="..."</code> 属性を書いた場合はそちらが接尾辞として優先。</p>
                    </td>
                </tr>
            </table>

            <h2>⑤ 挿入しないカテゴリー・タグ</h2>
            <table class="form-table">
                <tr>
                    <th>除外カテゴリー</th>
                    <td>
                        <?php
                        $excl_cats = array_map('intval', (array)($settings['exclude_category_ids'] ?? []));
                        $all_cats = get_categories(['hide_empty' => false, 'orderby' => 'name']);
                        if (empty($all_cats)) {
                            echo '<p class="description">カテゴリーがありません。</p>';
                        } else {
                            // 親子ツリーで表示 (インデント + └)。親チェックは下のJSで子孫に連動
                            $by_parent = [];
                            foreach ($all_cats as $cat) {
                                $by_parent[intval($cat->parent)][] = $cat;
                            }
                            echo '<div style="max-height:340px;overflow:auto;background:#fff;border:1px solid #dcdcde;border-radius:4px;padding:10px 14px;max-width:560px">';
                            $walk = function ($parent, $depth) use (&$walk, $by_parent, $excl_cats) {
                                foreach ($by_parent[$parent] ?? [] as $cat) {
                                    printf(
                                        '<label style="display:block;margin:0 0 4px %dpx;white-space:nowrap"><input type="checkbox" class="affiros-ai-excl-cat" name="%s[exclude_category_ids][]" value="%d" data-parent="%d" %s> %s%s <span style="color:#999">(%d)</span></label>',
                                        $depth * 22,
                                        esc_attr(AFFIROS_AI_OPTION_KEY),
                                        $cat->term_id,
                                        intval($cat->parent),
                                        checked(in_array($cat->term_id, $excl_cats, true), true, false),
                                        $depth ? '<span style="color:#bbb">└</span> ' : '',
                                        esc_html($cat->name),
                                        $cat->count
                                    );
                                    $walk($cat->term_id, $depth + 1);
                                }
                            };
                            $walk(0, 0);
                            echo '</div>';
                        }
                        ?>
                        <p class="description">チェックしたカテゴリーの記事には挿入しない（一括・個別・公開時自動すべて対象外）。<br>親をチェックすると子カテゴリーも連動して選択される（除外判定は記事に付いているカテゴリー単位のため、子も選ばないと子カテゴリーの記事は除外されない）。子だけ個別に外すのは後から可能。</p>
                    </td>
                </tr>
                <tr>
                    <th>除外タグ</th>
                    <td>
                        <input type="text" name="<?php echo AFFIROS_AI_OPTION_KEY; ?>[exclude_tags]" value="<?php echo esc_attr($settings['exclude_tags']); ?>" class="regular-text" placeholder="例: 広告なし, no-ads">
                        <p class="description">カンマ区切りでタグ名またはスラッグ。付いている記事には挿入しない。</p>
                    </td>
                </tr>
            </table>

            <h2>⑥ ランキング記事判定 (自動挿入対象外)</h2>
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

            <h2>⑦ 自動化</h2>
            <table class="form-table">
                <tr>
                    <th>公開時に自動挿入</th>
                    <td><label><input type="checkbox" name="<?php echo AFFIROS_AI_OPTION_KEY; ?>[auto_on_publish]" value="yes" <?php checked($settings['auto_on_publish'], 'yes'); ?>> 記事公開時に自動で商品カードを挿入する</label><p class="description">公開の60秒後に WP Cron 経由で実行 (公開自体を遅らせない)</p></td>
                </tr>
                <tr>
                    <th>月次リフレッシュ</th>
                    <td>
                        <label><input type="checkbox" name="<?php echo AFFIROS_AI_OPTION_KEY; ?>[monthly_refresh]" value="yes" <?php checked($settings['monthly_refresh'] ?? 'yes', 'yes'); ?>> 挿入から30日経過した記事の商品カードを自動で最新化する</label>
                        <p class="description">
                            毎日10件ずつの分散処理（全記事が同日に動かない）。リビジョンを作らず、更新日 (post_modified) も動かさない。<br>
                            商品再取得＋AI検品のみで約¥0.1〜0.4/記事/月。実行結果は一括挿入ページ下部の「リフレッシュ履歴」で確認できる。
                        </p>
                    </td>
                </tr>
            </table>

            <h2>⑧ セール表示 (マイクロコピー)</h2>
            <table class="form-table">
                <tr>
                    <th>セールマイクロコピー</th>
                    <td>
                        <label><input type="checkbox" name="<?php echo AFFIROS_AI_OPTION_KEY; ?>[sale_display]" value="yes" <?php checked($settings['sale_display'] ?? 'yes', 'yes'); ?>> 開催中のセールをボタンの上に「＼お買い物マラソン開催中／」のように表示する</label>
                        <p class="description">セールハブ (ke-ys.co.jp) の登録内容を1日1回取得。開催期間内だけ表示され、終了すると自動で消える。文言・動きはハブ側で一元管理。</p>
                    </td>
                </tr>
                <tr>
                    <th>配信元URL</th>
                    <td>
                        <input type="text" name="<?php echo AFFIROS_AI_OPTION_KEY; ?>[sale_feed_url]" value="<?php echo esc_attr($settings['sale_feed_url'] ?? ''); ?>" class="large-text" style="max-width:560px">
                        <?php
                        $sale_cache = get_option(AFFIROS_AI_SALES_CACHE_KEY, []);
                        $az = function_exists('affiros_ai_sale_active') ? affiros_ai_sale_active('amazon') : null;
                        $rk = function_exists('affiros_ai_sale_active') ? affiros_ai_sale_active('rakuten') : null;
                        ?>
                        <p class="description">
                            通常は変更不要。空で保存すると既定URLに戻る。設定を保存すると即時取得する。<br>
                            最終取得: <strong><?php echo esc_html($sale_cache['fetched'] ?? 'まだ取得していません'); ?></strong>
                            ／ 取得済み <?php echo count((array)($sale_cache['sales'] ?? [])); ?> 件
                            ／ 開催中: Amazon=<?php echo $az ? '「' . esc_html($az['label']) . '」' : 'なし'; ?>・楽天=<?php echo $rk ? '「' . esc_html($rk['label']) . '」' : 'なし'; ?>
                        </p>
                    </td>
                </tr>
            </table>

            <?php submit_button(); ?>
        </form>

        <script>
        document.addEventListener('click', function (e) {
            if (!e.target.classList || !e.target.classList.contains('affiros-ai-secret-toggle')) return;
            var input = e.target.closest('.affiros-ai-secret-wrap').querySelector('.affiros-ai-secret');
            var isPw = input.type === 'password';
            input.type = isPw ? 'text' : 'password';
            e.target.textContent = isPw ? '非表示' : '表示';
        });
        // 除外カテゴリー: 親のチェックを子孫に連動させる
        document.addEventListener('change', function (e) {
            if (!e.target.classList || !e.target.classList.contains('affiros-ai-excl-cat')) return;
            var byParent = {};
            document.querySelectorAll('.affiros-ai-excl-cat').forEach(function (cb) {
                (byParent[cb.dataset.parent] = byParent[cb.dataset.parent] || []).push(cb);
            });
            (function cascade(id, checked) {
                (byParent[id] || []).forEach(function (cb) {
                    cb.checked = checked;
                    cascade(cb.value, checked);
                });
            })(e.target.value, e.target.checked);
        });
        </script>

        <h2>⑨ サイドバー用ショートコード</h2>
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
                    <tr><td><code>[affiros_ai_top title="で迷ったらこれ"]</code></td><td>見出しの接尾辞を個別指定（既定は上の「サイドバー見出しの接尾辞」設定値。表示は「キーワード」＋接尾辞）</td></tr>
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
