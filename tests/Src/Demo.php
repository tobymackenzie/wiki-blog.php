<?php
namespace TJM\WikiBlog\Tests\Src;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use TJM\Wiki\Wiki;
use TJM\WikiBlog\Blog;
use TJM\WikiSite\FormatConverter\HtmlToMarkdownConverter;
use TJM\WikiSite\FormatConverter\MarkdownToCleanMarkdownConverter;
use TJM\WikiSite\FormatConverter\MarkdownToHtmlConverter;
use TJM\WikiSite\WikiSite;
use Twig\Environment;
use Twig\Extension\DebugExtension;
use Twig\TwigFunction;
use Twig\Loader\FilesystemLoader;

class Demo{
	static protected string $host = 'wiki://';
	static public function wikiSite(?string $host = null){
		date_default_timezone_set('America/New_York');
		$conf = [
			'name'=> 'Demo Blog',
			'description'=> 'Web postings by <b>Demo</b>',
		];
		if(!empty($host)){
			static::$host = $host;
		}

		$router = new Router();
		$twigLoader = new FilesystemLoader(__DIR__ . '/..');
		$twigLoader->addPath(__DIR__ . '/../../vendor/tjm/wiki-site/templates', 'TJMWikiSite');
		$twigLoader->addPath(__DIR__ . '/../../templates', 'TJMWikiBlog');
		$twigLoader->addPath(__DIR__ . '/../../demo/_wiki', 'TJMWikiBlog');
		$twig = new Environment($twigLoader, [
			'debug'=> true,
		]);
		$twig->addFunction(new TwigFunction('asset', function($value){
			return $value;
		}));
		$twig->addFunction(new TwigFunction('path', function($value, $data, $absolute = false){
			$path = $data['path'];
			if($absolute){
				$path = Demo::getHost() . $path;
			}
			return $path;
		}));
		$twig->addExtension(new DebugExtension());
		$twig->enableDebug();
		$wsite = new WikiSite(
			new Wiki([
				'path'=> getenv('WIKI_PATH') ?: __DIR__ . '/../../demo/_wiki',
			]),
			[
				'converters'=> [
					new HtmlToMarkdownConverter(),
					new MarkdownToCleanMarkdownConverter(),
					new MarkdownToHtmlConverter(),
				],
				'eventDispatcher'=> new EventDispatcher(),
				'router'=> $router,
				'twig'=> $twig,
			],
		);
		$wsite->addPlugin(new Blog($wsite, $conf));
		return $wsite;
	}
	static public function getHost(){
		return static::$host;
	}
}
