<?php
/**
 * 商品カードを記事本文へ挿入する処理
 */

if (!defined('ABSPATH')) exit;

class AI_PI_Inserter {

    /**
     * 1記事に商品を挿入
     * @param int $post_id
     * @param array $options [
     *   'insert_mode' => 'marker'|'marker_per_heading'|'auto',
     *   'card_design' => 'vertical'|'horizontal'|'ranking',
     *   'insert_position' => 'top'|'before_first_h2'|'after_first_h2'|'before_last_h2'|'after_last_h2'|'bottom',
     *   'dry_run' => bool,
     * ]
     */
    public static function insert_into_post($post_id, $options = []) {
        $post = get_post($post_id);
        if (!$post) return new WP_Error('post_not_found', '記事が見つかりません');

        $settings = get_option('ai_pi_settings', []);
        $mode = $options['insert_mode'] ?? $settings['default_insert_mode'] ?? 'marker';
        $design = $options['card_design'] ?? $settings['default_card_design'] ?? 'vertical';
        $position = $options['insert_position'] ?? $settings['default_position'] ?? $settings['auto_top3_position'] ?? 'bottom';
        $dry_run = $options['dry_run'] ?? false;

        // 旧モード互換: auto_top3 / ranking → auto
        if (in_array($mode, ['auto_top3', 'ranking'])) {
            $mode = 'auto';
        }

        // 再挿入時: バックアップ（=オリジナルのマーカー入り content）が残っていれば
        // そちらを source として使う。現在の content はカード描画後のためマーカーが消えており
        // そのままだと「マーカーが見つかりません」になる。
        $backup_content = get_post_meta($post_id, '_ai_pi_backup', true);
        $already_inserted = !empty(get_post_meta($post_id, '_ai_pi_inserted', true));
        if ($already_inserted && !empty($backup_content)) {
            $original_content = $backup_content;
        } else {
            $original_content = $post->post_content;
        }

        try {
            if ($mode === 'marker') {
                $result = self::process_marker_mode($post_id, $original_content, $design);
            } elseif ($mode === 'marker_per_heading') {
                $result = self::process_marker_per_heading_mode($post_id, $original_content, $design);
            } elseif ($mode === 'auto') {
                $result = self::process_auto_mode($post_id, $original_content, $design, $position);
            } else {
                return new WP_Error('invalid_mode', '不正な挿入モード: ' . $mode);
            }

            if (is_wp_error($result)) {
                self::log_failure($post_id, $result->get_error_message());
                return $result;
            }

            if ($dry_run) return $result;

            // バックアップ
            update_post_meta($post_id, '_ai_pi_backup', $original_content);
            update_post_meta($post_id, '_ai_pi_backup_at', current_time('mysql'));

            // 投稿更新
            $updated = wp_update_post([
                'ID' => $post_id,
                'post_content' => $result['new_content'],
            ], true);

            if (is_wp_error($updated)) return $updated;

            update_post_meta($post_id, '_ai_pi_inserted', 1);
            update_post_meta($post_id, '_ai_pi_inserted_at', current_time('mysql'));
            update_post_meta($post_id, '_ai_pi_mode', $mode);
            update_post_meta($post_id, '_ai_pi_design', $design);
            update_post_meta($post_id, '_ai_pi_position', $position);
            update_post_meta($post_id, '_ai_pi_products', $result['products']);
            update_post_meta($post_id, '_ai_pi_total_usage', $result['usage']);

            self::log_success($post_id, $mode, $result['usage']);

            return $result;

        } catch (Exception $e) {
            self::log_failure($post_id, $e->getMessage());
            return new WP_Error('exception', $e->getMessage());
        }
    }

