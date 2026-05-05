<?php
namespace TJM\WikiBlog\Tests;
use PHPUnit\Framework\TestCase;
use TJM\Wiki\Wiki;
use TJM\WikiBlog\Blog;
use TJM\WikiBlog\Tests\Src\Demo;

class BlogTest extends TestCase{
	protected ?Twig_Environment $twig = null;
	static protected array $htmlSuccessResponses = [
		'/blog'=> 'Web postings by',
		'/blog/2026'=> '2026 posts',
		'/blog/2026/01'=> 'January 2026 posts',
		'/blog/2026/01/01'=> 'January 1, 2026 posts',
		'/blog/2026/01/01/foo'=> 'Lorem ipsum dolor sit amet',
		'/blog/2003/07/31/9'=> 'My first posting',
		'/blog/category/me'=> 'The life and times of Me',
		'/blog/tag/ipsum'=> 'ipsum posts',

	];
	public function testHtmlSuccessResponses(){
		$wsite = $this->getWikiSite();
		foreach(self::$htmlSuccessResponses as $path=> $expect){
			$response = $wsite->viewAction($path);
			$this->assertEquals(200, $response->getStatusCode());
			$this->assertStringStartsWith('<!DOCTYPE html>', $response->getContent());
			$this->assertStringContainsString($expect, $response->getContent());
		}
	}
	public function testMarkdownSuccessResponses(){
		$wsite = $this->getWikiSite();
		foreach(self::$htmlSuccessResponses as $path=> $expect){
			$path .= '.md';
			$response = $wsite->viewAction($path);
			$this->assertEquals(200, $response->getStatusCode());
			$this->assertStringContainsString('wiki://' . $path, $response->getContent());
			$this->assertStringContainsString($expect, $response->getContent());
		}
	}
	public function testFeedResponse(){
		$wsite = $this->getWikiSite();
		$response = $wsite->viewAction('/blog/feed');
		$this->assertEquals(200, $response->getStatusCode());
		$this->assertStringStartsWith('<?xml version="1.0" encoding="utf-8"?>', $response->getContent());
		$this->assertStringContainsString('<rss version="2.0">', $response->getContent());
		$this->assertStringContainsString('<item>', $response->getContent());
		$this->assertStringContainsString('<p>Lorem ipsum dolor sit amet', $response->getContent());
		$this->assertStringContainsString('<pubDate>Sun, 05 Apr 2026 00:55:00 +0000</pubDate>', $response->getContent());
		$this->assertStringContainsString('<guid isPermalink="false">https://www.tobymackenzie.com/blog/?p=129</guid>', $response->getContent());
	}
	public function testRemoveSlash(){
		$wsite = $this->getWikiSite();
		foreach(self::$htmlSuccessResponses as $path=> $expect){
			$rpath = $path . '/';
			$response = $wsite->viewAction($rpath);
			$this->assertEquals(302, $response->getStatusCode());
			$this->assertEquals($path, $response->getTargetUrl());
		}
	}

	//==setup
	protected function getWikiSite(){
		return Demo::wikiSite();
	}
}
