<?php
/**
 * 記事タイプの判定。
 *
 * 本体 app.py の normalize_article_type / infer_title_article_type /
 * article_type_label を忠実に PHP 移植したもの。
 */

if (!defined('ABSPATH')) exit;

class Affiros_Rewrite_Article_Type {

    /** 有効な記事タイプ */
    const VALID = ['ranking', 'brand', 'column'];

    /**
     * 文字列を ranking / brand / column へ正規化する。
     * 本体 normalize_article_type の移植。
     */
    public static function normalize($value, $default = 'ranking') {
        $raw = mb_strtolower(trim((string)$value), 'UTF-8');
        $map = [
            'ranking'      => 'ranking',
            'rank'         => 'ranking',
            'ランキング'    => 'ranking',
            'ランキング記事' => 'ranking',
            'おすすめ'      => 'ranking',
            '比較'         => 'ranking',
            'brand'        => 'brand',
            'review'       => 'brand',
            '商標'         => 'brand',
            '商標記事'      => 'brand',
            'レビュー'      => 'brand',
            'レビュー記事'   => 'brand',
            'column'       => 'column',
            'コラム'        => 'column',
            'コラム記事'     => 'column',
        ];
        return $map[$raw] ?? $default;
    }

    /**
     * キーワード・タイトルから記事タイプを推定する。
     * 本体 infer_title_article_type の移植。
     *
     * @return string 'ranking' | 'brand' | 'column'
     */
    public static function infer($keyword = '', $title = '') {
        $text = trim((string)$keyword . ' ' . (string)$title);

        if (preg_match('/(?:口コミ|評判|レビュー|メリット|デメリット)/u', $text)) {
            $has_specific_name = (bool) preg_match(
                '/[A-Za-z][A-Za-z0-9-]{2,}|[A-Z]{2,}\s*-?\s*\d+|[A-Za-z]+\s*\d+/u',
                $text
            );
            if ($has_specific_name && !preg_match('/(?:おすすめ|比較|ランキング|選び方|人気|厳選)/u', $text)) {
                return 'brand';
            }
        }
        // 「○つのチェックポイント/ポイント/サイン/ステップ」等は column 寄り
        // （ranking 判定の前にチェック）
        if (preg_match('/[0-9０-９]+\s*つ\s*の?\s*(?:チェック|ポイント|サイン|ステップ|理由|秘訣|コツ|心得|注意点|特徴|失敗|落とし穴|教訓|タイミング|目安|基準)/u', $text)) {
            return 'column';
        }
        if (preg_match('/(?:とは|選び方|使い方|洗い方|原因|対策|方法|違い|必要|いつ|なぜ|ポイント)/u', $text)) {
            return 'column';
        }
        // 「○選」は ranking 強シグナル
        if (preg_match('/[0-90-9０-9]+\s*選/u', $text)) {
            return 'ranking';
        }
        if (preg_match('/(?:おすすめ|比較|ランキング|人気|厳選|ベスト)/u', $text)) {
            return 'ranking';
        }
        return 'ranking';
    }

    /**
     * タイトルに「ランキング系の強シグナル」が含まれるかを判定する。
     * これが true の時のみ ①②③ 見出しをランキング項目として扱う。
     */
    public static function has_ranking_signal($title) {
        $t = (string)$title;
        return (bool) preg_match(
            '/[0-9０-９]+\s*選|ランキング|おすすめ\s*[0-9０-９]+|ベスト\s*[0-9０-９]+|TOP\s*[0-9０-９]+/iu',
            $t
        );
    }

    /**
     * 記事タイプの日本語ラベル。本体 article_type_label の移植。
     */
    public static function label($article_type) {
        $map = [
            'ranking' => 'ランキング記事',
            'brand'   => '商標記事',
            'column'  => 'コラム記事',
        ];
        return $map[$article_type] ?? 'ランキング記事';
    }
}
