<?php
define('ABSPATH', __DIR__ . '/');
$GLOBALS['fj_test_terms']=array(); $GLOBALS['fj_test_option']=array('cta_enabled'=>1,'banner_enabled'=>1,'exclude_ids'=>'');
function plugin_dir_path($f){return dirname($f).'/';} function plugin_dir_url($f){return './';}
function add_action(){} function add_filter(){} function add_options_page(){}
function get_option($k,$d=array()){return $GLOBALS['fj_test_option'];} function update_option($k,$v){}
function is_wp_error($v){return false;} function wp_get_post_terms($i,$t,$a){return $GLOBALS['fj_test_terms'];}
function esc_html($s){return $s;} function esc_attr($s){return $s;} function esc_url($s){return $s;}
function wp_kses_post($s){return $s;} function is_admin(){return false;} function is_singular($t=''){return true;}
function in_the_loop(){return true;} function is_main_query(){return true;} function get_the_ID(){return 1;}
function get_queried_object_id(){return 1;} function wp_enqueue_style(){} function wp_enqueue_script(){}
function current_user_can(){return true;} function checked(){} function submit_button(){} function wp_nonce_field(){}
function wp_verify_nonce(){return false;} function sanitize_text_field($s){return $s;} function wp_unslash($s){return $s;}
class WP_Query{public $posts=array(); public function __construct($a=array()){}}
require __DIR__.'/../fukujimai-cta.php';
$mats = fjcta_materials();
foreach (fjcta_patterns() as $k=>$p){
  $hero=$p['hero']; $m=$mats[$hero];
  $side=fjcta_banner_html($p,'side'); $sp=fjcta_banner_html($p,'sp');
  $ok_side = (strpos($side, $m['banner_300x250']['a8mat'])!==false) && (strpos($side, $m['banner_300x250']['img'])!==false);
  $ok_sp   = (strpos($sp,   $m['banner_320x50']['a8mat'])!==false) && (strpos($sp,   $m['banner_320x50']['img'])!==false);
  printf("%-7s hero=%-6s side=%s sp=%s  side_mat=%s sp_mat=%s\n", $k, $hero,
    $ok_side?'OK':'NG', $ok_sp?'OK':'NG', $m['banner_300x250']['a8mat'], $m['banner_320x50']['a8mat']);
}
