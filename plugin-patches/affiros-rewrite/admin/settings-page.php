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

    // パスワード欄は空のときも '' を送信するため ?? では既存キーを拾えない。
    // 空欄で保存されたら既存キーを維持する。
    $submitted_key = trim((string)($input['claude_api_key'] ?? ''));
    if (defined('AFFIROS_REWRITE_API_KEY') && AFFIROS_REWRITE_API_KEY) {
        // wp-config.php 定数で管理しているときは DB にキーを保存しない
        $new_api_key = '';
    } else {
        $new_api_key = $submitted_key !== '' ? $submitted_key : $current['claude_api_key'];
    }

    $new = [
        'claude_api_key' => $new_api_key,
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

    // 広告挿入定義
    $valid_positions = ['after_each_h3_rank', 'before_first_h2', 'after_first_h2',
                        'before_matome_h2', 'after_matome_h2', 'after_last_h2', 'top', 'bottom'];
    $valid_designs   = ['vertical', 'compare', 'ranking'];
    $ad_patterns = [];
    foreach (['ranking', 'column', 'brand'] as $atype) {
        $rules = [];
        foreach ((array)($input['ad_patterns'][$atype] ?? []) as $rule) {
            if (!is_array($rule)) continue;
            $pos    = sanitize_text_field($rule['position'] ?? '');
            $design = sanitize_text_field($rule['design']   ?? '');
            if (!in_array($pos, $valid_positions, true) || !in_array($design, $valid_designs, true)) continue;
            $r = ['position' => $pos, 'design' => $design];
            if (isset($rule['count']) && $rule['count'] !== '') {
                $r['count'] = max(1, min(20, intval($rule['count'])));
            }
            if (isset($rule['repeat']) && $rule['repeat'] !== '') {
                $r['repeat'] = max(1, min(5, intval($rule['repeat'])));
            }
            $rules[] = $r;
        }
        $ad_patterns[$atype] = $rules;
    }
    $new['ad_patterns'] = $ad_patterns;

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
    $key_from_constant = defined('AFFIROS_REWRITE_API_KEY') && AFFIROS_REWRITE_API_KEY;
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
                        <?php if ($key_from_constant): ?>
                            <p style="margin:0 0 6px;">
                                <strong style="color:#0a7a2f;">✓ wp-config.php で設定済み</strong>
                                <code><?php echo esc_html($masked_key); ?></code>
                            </p>
                            <p class="description">
                                <code>wp-config.php</code> の <code>AFFIROS_REWRITE_API_KEY</code> 定数が使われています。<br>
                                この方式ならプラグインの更新・再インストール・削除でもキーは消えません。<br>
                                変更する場合は <code>wp-config.php</code> を直接編集してください（この画面からは変更できません）。
                            </p>
                        <?php else: ?>
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
                            <p class="description" style="margin-top:8px;padding:8px 10px;background:#f0f6fc;border-left:3px solid #2271b1;">
                                💡 <strong>キーを絶対に消したくない場合</strong>は、<code>wp-config.php</code> に次の行を追加してください。
                                プラグインを更新・再インストールしてもキーが残り、毎回入力し直す必要がなくなります。<br>
                                <code>define('AFFIROS_REWRITE_API_KEY', 'sk-ant-xxxxx');</code>
                            </p>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th><label for="claude_model">Claude モデル</label></th>
                    <td>
                        <select id="claude_model" name="affiros_rewrite[claude_model]">
                            <option value="claude-sonnet-4-6" <?php selected($settings['claude_model'], 'claude-sonnet-4-6'); ?>>Claude Sonnet 4.6（推奨・コスパ良）</option>
                            <option value="claude-opus-4-7" <?php selected($settings['claude_model'], 'claude-opus-4-7'); ?>>Claude Opus 4.7（最高品質・高コスト）</option>
                            <option value="claude-haiku-4-5-20251001" <?php selected($settings['claude_model'], 'claude-haiku-4-5-20251001'); ?>>Claude Haiku 4.5（低コスト・速度優先）</option>
                        </select>
                        <p class="description">wp_manager 本体の記事生成モデルと揃えています。</p>
                    </td>
                </tr>
            </table>

            <h2>// リライト デフォルト設定</h2>
            <p class="description">下記はリライト実行時に常に適用される設定です。</p>
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

            <h2>// 広告挿入定義</h2>
            <p class="description">
                「商品カードマーカーを挿入する」チェックを入れてリライトすると、ここで定義したルール通りに
                <code>&lt;!--ai-product:...--&gt;</code> マーカーが挿入されます。<br>
                実際の商品カード表示は <strong>affiros-product-inserter</strong> が担当します。
            </p>

            <?php
            $ad_patterns = Affiros_Rewrite_Marker_Inserter::get_patterns();
            $article_types = [
                'ranking' => 'ランキング記事',
                'column'  => 'コラム記事',
                'brand'   => '商標記事',
            ];
            $positions_map = [
                'after_each_h3_rank' => '各「N位」H3の直後',
                'before_first_h2'    => '最初のH2の直前',
                'after_first_h2'     => '最初のH2の直後',
                'before_matome_h2'   => 'まとめH2の直前',
                'after_matome_h2'    => 'まとめH2の直後',
                'after_last_h2'      => '最後のH2の直後（確定）',
                'top'                => '記事先頭',
                'bottom'             => '記事末尾',
            ];
            $designs_map = [
                'vertical' => '縦置きカード',
                'compare'  => '比較表カード',
                'ranking'  => 'ランキングカード',
            ];
            $count_designs  = ['compare', 'ranking'];
            $repeat_designs = ['vertical'];
            ?>
            <div id="affiros-ad-patterns" style="margin-bottom:20px;">
            <?php foreach ($article_types as $atype => $atype_label): ?>
                <div style="margin-bottom:16px;padding:16px 18px;background:#fafafa;border:1px solid #ddd;border-radius:4px;">
                    <strong style="display:block;margin-bottom:10px;color:#0073aa;">// <?php echo esc_html($atype_label); ?></strong>
                    <div class="affiros-ad-rules" id="rules-<?php echo esc_attr($atype); ?>">
                    <?php
                    $rules = $ad_patterns[$atype] ?? [];
                    foreach ($rules as $ri => $rule):
                        $d = $rule['design'] ?? 'vertical';
                        $needs_count  = in_array($d, $count_designs,  true);
                        $needs_repeat = in_array($d, $repeat_designs, true);
                    ?>
                        <div class="affiros-ad-rule" style="display:flex;gap:8px;align-items:center;margin-bottom:8px;flex-wrap:wrap;">
                            <select name="affiros_rewrite[ad_patterns][<?php echo esc_attr($atype); ?>][<?php echo $ri; ?>][position]" style="flex:2;min-width:180px;">
                                <?php foreach ($positions_map as $pv => $pl): ?>
                                    <option value="<?php echo esc_attr($pv); ?>" <?php selected($rule['position'] ?? '', $pv); ?>><?php echo esc_html($pl); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <select name="affiros_rewrite[ad_patterns][<?php echo esc_attr($atype); ?>][<?php echo $ri; ?>][design]" style="flex:2;min-width:140px;" onchange="affirosUpdateRuleRow(this)">
                                <?php foreach ($designs_map as $dv => $dl): ?>
                                    <option value="<?php echo esc_attr($dv); ?>" <?php selected($d, $dv); ?>><?php echo esc_html($dl); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <span class="affiros-count-wrap" style="display:<?php echo $needs_count ? 'flex' : 'none'; ?>;align-items:center;gap:4px;">
                                件数:&nbsp;<input type="number" name="affiros_rewrite[ad_patterns][<?php echo esc_attr($atype); ?>][<?php echo $ri; ?>][count]" value="<?php echo esc_attr($rule['count'] ?? 3); ?>" min="1" max="20" style="width:54px;">
                            </span>
                            <span class="affiros-repeat-wrap" style="display:<?php echo $needs_repeat ? 'flex' : 'none'; ?>;align-items:center;gap:4px;">
                                連続:&nbsp;<input type="number" name="affiros_rewrite[ad_patterns][<?php echo esc_attr($atype); ?>][<?php echo $ri; ?>][repeat]" value="<?php echo esc_attr($rule['repeat'] ?? 1); ?>" min="1" max="5" style="width:54px;">
                            </span>
                            <button type="button" onclick="affirosRemoveRule(this)" style="background:#dc3232;color:#fff;border:none;padding:5px 10px;border-radius:3px;cursor:pointer;font-size:13px;">✕</button>
                        </div>
                    <?php endforeach; ?>
                    </div>
                    <button type="button" class="button button-secondary" onclick="affirosAddRule('<?php echo esc_attr($atype); ?>')">＋ ルールを追加</button>
                </div>
            <?php endforeach; ?>
            </div>

            <script>
            (function(){
                var POSITIONS = <?php echo json_encode(array_keys($positions_map)); ?>;
                var POSITION_LABELS = <?php echo json_encode(array_values($positions_map)); ?>;
                var DESIGNS = <?php echo json_encode(array_keys($designs_map)); ?>;
                var DESIGN_LABELS = <?php echo json_encode(array_values($designs_map)); ?>;
                var COUNT_DESIGNS  = <?php echo json_encode($count_designs); ?>;
                var REPEAT_DESIGNS = <?php echo json_encode($repeat_designs); ?>;

                window.affirosUpdateRuleRow = function(sel) {
                    var row = sel.closest('.affiros-ad-rule');
                    var val = sel.value;
                    row.querySelector('.affiros-count-wrap').style.display  = COUNT_DESIGNS.indexOf(val)  >= 0 ? 'flex' : 'none';
                    row.querySelector('.affiros-repeat-wrap').style.display = REPEAT_DESIGNS.indexOf(val) >= 0 ? 'flex' : 'none';
                };

                window.affirosRemoveRule = function(btn) {
                    var row = btn.closest('.affiros-ad-rule');
                    var rulesEl = row.closest('.affiros-ad-rules');
                    var atype = rulesEl.id.replace('rules-', '');
                    row.remove();
                    affirosRenumber(atype);
                };

                function affirosRenumber(atype) {
                    var rows = document.querySelectorAll('#rules-' + atype + ' .affiros-ad-rule');
                    rows.forEach(function(row, i) {
                        row.querySelectorAll('[name]').forEach(function(el) {
                            el.name = el.name.replace(
                                /\[ad_patterns\]\[[^\]]+\]\[\d+\]/,
                                '[ad_patterns][' + atype + '][' + i + ']'
                            );
                        });
                    });
                }

                window.affirosAddRule = function(atype) {
                    var rulesEl = document.getElementById('rules-' + atype);
                    var idx = rulesEl.querySelectorAll('.affiros-ad-rule').length;
                    var posBuild = POSITIONS.map(function(v,i){ return '<option value="'+v+'">'+POSITION_LABELS[i]+'</option>'; }).join('');
                    var desBuild = DESIGNS.map(function(v,i){ return '<option value="'+v+'">'+DESIGN_LABELS[i]+'</option>'; }).join('');
                    var html = '<div class="affiros-ad-rule" style="display:flex;gap:8px;align-items:center;margin-bottom:8px;flex-wrap:wrap;">'
                        + '<select name="affiros_rewrite[ad_patterns]['+atype+']['+idx+'][position]" style="flex:2;min-width:180px;">'+posBuild+'</select>'
                        + '<select name="affiros_rewrite[ad_patterns]['+atype+']['+idx+'][design]" style="flex:2;min-width:140px;" onchange="affirosUpdateRuleRow(this)">'+desBuild+'</select>'
                        + '<span class="affiros-count-wrap" style="display:none;align-items:center;gap:4px;">件数:&nbsp;<input type="number" name="affiros_rewrite[ad_patterns]['+atype+']['+idx+'][count]" value="3" min="1" max="20" style="width:54px;"></span>'
                        + '<span class="affiros-repeat-wrap" style="display:flex;align-items:center;gap:4px;">連続:&nbsp;<input type="number" name="affiros_rewrite[ad_patterns]['+atype+']['+idx+'][repeat]" value="1" min="1" max="5" style="width:54px;"></span>'
                        + '<button type="button" onclick="affirosRemoveRule(this)" style="background:#dc3232;color:#fff;border:none;padding:5px 10px;border-radius:3px;cursor:pointer;font-size:13px;">✕</button>'
                        + '</div>';
                    rulesEl.insertAdjacentHTML('beforeend', html);
                };
            })();
            </script>

            <p class="submit">
                <button type="submit" class="button button-primary">設定を保存</button>
            </p>
        </form>
    </div>
    <?php
}