    /**
     * マーカーモード（記事全体の文脈から関連商品を選定）
     */
    private static function process_marker_mode($post_id, $content, $design) {
        $marker_pattern = '/<!--\s*ai-product(?::([a-z]+)(?::(\d+))?)?\s*-->/i';
        preg_match_all($marker_pattern, $content, $markers, PREG_SET_ORDER);
        $marker_count = count($markers);

        if ($marker_count === 0) {
            return new WP_Error('no_markers', '記事本文に <!--ai-product--> マーカーが見つかりません');
        }

        // 単体マーカー(vertical/proscons等)と多商品マーカー(ranking/compare)を分けてカウント
        // 多商品マーカーは「単体マーカーで選定された商品」を流用するため、Claudeへは単体分だけ依頼する
        $single_marker_count = 0;
        foreach ($markers as $m) {
            $m_design = !empty($m[1]) ? strtolower($m[1]) : $design;
            if ($m_design !== 'ranking' && $m_design !== 'compare') {
                $single_marker_count++;
            }
        }

        $claude = new AI_PI_Claude_API();
        $total_usage = ['input_tokens' => 0, 'output_tokens' => 0];

        // キーワード抽出は記事全体から（単体マーカー数を考慮）
        $kw_count = max($single_marker_count, 1);
        $kw_result = $claude->extract_keywords($content, $kw_count);
        if (is_wp_error($kw_result)) return $kw_result;
        $keywords = $kw_result['keywords'];
        self::accumulate_usage($total_usage, $kw_result['usage']);

        $settings = get_option('ai_pi_settings', []);
        $per_keyword = intval($settings['candidates_per_keyword'] ?? 10);

        $all_candidates = [];
        foreach ($keywords as $kw) {
            $cands = AI_PI_Product_Selector::fetch_candidates($kw, $per_keyword);
            foreach ($cands as $c) {
                $all_candidates[$c['id']] = $c;
            }
        }
        $all_candidates = array_values($all_candidates);

        if (empty($all_candidates)) {
            $errs = AI_PI_Product_Selector::get_last_api_errors();
            $detail = !empty($errs)
                ? '  詳細: ' . implode(' / ', array_slice($errs, 0, 3))
                : '';
            return new WP_Error('no_candidates', '商品候補が取得できませんでした。' . $detail);
        }

        // Claudeには「単体マーカー数」分だけ選定を依頼（多商品マーカーは流用するため重複依頼を避ける）
        $selections_by_index = [];
        if ($single_marker_count > 0) {
            $sel_result = $claude->select_products_marker($content, $all_candidates, $single_marker_count);
            if (is_wp_error($sel_result)) return $sel_result;
            self::accumulate_usage($total_usage, $sel_result['usage']);

            foreach ($sel_result['selections'] as $sel) {
                if (!isset($sel['marker_index'])) continue;
                $idx = intval($sel['marker_index']);
                if (!AI_PI_Product_Selector::find_by_id($all_candidates, $sel['product_id'])) continue;
                if (!isset($selections_by_index[$idx])) {
                    $selections_by_index[$idx] = $sel;
                }
            }
        }

        // 単体マーカーの選定結果を順番に並べた配列（多商品マーカーの一次ソース）
        $article_products = [];
        $seen_article_ids = [];
        foreach ($selections_by_index as $sel) {
            $p = AI_PI_Product_Selector::find_by_id($all_candidates, $sel['product_id']);
            if (!$p) continue;
            $pid = $p['id'] ?? '';
            if (!$pid || isset($seen_article_ids[$pid])) continue;
            $article_products[] = $p;
            $seen_article_ids[$pid] = true;
        }

        $selected_products = [];
        $single_counter = 0;

        $new_content = preg_replace_callback(
            $marker_pattern,
            function($match) use (&$single_counter, $selections_by_index, $all_candidates, $article_products, $design, &$selected_products) {
                // マーカーから design hint を取得（無ければプラグイン既定）
                $marker_design = !empty($match[1]) ? strtolower($match[1]) : $design;
                $marker_count  = !empty($match[2]) ? intval($match[2]) : 3;

                // multi-product マーカー: 記事内で既に使われた商品だけを使う（候補プール補完なし）
                // → 比較表/ランキングは「本文で語った商品」と完全一致する保証
                // → 足りない場合は表示数が指定Nより少なくなる（=記事内容との不一致を防ぐ）
                if ($marker_design === 'ranking' || $marker_design === 'compare') {
                    $multi = array_slice($article_products, 0, $marker_count);
                    if (empty($multi)) return $match[0];

                    foreach ($multi as $p) {
                        $pid = $p['id'] ?? '';
                        $already = false;
                        foreach ($selected_products as $sp) {
                            if (($sp['id'] ?? '') === $pid) { $already = true; break; }
                        }
                        if (!$already) $selected_products[] = $p;
                    }

                    if ($marker_design === 'compare') {
                        return AI_PI_Card_Renderer::render_compare($multi);
                    }
                    return AI_PI_Card_Renderer::render_ranking($multi);
                }

                // 単体マーカー: Claudeが選定した商品を使う
                $current_idx = $single_counter;
                $single_counter++;

                if (!isset($selections_by_index[$current_idx])) return $match[0];

                $sel = $selections_by_index[$current_idx];
                $product = AI_PI_Product_Selector::find_by_id($all_candidates, $sel['product_id']);
                if (!$product) return $match[0];

                $selected_products[] = $product;
                return AI_PI_Card_Renderer::render($product, $marker_design);
            },
            $content
        );

        return [
            'new_content' => $new_content,
            'products' => $selected_products,
            'keywords' => $keywords,
            'usage' => $total_usage,
        ];
    }

