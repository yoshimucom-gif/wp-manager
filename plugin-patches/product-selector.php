<?php
/**
 * 商品選定ロジック（API連携の中核）
 */

if (!defined('ABSPATH')) exit;

class AI_PI_Product_Selector {

    /**
     * キーワードでAmazon・楽天両方を検索して候補を収集
     *
     * エラーは静かに握りつぶさず、PHPエラーログとプラグイン内ログにも残す。
     * デバッグのために最後に発生したAPIエラーをトランジェントに保持する。
     */
    public static function fetch_candidates($keyword, $per_source = 10) {
        $settings = get_option('ai_pi_settings', []);
        $preferred = $settings['preferred_site'] ?? 'both';

        $candidates = [];
        $api_errors = [];

        if (in_array($preferred, ['amazon', 'both'])) {
            $amazon = new AI_PI_Amazon_API();
            if ($amazon->is_configured()) {
                $amazon_results = $amazon->search($keyword, $per_source);
                if (is_wp_error($amazon_results)) {
                    $msg = '[Amazon] ' . $amazon_results->get_error_message() . ' (keyword=' . $keyword . ')';
                    $api_errors[] = $msg;
                    error_log('[AI_PI] ' . $msg);
                } else {
                    $candidates = array_merge($candidates, $amazon_results);
                }
            } else {
                $msg = '[Amazon] APIキー未設定（Access Key / Secret Key / Partner Tag のいずれかが空）';
                $api_errors[] = $msg;
                error_log('[AI_PI] ' . $msg);
            }
        }

        if (in_array($preferred, ['rakuten', 'both'])) {
            $rakuten = new AI_PI_Rakuten_API();
            if ($rakuten->is_configured()) {
                $rakuten_results = $rakuten->search($keyword, $per_source);
                if (is_wp_error($rakuten_results)) {
                    $msg = '[楽天] ' . $rakuten_results->get_error_message() . ' (keyword=' . $keyword . ')';
                    $api_errors[] = $msg;
                    error_log('[AI_PI] ' . $msg);
                } else {
                    // 楽天のタイトルは販促ノイズが多いので、クリーン版を別フィールドに保持
                    foreach ($rakuten_results as &$r) {
                        $r['title_raw'] = $r['title'];
                        $r['title'] = self::clean_rakuten_title($r['title']);
                    }
                    unset($r);
                    $candidates = array_merge($candidates, $rakuten_results);
                }
            } elseif ($preferred === 'rakuten') {
                $msg = '[楽天] APIキー未設定（アプリID が空）';
                $api_errors[] = $msg;
                error_log('[AI_PI] ' . $msg);
            }
        }

        // デバッグ用: 最後のAPIエラーを5分保持
        if (!empty($api_errors)) {
            set_transient('ai_pi_last_api_errors', $api_errors, 5 * MINUTE_IN_SECONDS);
        }

        return $candidates;
    }

    /**
     * 直近のAPIエラーを取得（デバッグ表示用）
     */
    public static function get_last_api_errors() {
        $errors = get_transient('ai_pi_last_api_errors');
        return is_array($errors) ? $errors : [];
    }

    /**
     * 候補リストにペア情報を付与（ハイブリッドモード）
     *
     * - merge_duplicates でタイトル類似度ペア化を実行
     * - ペアが見つかれば Amazon商品に rakuten_pair が紐付く
     * - フィルタリングはしない（全候補を Claude に渡す）
     * - 並び順: ペア済 → 単独 の順なので、Claude選定で自然にペア商品が優先される
     *
     * テンプレ側ではペアあり=直リン、ペア無し=検索URLにフォールバック。
     *
     * @param array $candidates fetch_candidates の戻り値
     * @return array merge_duplicates 適用後の全候補
     */
    public static function pair_candidates($candidates) {
        if (empty($candidates)) return [];

        $merged = self::merge_duplicates($candidates);

        // 統計ログ
        $paired_count = 0;
        $unpaired_count = 0;
        foreach ($merged as $item) {
            if (!empty($item['rakuten_pair']['url']) || !empty($item['amazon_pair']['asin'])) {
                $paired_count++;
            } else {
                $unpaired_count++;
            }
        }
        error_log("[AI_PI] pair_candidates: paired={$paired_count}, unpaired={$unpaired_count} (all kept, paired sorted first)");

        return $merged;
    }

    /**
     * IDから候補商品を検索
     */
    public static function find_by_id($candidates, $id) {
        foreach ($candidates as $c) {
            if ($c['id'] === $id) return $c;
        }
        return null;
    }

    /**
     * 同一商品の重複統合（Amazonと楽天の同一商品をペア化）
     */
    public static function merge_duplicates($candidates) {
        $merged = [];
        $used_amazon = [];

        foreach ($candidates as $c) {
            if ($c['source'] === 'rakuten') {
                $matched_amazon = null;
                foreach ($candidates as $a) {
                    if ($a['source'] !== 'amazon') continue;
                    if (in_array($a['id'], $used_amazon)) continue;

                    if (self::title_similarity($c['title'], $a['title']) > 0.6) {
                        $matched_amazon = $a;
                        $used_amazon[] = $a['id'];
                        break;
                    }
                }

                if ($matched_amazon) {
                    $merged_item = $matched_amazon;
                    $merged_item['rakuten_pair'] = [
                        'url' => $c['url'],
                        'price_display' => $c['price_display'],
                    ];
                    $merged[] = $merged_item;
                } else {
                    $merged[] = $c;
                }
            }
        }

        foreach ($candidates as $c) {
            if ($c['source'] !== 'amazon') continue;
            if (in_array($c['id'], $used_amazon)) continue;
            $merged[] = $c;
        }

        return $merged;
    }

