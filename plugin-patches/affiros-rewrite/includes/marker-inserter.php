<?php
/**
 * 商品カードマーカー挿入エンジン
 *
 * Affiros の DEFAULT_CARD_INSERTION_PATTERNS を PHP 移植。
 * リライト後の HTML に <!--ai-product:vertical--> や <!--ai-product:ranking:3--> を
 * 記事タイプ別の規則で挿入する。実際の商品カード描画は ai-product-inserter プラグインが担当。
 */

if (!defined('ABSPATH')) exit;

class Affiros_Rewrite_Marker_Inserter {

    /**
     * 記事タイプ別 挿入パターン
     * 各エントリは { position, design, count?, repeat? }
     */
    public static function default_patterns() {
        return [
            'ranking' => [
                ['position' => 'each_h3',         'design' => 'vertical'],
                ['position' => 'after_matome_h2', 'design' => 'ranking', 'count' => 3],
            ],
            'brand' => [
                ['position' => 'after_first_h2',  'design' => 'vertical'],
                ['position' => 'before_matome_h2','design' => 'vertical'],
            ],
            'column' => [
                ['position' => 'before_first_h2', 'design' => 'vertical', 'repeat' => 3],
                ['position' => 'after_matome_h2', 'design' => 'ranking', 'count' => 3],
            ],
        ];
    }

    /**
     * 記事タイプに応じてマーカーを挿入
     *
     * @param string $html  リライト後のHTML
     * @param string $article_type  'ranking' | 'brand' | 'column'
     * @return string  マーカー挿入後のHTML
     */
    public static function insert($html, $article_type) {
        $patterns = self::default_patterns();
        if (!isset($patterns[$article_type])) {
            return $html;
        }
        $rules = $patterns[$article_type];

        // 既存の <!--ai-product:...--> マーカーを残したまま追加（重複は避ける = 既に同じ位置にあれば追加しない、はせず素直に追加）
        // 各ルールを順に適用
        foreach ($rules as $rule) {
            $html = self::apply_rule($html, $rule);
        }
        return $html;
    }

    private static function apply_rule($html, $rule) {
        $position = $rule['position'] ?? '';
        $marker = self::build_marker($rule);

        switch ($position) {
            case 'each_h3':
                // すべての </h3> 直後にマーカー挿入
                return preg_replace_callback(
                    '#</h3\s*>#i',
                    function ($m) use ($marker) { return $m[0] . "\n" . $marker; },
                    $html
                );

            case 'after_first_h2':
                // 最初の </h2> 直後にマーカー挿入
                return preg_replace('#</h2\s*>#i', '$0' . "\n" . $marker, $html, 1);

            case 'before_first_h2':
                // 最初の <h2 ...> 直前にマーカー挿入（repeat 指定があれば繰り返す）
                $repeat = max(1, intval($rule['repeat'] ?? 1));
                $block = str_repeat($marker . "\n", $repeat);
                return preg_replace('#<h2[\s>]#i', $block . '$0', $html, 1);

            case 'after_matome_h2':
                // 「まとめ」を含む h2 の直後（その h2 配下のセクションを区切らずに次の h2 / 末尾の直前）
                // 簡易版: 「まとめ」を含む最初の <h2>...</h2> の直後に挿入
                return self::insert_after_matome($html, $marker);

            case 'before_matome_h2':
                return self::insert_before_matome($html, $marker);
        }
        return $html;
    }

    private static function build_marker($rule) {
        $design = $rule['design'] ?? 'vertical';
        if ($design === 'ranking') {
            $count = max(1, intval($rule['count'] ?? 3));
            return '<!--ai-product:ranking:' . $count . '-->';
        }
        if ($design && $design !== 'default') {
            return '<!--ai-product:' . preg_replace('/[^a-z0-9_-]/', '', strtolower($design)) . '-->';
        }
        return '<!--ai-product-->';
    }

    /**
     * 「まとめ」「最後に」「終わりに」など総括見出しを含む h2 の直後にマーカー挿入
     */
    private static function insert_after_matome($html, $marker) {
        $matome_keywords = ['まとめ', '最後に', '終わりに', 'おわりに', '結論'];
        $pattern = '#<h2\b[^>]*>(.*?)</h2\s*>#is';
        $inserted = false;
        return preg_replace_callback($pattern, function ($m) use ($matome_keywords, $marker, &$inserted) {
            if ($inserted) return $m[0];
            $text = wp_strip_all_tags($m[1]);
            foreach ($matome_keywords as $kw) {
                if (mb_strpos($text, $kw) !== false) {
                    $inserted = true;
                    return $m[0] . "\n" . $marker;
                }
            }
            return $m[0];
        }, $html);
    }

    /**
     * 「まとめ」等の h2 の直前にマーカー挿入
     */
    private static function insert_before_matome($html, $marker) {
        $matome_keywords = ['まとめ', '最後に', '終わりに', 'おわりに', '結論'];
        $pattern = '#<h2\b[^>]*>(.*?)</h2\s*>#is';
        $inserted = false;
        return preg_replace_callback($pattern, function ($m) use ($matome_keywords, $marker, &$inserted) {
            if ($inserted) return $m[0];
            $text = wp_strip_all_tags($m[1]);
            foreach ($matome_keywords as $kw) {
                if (mb_strpos($text, $kw) !== false) {
                    $inserted = true;
                    return $marker . "\n" . $m[0];
                }
            }
            return $m[0];
        }, $html);
    }
}
