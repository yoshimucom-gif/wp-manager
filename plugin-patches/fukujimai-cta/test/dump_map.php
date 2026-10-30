<?php
define('ABSPATH', __DIR__.'/');
require __DIR__.'/../includes/patterns.php';
$pat=array(); foreach(fjcta_patterns() as $k=>$v){ $pat[$k]=$v['hero']; }
echo json_encode(array('map'=>fjcta_category_map(),'hero'=>$pat), JSON_UNESCAPED_UNICODE);
