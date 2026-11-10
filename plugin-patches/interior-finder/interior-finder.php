<?php
/**
 * Plugin Name: インテリアグッズLab 条件で探す
 * Description: 品目ごとの商品データ（Amazon公式APIで取得した仕様）を同梱し、ショートコード [interior_finder set="chest"] で「幅・奥行・高さ・段数・素材・色・置き場所・入れるもの」の掛け合わせ絞り込みページを出します。商品は全点サーバー側で描き、絞り込みは表示の出し入れだけで行うため、検索エンジンには全商品が見えます。価格は扱いません。
 * Version:     1.1.0
 * Author:      Keys
 * License:     GPLv2 or later
 * Text Domain: interior-finder
 */

if (!defined('ABSPATH')) {
    exit;
}

/* 自動更新（GitHub直配信）。機能より先に入れる決まり */
require_once __DIR__ . '/includes/plugin-updater.php';
add_action('init', function () {
    new Interior_Finder_Plugin_Updater(
        __FILE__,
        'https://raw.githubusercontent.com/yoshimucom-gif/wp-manager/main/plugin-host/api/plugin-update/interior-finder'
    );
});

class Interior_Finder
{
    const VERSION = '1.1.0';

    /* ショートコードが使われたページでだけCSS/JSを読む */
    private static $used = false;

    public static function boot()
    {
        add_shortcode('interior_finder', array(__CLASS__, 'shortcode'));
        add_action('wp_enqueue_scripts', array(__CLASS__, 'assets'), 20);
    }

    public static function assets()
    {
        if (!self::$used) {
            return;
        }
        $base = plugin_dir_url(__FILE__);
        wp_enqueue_style('interior-finder', $base . 'assets/finder.css', array(), self::VERSION);
        wp_enqueue_script('interior-finder', $base . 'assets/finder.js', array(), self::VERSION, true);
    }

    /* 同梱データを読む。品目を増やすときは data/<slug>.json を足すだけ */
    private static function load($set)
    {
        if (!preg_match('/\A[a-z0-9\-]+\z/', $set)) {
            return null;
        }
        $path = __DIR__ . '/data/' . $set . '.json';
        if (!file_exists($path)) {
            return null;
        }
        $d = json_decode(file_get_contents($path), true);
        return is_array($d) && !empty($d['items']) ? $d : null;
    }

