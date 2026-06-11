<?php
/**
 * Gutenberg ブロック変換
 *
 * Claude が返した plain HTML を WordPress Gutenberg ブロック形式に変換する。
 * 変換しないと WP は記事を「Classic ブロック」1個として扱ってしまい、
 * 編集画面で個別の見出し・段落を編集できなくなる。
 *
 * 変換対象:
 *   <h2>...</h2>          → <!-- wp:heading --><h2>...</h2><!-- /wp:heading -->
 *   <h3>...</h3>          → <!-- wp:heading {"level":3} --><h3>...</h3><!-- /wp:heading -->
 *   <p>...</p>            → <!-- wp:paragraph --><p>...</p><!-- /wp:paragraph -->
 *   <ul>...</ul>          → <!-- wp:list --><ul>...</ul><!-- /wp:list -->
 *   <ol>...</ol>          → <!-- wp:list {"ordered":true} --><ol>...</ol><!-- /wp:list -->
 *   <table>...</table>    → <!-- wp:table --><figure><table>...</table></figure><!-- /wp:table -->
 *   <blockquote>...</blockquote> → <!-- wp:quote -->...<!-- /wp:quote -->
 *
 * HTML コメント (<!--ai-product:vertical--> など) は変換せず、そのままの位置に保持する。
 */

if (!defined('ABSPATH')) exit;

class Affiros_Rewrite_Gutenberg {

    /** @var string[] トップレベルとして扱うブロックタグ */
    private static $BLOCK_TAGS = ['h1','h2','h3','h4','h5','h6','p','ul','ol','table','blockquote','figure','pre'];

    /**
     * HTML を Gutenberg ブロック形式に変換する。
     *
     * 既に wp:xxx マーカーを含む場合は何もせず返す（二重変換防止）。
     *
     * @param string $html
     * @return string
     */
    public static function convert($html) {
        if ($html === null || $html === '') return (string)$html;
        $html = (string)$html;

        // 既にブロック化されてればそのまま
        if (strpos($html, '<!-- wp:') !== false) {
            return $html;
        }

        // トップレベル要素を抜き出すパターン
        // 1. ブロックタグの開始タグ〜閉じタグ（同名ネストには対応しないがコンテンツ生成では通常問題ない）
        // 2. HTML コメント（<!--ai-product:...--> など）
        // 3. <hr> / <hr/>（自己閉じタグ）
        $tags = implode('|', self::$BLOCK_TAGS);
        $pattern = '/(<(' . $tags . ')\b[^>]*>[\s\S]*?<\/\2>|<!--[\s\S]*?-->|<hr\b[^>]*\/?>)/i';

        $result = [];
        $last_end = 0;

        if (preg_match_all($pattern, $html, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $match) {
                $element = $match[0];
                $start   = $match[1];
                $end     = $start + strlen($element);

                // 前回のマッチ末尾〜今回開始の間にある「裸テキスト」を回収して段落化
                if ($start > $last_end) {
                    $between = substr($html, $last_end, $start - $last_end);
                    $piece = trim($between);
                    if ($piece !== '') {
                        $result[] = self::wrap_paragraph($piece);
                    }
                }

                $result[] = self::wrap_block($element);
                $last_end = $end;
            }
        }

        // 末尾の残り
        if ($last_end < strlen($html)) {
            $tail = trim(substr($html, $last_end));
            if ($tail !== '') {
                $result[] = self::wrap_paragraph($tail);
            }
        }

        // 何もマッチしなかった = 全文プレーンテキスト
        if (empty($result)) {
            $piece = trim($html);
            return $piece === '' ? '' : self::wrap_paragraph($piece);
        }

        return implode("\n\n", $result);
    }

    /** タグ単位のブロック種別を判定して包む */
    private static function wrap_block($element) {
        // HTML コメントはそのまま保持（マーカー等）
        if (substr($element, 0, 4) === '<!--') {
            return $element;
        }

        // タグ名抽出
        if (!preg_match('/^<([a-z][a-z0-9]*)/i', $element, $m)) {
            return $element;
        }
        $tag = strtolower($m[1]);

        switch ($tag) {
            case 'h1':
                return "<!-- wp:heading {\"level\":1} -->\n{$element}\n<!-- /wp:heading -->";
            case 'h2':
                return "<!-- wp:heading -->\n{$element}\n<!-- /wp:heading -->";
            case 'h3':
                return "<!-- wp:heading {\"level\":3} -->\n{$element}\n<!-- /wp:heading -->";
            case 'h4':
                return "<!-- wp:heading {\"level\":4} -->\n{$element}\n<!-- /wp:heading -->";
            case 'h5':
                return "<!-- wp:heading {\"level\":5} -->\n{$element}\n<!-- /wp:heading -->";
            case 'h6':
                return "<!-- wp:heading {\"level\":6} -->\n{$element}\n<!-- /wp:heading -->";
            case 'p':
                return "<!-- wp:paragraph -->\n{$element}\n<!-- /wp:paragraph -->";
            case 'ul':
                return "<!-- wp:list -->\n{$element}\n<!-- /wp:list -->";
            case 'ol':
                return "<!-- wp:list {\"ordered\":true} -->\n{$element}\n<!-- /wp:list -->";
            case 'table':
                // WP の table block は HTML スキーマが厳格。
                // - <figure class="wp-block-table"> でラップ必須
                // - <tr> は <thead> または <tbody> の直下である必要がある
                // - table / tr / td / th の inline style / class / border 等は許容されない
                // これを満たさないと「想定されていないコンテンツ」エラーになり、
                // 「復旧を試みる」を押すとブロックごと消えて連続H2状態になる。
                $normalized = self::normalize_table_for_gutenberg($element);
                return "<!-- wp:table -->\n<figure class=\"wp-block-table\">{$normalized}</figure>\n<!-- /wp:table -->";
            case 'figure':
                return "<!-- wp:image -->\n{$element}\n<!-- /wp:image -->";
            case 'blockquote':
                return "<!-- wp:quote -->\n{$element}\n<!-- /wp:quote -->";
            case 'pre':
                return "<!-- wp:preformatted -->\n{$element}\n<!-- /wp:preformatted -->";
            case 'hr':
                return "<!-- wp:separator -->\n{$element}\n<!-- /wp:separator -->";
            default:
                return $element;
        }
    }

