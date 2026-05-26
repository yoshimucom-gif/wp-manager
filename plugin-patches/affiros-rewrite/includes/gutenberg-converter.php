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
                // WP標準は figure ラップ。既に figure 内にあれば二重ラップしない
                if (preg_match('/^<table/i', $element)) {
                    return "<!-- wp:table -->\n<figure class=\"wp-block-table\">{$element}</figure>\n<!-- /wp:table -->";
                }
                return "<!-- wp:table -->\n{$element}\n<!-- /wp:table -->";
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
}