    public static function shortcode($atts)
    {
        $a = shortcode_atts(array(
            'set'   => '',
            'title' => '',          /* 絞り込みの見出し。空なら出さない（テーマの見出しに任せる） */
            'cols'  => '3',
            /* ここから初期条件。記事の中に「その記事の話に絞った状態」で埋めるため。
               例) [interior_finder set="chest" fit_d="30" place="洗面所"]
               読者がURLを共有したときは、そのURLの条件が初期条件より優先される。 */
            'fit_w'    => '',       /* 幅が何cm以下に収まるか（数字） */
            'fit_d'    => '',
            'fit_h'    => '',
            'w'        => '',       /* 寸法の区分。"0-35" のように下限-上限。複数はカンマ区切り */
            'd'        => '',
            'h'        => '',
            'step'     => '',       /* 段数。"3,4" */
            'material' => '',       /* 素材。"木,ラタン" */
            'color'    => '',
            'place'    => '',       /* 置き場所。"洗面所" */
            'use'      => '',       /* 入れるもの。"衣類" */
            'flag'     => '',       /* "caster" / "door" */
        ), $atts, 'interior_finder');

        $d = self::load($a['set']);
        if (!$d) {
            return current_user_can('edit_posts')
                ? '<p>[interior_finder] データが見つかりません（set="' . esc_html($a['set']) . '"）。</p>'
                : '';
        }
        self::$used = true;
        /* enqueue が走り終わったあとに呼ばれるページでも読ませる */
        if (did_action('wp_enqueue_scripts')) {
            self::assets();
        }

        $items = $d['items'];
        /* 寸法が取れている商品を先に出す。絞り込みの主役が寸法なので */
        usort($items, function ($x, $y) {
            $a1 = empty($x['w']) ? 1 : 0;
            $b1 = empty($y['w']) ? 1 : 0;
            if ($a1 !== $b1) {
                return $a1 - $b1;
            }
            return strcmp((string) $x['b'], (string) $y['b']);
        });
        $nodim = 0;
        foreach ($items as $it) {
            if (empty($it['w'])) {
                $nodim++;
            }
        }

        /* 1ページに2つ置かれてもidが衝突しないよう連番を足す */
        static $seq = 0;
        $seq++;
        $id    = 'ifd-' . sanitize_html_class($d['slug']) . ($seq > 1 ? '-' . $seq : '');
        $label = $d['label'];
        $cols  = max(2, min(4, (int) $a['cols']));
        $init  = self::init_query($a, $d);

        ob_start();
        ?>
<div class="ifd" id="<?php echo esc_attr($id); ?>"<?php if ($init) : ?> data-init="<?php echo esc_attr(wp_json_encode($init)); ?>"<?php endif; ?>>

	<?php if ($a['title'] !== '') : ?>
	<p class="ifd-h"><?php echo esc_html($a['title']); ?></p>
	<?php endif; ?>

	<div class="ifd-panel">

		<div class="ifd-fit">
			<p class="ifd-fit-h">置きたい場所の寸法で探す</p>
			<p class="ifd-fit-d">すき間や棚下の実寸を入れると、そこに収まる<?php echo esc_html($label); ?>だけが残ります。ひとつだけ入れても使えます。</p>
			<div class="ifd-fit-r">
				<?php foreach ($d['sizes'] as $s) : ?>
				<label class="ifd-fit-i"><span><?php echo esc_html($s['label']); ?></span><input type="number"
						inputmode="decimal" min="1" max="400" step="0.1"
						data-fit="<?php echo esc_attr($s['key']); ?>"
						placeholder="<?php echo esc_attr('例 ' . (int) $s['max']); ?>"><span class="ifd-u">cm以下</span></label>
				<?php endforeach; ?>
			</div>
		</div>

		<?php foreach ($d['sizes'] as $s) : ?>
		<div class="ifd-g">
			<p class="ifd-g-h"><?php echo esc_html($s['label']); ?></p>
			<div class="ifd-g-b">
				<?php foreach ($s['buckets'] as $b) : ?>
				<button type="button" class="ifd-b" data-key="<?php echo esc_attr($s['key']); ?>"
					data-lo="<?php echo esc_attr((string) $b[0]); ?>"
					data-hi="<?php echo esc_attr((string) $b[1]); ?>"><?php echo esc_html($b[2]); ?></button>
				<?php endforeach; ?>
			</div>
		</div>
		<?php endforeach; ?>

		<?php foreach ($d['facets'] as $f) : ?>
		<div class="ifd-g">
			<p class="ifd-g-h"><?php echo esc_html($f['label']); ?></p>
			<div class="ifd-g-b">
				<?php foreach ($f['options'] as $o) : ?>
				<button type="button" class="ifd-b" data-key="<?php echo esc_attr($f['key']); ?>"
					data-v="<?php echo esc_attr($o['v']); ?>"><?php echo esc_html($o['l']); ?><span
						class="ifd-n"><?php echo (int) $o['n']; ?></span></button>
				<?php endforeach; ?>
			</div>
		</div>
		<?php endforeach; ?>

		<?php if (!empty($d['flags'])) : ?>
		<div class="ifd-g">
			<p class="ifd-g-h">そのほかの条件</p>
			<div class="ifd-g-b">
				<?php foreach ($d['flags'] as $f) : ?>
				<button type="button" class="ifd-b" data-key="f"
					data-v="<?php echo esc_attr($f['v']); ?>"><?php echo esc_html($f['l']); ?><span
						class="ifd-n"><?php echo (int) $f['n']; ?></span></button>
				<?php endforeach; ?>
			</div>
		</div>
		<?php endif; ?>

	</div>

	<?php if (!empty($d['presets'])) : ?>
	<div class="ifd-pre">
		<p class="ifd-pre-h">よく使われる条件</p>
		<div class="ifd-pre-b">
			<?php foreach ($d['presets'] as $p) : ?>
			<button type="button" class="ifd-p"
				data-q="<?php echo esc_attr(wp_json_encode($p['q'])); ?>"><?php echo esc_html($p['l']); ?><span
					class="ifd-n"><?php echo (int) $p['n']; ?></span></button>
			<?php endforeach; ?>
		</div>
	</div>
	<?php endif; ?>

	<div class="ifd-bar">
		<p class="ifd-res"><span class="ifd-count"><?php echo count($items); ?></span>点の<?php echo esc_html($label); ?>が条件に合います</p>
		<div class="ifd-chips"></div>
		<button type="button" class="ifd-reset" hidden>条件をすべて外す</button>
	</div>

	<div class="ifd-list" data-cols="<?php echo (int) $cols; ?>">
		<?php foreach ($items as $it) : ?>
		<article class="ifd-c"<?php if (!empty($it['w'])) : ?> data-w="<?php echo esc_attr((string) $it['w']); ?>" data-d="<?php echo esc_attr((string) $it['d']); ?>" data-h="<?php echo esc_attr((string) $it['h']); ?>"<?php endif; ?><?php if (!empty($it['s'])) : ?> data-s="<?php echo esc_attr((string) $it['s']); ?>"<?php endif; ?> data-m="<?php echo esc_attr(implode(',', (array) $it['m'])); ?>" data-c="<?php echo esc_attr(implode(',', (array) $it['c'])); ?>" data-p="<?php echo esc_attr(implode(',', (array) $it['p'])); ?>" data-g="<?php echo esc_attr(implode(',', (array) $it['g'])); ?>" data-f="<?php echo esc_attr(implode(',', (array) $it['f'])); ?>">
			<a class="ifd-c-i" href="<?php echo esc_url($it['u']); ?>" target="_blank" rel="nofollow sponsored noopener"><img
					src="<?php echo esc_url($it['i']); ?>" alt="<?php echo esc_attr($it['t']); ?>" loading="lazy"
					decoding="async" width="200" height="200"></a>
			<div class="ifd-c-t">
				<?php if ($it['b'] !== '') : ?><p class="ifd-c-b"><?php echo esc_html($it['b']); ?></p><?php endif; ?>
				<p class="ifd-c-n"><a href="<?php echo esc_url($it['u']); ?>" target="_blank"
						rel="nofollow sponsored noopener"><?php echo esc_html($it['t']); ?></a></p>
				<?php if (!empty($it['w'])) : ?>
				<p class="ifd-c-d">幅<?php echo esc_html(self::num($it['w'])); ?> × 奥行<?php echo esc_html(self::num($it['d'])); ?> × 高さ<?php echo esc_html(self::num($it['h'])); ?>cm</p>
				<?php else : ?>
				<p class="ifd-c-d ifd-c-d0">寸法はAmazonの商品ページでご確認ください</p>
				<?php endif; ?>
				<p class="ifd-c-tag"><?php
					$tags = array();
				if (!empty($it['s'])) {
					$tags[] = (int) $it['s'] . '段';
				}
				foreach (array('m', 'c') as $k) {
					foreach ((array) $it[$k] as $v) {
						$tags[] = $v;
					}
				}
				foreach ((array) $it['f'] as $v) {
					$tags[] = ($v === 'caster') ? 'キャスター付き' : '扉付き';
				}
				foreach (array_slice($tags, 0, 6) as $t) {
					echo '<span>' . esc_html($t) . '</span>';
				}
				?></p>
				<p class="ifd-c-a"><a href="<?php echo esc_url($it['u']); ?>" target="_blank"
						rel="nofollow sponsored noopener">Amazonで見る</a></p>
			</div>
		</article>
		<?php endforeach; ?>
	</div>

	<p class="ifd-none" hidden>条件に合う<?php echo esc_html($label); ?>がありません。条件をひとつ外してお試しください。</p>

	<p class="ifd-note">Amazonで販売中の<?php echo esc_html($label); ?><?php echo count($items); ?>点をもとにしています。<?php if ($nodim) : ?>うち<?php echo (int) $nodim; ?>点は寸法が公開されていないため、寸法で絞ると外れます。<?php endif; ?>仕様と在庫はAmazonの商品ページでご確認ください（<?php echo esc_html(self::ymd($d['updated'])); ?>時点の情報です）。</p>
</div>
		<?php
        return ob_get_clean();
    }

