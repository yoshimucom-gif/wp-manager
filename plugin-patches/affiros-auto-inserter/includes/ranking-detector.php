<?php
/**
 * ランキング記事の判定 (自動挿入対象外)
 *
 * タイトルパターンで判定。「〜おすすめN選」「ランキング」等が入ってると
 * ランキング型記事と判定して自動挿入をスキップする。
 *
 * ランキング記事は本文に「第1位: {商品名}」等が既に埋め込まれているので、
 * さらに3商品カードを挿入すると重複して見苦しくなる。既存の
 * affiros-product-inserter (マーカー方式) 側に任せる。
 */

if (!defined('ABSPATH')) exit;

if (!class_exists('Affiros_AI_Ranking_Detector')) :

class Affiros_AI_Ranking_Detector {

    /**
     * ランキング記事か判定
     * @param WP_Post|int $post
     * @return bool
     */
    public static function is_ranking($post) {
        $post = is_numeric($post) ? get_post($post) : $post;
        if (!$post) return false;

        // 手動除外フラグが最優先
        $excluded = get_post_meta($post->ID, AFFIROS_AI_META_EXCLUDED, true);
        if ($excluded === 'yes') return true;

        $settings = affiros_ai_get_settings();
        if (($settings['skip_ranking_articles'] ?? 'yes') !== 'yes') return false;

        $title = (string)$post->post_title;
        $patterns_raw = $settings['ranking_title_patterns'] ?? '';
        $patterns = array_filter(array_map('trim', preg_split('/\r?\n/', $patterns_raw)));
        if (empty($patterns)) return false;

        foreach ($patterns as $pat) {
            // 正規表現として解釈。無効ならリテラル文字列マッチにフォールバック
            $regex = '/' . str_replace('/', '\/', $pat) . '/u';
            $suppressed = @preg_match($regex, $title);
            if ($suppressed === false) {
                // 無効パターン → リテラル
                if (mb_stripos($title, $pat) !== false) return true;
            } elseif ($suppressed) {
                return true;
            }
        }
        return false;
    }
}

endif;