    /** タグ無し裸テキストを wp:paragraph で包む */
    private static function wrap_paragraph($text) {
        // タグを含む場合はそのまま、含まなければ <p> で囲む
        if (preg_match('/^<[a-z]/i', $text)) {
            return $text;
        }
        return "<!-- wp:paragraph -->\n<p>" . $text . "</p>\n<!-- /wp:paragraph -->";
    }

    /**
     * Claude が返す <table>...</table> を Gutenberg の table block が
     * 受け付ける形に正規化する。
     *
     * Gutenberg が許容する構造:
     *   <table class="">
     *     <thead><tr><th>..</th></tr></thead>   ← 任意
     *     <tbody><tr><td>..</td></tr></tbody>   ← 必須
     *   </table>
     *
     * よくある不適合（実装で吸収する）:
     *   - <table style="..."> や class つき → 属性を全部剥がす
     *   - <tr> が <thead>/<tbody> の外に直書き → <tbody> でラップ
     *   - <td style="..."> / <th colspan="2"> → style/class/border等は剥がす
     *     ただし colspan/rowspan/scope は Gutenberg が許容するので保持
     *   - colgroup / caption → そのまま保持（許容される）
     */
    private static function normalize_table_for_gutenberg($html) {
        if (!$html) return '';
        // 1) <table ...> → <table class="">
        $html = preg_replace('/<table\b[^>]*>/i', '<table class="">', $html);
        // 2) <thead ...> / <tbody ...> / <tfoot ...> / <tr ...> → 属性剥がし
        $html = preg_replace('/<(thead|tbody|tfoot|tr)\b[^>]*>/i', '<$1>', $html);
        // 3) <td ...> / <th ...> は colspan/rowspan/scope だけ残す
        $html = preg_replace_callback(
            '/<(td|th)\b([^>]*)>/i',
            function ($m) {
                $tag = strtolower($m[1]);
                $attrs = $m[2];
                $keep = [];
                if (preg_match('/\bcolspan\s*=\s*"(\d+)"/i', $attrs, $cm)) {
                    $keep[] = 'colspan="' . intval($cm[1]) . '"';
                }
                if (preg_match('/\browspan\s*=\s*"(\d+)"/i', $attrs, $rm)) {
                    $keep[] = 'rowspan="' . intval($rm[1]) . '"';
                }
                if (preg_match('/\bscope\s*=\s*"(row|col|rowgroup|colgroup)"/i', $attrs, $sm)) {
                    $keep[] = 'scope="' . $sm[1] . '"';
                }
                return '<' . $tag . ($keep ? ' ' . implode(' ', $keep) : '') . '>';
            },
            $html
        );

        // 4) <thead>/<tbody>/<tfoot> の外側にある <tr> を <tbody> で囲む。
        //    Claude がたまに <table><tr>...</tr></table> を出すケースを救済する。
        if (!preg_match('/<(?:thead|tbody|tfoot)\b/i', $html)) {
            // どのグループにも入ってない → 全部の <tr> を <tbody> でくくる
            $html = preg_replace(
                '/<table\b([^>]*)>([\s\S]*?)<\/table>/i',
                '<table$1><tbody>$2</tbody></table>',
                $html,
                1
            );
        } else {
            // thead だけあって tbody が無い場合などに、thead 後の生 tr を tbody でくくる
            $html = preg_replace_callback(
                '/<\/thead>([\s\S]*?)<\/table>/i',
                function ($m) {
                    $rest = $m[1];
                    // 既に tbody/tfoot が入ってれば触らない
                    if (preg_match('/<(?:tbody|tfoot)\b/i', $rest)) {
                        return '</thead>' . $rest . '</table>';
                    }
                    // 生 tr が残ってれば tbody でくくる
                    if (preg_match('/<tr\b/i', $rest)) {
                        return '</thead><tbody>' . trim($rest) . '</tbody></table>';
                    }
                    return '</thead>' . $rest . '</table>';
                },
                $html
            );
        }

        return $html;
    }
}
