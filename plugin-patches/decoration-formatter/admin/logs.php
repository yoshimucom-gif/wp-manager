<?php
/**
 * 処理ログ画面（AI装飾）
 */

if (!defined('ABSPATH')) exit;

function decofmt_logs_render() {
    if (!current_user_can('manage_options')) return;

    // ログ削除処理（nonce検証付き）
    $cleared = false;
    if (isset($_POST['decofmt_clear_logs']) && check_admin_referer('decofmt_clear_logs')) {
        delete_option('decofmt_deco_logs');
        $cleared = true;
    }

    $logs = get_option('decofmt_deco_logs', []);
    $logs = array_reverse($logs); // 新しい順

    $total = count($logs);
    $success = count(array_filter($logs, fn($l) => ($l['result'] ?? '') === 'success'));
    $failure = $total - $success;
    $total_input_tokens = array_sum(array_column($logs, 'input_tokens'));
    $total_output_tokens = array_sum(array_column($logs, 'output_tokens'));

    // モデル別集計
    $by_model = [];
    foreach ($logs as $log) {
        $m = $log['model'] ?? '(不明)';
        if (!isset($by_model[$m])) {
            $by_model[$m] = ['count' => 0, 'input' => 0, 'output' => 0];
        }
        $by_model[$m]['count']++;
        $by_model[$m]['input'] += $log['input_tokens'] ?? 0;
        $by_model[$m]['output'] += $log['output_tokens'] ?? 0;
    }
    ?>
    <div class="wrap decofmt-wrap">
        <h1>AI装飾 処理ログ</h1>

        <?php if ($cleared): ?>
            <div class="notice notice-success is-dismissible"><p>処理ログを削除しました。</p></div>
        <?php endif; ?>

        <?php if (!empty($logs)): ?>
            <form method="post" style="margin:12px 0;">
                <?php wp_nonce_field('decofmt_clear_logs'); ?>
                <button type="submit" name="decofmt_clear_logs" value="1" class="button button-secondary"
                        onclick="return confirm('処理ログをすべて削除します。よろしいですか？\n（投稿の装飾状態・バックアップには影響しません）');">
                    🗑 ログをすべて削除
                </button>
            </form>
        <?php endif; ?>

        <div class="decofmt-log-summary">
            <div class="decofmt-stat">
                <div class="decofmt-stat-num"><?php echo esc_html($total); ?></div>
                <div class="decofmt-stat-label">総処理数</div>
            </div>
            <div class="decofmt-stat">
                <div class="decofmt-stat-num decofmt-stat-num--success"><?php echo esc_html($success); ?></div>
                <div class="decofmt-stat-label">成功</div>
            </div>
            <div class="decofmt-stat">
                <div class="decofmt-stat-num decofmt-stat-num--failure"><?php echo esc_html($failure); ?></div>
                <div class="decofmt-stat-label">失敗</div>
            </div>
            <div class="decofmt-stat">
                <div class="decofmt-stat-num"><?php echo esc_html(number_format($total_input_tokens)); ?></div>
                <div class="decofmt-stat-label">入力トークン</div>
            </div>
            <div class="decofmt-stat">
                <div class="decofmt-stat-num"><?php echo esc_html(number_format($total_output_tokens)); ?></div>
                <div class="decofmt-stat-label">出力トークン</div>
            </div>
        </div>

        <?php if (!empty($by_model)): ?>
            <h2>モデル別集計</h2>
            <table class="wp-list-table widefat striped" style="max-width:800px;">
                <thead>
                    <tr>
                        <th>モデル</th>
                        <th>処理数</th>
                        <th>入力トークン</th>
                        <th>出力トークン</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($by_model as $model_id => $stats): ?>
                        <tr>
                            <td>
                                <?php echo esc_html(decofmt_get_model_label($model_id)); ?>
                                <br><small style="color:#888;"><?php echo esc_html($model_id); ?></small>
                            </td>
                            <td><?php echo esc_html($stats['count']); ?></td>
                            <td><?php echo esc_html(number_format($stats['input'])); ?></td>
                            <td><?php echo esc_html(number_format($stats['output'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <h2>履歴（直近100件）</h2>
        <table class="wp-list-table widefat striped">
            <thead>
                <tr>
                    <th>日時</th>
                    <th>記事</th>
                    <th>結果</th>
                    <th>ステータス</th>
                    <th>モデル</th>
                    <th>レベル</th>
                    <th>トークン (入/出)</th>
                    <th>メッセージ</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (array_slice($logs, 0, 100) as $log): ?>
                    <tr>
                        <td><?php echo esc_html($log['timestamp']); ?></td>
                        <td>
                            <?php $title = get_the_title($log['post_id']); ?>
                            <a href="<?php echo esc_url(get_edit_post_link($log['post_id'])); ?>">
                                <?php echo esc_html($title ?: '(削除済み: ID ' . $log['post_id'] . ')'); ?>
                            </a>
                        </td>
                        <td>
                            <?php if (($log['result'] ?? '') === 'success'): ?>
                                <span style="color:#27ae60;">✅ 成功</span>
                            <?php else: ?>
                                <span style="color:#e74c3c;">❌ 失敗</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php
                            $status_label = [
                                'ok' => '✅ OK',
                                'warning' => '⚠️ 要確認',
                                'error' => '❌ エラー',
                            ][$log['status'] ?? ''] ?? '-';
                            echo esc_html($status_label);
                            ?>
                        </td>
                        <td>
                            <?php
                            if (!empty($log['model'])) {
                                echo esc_html(decofmt_get_model_label($log['model']));
                            } else {
                                echo '-';
                            }
                            ?>
                        </td>
                        <td><?php echo esc_html($log['level'] ?? '-'); ?></td>
                        <td>
                            <?php
                            $in = $log['input_tokens'] ?? 0;
                            $out = $log['output_tokens'] ?? 0;
                            if ($in || $out) {
                                echo esc_html(number_format($in) . " / " . number_format($out));
                            } else {
                                echo '-';
                            }
                            ?>
                        </td>
                        <td><?php echo esc_html($log['message'] ?? '-'); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php if (empty($logs)): ?>
            <p>まだ処理ログがありません。</p>
        <?php endif; ?>
    </div>
    <?php
}
