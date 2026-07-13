<?php
/**
 * H2 セクション並び替えエンジン
 *
 * SEO 最適な章順序（本体 CLAUDE.md ❶ 準拠）:
 *   1. 導入（H2 なし）
 *   2. 選定基準 / 評価軸
 *   3. ランキング（1位〜N位の個別解説）
 *   4. 選び方
 *   5. FAQ（よくある質問）
 *   6. まとめ
 *   7. その他 H2 は「まとめ手前」に配置
 *
 * リライト不要（Claude 呼ばない）。既存記事の章順を機械的に並び替えるだけ。
 */

if (!defined('ABSPATH')) exit;

class Affiros_Rewrite_Section_Reorder {

    // 章カテゴリの優先順位
    private static $order_map = [
        'criteria' => 1,
        'ranking'  => 2,
        'howto'    => 3,
        'faq'      => 4,
        'other'    => 5,
        'summary'  => 6,
    ];

    /**
     * @param string $html
     * @return array {
     *   'html'    => string 並び替え後のHTML,
     *   'changed' => bool 実際に順序が変わったか,
     *   'sections' => array 検出されたH2セクション一覧（診断用）,
     * }
     */
    public static function reorder($html) {
        if (empty($html)) {
            return ['html' => (string)$html, 'changed' => false, 'sections' => []];
        }
        $parts = self::split_into_sections($html);
        if (count($parts) <= 1) {
            return ['html' => $html, 'changed' => false, 'sections' => []];
        }

        // 導入部と H2 セクションを分離
        $intro = '';
        $h2_sections = [];
        foreach ($parts as $p) {
            if (!$p['is_h2']) {
                $intro .= $p['content'];
            } else {
                $p['category'] = self::classify($p['title'], $p['content']);
                $h2_sections[] = $p;
            }
        }

        // 元の順序を記録（安定ソート用）
        foreach ($h2_sections as $i => &$s) {
            $s['orig_index'] = $i;
        }
        unset($s);

        // 並び替え
        $sorted = $h2_sections;
        usort($sorted, function ($a, $b) {
            $ao = self::$order_map[$a['category']] ?? 5;
            $bo = self::$order_map[$b['category']] ?? 5;
            if ($ao !== $bo) return $ao - $bo;
            // 同カテゴリは元の順序を保持
            return $a['orig_index'] - $b['orig_index'];
        });

        // 変化検出
        $changed = false;
        foreach ($h2_sections as $i => $s) {
            if ($sorted[$i]['orig_index'] !== $s['orig_index']) {
                $changed = true;
                break;
            }
        }

        $result = $intro;
        foreach ($sorted as $s) {
            $result .= $s['content'];
        }

        // 診断用サマリー
        $sections_summary = [];
        foreach ($sorted as $s) {
            $sections_summary[] = [
                'title'    => $s['title'],
                'category' => $s['category'],
                'orig_pos' => $s['orig_index'] + 1,
            ];
        }

        return [
            'html'     => $result,
            'changed'  => $changed,
            'sections' => $sections_summary,
        ];
    }

    /**
     * HTML を H2 セクション単位に分割
     */
    private static function split_into_sections($html) {
        // Gutenberg コメント込みで H2 を検出（<h2 タグの開始位置を拾う）
        if (!preg_match_all('/<h2\b[^>]*>/i', $html, $matches, PREG_OFFSET_CAPTURE)) {
            return [['is_h2' => false, 'content' => $html, 'title' => '']];
        }
        $h2_starts = [];
        foreach ($matches[0] as $m) {
            $h2_starts[] = $m[1];
        }

        // 各 H2 の前にある wp:heading コメントも含めて開始位置を補正
        $corrected_starts = [];
        foreach ($h2_starts as $pos) {
            $before = substr($html, 0, $pos);
            if (preg_match('/<!--\s*wp:heading[^>]*-->\s*$/i', $before, $mm, PREG_OFFSET_CAPTURE)) {
                $corrected_starts[] = $mm[0][1];
            } else {
                $corrected_starts[] = $pos;
            }
        }

        $parts = [];
        // 導入部分（最初の H2 の前）
        if ($corrected_starts[0] > 0) {
            $parts[] = [
                'is_h2'   => false,
                'content' => substr($html, 0, $corrected_starts[0]),
                'title'   => '',
            ];
        }

        // 各 H2 セクション: 開始位置 〜 次の H2 開始位置（または末尾）
        $n = count($corrected_starts);
        for ($i = 0; $i < $n; $i++) {
            $start = $corrected_starts[$i];
            $end = ($i + 1 < $n) ? $corrected_starts[$i + 1] : strlen($html);
            $section = substr($html, $start, $end - $start);
            // タイトル抽出
            $title = '';
            if (preg_match('/<h2\b[^>]*>((?:(?!<\/h2>)[\s\S])*?)<\/h2>/i', $section, $tm)) {
                $title = trim(strip_tags($tm[1]));
            }
            $parts[] = [
                'is_h2'   => true,
                'content' => $section,
                'title'   => $title,
            ];
        }

        return $parts;
    }

    /**
     * H2 タイトルと本文からカテゴリを判定
     */
    private static function classify($title, $content) {
        // まとめ（最優先で判定）
        if (preg_match('/まとめ|総括|結論|選びのポイント|選ぶポイント/u', $title)) {
            return 'summary';
        }
        // FAQ
        if (preg_match('/よくある質問|FAQ|Q\s*&\s*A|質問/iu', $title)) {
            return 'faq';
        }
        // ランキング: タイトル or 本文にランキング系 H3 がある
        $ranking_h3 = '/<h3[^>]*>[\s\[【★●■◆▼《「『（(]*(?:第\s*)?(?:\d+|[０-９]+)\s*位/u';
        if (preg_match('/ランキング|おすすめ.*[選比]/u', $title) ||
            preg_match($ranking_h3, $content)) {
            return 'ranking';
        }
        // 選び方
        if (preg_match('/選び方|選ぶ|選定のコツ|選定基準の使い方/u', $title)) {
            return 'howto';
        }
        // 選定基準
        if (preg_match('/選定基準|評価軸|評価基準|評価ポイント|判定基準/u', $title)) {
            return 'criteria';
        }
        return 'other';
    }
}