    /**
     * ★ v1.2.0新規: タイトル類似度判定（外部から呼べるpublic）
     * 共通単語の割合（Jaccard係数の簡易版）
     */
    public static function title_similarity($a, $b) {
        $tokens_a = self::tokenize($a);
        $tokens_b = self::tokenize($b);

        if (empty($tokens_a) || empty($tokens_b)) return 0;

        $common = array_intersect($tokens_a, $tokens_b);
        $total = max(count($tokens_a), count($tokens_b));
        if ($total === 0) return 0;

        return count($common) / $total;
    }

    /**
     * タイトルをトークン化（簡易）
     * - 半角/全角スペース・記号で分割
     * - 1文字トークンは除外
     */
    private static function tokenize($text) {
        $text = mb_strtolower($text);
        $tokens = preg_split('/[\s　、,\.\(\)（）\[\]【】\/\-_:：;；・]+/u', $text);
        return array_filter($tokens, function($t) {
            return mb_strlen($t) >= 2;
        });
    }

    /**
     * ★ v1.2.0新規: 商品リストから類似商品を除去
     * @param array $products 商品配列
     * @param float $threshold 類似度閾値（0.0-1.0）。これ以上なら重複とみなす
     * @return array 重複除去後の商品配列
     */
    public static function dedupe_by_similarity($products, $threshold = 0.5) {
        $kept = [];
        foreach ($products as $p) {
            $is_dup = false;
            foreach ($kept as $k) {
                if (self::title_similarity($p['title'], $k['title']) >= $threshold) {
                    $is_dup = true;
                    break;
                }
            }
            if (!$is_dup) {
                $kept[] = $p;
            }
        }
        return $kept;
    }

    /**
     * ★ v1.2.0新規: 楽天タイトルから販促ノイズを除去
     *
     * 例:
     *   「複数買い最大15％OFF 20:00〜16日迄 ☆シルク100％で新発売☆ 【ピコタン専用バッグインバッグ】 …」
     *   → 「ピコタン専用バッグインバッグ …」
     */
    public static function clean_rakuten_title($title) {
        if (empty($title)) return $title;

        $text = $title;
        $max_iter = 10;

        while ($max_iter-- > 0) {
            $prev = $text;

            // 先頭の空白
            $text = preg_replace('/^[\s　]+/u', '', $text);

            // 先頭の【販促文言】を剥がす
            //   例: 【楽天1位】【新発売】【SALE】【ポイント10倍】【期間限定】
            $promo_in_brackets = '楽天[\d０-９]*位|ランキング[\d０-９]*位|楽天ランキング[\d０-９]*位?|新発売|新商品|新登場|再入荷|SALE|セール|タイムセール|スーパーSALE|スーパーセール|お買い物マラソン|送料無料|あす楽|即納|翌日配送|ポイント[\d０-９]+倍|[\d０-９]+ポイント|本日限定|期間限定|限定特価|特価|大特価|お買得|お得|目玉|レビュー特典|プレゼント付|楽天市場|限定';
            $text = preg_replace('/^【\s*(' . $promo_in_brackets . ')(\s*[\/\|・]\s*(' . $promo_in_brackets . '))*\s*】\s*/u', '', $text);

            // 「複数買い最大XX％OFF」「まとめ買いXX％OFF」「最大XX％OFF」
            $text = preg_replace('/^(複数買い|まとめ買い|最大|今だけ)?[最大]*[\d０-９]+[％%]\s*(OFF|オフ|お値引き|引き)\s*/u', '', $text);

            // 期間表記
            $text = preg_replace('/^[\d０-９]{1,2}\/[\d０-９]{1,2}[\s　]*[\d０-９]{1,2}:[\d０-９]{2}[〜~から][\s　]*/u', '', $text);
            $text = preg_replace('/^[\d０-９]{1,2}:[\d０-９]{2}[〜~から][\d０-９]{1,2}日(\s*[\d０-９]{1,2}:[\d０-９]{2})?(迄|まで)?\s*/u', '', $text);
            $text = preg_replace('/^[\d０-９]{1,2}日[\s　]*[\d０-９]{1,2}:[\d０-９]{2}\s*(迄|まで)?\s*/u', '', $text);
            $text = preg_replace('/^(本日|今日|月末)\s*限り\s*/u', '', $text);

            // 「☆〜☆」「★〜★」「♪〜♪」で囲まれた販促文（50文字以内）
            $text = preg_replace('/^[★☆][^★☆]{1,50}[★☆]\s*/u', '', $text);
            $text = preg_replace('/^[♪♫][^♪♫]{1,50}[♪♫]\s*/u', '', $text);

            // セール系のキーワード単体
            $text = preg_replace('/^(タイムセール|スーパーセール|スーパーSALE|楽天スーパーセール|お買い物マラソン|セール|期間限定|限定特価|特価|大特価|本日限定|新発売|新登場|新商品|再入荷|楽天1位|楽天ランキング1位|送料無料|即納|あす楽|翌日配送|ポイント[\d０-９]+倍|[\d０-９]+ポイント|レビュー特典|プレゼント付|あす楽対応)\s*/u', '', $text);

            // 残った装飾記号
            $text = preg_replace('/^[★☆◆◇●○▼▽■□▲△※♪♫♥♡]+\s*/u', '', $text);

            if ($prev === $text) break;
        }

        $text = trim($text);

        // クリーニング後に空になった場合は元のタイトルを返す
        if (empty($text)) return $title;

        return $text;
    }
}
