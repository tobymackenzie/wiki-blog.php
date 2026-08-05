<?php
namespace TJM\WikiBlog\Tests;
use DateTime;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use TJM\Wiki\Wiki;
use TJM\WikiSite\WikiSite;
use TJM\WikiBlog\Blog;
use TJM\WikiBlog\Tests\Src\Demo;

class BlogTest extends TestCase{
	protected ?Twig_Environment $twig = null;
	static protected array $htmlSuccessResponses = [
		'/blog'=> 'Web postings by',
		'/blog/2026'=> '2026 posts',
		'/blog/2026/01'=> 'January 2026 posts',
		'/blog/2026/01/01'=> 'January 1, 2026 posts',
		'/blog/2025/06/07/wiki'=> 'This is the wiki',
		'/blog/2026/01/01/foo'=> 'Lorem ipsum dolor sit amet',
		'/blog/2003/07/31/9'=> 'My first posting',
		'/blog/category/me'=> 'The life and times of Me',
		'/blog/tag/ipsum'=> 'ipsum posts',

	];
	static public function getNotFoundViewData(){
		return [
			['/blog.aspx'],
			['/blog.foo'],
			['/blog.php7'],
			['/blog.txtt'],
		];
	}
	public function testHtmlSuccessResponses(){
		$wsite = $this->getWikiSite();
		foreach(self::$htmlSuccessResponses as $path=> $expect){
			$response = $wsite->viewAction($path);
			$this->assertEquals(200, $response->getStatusCode());
			$this->assertStringStartsWith('<!DOCTYPE html>', $response->getContent());
			$this->assertStringContainsString($expect, $response->getContent());
		}
	}
	#[DataProvider('getNotFoundViewData')]
	public function test404Responses($path){
		$wsite = $this->getWikiSite();
		$this->expectException(NotFoundHttpException::class);
		$response = $wsite->viewAction($path);
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
	//-# also makes sure mentions aren't included in list
	public function testRelNav(){
		$site = $this->getWikiSite();
		$response = $site->viewAction('/blog/2003/07/31/9');
		$this->assertStringContainsString('Next post: Other name', $response->getContent());
		$response = $site->viewAction('/blog/2025/01/01/121');
		$this->assertStringContainsString('Previous post: First Post', $response->getContent());
		$this->assertStringContainsString('Next post: #1211', $response->getContent());
		$response = $site->viewAction('/blog/2026/01/06/bar');
		$this->assertStringContainsString('Previous post: The Foo', $response->getContent());
		$this->assertStringContainsString('Next post: #125', $response->getContent());
	}
	public function testPublish(){
		$path = __DIR__ . '/tmp';
		mkdir($path);
		$wiki = new Wiki(['path'=> $path]);
		$site = new WikiSite();
		$wiki->addPlugin($site);
		$blog = new Blog($site);
		$wiki->addPlugin($blog);
		mkdir($path . '/blog');
		mkdir($path . '/blog/drafts');
		$date = new DateTime();

		//--publish files
		$fileFoo = $path . '/blog/drafts/foo.md';
		file_put_contents($fileFoo, 'This is a test');
		$blog->publish('foo.md');
		$this->assertEquals("Add blog post 'foo'\n", shell_exec('git -C ' . escapeshellarg($path) . ' log --pretty="%s" -n 1'));
		$fileFoo = $path . '/blog/drafts/draft.md';
		file_put_contents($fileFoo, 'This is another test');
		$blog->publish('draft.md');
		$this->assertEquals("Add blog post '2'\n", shell_exec('git -C ' . escapeshellarg($path) . ' log --pretty="%s" -n 1'));

		//--check posts
		$post = $blog->getPost('/blog/' . $date->format('Y/m/d/') . 'foo.md');
		$post->build();
		$this->assertEquals('1', $post->getId());
		$this->assertEquals('Foo', $post->getName());
		$this->assertEquals($date->format('Ymd'), $post->getDate()->format('Ymd'));
		$this->assertFalse($post->getNameIsId(), 'Post with slug should not consider name as ID');
		$this->assertStringContainsString('This is a test', $post->getContent());

		$post = $blog->getPost('/blog/' . $date->format('Y/m/d/') . '2.md');
		$post->build();
		$this->assertEquals('2', $post->getId());
		$this->assertEquals('#2', $post->getName());
		$this->assertEquals($date->format('Ymd'), $post->getDate()->format('Ymd'));
		$this->assertTrue($post->getNameIsId(), 'ID named post should consider name is ID');
		$this->assertStringContainsString('This is another test', $post->getContent());

		//--clean
		shell_exec('rm -rf ' . escapeshellarg($path));
	}
	public function testFeedResponse(){
		$wsite = $this->getWikiSite();
		$response = $wsite->viewAction('/blog/feed');
		$this->assertEquals(200, $response->getStatusCode());
		$this->assertStringStartsWith('<?xml version="1.0" encoding="utf-8"?>', $response->getContent());
		$this->assertStringContainsString('<rss version="2.0">', $response->getContent());
		$this->assertStringContainsString('<item>', $response->getContent());
		$this->assertStringContainsString('<p>Lorem ipsum dolor sit amet', $response->getContent());
		$this->assertStringContainsString('<pubDate>Sat, 04 Apr 2026 20:55:00 -0400</pubDate>', $response->getContent());
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

	//--mentions
	public function testMentions(){
		$path = __DIR__ . '/resources';
		$wiki = new Wiki(['path'=> $path]);
		$site = new WikiSite();
		$wiki->addPlugin($site);
		$blog = new Blog($site);
		$wiki->addPlugin($blog);
		$posts = $blog->getPosts('xhtml');
		//--get posts shouldn't get mentions page
		$this->assertEquals(2, count($posts));
		//--oldest post should have mentions
		$post = $posts[0];
		$this->assertEmpty($post->getMentionsPath());
		$post = $posts[1];
		$this->assertEquals('/blog/2003/07/31/123/mentions.xhtml', $post->getMentionsPath());
		// $this->assertEquals(
	}

	//==setup
	protected function getWikiSite(){
		return Demo::wikiSite();
	}
}
