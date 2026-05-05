<?php
namespace TJM\WikiBlog\Demo;
use TJM\WikiBlog\Tests\Src\Demo;
require_once(__DIR__ . '/../vendor/autoload.php');

if(php_sapi_name() === 'cli'){
	$path = $argv[1] ?? '/blog/2026/01/06/foo';
	$host = 'wiki://';
}else{
	$path = $_SERVER['REQUEST_URI'];
	$host = $_SERVER['HTTP_HOST'];
	if(!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'){
		$host = 'https://' . $host;
	}else{
		$host = 'http://' . $host;
	}
}
$wsite = Demo::wikiSite($host);
try{
	$response = $wsite->viewAction($path);
}catch(NotFoundHttpException $e){
	http_response_code(404);
	echo "404: Page not found";
	exit;
}
if(php_sapi_name() === 'cli'){
	echo $response;
}else{
	$response->send();
}

