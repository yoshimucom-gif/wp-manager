<?php
/**
 * REST から挿入を動かす口 (v0.21.8)
 *
 * POST /wp-json/affiros-ai/v1/apply   {"post_id":123}
 *   一括挿入ページの「適用」ボタン (wp_ajax_affiros_ai_apply) と同じ処理を、
 *   アプリケーションパスワードの REST 認証で呼べるようにしたもの。
 *   管理画面の AJAX はクッキーと nonce が要り、外から動かせなかった。
 *   除外 (カテゴリー・タグ・「このタグの記事だけに出す」・ランキング) の判定は
 *   process() の中でそのまま効く。対象外の記事は skipped を返し、本文に触らない。
 *
 * GET /wp-json/affiros-ai/v1/state?post_id=123
 *   挿入の状態だけを返す（本文に触らない）。対象になるか・キーワード・最終挿入日時・本文のカード枚数。
 */

if (!defined('ABSPATH')) exit;

add_action('rest_api_init', function () {
    $perm = function () { return current_user_can('manage_options'); };

    register_rest_route('affiros-ai/v1', '/apply', [
        'methods'             => 'POST',
        'permission_callback' => $perm,
        'callback'            => function (WP_REST_Request $req) {
            $post_id = intval($req->get_param('post_id'));
            if (!$post_id || get_post_type($post_id) !== 'post') {
                return new WP_Error('affiros_ai_bad_post', 'post_id が投稿ではありません。', ['status' => 400]);
            }
            @set_time_limit(120);
            // 管理画面の「適用」と同じ（キーワードと商品を取り直す）
            $res = Affiros_AI_Inserter::process($post_id, [
                'force_refresh_keyword'  => true,
                'force_refresh_products' => true,
            ]);
            return rest_ensure_response($res);
        },
    ]);

    register_rest_route('affiros-ai/v1', '/state', [
        'methods'             => 'GET',
        'permission_callback' => $perm,
        'callback'            => function (WP_REST_Request $req) {
            $post_id = intval($req->get_param('post_id'));
            $post = $post_id ? get_post($post_id) : null;
            if (!$post || $post->post_type !== 'post') {
                return new WP_Error('affiros_ai_bad_post', 'post_id が投稿ではありません。', ['status' => 400]);
            }
            $settings = affiros_ai_get_settings();
            $content = (string) $post->post_content;
            return [
                'post_id'      => $post_id,
                'slug'         => $post->post_name,
                'target'       => !affiros_ai_taxonomy_excluded($post_id, $settings)
                                  && !Affiros_AI_Ranking_Detector::is_ranking($post)
                                  && get_post_meta($post_id, AFFIROS_AI_META_EXCLUDED, true) !== 'yes',
                'tag_allowed'  => affiros_ai_tag_allowed($post_id, $settings),
                'keyword'      => get_post_meta($post_id, AFFIROS_AI_META_KEYWORD, true),
                'last_insert'  => get_post_meta($post_id, AFFIROS_AI_META_LAST_INSERT_AT, true),
                'cards'        => substr_count($content, 'affiros-ai-card-start')
                                  + substr_count($content, '[affiros_ai_card'),
                'has_products' => !empty(get_post_meta($post_id, AFFIROS_AI_META_PRODUCTS, true)),
            ];
        },
    ]);
});
