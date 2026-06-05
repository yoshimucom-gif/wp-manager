<?php
/**
 * マーカー挿入結果の検証
 *
 * Affiros_Rewrite_Marker_Inserter::insert() が返す stats を解析し、
 * 「期待通りの位置・件数で挿入できたか」を判定する。
 *
 * 状態は3段階:
 *   ok      ... 期待通り
 *   warning ... 軽微な欠落（再リライト推奨）
 *   error   ... 重大な失敗（緊急フォールバック発動・全ルール失敗 等）
 *
 * この結果はリライト保存時に投稿メタ _affiros_marker_status へ保存され、
 * 投稿一覧でユーザーが一目で「マーカーがおかしい記事」を判別できる。
 */

if (!defined('ABSPATH')) exit;

class Affiros_Rewrite_Marker_Validator {

    /**
     * @param array  $stats        insert() が返した stats
     * @param string $article_type 'ranking' | 'brand' | 'column'
     * @param string $title
     * @return array {
     *   'status'   => 'ok'|'warning'|'error',
     *   'problems' => string[],
     *   'summary'  => string  人間が読める要約
     * }
     */
    public static function check($stats, $article_type, $title = '') {
        $problems = [];
        $status = 'ok';

        if (!is_array($stats)) {
            return [
                'status' => 'error',
                'problems' => ['stats が取得できませんでした'],
                'summary'  => 'stats 不正',
            ];
        }

        // 1) 緊急フォールバックが動いた = 全ルール失敗
        if (!empty($stats['fallback_used'])) {
            $problems[] = '全ルール失敗 → 末尾に緊急フォールバックで1個だけ挿入';
            $status = 'error';
        }

        // 2) 一部ルールが失敗
        $failed = (array)($stats['rules_failed'] ?? []);
        if (!empty($failed)) {
            $problems[] = '未適用ルール: ' . implode(' / ', $failed);
            if ($status === 'ok') $status = 'warning';
        }

        // 3) ranking記事固有の検証: タイトルに○選が入っているのに H3ランクマーカーが0個 = 異常
        if ($article_type === 'ranking') {
            $expected_n = self::extract_ranking_count($title);
            $actual_rank_n = (int)($stats['per_position']['after_each_h3_rank'] ?? 0);
            if ($expected_n && $actual_rank_n === 0) {
                $problems[] = "ランキング {$expected_n} 選なのにランク H3 マーカーが0個";
                $status = 'error';
            } elseif ($expected_n && $actual_rank_n < $expected_n) {
                $problems[] = "ランキング H3 マーカー {$actual_rank_n}/{$expected_n} 個（不足）";
                if ($status === 'ok') $status = 'warning';
            }
        }

        // 4) マーカー総数が0（フォールバックも動かなかった = 何かが壊れている）
        if ((int)($stats['marker_count'] ?? 0) === 0) {
            $problems[] = 'マーカーが1個も入っていない';
            $status = 'error';
        }

        $summary = $status === 'ok' ? '正常' : implode(' / ', $problems);

        return [
            'status'   => $status,
            'problems' => $problems,
            'summary'  => $summary,
        ];
    }

    /**
     * タイトルから「○選」の数字を抽出。
     */
    private static function extract_ranking_count($title) {
        $t = mb_convert_kana((string)$title, 'n', 'UTF-8'); // 全角→半角数字
        if (preg_match('/([1-9][0-9]?)\s*選/u', $t, $m)) {
            $n = (int)$m[1];
            if ($n >= 2 && $n <= 30) return $n;
        }
        return null;
    }
}
