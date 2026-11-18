<?php
/**
 * 1.3.0 掛け算の絞り込み（[kawaerabi_finder]）
 *
 * 革の種類・形・色・性別・年代/相手・用途・仕様を選ぶと、その条件に合う記事の数がその場で変わり、
 * 0本になる選択肢は選べなくなる。送信先は WP 標準のタクソノミー問い合わせ
 *   /?kawa_leather=cordovan&kawa_form=long-wallet
 * なので、結果の一覧はテーマのアーカイブ画面がそのまま描く。
 *
 * - 2つ以上を掛けた結果ページは noindex,follow（同じ記事の並べ替えページを量産しないため）
 * - 結果ページと各一覧ページの上にも同じ絞り込みを出し、今の条件を選んだ状態にする
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** 今のリクエストで指定されている軸 => slug（空は除く） */
function kawaerabi_finder_selected() {
	$sel = array();
	foreach ( array_keys( kawaerabi_tax_axes() ) as $tax ) {
		$v = get_query_var( $tax );
		if ( is_string( $v ) && $v !== '' ) {
			// 階層のURL（/leather/cowhide/tochigi-leather/）では「親/子」で入るので末尾を使う
			$parts       = explode( '/', trim( $v, '/' ) );
			$sel[ $tax ] = sanitize_title( end( $parts ) );
		}
	}
	return $sel;
}

// 空の kawa_* が来ても問い合わせに混ぜない（JSが無いブラウザで空の select が送られる）
add_filter( 'request', function ( $vars ) {
	foreach ( array_keys( kawaerabi_tax_axes() ) as $tax ) {
		if ( isset( $vars[ $tax ] ) && $vars[ $tax ] === '' ) {
			unset( $vars[ $tax ] );
		}
	}
	return $vars;
} );

/** 2つ以上を掛けた結果ページか */
function kawaerabi_finder_is_multi() {
	return is_tax( array_keys( kawaerabi_tax_axes() ) ) && count( kawaerabi_finder_selected() ) >= 2;
}

/** 記事ごとの項目IDの表（JSで件数を数える）。記事の更新で作り直す */
function kawaerabi_finder_data() {
	$cached = get_transient( 'kawaerabi_finder_data' );
	if ( is_array( $cached ) ) {
		return $cached;
	}
	$axes  = array_keys( kawaerabi_tax_axes() );
	$terms = array();
	foreach ( $axes as $tax ) {
		$list = get_terms( array( 'taxonomy' => $tax, 'hide_empty' => true, 'orderby' => 'count', 'order' => 'DESC' ) );
		$terms[ $tax ] = array();
		if ( is_wp_error( $list ) ) {
			continue;
		}
		foreach ( $list as $t ) {
			$terms[ $tax ][] = array( 'id' => $t->term_id, 'slug' => $t->slug, 'name' => $t->name, 'parent' => $t->parent );
		}
	}
	$ids   = get_posts( array(
		'post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids',
		'tax_query' => array_merge( array( 'relation' => 'OR' ), array_map( function ( $tax ) {
			return array( 'taxonomy' => $tax, 'operator' => 'EXISTS' );
		}, $axes ) ),
	) );
	$posts = array();
	foreach ( $ids as $pid ) {
		$row = array();
		foreach ( $axes as $i => $tax ) {
			$row[ $i ] = array_map( 'intval', wp_get_object_terms( $pid, $tax, array( 'fields' => 'ids' ) ) );
		}
		$posts[] = $row;
	}
	$data = array( 'axes' => $axes, 'terms' => $terms, 'posts' => $posts );
	set_transient( 'kawaerabi_finder_data', $data, DAY_IN_SECONDS );
	return $data;
}
foreach ( array( 'save_post_post', 'deleted_post', 'set_object_terms', 'edited_term', 'created_term', 'delete_term' ) as $hook ) {
	add_action( $hook, function () {
		delete_transient( 'kawaerabi_finder_data' );
	} );
}

