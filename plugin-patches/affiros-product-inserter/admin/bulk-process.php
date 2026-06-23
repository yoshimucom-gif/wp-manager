<?php
/**
 * 一括処理画面
 */
if (!defined('ABSPATH')) exit;

function ai_pi_render_bulk_page() {
    if (!current_user_can('manage_options')) return;

    $categories = get_categories(['hide_empty' => false]);
    $tags = get_tags(['hide_empty' => false]);
    $preview_url = admin_url('admin.php?page=ai-product-inserter-preview');
    ?>
    <div class="wrap aipi-wrap">
        <h1>AI商品挿入 一括処理</h1>

        <div class="notice notice-warning">
            <p><strong>⚠️ 注意：</strong>一括処理はAPIコストが発生します。最初は5〜10件で必ずテストしてください。</p>
        </div>

        <div class="aipi-bulk-form">
            <h2>対象記事の絞り込み</h2>

            <table class="form-table">
                <tr>
                    <th>カテゴリ</th>
                    <td>
                        <div class="aipi-checkbox-list">
                            <?php foreach ($categories as $cat): ?>
                                <label><input type="checkbox" class="aipi-cat" value="<?php echo esc_attr($cat->term_id); ?>"> <?php echo esc_html($cat->name); ?> <span class="aipi-count">(<?php echo esc_html($cat->count); ?>)</span></label>
                            <?php endforeach; ?>
                        </div>
                    </td>
                </tr>

                <tr>
                    <th>タグ</th>
                    <td>
                        <div class="aipi-checkbox-list">
                            <?php foreach ($tags as $tag): ?>
                                <label><input type="checkbox" class="aipi-tag" value="<?php echo esc_attr($tag->term_id); ?>"> <?php echo esc_html($tag->name); ?> <span class="aipi-count">(<?php echo esc_html($tag->count); ?>)</span></label>
                            <?php endforeach; ?>
                        </div>
                    </td>
                </tr>

                <tr>
                    <th>処理対象</th>
                    <td>
                        <label><input type="radio" name="aipi_filter" value="has_marker" checked> マーカー(<code>&lt;!--ai-product...--&gt;</code>)を含む未処理記事</label><br>
                        <label><input type="radio" name="aipi_filter" value="residual"> <span style="color:#d63638;font-weight:600;">⚠️ マーカー残存（前回挿入に失敗・要再処理）</span></label><br>
                        <label><input type="radio" name="aipi_filter" value="expired"> ⚠️24時間経過の記事（再取得）</label>
                    </td>
                </tr>
            </table>

            <h2>挿入の設定</h2>
            <p class="description" style="margin-left:0;">マーカー方式で固定。各マーカーのデザインは記事本文に書かれた <code>&lt;!--ai-product:vertical--&gt;</code> 等のヒントに従って自動切替されます。</p>
            <input type="hidden" name="aipi_bulk_mode" value="marker">
            <input type="hidden" name="aipi_bulk_design" value="vertical">
            <table class="form-table">
                <tr>
                    <th>処理上限</th>
                    <td>
                        <input type="number" id="aipi_limit" value="5" min="1" max="200" style="width:80px;">
                        <p class="description">最初は<strong>5件</strong>でテスト推奨。最大 <strong>200件</strong> まで指定可能。</p>
                        <details style="margin-top:10px;background:#fafafa;border:1px solid #e0e0e0;border-radius:4px;">
                            <summary style="padding:8px 12px;cursor:pointer;font-weight:600;color:#2271b1;">📊 件数別の所要時間・コスト目安（クリックで展開）</summary>
                            <table style="width:100%;border-collapse:collapse;font-size:12px;margin:0;">
                                <thead>
                                    <tr style="background:#f0f6fc;">
                                        <th style="padding:6px 10px;border-bottom:1px solid #ddd;text-align:left;">件数</th>
                                        <th style="padding:6px 10px;border-bottom:1px solid #ddd;text-align:left;">所要時間</th>
                                        <th style="padding:6px 10px;border-bottom:1px solid #ddd;text-align:left;">Haiku 4.5<br><span style="font-weight:normal;color:#666;">(¥2/件)</span></th>
                                        <th style="padding:6px 10px;border-bottom:1px solid #ddd;text-align:left;">Sonnet 4.6<br><span style="font-weight:normal;color:#666;">(¥15/件)</span></th>
                                        <th style="padding:6px 10px;border-bottom:1px solid #ddd;text-align:left;">Opus 4.7<br><span style="font-weight:normal;color:#666;">(¥80/件)</span></th>
                                        <th style="padding:6px 10px;border-bottom:1px solid #ddd;text-align:left;">実用性</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr><td style="padding:6px 10px;">5件</td><td style="padding:6px 10px;">3〜5分</td><td style="padding:6px 10px;">〜¥10</td><td style="padding:6px 10px;">〜¥75</td><td style="padding:6px 10px;">〜¥400</td><td style="padding:6px 10px;color:#0a7a2f;">⭐⭐⭐⭐⭐ テスト最適</td></tr>
                                    <tr style="background:#fafafa;"><td style="padding:6px 10px;">20件</td><td style="padding:6px 10px;">15〜20分</td><td style="padding:6px 10px;">〜¥40</td><td style="padding:6px 10px;">〜¥300</td><td style="padding:6px 10px;">〜¥1,600</td><td style="padding:6px 10px;color:#0a7a2f;">⭐⭐⭐⭐ 日常運用◎</td></tr>
                                    <tr><td style="padding:6px 10px;">50件</td><td style="padding:6px 10px;">40〜50分</td><td style="padding:6px 10px;">〜¥100</td><td style="padding:6px 10px;">〜¥750</td><td style="padding:6px 10px;">〜¥4,000</td><td style="padding:6px 10px;color:#a06000;">⭐⭐⭐ 我慢できる</td></tr>
                                    <tr style="background:#fafafa;"><td style="padding:6px 10px;">100件</td><td style="padding:6px 10px;">1.5〜2時間</td><td style="padding:6px 10px;">〜¥200</td><td style="padding:6px 10px;">〜¥1,500</td><td style="padding:6px 10px;">〜¥8,000</td><td style="padding:6px 10px;color:#a06000;">⭐⭐ ブラウザ拘束辛い</td></tr>
                                    <tr><td style="padding:6px 10px;"><strong>200件（最大）</strong></td><td style="padding:6px 10px;">3〜4時間</td><td style="padding:6px 10px;">〜¥400</td><td style="padding:6px 10px;">〜¥3,000</td><td style="padding:6px 10px;">〜¥16,000</td><td style="padding:6px 10px;color:#c00;">⭐ 非推奨（分割推奨）</td></tr>
                                </tbody>
                            </table>
                            <div style="padding:8px 12px;background:#eaf6ff;border-top:1px solid #bcd9f0;font-size:11px;color:#1a4a7a;line-height:1.6;">
                                <strong>💡 コストを抑えたいときは</strong><br>
                                ・モデルを <strong>Haiku 4.5</strong> に変更すると Sonnet の約 1/7（設定画面 → Claude モデル）<br>
                                ・Haiku でも商品選定の精度は実用レベル。大量処理時の第一候補です<br>
                                ・上記コストは保守的な上限値。実測は 50〜70% 程度に収まることが多いです
                            </div>
                            <div style="padding:8px 12px;background:#fff8f0;border-top:1px solid #f0d8a0;font-size:11px;color:#8a5800;line-height:1.6;">
                                <strong>⚠️ 大量実行時の注意</strong><br>
                                ・ブラウザタブを閉じると残りは処理されません（JS ループ方式）<br>
                                ・PC スリープ・WiFi 切断で停止します<br>
                                ・SiteGuard / WAF が連続POST で 403 を返すことがあります（管理ページアクセス制限を一時OFF推奨）<br>
                                ・<strong>大量処理は 50件ずつ × 数回の分割実行が現実的</strong>
                            </div>
                        </details>
                    </td>
                </tr>
            </table>

            <p>
                <button type="button" class="button aipi-count-targets">対象記事を確認</button>
                <button type="button" class="button button-primary aipi-start-bulk" disabled>一括処理を開始</button>
            </p>

            <div class="aipi-targets-result" style="display:none;">
                <h3>対象記事</h3>
                <div class="aipi-targets-summary"></div>
                <div class="aipi-targets-list"></div>
            </div>

            <div class="aipi-progress" style="display:none;">
                <h3>処理状況</h3>
                <div class="aipi-progress-bar">
                    <div class="aipi-progress-fill" style="width:0%;">0%</div>
                </div>
                <div class="aipi-progress-log"></div>
                <button type="button" class="button aipi-stop-bulk">⏹ 中断</button>
            </div>
        </div>
    </div>

    <?php
}
