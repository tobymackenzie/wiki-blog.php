<?php
namespace TJM\WikiBlog\Tests\Src;
use Monolog\Logger;
use Monolog\Handler\NullHandler;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\Router as Base;
use Symfony\Component\Routing\RouteCollection;

class Router extends Base{
	public function __construct(){
		$this->collection = new RouteCollection();
		$this->collection->add('tjm_wiki', new Route('{path}', ['controller'=> 'TJM\WikiSite\WikiSite::viewAction'], ['path'=> '.*']));
		$fullHost = Demo::getHost();
		$hostBits = explode(':', $fullHost, 2);
		$host = $hostBits[1];
		if(substr($host, 0, 2) === '//'){
			$host = substr($host, 2);
		}
		$this->context = new RequestContext('', 'GET', $host, $hostBits[0]);
		$this->logger = new Logger('null');
		$this->logger->pushHandler(new NullHandler());
		$this->setOptions([]);
		$this->defaultLocale = 'en';
	}
}