    /**
     * 見出し連動マーカーモード
     */
    private static function process_marker_per_heading_mode($post_id, $content, $design) {
        $marker_pattern = '/<!--\s*ai-product(?::([a-z]+)(?::(\d+))?)?\s*-->/i';
        preg_match_all($marker_pattern, $content, $markers);
        $marker_count = count($markers[0]);

        if ($marker_count === 0) {
            return new WP_Error('no_markers', '記事本文に <!--ai-product--> マーカーが見つかりません');
        }

        $post = get_post($post_id);
        $title = $post ? $post->post_title : '';
        $marker_headings = self::extract_marker_headings($content, $title);

        if (count($marker_headings) !== $marker_count) {
            return new WP_Error(
                'heading_extraction_failed',
                "見出し抽出失敗（マーカー{$marker_count}個に対し見出し" . count($marker_headings) . "個）"
            );
        }

        $search_queries = [];
        foreach ($marker_headings as $h) {
            $search_queries[] = self::clean_heading_for_search($h, $title);
        }

        $settings = get_option('ai_pi_settings', []);
        $per_keyword = intval($settings['candidates_per_keyword'] ?? 10);

        $marker_data = [];
        $all_candidates_pool = [];

        foreach ($search_queries as $idx => $query) {
            if (empty($query)) {
                $marker_data[$idx] = ['heading' => $marker_headings[$idx], 'query' => '', 'candidates' => []];
                continue;
            }

            $cands = AI_PI_Product_Selector::fetch_candidates($query, $per_keyword);
            $marker_data[$idx] = ['heading' => $marker_headings[$idx], 'query' => $query, 'candidates' => $cands];

            foreach ($cands as $c) {
                $all_candidates_pool[$c['id']] = $c;
            }
        }

        $all_candidates_pool = array_values($all_candidates_pool);

        if (empty($all_candidates_pool)) {
            return new WP_Error('no_candidates', '全マーカーで商品候補が0件でした');
        }

        $claude = new AI_PI_Claude_API();
        $sel_result = $claude->select_products_per_heading($marker_data);
        if (is_wp_error($sel_result)) return $sel_result;

        $total_usage = ['input_tokens' => 0, 'output_tokens' => 0];
        self::accumulate_usage($total_usage, $sel_result['usage']);

        $selections_by_index = [];
        foreach ($sel_result['selections'] as $sel) {
            if (!isset($sel['marker_index'])) continue;
            $idx = intval($sel['marker_index']);
            if (!AI_PI_Product_Selector::find_by_id($all_candidates_pool, $sel['product_id'])) continue;
            if (!isset($selections_by_index[$idx])) {
                $selections_by_index[$idx] = $sel;
            }
        }

        // 単体マーカーで選定された商品を順に並べた配列（多商品マーカーの一次ソース）
        $article_products = [];
        $seen_article_ids = [];
        ksort($selections_by_index);
        foreach ($selections_by_index as $sel) {
            $p = AI_PI_Product_Selector::find_by_id($all_candidates_pool, $sel['product_id']);
            if (!$p) continue;
            $pid = $p['id'] ?? '';
            if (!$pid || isset($seen_article_ids[$pid])) continue;
            $article_products[] = $p;
            $seen_article_ids[$pid] = true;
        }

        $selected_products = [];
        $marker_counter = 0;

        $new_content = preg_replace_callback(
            $marker_pattern,
            function($match) use (&$marker_counter, $selections_by_index, $all_candidates_pool, $article_products, $design, &$selected_products) {
                $current_idx = $marker_counter;
                $marker_counter++;

                // マーカーから design hint を取得（無ければプラグイン既定）
                $marker_design = !empty($match[1]) ? strtolower($match[1]) : $design;
                $marker_count  = !empty($match[2]) ? intval($match[2]) : 3;

                // multi-product マーカー: 記事内で既に使われた商品だけを使う（候補プール補完なし）
                if ($marker_design === 'ranking' || $marker_design === 'compare') {
                    $multi = array_slice($article_products, 0, $marker_count);
                    if (empty($multi)) return $match[0];

                    foreach ($multi as $p) {
                        $pid = $p['id'] ?? '';
                        $already = false;
                        foreach ($selected_products as $sp) {
                            if (($sp['id'] ?? '') === $pid) { $already = true; break; }
                        }
                        if (!$already) $selected_products[] = $p;
                    }

                    if ($marker_design === 'compare') {
                        return AI_PI_Card_Renderer::render_compare($multi);
                    }
                    return AI_PI_Card_Renderer::render_ranking($multi);
                }

                if (!isset($selections_by_index[$current_idx])) return $match[0];

                $sel = $selections_by_index[$current_idx];
                $product = AI_PI_Product_Selector::find_by_id($all_candidates_pool, $sel['product_id']);
                if (!$product) return $match[0];

                $selected_products[] = $product;
                return AI_PI_Card_Renderer::render($product, $marker_design);
            },
            $content
        );

        return [
            'new_content' => $new_content,
            'products' => $selected_products,
            'headings' => $marker_headings,
            'queries' => $search_queries,
            'usage' => $total_usage,
        ];
    }

