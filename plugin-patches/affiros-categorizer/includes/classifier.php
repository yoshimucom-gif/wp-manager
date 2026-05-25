<?php
/**
 * カテゴリー分類エンジン
 *
 * サイトの「実カテゴリー」を get_terms() で動的に取得し、その一覧を
 * プロンプトに渡して Claude に判定させる。ハードコードした分類表は持たない
 * ため、どのサイト・どのジャンルでもそのまま動作する。
 */

if (!defined('ABSPATH')) {
    exit;
}

class Affiros_Cat_Classifier {

    /** 本文をプロンプトに載せる最大文字数 */
    const CONTENT_LIMIT = 2500;

    /**
     * 判定対象のカテゴリー term を取得する。
     * 既定カテゴリー（通常「未分類」）は分類先として無意味なので除外する。
     *
     * @return WP_Term[]
     */
    public static function get_target_terms() {
        $terms = get_terms([
            'taxonomy'   => 'category',
            'hide_empty' => false,
        ]);
        if (is_wp_error($terms) || empty($terms)) {
            return [];
        }
        $default = (int) get_option('default_category');
        $out = [];
        foreach ($terms as $t) {
            if ((int) $t->term_id === $default) {
                continue;
            }
            $out[] = $t;
        }
        return $out;
    }

    /**
     * カテゴリーツリーを「[ID] 名前 — 説明」のインデント付きテキストに整形する。
     */
    public static function build_category_list($terms) {
        $valid = [];
        foreach ($terms as $t) {
            $valid[(int) $t->term_id] = true;
        }
        $by_parent = [];
        foreach ($terms as $t) {
            // 親が候補一覧に無い場合（親が既定カテゴリー等）はトップレベル扱い
            $parent = isset($valid[(int) $t->parent]) ? (int) $t->parent : 0;
            $by_parent[$parent][] = $t;
        }
        return self::render_terms($by_parent, 0, 0);
    }

    private static function render_terms($by_parent, $parent_id, $depth) {
        if (empty($by_parent[$parent_id])) {
            return '';
        }
        $out = '';
        $indent = str_repeat('  ', $depth);
        foreach ($by_parent[$parent_id] as $t) {
            $line = $indent . '[' . (int) $t->term_id . '] ' . $t->name;
            $desc = trim($t->description);
            if ($desc !== '') {
                $line .= ' — ' . $desc;
            }
            $out .= $line . "\n";
            $out .= self::render_terms($by_parent, (int) $t->term_id, $depth + 1);
        }
        return $out;
    }

    /**
     * 分類用プロンプトを生成する。
     */
    public static function build_prompt($title, $content, $terms) {
        $list = self::build_category_list($terms);
        $settings = affiros_cat_get_settings();
        $ctx = trim((string) ($settings['site_context'] ?? ''));
        $ctx_block = $ctx !== '' ? "\n【このサイトについて】\n{$ctx}\n" : '';

        $prompt = <<<PROMPT
あなたは WordPress サイトの記事を、適切なカテゴリーに分類する編集者です。
{$ctx_block}
以下の記事を読み、下記カテゴリー一覧の中から最も適切なものを1つだけ選んでください。

【記事タイトル】
{$title}

【記事本文（抜粋）】
{$content}

---

【分類ルール】
1. 下記カテゴリー一覧の中から、最も適切なものを必ず1つだけ選ぶ
2. 各行は「[ID] カテゴリー名 — 説明」の形式。説明文も判断材料にする
3. インデントは親子関係を表す。できるだけ具体的な（インデントが深い）子カテゴリーを優先する
4. 一覧に存在する数値IDのみを使う。リストにないIDは絶対に使わない

【カテゴリー一覧】
{$list}
---

以下の JSON 形式のみで回答してください。説明文や前置きは不要です。
{"categoryId": 数値, "reason": "選んだ理由を1文で"}
PROMPT;

        return $prompt;
    }

    /**
     * 1記事を分類してカテゴリーを設定する。
     *
     * @param int  $post_id
     * @param bool $force  true なら上書きモード設定を無視して必ず分類する
     *                     （手動実行・一括分類で使用）
     * @return array ['success'=>bool, 'skipped'=>bool, 'category'=>string,
     *                'category_id'=>int, 'reason'=>string, 'error'=>string]
     */
    public static function classify($post_id, $force = false) {
        $post = get_post($post_id);
        if (!$post || $post->post_type !== 'post') {
            return self::error('記事が見つかりません');
        }

        $settings = affiros_cat_get_settings();

        // 上書きモード "empty": すでにカテゴリーが付いている記事は自動分類しない。
        // 手動・一括（$force）のときは常に分類する。
        if (!$force && ($settings['overwrite'] ?? 'empty') === 'empty' && self::has_real_category($post_id)) {
            update_post_meta($post_id, '_affiros_cat_log', current_time('Y-m-d H:i') . ' / スキップ（既存カテゴリーを維持）');
            return [
                'success' => true, 'skipped' => true, 'category' => '既存カテゴリーを維持',
                'category_id' => 0, 'reason' => '', 'error' => '',
            ];
        }

        $terms = self::get_target_terms();
        if (empty($terms)) {
            return self::error('判定対象のカテゴリーがありません。先にカテゴリーを作成してください。');
        }

        $title   = $post->post_title;
        $content = mb_substr(wp_strip_all_tags($post->post_content), 0, self::CONTENT_LIMIT);
        $prompt  = self::build_prompt($title, $content, $terms);

        $api = new Affiros_Cat_Claude_API();
        if (!$api->is_configured()) {
            return self::error('Claude API キーが未設定です。');
        }

        $res = $api->complete($prompt, 400);
        if (is_wp_error($res)) {
            return self::error($res->get_error_message());
        }

        $text = $res['text'] ?? '';
        if (!preg_match('/\{[\s\S]*\}/', $text, $m)) {
            return self::error('AI 応答の解析に失敗しました（JSON が見つかりません）。');
        }
        $data = json_decode($m[0], true);
        if (!is_array($data)) {
            return self::error('AI 応答の JSON デコードに失敗しました。');
        }

        $cat_id = isset($data['categoryId']) ? intval($data['categoryId']) : 0;
        $valid_ids = [];
        foreach ($terms as $t) {
            $valid_ids[] = (int) $t->term_id;
        }
        if (!$cat_id || !in_array($cat_id, $valid_ids, true)) {
            return self::error('AI が有効なカテゴリーを返しませんでした。');
        }

        wp_set_post_categories($post_id, [$cat_id], false);

        $term   = get_term($cat_id, 'category');
        $name   = ($term && !is_wp_error($term)) ? $term->name : (string) $cat_id;
        $reason = isset($data['reason']) ? sanitize_text_field($data['reason']) : '';

        $log = current_time('Y-m-d H:i') . ' / ' . $name;
        if ($reason !== '') {
            $log .= "\n理由: " . $reason;
        }
        update_post_meta($post_id, '_affiros_cat_log', $log);

        return [
            'success' => true, 'skipped' => false, 'category' => $name,
            'category_id' => $cat_id, 'reason' => $reason, 'error' => '',
        ];
    }

    /**
     * 既定カテゴリー以外のカテゴリーが付いているか。
     */
    private static function has_real_category($post_id) {
        $default = (int) get_option('default_category');
        $cats = wp_get_post_categories($post_id);
        foreach ($cats as $c) {
            if ((int) $c !== $default) {
                return true;
            }
        }
        return false;
    }

    private static function error($message) {
        return [
            'success' => false, 'skipped' => false, 'category' => '',
            'category_id' => 0, 'reason' => '', 'error' => $message,
        ];
    }
}