function kawaerabi_finder_render( $args = array() ) {
	$data = kawaerabi_finder_data();
	if ( empty( $data['posts'] ) ) {
		return '';
	}
	$axes_def = kawaerabi_tax_axes();
	$sel      = kawaerabi_finder_selected();
	$title    = isset( $args['title'] ) ? $args['title'] : '革の種類×形×条件で記事を絞り込む';
	$uid      = 'kawa-finder-' . wp_rand( 1000, 9999 );

	ob_start();
	?>
	<form class="kawa-finder" id="<?php echo esc_attr( $uid ); ?>" method="get" action="<?php echo esc_url( home_url( '/' ) ); ?>" role="search">
		<?php if ( $title !== '' ) : ?>
			<p class="kawa-finder__title"><?php echo esc_html( $title ); ?></p>
		<?php endif; ?>
		<div class="kawa-finder__grid">
		<?php
		foreach ( $data['axes'] as $tax ) :
			if ( empty( $data['terms'][ $tax ] ) ) {
				continue;
			}
			$label = $axes_def[ $tax ]['label'];
			// 親を先に、子をその下に（革・形は階層）
			$list = $data['terms'][ $tax ];
			$top  = array_values( array_filter( $list, function ( $t ) { return ! $t['parent']; } ) );
			$kids = array();
			foreach ( $list as $t ) {
				if ( $t['parent'] ) {
					$kids[ $t['parent'] ][] = $t;
				}
			}
			$ids_in = wp_list_pluck( $list, 'id' );
			?>
			<label class="kawa-finder__field">
				<span class="kawa-finder__label"><?php echo esc_html( $label ); ?></span>
				<select name="<?php echo esc_attr( $tax ); ?>" data-axis="<?php echo esc_attr( $tax ); ?>">
					<option value="">指定なし</option>
					<?php
					$emit = function ( $t, $depth ) use ( $sel, $tax ) {
						printf(
							'<option value="%s" data-id="%d" data-name="%s"%s>%s%s</option>',
							esc_attr( $t['slug'] ), (int) $t['id'], esc_attr( $t['name'] ),
							selected( isset( $sel[ $tax ] ) ? $sel[ $tax ] : '', $t['slug'], false ),
							$depth ? '　' : '', esc_html( $t['name'] )
						);
					};
					foreach ( $top as $t ) {
						$emit( $t, 0 );
						foreach ( isset( $kids[ $t['id'] ] ) ? $kids[ $t['id'] ] : array() as $c ) {
							$emit( $c, 1 );
						}
					}
					// 親が一覧に無い子（親の記事数0）
					foreach ( $kids as $pid => $cs ) {
						if ( ! in_array( $pid, $ids_in, true ) ) {
							foreach ( $cs as $c ) {
								$emit( $c, 0 );
							}
						}
					}
					?>
				</select>
			</label>
		<?php endforeach; ?>
		</div>
		<div class="kawa-finder__foot">
			<span class="kawa-finder__count" aria-live="polite"></span>
			<button type="submit" class="kawa-finder__submit">この条件で記事を見る</button>
			<button type="button" class="kawa-finder__reset">条件をクリア</button>
		</div>
	</form>
	<script>
	(function(){
		var f=document.getElementById(<?php echo wp_json_encode( $uid ); ?>);if(!f)return;
		var D=<?php echo wp_json_encode( array( 'axes' => $data['axes'], 'posts' => $data['posts'] ) ); ?>;
		var sels=[].slice.call(f.querySelectorAll('select[data-axis]'));
		function cur(){var c={};sels.forEach(function(s){var o=s.options[s.selectedIndex];if(o&&o.value)c[D.axes.indexOf(s.dataset.axis)]=+o.dataset.id;});return c;}
		function match(p,c,skip){for(var k in c){if(+k===skip)continue;if(p[k].indexOf(c[k])<0)return false;}return true;}
		function upd(){
			var c=cur(),total=0;
			D.posts.forEach(function(p){if(match(p,c,-1))total++;});
			sels.forEach(function(s){
				var ax=D.axes.indexOf(s.dataset.axis);
				[].forEach.call(s.options,function(o){
					if(!o.value)return;
					var n=0;D.posts.forEach(function(p){if(match(p,c,ax)&&p[ax].indexOf(+o.dataset.id)>=0)n++;});
					o.disabled=n===0&&!o.selected;
					o.textContent=(o.text.charAt(0)==='　'?'　':'')+o.dataset.name+'（'+n+'）';
				});
			});
			var none=Object.keys(c).length===0;
			f.querySelector('.kawa-finder__count').textContent=none?'条件を選ぶと、当てはまる記事の数が出ます':'当てはまる記事 '+total+'本';
			f.querySelector('.kawa-finder__submit').disabled=none||total===0;
		}
		sels.forEach(function(s){s.addEventListener('change',upd);});
		f.querySelector('.kawa-finder__reset').addEventListener('click',function(){sels.forEach(function(s){s.selectedIndex=0;});upd();});
		f.addEventListener('submit',function(){sels.forEach(function(s){if(!s.value)s.disabled=true;});});
		upd();
	})();
	</script>
	<?php
	return ob_get_clean();
}