    /* ショートコードの初期条件を、JSが読む形（内部キー）に詰め直す。
       ★データに無い値は黙って捨てる。綴り違いで「0点」の画面を出さないため。 */
    private static function init_query($a, $d)
    {
        $out = array();

        foreach (array('w', 'd', 'h') as $k) {
            $v = trim($a['fit_' . $k]);
            if ($v !== '' && (float) $v > 0) {
                $out['fit' . $k] = (float) $v;
            }
            $ok = array();
            foreach (array_filter(array_map('trim', explode(',', $a[$k]))) as $b) {
                foreach ($d['sizes'] as $s) {
                    if ($s['key'] !== $k) {
                        continue;
                    }
                    foreach ($s['buckets'] as $x) {
                        if ($b === $x[0] . '-' . $x[1]) {
                            $ok[] = $b;
                        }
                    }
                }
            }
            if ($ok) {
                $out[$k] = array_values(array_unique($ok));
            }
        }

        $map = array('step' => 's', 'material' => 'm', 'color' => 'c',
                     'place' => 'p', 'use' => 'g');
        foreach ($map as $att => $key) {
            $want = array_filter(array_map('trim', explode(',', $a[$att])));
            if (!$want) {
                continue;
            }
            $have = array();
            foreach ($d['facets'] as $f) {
                if ($f['key'] === $key) {
                    foreach ($f['options'] as $o) {
                        $have[] = (string) $o['v'];
                    }
                }
            }
            $ok = array_values(array_intersect($want, $have));
            if ($ok) {
                $out[$key] = $ok;
            }
        }

        $want = array_filter(array_map('trim', explode(',', $a['flag'])));
        if ($want) {
            $have = array();
            foreach ((array) $d['flags'] as $f) {
                $have[] = (string) $f['v'];
            }
            $ok = array_values(array_intersect($want, $have));
            if ($ok) {
                $out['f'] = $ok;
            }
        }
        return $out;
    }

    /* 54.5 → 54.5 / 78.0 → 78（小数の0を見せない） */
    private static function num($v)
    {
        return rtrim(rtrim(number_format((float) $v, 1, '.', ''), '0'), '.');
    }

    private static function ymd($s)
    {
        $t = strtotime((string) $s);
        return $t ? date_i18n('Y年n月', $t) : '';
    }
}

Interior_Finder::boot();
