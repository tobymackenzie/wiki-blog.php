<?php
namespace TJM\WikiBlog\Tests\Src;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RouterInterface;

class Router implements RouterInterface{
	protected RouteCollection $c;
	protected RequestContext $context;
	public function __construct(){
		$this->c = new RouteCollection();
		$this->c->add('tjm_wiki', new Route('{path}', ['controller'=> 'TJM\WikiSite\WikiSite::viewAction']));
	}
	public function getRouteCollection(): RouteCollection{ return $this->c; }
	public function match(string $pathinfo): array{}
	public function generate(string $name, array $parameters = [], int $referenceType = self::ABSOLUTE_PATH): string{
		$path = $parameters['path'];
		if($referenceType === true || $referenceType === self::ABSOLUTE_URL){
			$path = Demo::getHost() . $path;
		}
		return $path;
	}
	public function setContext(RequestContext $context): void{ $this->context = $context; }
	public function getContext(): RequestContext{ return $this->context; }
}
