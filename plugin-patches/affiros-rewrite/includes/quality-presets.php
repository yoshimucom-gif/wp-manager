<?php
/**
 * 品質プリセット管理
 *
 * 保存形式: WP option 'affiros_rewrite_presets' に配列で格納
 * 1件のスキーマ:
 *   {
 *     id: string (uniq),
 *     name: string,
 *     article_type: 'ranking' | 'brand' | 'column' | '',
 *     prompt: string (リライト時の追加指示),
 *     target_chars: int (0=元記事に合わせる),
 *     tone: 'natural'|'professional'|'casual',
 *     reference_url: string (参考URL、任意)
 *   }
 */

if (!defined('ABSPATH')) exit;

define('AFFIROS_REWRITE_PRESETS_KEY', 'affiros_rewrite_presets');

class Affiros_Rewrite_Quality_Presets {

    /** @return array */
    public static function all() {
        $raw = get_option(AFFIROS_REWRITE_PRESETS_KEY, []);
        return is_array($raw) ? array_values(array_filter($raw, 'is_array')) : [];
    }

    public static function find($id) {
        foreach (self::all() as $p) {
            if (($p['id'] ?? '') === $id) return $p;
        }
        return null;
    }

    public static function save_all($presets) {
        $clean = [];
        foreach ((array)$presets as $p) {
            $clean[] = self::sanitize_one($p);
        }
        update_option(AFFIROS_REWRITE_PRESETS_KEY, $clean);
    }

    public static function upsert($preset) {
        $list = self::all();
        $id = $preset['id'] ?? '';
        if (!$id) {
            $id = 'preset_' . wp_generate_uuid4();
            $preset['id'] = $id;
        }
        $replaced = false;
        foreach ($list as $i => $p) {
            if (($p['id'] ?? '') === $id) {
                $list[$i] = self::sanitize_one($preset);
                $replaced = true;
                break;
            }
        }
        if (!$replaced) {
            $list[] = self::sanitize_one($preset);
        }
        self::save_all($list);
        return $id;
    }

    public static function delete($id) {
        $list = array_values(array_filter(self::all(), function ($p) use ($id) {
            return ($p['id'] ?? '') !== $id;
        }));
        self::save_all($list);
    }

    /**
     * JSON （Affiros export 形式と互換）から一括 import
     * 既存IDがあれば置換、なければ追加
     */
    public static function import_json($json_text) {
        $data = json_decode($json_text, true);
        if (!is_array($data)) {
            return new WP_Error('invalid_json', 'JSONの解析に失敗しました');
        }
        // Affiros 形式は配列でも { "presets": [...] } でも来うる
        $items = isset($data['presets']) && is_array($data['presets']) ? $data['presets'] : $data;
        if (!is_array($items)) {
            return new WP_Error('invalid_json', 'プリセット配列が見つかりません');
        }
        $count = 0;
        foreach ($items as $raw) {
            if (!is_array($raw)) continue;
            // Affiros の reference_url / article_type / target_chars / tone / prompt をそのまま受ける
            $mapped = [
                'id' => (string)($raw['id'] ?? ''),
                'name' => (string)($raw['name'] ?? '無題'),
                'article_type' => self::normalize_article_type($raw['article_type'] ?? ''),
                'prompt' => (string)($raw['prompt'] ?? ($raw['custom_prompt'] ?? '')),
                'target_chars' => intval($raw['target_chars'] ?? 0),
                'tone' => self::normalize_tone($raw['tone'] ?? 'natural'),
                'reference_url' => (string)($raw['reference_url'] ?? ''),
            ];
            self::upsert($mapped);
            $count++;
        }
        return ['imported' => $count];
    }

    private static function sanitize_one($p) {
        return [
            'id' => sanitize_text_field((string)($p['id'] ?? ('preset_' . wp_generate_uuid4()))),
            'name' => sanitize_text_field((string)($p['name'] ?? '無題')),
            'article_type' => self::normalize_article_type($p['article_type'] ?? ''),
            'prompt' => wp_kses_post((string)($p['prompt'] ?? '')),
            'target_chars' => max(0, intval($p['target_chars'] ?? 0)),
            'tone' => self::normalize_tone($p['tone'] ?? 'natural'),
            'reference_url' => esc_url_raw((string)($p['reference_url'] ?? '')),
        ];
    }

    private static function normalize_article_type($v) {
        $v = (string)$v;
        // Affiros 旧フォーマットの「ですます調」「ランキング記事」等のラベルを変換
        $map = [
            'ranking' => 'ranking', 'ランキング' => 'ranking', 'ランキング記事' => 'ranking',
            'brand' => 'brand', '商標' => 'brand', '商標記事' => 'brand', 'review' => 'brand',
            'column' => 'column', 'コラム' => 'column', 'コラム記事' => 'column',
        ];
        return $map[$v] ?? '';
    }

    private static function normalize_tone($v) {
        $v = (string)$v;
        // Affiros の日本語ラベルも吸収
        $map = [
            'natural' => 'natural', '自然' => 'natural', 'ですます調' => 'natural',
            'professional' => 'professional', '専門家風の丁寧な文体' => 'professional', 'である調' => 'professional',
            'casual' => 'casual', '親しみやすいですます調' => 'casual',
        ];
        return $map[$v] ?? 'natural';
    }
}
