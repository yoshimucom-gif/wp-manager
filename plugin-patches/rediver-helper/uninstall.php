<?php
/**
 * プラグイン削除時の後始末。
 * 退避（rdh_backups）と更新チェックのキャッシュを消す。
 *
 * 「停止」では走らない。管理画面から「削除」したときだけ WP が実行する。
 */

if (!defined('WP_UNINSTALL_PLUGIN')) exit;

delete_option('rdh_backups');

// 更新チェッカーの transient（キー名は plugin_basename の md5）
$rdh_hash = md5('rediver-helper/rediver-helper.php');
delete_transient('rdh_updater_' . $rdh_hash);
delete_transient('rdh_updater_fail_' . $rdh_hash);