    /**
     * ★ v1.2.0変更: 自動配置モード（旧 auto_top3 / ranking を統合）
     * 位置・デザインを独立して指定可能
     */
    private static function process_auto_mode($post_id, $content, $design, $position) {
        $settings = get_option('ai_pi_settings', []);

        // デザインがランキング型なら複数商品、それ以外なら1商品
        $product_count = ($design === 'ranking')
            ? max(1, min(10, intval($settings['ranking_count'] ?? 3)))
            : 1;

        $claude = new AI_PI_Claude_API();
        $total_usage = ['input_tokens' => 0, 'output_tokens' => 0];

        // キーワード抽出
        $kw_result = $claude->extract_keywords($content, max(2, $product_count));
        if (is_wp_error($kw_result)) return $kw_result;
        $keywords = $kw_result['keywords'];
        self::accumulate_usage($total_usage, $kw_result['usage']);

        // 候補取得
        $per_keyword = intval($settings['candidates_per_keyword'] ?? 10);
        $all_candidates = [];
        foreach ($keywords as $kw) {
            $cands = AI_PI_Product_Selector::fetch_candidates($kw, $per_keyword);
            foreach ($cands as $c) {
                $all_candidates[$c['id']] = $c;
            }
        }
        $all_candidates = array_values($all_candidates);

        if (empty($all_candidates)) {
            $errs = AI_PI_Product_Selector::get_last_api_errors();
            $detail = !empty($errs)
                ? '  詳細: ' . implode(' / ', array_slice($errs, 0, 3))
                : '';
            return new WP_Error('no_candidates', '商品候補が取得できませんでした。' . $detail);
        }

        // AI選定（ランキング）
        $rank_result = $claude->select_products_ranking($content, $all_candidates, $product_count);
        if (is_wp_error($rank_result)) return $rank_result;
        self::accumulate_usage($total_usage, $rank_result['usage']);

        // ★ バグ修正A: product_idバリデーション + 欠落商品はスキップ
        $ranked_products = [];
        foreach ($rank_result['ranking'] as $r) {
            $pid = $r['product_id'] ?? '';
            $product = AI_PI_Product_Selector::find_by_id($all_candidates, $pid);
            if (!$product) continue; // AIがハルシネートしたIDは捨てる
            $product['reason'] = $r['reason'] ?? '';
            $ranked_products[] = $product;
        }

        // ★ バグ修正B: 類似商品の除去（タイトル類似度50%以上は重複扱い）
        $ranked_products = AI_PI_Product_Selector::dedupe_by_similarity($ranked_products, 0.5);

        // 必要件数に丸め込み
        $ranked_products = array_slice($ranked_products, 0, $product_count);

        if (empty($ranked_products)) {
            return new WP_Error('no_valid_selection', 'AIが返した商品IDが候補に存在しませんでした');
        }

        // ★ バグ修正A: rank を1,2,3...にリナンバー（欠番なし）
        foreach ($ranked_products as $i => &$p) {
            $p['rank'] = $i + 1;
        }
        unset($p);

        // HTML生成
        if ($design === 'ranking') {
            $insert_html = AI_PI_Card_Renderer::render_ranking($ranked_products, $rank_result['criteria']);
        } else {
            // 縦置き/横長: 1商品のみ
            $insert_html = AI_PI_Card_Renderer::render($ranked_products[0], $design);
        }

        // 挿入位置に応じて配置
        $new_content = self::insert_at_position($content, $insert_html, $position);

        return [
            'new_content' => $new_content,
            'products' => $ranked_products,
            'keywords' => $keywords,
            'criteria' => $rank_result['criteria'] ?? '',
            'usage' => $total_usage,
        ];
    }

