<?php
/**
 * 処理ログ画面
 */
if (!defined('ABSPATH')) exit;

function ai_pi_logs_render() {
    if (!current_user_can('manage_options')) return;

    $logs = get_option('ai_pi_logs', []);
    $logs = array_reverse($logs);

    $total = count($logs);
    $success = count(array_filter($logs, fn($l) => ($l['result'] ?? '') === 'success'));
    $failure = $total - $success;
    $total_input = array_sum(array_column($logs, 'input_tokens'));
    $total_output = array_sum(array_column($logs, 'output_tokens'));
    ?>
    <div class="wrap aipi-wrap">
        <h1>処理ログ</h1>

        <div class="aipi-log-summary">
            <div class="aipi-stat"><div class="aipi-stat-num"><?php echo esc_html($total); ?></div><div class="aipi-stat-label">総処理</div></div>
            <div class="aipi-stat"><div class="aipi-stat-num aipi-stat-num--success"><?php echo esc_html($success); ?></div><div class="aipi-stat-label">成功</div></div>
            <div class="aipi-stat"><div class="aipi-stat-num aipi-stat-num--failure"><?php echo esc_html($failure); ?></div><div class="aipi-stat-label">失敗</div></div>
            <div class="aipi-stat"><div class="aipi-stat-num"><?php echo esc_html(number_format($total_input)); ?></div><div class="aipi-stat-label">入力トークン</div></div>
            <div class="aipi-stat"><div class="aipi-stat-num"><?php echo esc_html(number_format($total_output)); ?></div><div class="aipi-stat-label">出力トークン</div></div>
        </div>

        <h2>履歴（直近100件）</h2>
        <table class="wp-list-table widefat striped">
            <thead>
                <tr>
                    <th>日時</th>
                    <th>記事</th>
                    <th>結果</th>
                    <th>モード</th>
                    <th>トークン</th>
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
                                <span style="color:#27ae60;">✅</span>
                            <?php else: ?>
                                <span style="color:#e74c3c;">❌</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html($log['mode'] ?? '-'); ?></td>
                        <td>
                            <?php
                            $in = $log['input_tokens'] ?? 0;
                            $out = $log['output_tokens'] ?? 0;
                            echo ($in || $out) ? esc_html("{$in} / {$out}") : '-';
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
