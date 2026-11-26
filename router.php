<?php
declare(strict_types=1);
$path=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH)?:'/';
$routes=[
'/'=> 'pages/index.php','/news'=>'pages/news.php','/news.php'=>'pages/news.php','/prices'=>'pages/prices.php','/prices.php'=>'pages/prices.php',
'/admin'=>'pages/admin/index.php','/admin/'=>'pages/admin/index.php',
'/admin/dashboard'=>'pages/admin/dashboard/index.php','/admin/dashboard/'=>'pages/admin/dashboard/index.php','/admin/dashboard/index.php'=>'pages/admin/dashboard/index.php',
'/admin/dashboard/users'=>'pages/admin/dashboard/users.php','/admin/dashboard/users.php'=>'pages/admin/dashboard/users.php',
'/admin/dashboard/keys'=>'pages/admin/dashboard/keys.php','/admin/dashboard/keys.php'=>'pages/admin/dashboard/keys.php'];
if(preg_match('#^/api/(authorization|logout)(?:\\.php)?$#',$path)){require __DIR__.'/api/router.php';return true;}
if(isset($routes[$path])){require __DIR__.'/'.$routes[$path];return true;}
$file=__DIR__.$path;if($path!=='/'&&is_file($file))return false;
http_response_code(404);echo 'Not Found';return true;