    /**
     * ★ v1.2.0新規: 指定位置にHTMLを挿入する
     *
     * @param string $content 元の記事本文
     * @param string $insert_html 挿入するHTML
     * @param string $position 位置キー
     *   - top: 記事冒頭
     *   - before_first_h2: 最初のH2の直前
     *   - after_first_h2: 最初のH2の直後
     *   - before_last_h2: 最後のH2の直前
     *   - after_last_h2: 最後のH2の直後
     *   - bottom: 記事末尾
     */
    private static function insert_at_position($content, $insert_html, $position) {
        $separator = "\n\n";
        // Gutenbergブロック付きH2 + 生のH2 両対応
        // 「（オプションのwp:headingコメント）<h2>...</h2>（オプションの/wp:headingコメント）」を1つの単位とする
        $h2_pattern = '/(?:<!--\s*wp:heading[^>]*-->\s*)?<h2[^>]*>.*?<\/h2>(?:\s*<!--\s*\/wp:heading\s*-->)?/is';

        switch ($position) {
            case 'top':
                return $insert_html . $separator . $content;

            case 'bottom':
                return $content . $separator . $insert_html;

            case 'before_first_h2':
                if (preg_match($h2_pattern, $content, $m, PREG_OFFSET_CAPTURE)) {
                    $pos = $m[0][1];
                    return substr($content, 0, $pos) . $insert_html . $separator . substr($content, $pos);
                }
                return $content . $separator . $insert_html;

            case 'after_first_h2':
                if (preg_match($h2_pattern, $content, $m, PREG_OFFSET_CAPTURE)) {
                    $pos = $m[0][1] + strlen($m[0][0]);
                    return substr($content, 0, $pos) . $separator . $insert_html . substr($content, $pos);
                }
                return $insert_html . $separator . $content;

            case 'before_last_h2':
                if (preg_match_all($h2_pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
                    $last = end($matches[0]);
                    $pos = $last[1];
                    return substr($content, 0, $pos) . $insert_html . $separator . substr($content, $pos);
                }
                return $content . $separator . $insert_html;

            case 'after_last_h2':
                if (preg_match_all($h2_pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
                    $last = end($matches[0]);
                    $pos = $last[1] + strlen($last[0]);
                    return substr($content, 0, $pos) . $separator . $insert_html . substr($content, $pos);
                }
                return $content . $separator . $insert_html;

            default:
                return $content . $separator . $insert_html;
        }
    }

    /**
     * 記事本文を走査し、各マーカーの直前にあるH2/H3/H4の見出しテキストを抽出
     */
    private static function extract_marker_headings($content, $fallback = '') {
        preg_match_all(
            '/(<h([234])[^>]*>(.*?)<\/h\2>)|(<!--\s*ai-product(?::[a-z]+(?::\d+)?)?\s*-->)/is',
            $content,
            $matches,
            PREG_SET_ORDER
        );

        $current_heading = $fallback;
        $pairs = [];

        foreach ($matches as $m) {
            if (!empty($m[1])) {
                $heading_text = trim(wp_strip_all_tags($m[3] ?? ''));
                if (!empty($heading_text)) {
                    $current_heading = $heading_text;
                }
            } elseif (isset($m[4]) && $m[4] !== '') {
                $pairs[] = $current_heading;
            }
        }

        return $pairs;
    }

    /**
     * 見出しから装飾・順位表記を除去
     */
    private static function clean_heading_for_search($heading, $fallback = '') {
        if (empty($heading)) return $fallback;

        $text = $heading;
        $max_iter = 5;
        while ($max_iter-- > 0) {
            $prev = $text;
            $text = preg_replace('/^[\s★☆◆◇●○▼▽■□▲△※♪♥♡]+/u', '', $text);
            $text = preg_replace('/^第?[\d０-９]+位[\s:：\.、\)）]*/u', '', $text);
            $text = preg_replace('/^[①②③④⑤⑥⑦⑧⑨⑩⑪⑫⑬⑭⑮⓪]+[\s:：\.、\)）]*/u', '', $text);
            $text = preg_replace('/^[\d０-９]+[\.．、\)）:：]\s*/u', '', $text);
            $text = preg_replace('/^[\[【]([^\]】]{1,15})[\]】]\s*/u', '', $text);
            if ($prev === $text) break;
        }

        $text = trim($text);
        if (empty($text)) $text = trim($heading);
        if (empty($text)) $text = $fallback;
        return $text;
    }

    /**
     * 挿入を元に戻す
     */
    public static function rollback($post_id) {
        $backup = get_post_meta($post_id, '_ai_pi_backup', true);
        if (empty($backup)) {
            return new WP_Error('no_backup', 'バックアップが見つかりません');
        }

        wp_update_post([
            'ID' => $post_id,
            'post_content' => $backup,
        ]);

        delete_post_meta($post_id, '_ai_pi_inserted');
        delete_post_meta($post_id, '_ai_pi_inserted_at');
        delete_post_meta($post_id, '_ai_pi_mode');
        delete_post_meta($post_id, '_ai_pi_design');
        delete_post_meta($post_id, '_ai_pi_position');
        delete_post_meta($post_id, '_ai_pi_products');
        delete_post_meta($post_id, '_ai_pi_total_usage');

        return ['success' => true];
    }

    private static function accumulate_usage(&$total, $usage) {
        $total['input_tokens'] += $usage['input_tokens'] ?? 0;
        $total['output_tokens'] += $usage['output_tokens'] ?? 0;
    }

    private static function log_success($post_id, $mode, $usage) {
        $logs = get_option('ai_pi_logs', []);
        $logs[] = [
            'timestamp' => current_time('mysql'),
            'post_id' => $post_id,
            'result' => 'success',
            'mode' => $mode,
            'input_tokens' => $usage['input_tokens'] ?? 0,
            'output_tokens' => $usage['output_tokens'] ?? 0,
        ];
        if (count($logs) > 500) $logs = array_slice($logs, -500);
        update_option('ai_pi_logs', $logs, false);
    }

    private static function log_failure($post_id, $message) {
        $logs = get_option('ai_pi_logs', []);
        $logs[] = [
            'timestamp' => current_time('mysql'),
            'post_id' => $post_id,
            'result' => 'failure',
            'message' => $message,
        ];
        if (count($logs) > 500) $logs = array_slice($logs, -500);
        update_option('ai_pi_logs', $logs, false);
    }
}