add_shortcode( 'kawaerabi_finder', function ( $atts ) {
	$atts = shortcode_atts( array( 'title' => '革の種類×形×条件で記事を絞り込む' ), $atts );
	return kawaerabi_finder_render( $atts );
} );

// 見た目。テーマの色（--rd--c--*）を使い、文字サイズはテーマ既定のまま（小さくしない）
add_action( 'wp_head', function () {
	?>
	<style id="kawa-finder-css">
	.kawa-finder{border:2px solid rgb(var(--rd--c--accent));border-radius:10px;padding:20px 22px;margin:0 0 2em;background:rgb(var(--rd--c--background))}
	.kawa-finder__title{font-weight:700;font-size:1.15em;margin:0 0 14px !important;color:rgb(var(--rd--c--secondary))}
	.kawa-finder__grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:12px 14px}
	.kawa-finder__field{display:flex;flex-direction:column;gap:4px;margin:0}
	.kawa-finder__label{font-weight:700;color:rgb(var(--rd--c--text))}
	.kawa-finder select{width:100%;padding:10px 12px;border:1px solid rgba(var(--rd--c--text),.35);border-radius:6px;background:#fff;color:rgb(var(--rd--c--text));font-size:16px}
	.kawa-finder__foot{display:flex;flex-wrap:wrap;align-items:center;gap:10px 14px;margin-top:16px}
	.kawa-finder__count{flex:1 1 220px;font-weight:700;color:rgb(var(--rd--c--accent))}
	.kawa-finder__submit{background:rgb(var(--rd--c--accent));color:#fff;border:0;border-radius:6px;padding:12px 22px;font-weight:700;font-size:16px;cursor:pointer}
	.kawa-finder__submit:disabled{opacity:.45;cursor:not-allowed}
	.kawa-finder__reset{background:none;border:1px solid rgba(var(--rd--c--text),.35);border-radius:6px;padding:11px 16px;color:rgb(var(--rd--c--text));font-size:16px;cursor:pointer}
	@media (max-width:600px){.kawa-finder{padding:16px}.kawa-finder__grid{grid-template-columns:1fr 1fr}.kawa-finder__submit{flex:1 1 100%}}
	</style>
	<?php
} );

// 一覧ページ（1軸・掛け算の結果とも）の見出しの下に、今の条件を選んだ絞り込みを出す
add_action( 'diver_archive_main', function () {
	if ( ! is_tax( array_keys( kawaerabi_tax_axes() ) ) ) {
		return;
	}
	echo kawaerabi_finder_render( array( 'title' => '条件を変えて絞り込む' ) ); // phpcs:ignore
}, 9 );

/** 掛け算の結果ページの見出し（「コードバン × 長財布 の記事」） */
function kawaerabi_finder_multi_heading() {
	$names = array();
	foreach ( kawaerabi_finder_selected() as $tax => $slug ) {
		$t = get_term_by( 'slug', $slug, $tax );
		if ( $t ) {
			$names[] = $t->name;
		}
	}
	return implode( ' × ', $names ) . ' の記事';
}

add_filter( 'wp_robots', function ( $robots ) {
	if ( kawaerabi_finder_is_multi() ) {
		$robots['noindex'] = true;
		$robots['follow']  = true;
		unset( $robots['max-image-preview'] );
	}
	return $robots;
}, 20 );
