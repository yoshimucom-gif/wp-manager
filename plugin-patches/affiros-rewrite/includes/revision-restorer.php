<?php
/**
 * リビジョン復元 — リライト前の状態に WP リビジョンから戻す
 *
 * Affiros リライターでリライトした記事を、WP の標準リビジョン機能を使って
 * 「リライト直前」の状態に戻す。
 *
 * 動作:
 *  1. _affiros_rewrite_last_at（最終リライト日時）より古い最新リビジョンを取得
 *  2. wp_restore_post_revision() で復元
 *  3. リライト履歴メタ・マーカー検証メタをクリア
 *
 * 注意: リライト後に手動編集や商品挿入があった場合、それらの変更も失う。
 *       UI 側で必ず警告を出すこと。
 */

if (!defined('ABSPATH')) exit;

class Affiros_Rewrite_Revision_Restorer {

    /**
     * リライト履歴がある投稿を一覧取得する。
     *
     * @param array $args ['per_page'=>20, 'page'=>1]
     * @return array { items: [...], total: int }
     */
    public static function list_rewritten_posts($args = []) {
        $per_page = max(1, intval($args['per_page'] ?? 20));
        $page = max(1, intval($args['page'] ?? 1));

        $query = new WP_Query([
            'post_type'      => 'post',
            'post_status'    => ['publish', 'draft', 'pending', 'future', 'private'],
            'posts_per_page' => $per_page,
            'paged'          => $page,
            'orderby'        => 'meta_value',
            'meta_key'       => '_affiros_rewrite_last_at',
            'order'          => 'DESC',
            'meta_query'     => [
                [
                    'key'     => '_affiros_rewrite_count',
                    'value'   => 0,
                    'compare' => '>',
                    'type'    => 'NUMERIC',
                ],
            ],
        ]);

        $items = [];
        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $post_id = get_the_ID();
                $rw_count = (int) get_post_meta($post_id, '_affiros_rewrite_count', true);
                $rw_last  = (string) get_post_meta($post_id, '_affiros_rewrite_last_at', true);
                $revisions = self::count_pre_rewrite_revisions($post_id, $rw_last);
                $items[] = [
                    'id'             => $post_id,
                    'title'          => get_the_title($post_id),
                    'status'         => get_post_status($post_id),
                    'rewrite_count'  => $rw_count,
                    'rewrite_last'   => $rw_last !== '' ? mysql2date('Y-m-d H:i', $rw_last) : '',
                    'revisions'      => $revisions,
                    'has_revision'   => $revisions > 0,
                    'edit_url'       => get_edit_post_link($post_id, ''),
                    'view_url'       => get_permalink($post_id),
                ];
            }
            wp_reset_postdata();
        }

        return [
            'items'       => $items,
            'total'       => intval($query->found_posts),
            'total_pages' => intval($query->max_num_pages),
            'page'        => $page,
            'per_page'    => $per_page,
        ];
    }

    /**
     * 指定投稿について「リライト直前」のリビジョン数を返す。
     * _affiros_rewrite_last_at より古いリビジョンの個数。
     */
    private static function count_pre_rewrite_revisions($post_id, $rewrite_last) {
        if (!$rewrite_last) return 0;
        $revisions = self::get_pre_rewrite_revisions($post_id, $rewrite_last);
        return count($revisions);
    }

    /**
     * 指定投稿のリビジョンのうち、リライト最終日時より古いものを返す（新しい順）。
     * post_modified が rewrite_last より前 = リライト前の状態。
     */
    private static function get_pre_rewrite_revisions($post_id, $rewrite_last) {
        $all = wp_get_post_revisions($post_id, [
            'numberposts' => 50,
            'orderby'     => 'date',
            'order'       => 'DESC',
        ]);
        if (empty($all)) return [];
        $rewrite_last_ts = strtotime($rewrite_last);
        if (!$rewrite_last_ts) return [];

        $pre = [];
        foreach ($all as $rev) {
            $rev_ts = strtotime($rev->post_modified_gmt ?: $rev->post_modified);
            if ($rev_ts && $rev_ts < $rewrite_last_ts) {
                $pre[] = $rev;
            }
        }
        return $pre;
    }

    /**
     * 指定投稿の「リライト直前」リビジョンプレビューを返す。
     */
    public static function preview($post_id) {
        $rewrite_last = (string) get_post_meta($post_id, '_affiros_rewrite_last_at', true);
        if (!$rewrite_last) {
            return new WP_Error('no_rewrite_history', 'この投稿にはリライト履歴がありません');
        }
        $revisions = self::get_pre_rewrite_revisions($post_id, $rewrite_last);
        if (empty($revisions)) {
            return new WP_Error('no_revision', 'リライト直前のリビジョンが見つかりません（WP のリビジョン保存が無効化されている可能性）');
        }
        // 最新の「リライト前」リビジョンを採用
        $target = $revisions[0];
        $current = get_post($post_id);
        return [
            'post_id'           => $post_id,
            'target_revision_id'=> $target->ID,
            'target_modified'   => mysql2date('Y-m-d H:i', $target->post_modified),
            'current_modified'  => mysql2date('Y-m-d H:i', $current->post_modified),
            'rewrite_last'      => mysql2date('Y-m-d H:i', $rewrite_last),
            'title_before'      => $target->post_title,
            'title_after'       => $current->post_title,
            'content_chars_before' => mb_strlen(wp_strip_all_tags($target->post_content)),
            'content_chars_after'  => mb_strlen(wp_strip_all_tags($current->post_content)),
            'revisions_total'   => count($revisions),
        ];
    }

    /**
     * 1記事を「リライト直前」のリビジョンに復元する。
     * 関連メタ（リライト履歴・マーカー検証）もクリア。
     *
     * @param int $post_id
     * @param string $mode  'one_step'    = 直前のリビジョンに1回戻す（既定）
     *                      'oldest'      = リライト履歴より前で最も古いリビジョンに戻す
     *                      'before_date' = 指定日時より前で最新のリビジョンに戻す
     * @param string $target_date 'before_date' モード時の基準日時（'YYYY-MM-DD HH:MM' or ISO 形式）
     */
    public static function restore_one($post_id, $mode = 'one_step', $target_date = '') {
        // === before_date モード（時期指定復元） ===
        // リライト履歴メタに依存せず、WP の全リビジョンから時期指定で復元できる。
        // 「どれが2回リライトしたかわからない」記事や、履歴メタが消えてしまった記事にも対応。
        if ($mode === 'before_date') {
            if (empty($target_date)) {
                return new WP_Error('no_target_date', '基準日時が指定されていません');
            }
            $target_ts = strtotime($target_date);
            if (!$target_ts) {
                return new WP_Error('invalid_target_date', '基準日時の形式が不正です（例: 2025-12-31 23:59）');
            }
            $all = wp_get_post_revisions($post_id, [
                'numberposts' => 100,
                'orderby'     => 'date',
                'order'       => 'DESC',
            ]);
            if (empty($all)) {
                return new WP_Error('no_revision', 'この投稿には WordPress リビジョンが残っていません');
            }
            $target = null;
            foreach ($all as $rev) {
                $rev_ts = strtotime($rev->post_modified_gmt ?: $rev->post_modified);
                if ($rev_ts && $rev_ts <= $target_ts) {
                    $target = $rev;
                    break; // DESC ソートなので最初に見つかったものが「指定日時以前の最新」
                }
            }
            if (!$target) {
                return new WP_Error('no_revision_before_date', '指定日時より前のリビジョンが見つかりません');
            }
        } else {
            $rewrite_last = (string) get_post_meta($post_id, '_affiros_rewrite_last_at', true);
            if (!$rewrite_last) {
                return new WP_Error('no_rewrite_history', 'この投稿にはリライト履歴がありません');
            }
            $revisions = self::get_pre_rewrite_revisions($post_id, $rewrite_last);
            if (empty($revisions)) {
                return new WP_Error('no_revision', 'リライト直前のリビジョンが見つかりません');
            }
            // mode に応じてターゲットを選定:
            //   one_step  : 最新の「リライト前」リビジョン = 1回分戻る
            //   oldest    : 最古の「リライト前」リビジョン = すべてのリライトを取り消す
            if ($mode === 'oldest') {
                $target = end($revisions); // 末尾 = 最古
            } else {
                $target = $revisions[0]; // 先頭 = 直前
            }
        }
        $restored = wp_restore_post_revision($target->ID);
        if (is_wp_error($restored)) {
            return $restored;
        }
        if (!$restored) {
            return new WP_Error('restore_failed', 'リビジョン復元に失敗しました');
        }

        // === リライト履歴メタの更新ロジック ===
        // 単純にメタを削除すると、2回リライトした記事を1回戻しただけで
        // 「リライト履歴がない」と判定されてしまい、2回目を戻せなくなる。
        //
        // - one_step モード: count を 1 減らす（残りリビジョンがあれば履歴維持）
        //                    last_at を今戻したリビジョン時刻に更新
        // - oldest モード:   リライトを全部取り消したので count=0 にクリア
        // - 復元後にもう「リライト前」リビジョンが無い場合もクリア
        $remaining_revisions = self::get_pre_rewrite_revisions($post_id, $target->post_modified);
        $current_count = (int) get_post_meta($post_id, '_affiros_rewrite_count', true);

        if ($mode === 'oldest' || $mode === 'before_date' || empty($remaining_revisions) || $current_count <= 1) {
            // 完全に履歴消化 → メタを削除（リストから消える）
            // before_date モードは「指定時点の状態」に戻すので、それまでのリライト履歴は全部無効
            delete_post_meta($post_id, '_affiros_rewrite_count');
            delete_post_meta($post_id, '_affiros_rewrite_last_at');
            $new_count = 0;
        } else {
            // 履歴が残る → count を 1 減らして last_at を更新（リストに残る）
            $new_count = max(0, $current_count - 1);
            update_post_meta($post_id, '_affiros_rewrite_count', $new_count);
            update_post_meta($post_id, '_affiros_rewrite_last_at', $target->post_modified);
        }
        // マーカー検証メタは復元後の内容と整合しないので必ず削除
        delete_post_meta($post_id, '_affiros_marker_status');
        delete_post_meta($post_id, '_affiros_marker_summary');

        return [
            'success'           => true,
            'post_id'           => $post_id,
            'restored_revision' => $target->ID,
            'restored_to'       => mysql2date('Y-m-d H:i', $target->post_modified),
            'mode'              => $mode,
            'total_revisions'   => count($revisions),
            'remaining_revisions'  => count($remaining_revisions),
            'rewrite_count_after'  => $new_count,
            'can_restore_more'     => $new_count > 0,
        ];
    }

    /**
     * リライト履歴がある全ての投稿 ID を返す（ページネーションなし）。
     * 「全件復元」ボタン用。
     */
    public static function list_all_rewritten_post_ids() {
        $ids = get_posts([
            'post_type'      => 'post',
            'post_status'    => ['publish', 'draft', 'pending', 'future', 'private'],
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => [
                [
                    'key'     => '_affiros_rewrite_count',
                    'value'   => 0,
                    'compare' => '>',
                    'type'    => 'NUMERIC',
                ],
            ],
        ]);
        return is_array($ids) ? array_map('intval', $ids) : [];
    }

    /**
     * 「指定日時より後に更新された」全投稿 ID を返す（時期指定復元の全件対象）。
     * リライト履歴メタに依存しないため、履歴メタが消えた記事も含まれる。
     */
    public static function list_posts_modified_after($target_date) {
        if (empty($target_date)) return [];
        $target_ts = strtotime($target_date);
        if (!$target_ts) return [];
        $cutoff = gmdate('Y-m-d H:i:s', $target_ts);

        $args = [
            'post_type'      => 'post',
            'post_status'    => ['publish', 'draft', 'pending', 'future', 'private'],
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'date_query'     => [
                [
                    'column' => 'post_modified_gmt',
                    'after'  => $cutoff,
                ],
            ],
        ];
        $ids = get_posts($args);
        if (!is_array($ids)) return [];

        // リビジョンがある投稿だけに絞る
        $filtered = [];
        foreach ($ids as $pid) {
            $revs = wp_get_post_revisions((int)$pid, [
                'numberposts' => 1,
                'orderby'     => 'date',
                'order'       => 'DESC',
            ]);
            if (!empty($revs)) {
                // さらに「指定日時より前のリビジョン」があるか軽く確認
                foreach ($revs as $rev) {
                    $rev_ts = strtotime($rev->post_modified_gmt ?: $rev->post_modified);
                    if ($rev_ts && $rev_ts <= $target_ts) {
                        $filtered[] = (int)$pid;
                        break;
                    }
                }
                // 1件しか取ってないので、より古いのは別途確認
                if (empty($filtered) || end($filtered) !== (int)$pid) {
                    $more = wp_get_post_revisions((int)$pid, [
                        'numberposts' => 100,
                        'orderby'     => 'date',
                        'order'       => 'DESC',
                    ]);
                    foreach ($more as $rev) {
                        $rev_ts = strtotime($rev->post_modified_gmt ?: $rev->post_modified);
                        if ($rev_ts && $rev_ts <= $target_ts) {
                            $filtered[] = (int)$pid;
                            break;
                        }
                    }
                }
            }
        }
        return array_values(array_unique($filtered));
    }

    /**
     * before_date モード時の投稿一覧（プレビュー用）。
     * 指定日時より後に更新された投稿で、指定日時以前のリビジョンがあるものを返す。
     */
    public static function list_posts_for_before_date($target_date, $args = []) {
        $per_page = max(1, intval($args['per_page'] ?? 20));
        $page = max(1, intval($args['page'] ?? 1));

        $all_ids = self::list_posts_modified_after($target_date);
        $total = count($all_ids);
        $offset = ($page - 1) * $per_page;
        $slice = array_slice($all_ids, $offset, $per_page);

        $target_ts = strtotime($target_date);
        $items = [];
        foreach ($slice as $pid) {
            $post = get_post($pid);
            if (!$post) continue;
            // 指定日時以前で最新のリビジョンを特定（プレビュー用）
            $candidate_rev = null;
            $revs = wp_get_post_revisions($pid, [
                'numberposts' => 50,
                'orderby'     => 'date',
                'order'       => 'DESC',
            ]);
            foreach ($revs as $rev) {
                $rev_ts = strtotime($rev->post_modified_gmt ?: $rev->post_modified);
                if ($rev_ts && $rev_ts <= $target_ts) {
                    $candidate_rev = $rev;
                    break;
                }
            }
            $rw_count = (int) get_post_meta($pid, '_affiros_rewrite_count', true);
            $items[] = [
                'id'             => $pid,
                'title'          => get_the_title($pid),
                'status'         => $post->post_status,
                'rewrite_count'  => $rw_count,
                'rewrite_last'   => '',
                'revisions'      => $candidate_rev ? 1 : 0,
                'has_revision'   => (bool)$candidate_rev,
                'target_rev_date'=> $candidate_rev ? mysql2date('Y-m-d H:i', $candidate_rev->post_modified) : '',
                'current_modified' => mysql2date('Y-m-d H:i', $post->post_modified),
                'edit_url'       => get_edit_post_link($pid, ''),
                'view_url'       => get_permalink($pid),
            ];
        }
        return [
            'items'       => $items,
            'total'       => $total,
            'total_pages' => max(1, (int)ceil($total / $per_page)),
            'page'        => $page,
            'per_page'    => $per_page,
        ];
    }

    /**
     * 複数記事を一括で「リライト直前」のリビジョンに復元する。
     */
    public static function bulk_restore($post_ids) {
        $results = ['success' => [], 'failed' => []];
        foreach ($post_ids as $pid) {
            $r = self::restore_one(intval($pid));
            if (is_wp_error($r)) {
                $results['failed'][] = [
                    'post_id' => intval($pid),
                    'title'   => get_the_title($pid),
                    'error'   => $r->get_error_message(),
                ];
            } else {
                $results['success'][] = [
                    'post_id'      => intval($pid),
                    'title'        => get_the_title($pid),
                    'restored_to'  => $r['restored_to'],
                ];
            }
        }
        return $results;
    }
}
