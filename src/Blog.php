<?php
namespace TJM\WikiBlog;
use DateTime;
use DomDocument;
use Exception;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use TJM\Wiki\File;
use TJM\Wiki\Plugin;
use TJM\Wiki\Wiki;
use TJM\WikiBlog\Post;
use TJM\WikiSite\Event\ViewDataEvent;
use TJM\WikiSite\Event\ViewStartEvent;
use TJM\WikiSite\Event\ViewNameEvent;
use TJM\WikiSite\WikiSite;

class Blog extends Plugin{
	//-! POST_PATH_TO: define URL path structure used for detail pages
	// eg /post-name
	// const POST_PATH_TO_BASE = 0;
	// eg /2026/01/06/post-name
	// const POST_PATH_TO_DAY = 1;
	// eg /2026/01/post-name
	// const POST_PATH_TO_MONTH = 2;
	// eg /2026/post-name
	// const POST_PATH_TO_YEAR = 3;
	// eg /cat-name/post-name
	// const POST_PATH_TO_CAT = 4;

	protected string $name = 'Blog';
	protected ?string $shortName;
	protected ?string $description = null;
	protected ?int $feedCount = null;
	protected int $indexCount = 12;
	protected int $maxCount = 100;
	protected WikiSite $site;
	protected ?Wiki $wiki;
	//--paths / urls
	protected string $blogPath = '/blog';
	//-! if categoryPath or tagPath empty, will need smarter logic to figure out what non-year strings match
	protected string $categoryPath = 'category';
	protected string $commentsPath = 'comments';
	//-! protected int $detailPathType = self::POST_PATH_TO_MONTH;
	protected string $feedPath = 'feed';
	protected string $mediaPath = 'media';
	protected string $mentionsPath = 'mentions';
	protected string $tagPath = 'tag';
	//--templates
	protected ?string $detailTemplate = '@TJMWikiBlog/detail';
	protected ?string $listTemplate = '@TJMWikiBlog/list';
	protected ?string $postTemplate = '@TJMWikiBlog/post';
	//--cache
	protected ?int $day = null;
	protected ?int $month = null;
	protected ?int $year = null;

	public function __construct(WikiSite $site, array $opts = []){
		if($opts && is_array($opts)){
			foreach($opts as $opt=> $value){
				$this->$opt = $value;
			}
		}
		$this->site = $site;
		$this->wiki = $site->getWiki();
	}
	protected function getShortName(){
		return $this->shortName ?? $this->name;
	}
	//--paths
	protected function getCategoryPath(){
		if(substr($this->categoryPath, 0, 1) !== '/' && strpos($this->categoryPath, '://') === false){
			return $this->blogPath . '/' . $this->categoryPath;
		}
		return $this->categoryPath;
	}
	protected function getFeedPath(){
		if(substr($this->feedPath, 0, 1) !== '/' && strpos($this->feedPath, '://') === false){
			return $this->blogPath . '/' . $this->feedPath;
		}
		return $this->feedPath;
	}
	protected function getMediaPath(){
		if(substr($this->mediaPath, 0, 1) !== '/' && strpos($this->mediaPath, '://') === false){
			return $this->blogPath . '/' . $this->mediaPath;
		}
		return $this->mediaPath;
	}
	protected function getTagPath(){
		if(substr($this->tagPath, 0, 1) !== '/' && strpos($this->tagPath, '://') === false){
			return $this->blogPath . '/' . $this->tagPath;
		}
		return $this->tagPath;
	}

	//==events
	static public function getSubscribedEvents(): array{
		return [
			ViewDataEvent::class=> 'onData',
			ViewNameEvent::class=> 'onName',
			ViewStartEvent::class=> 'onStart',
		];
	}
	public function onStart(ViewStartEvent $event){
		$path = $event->getPath();
		// $pagePath = $event->getPagePath();
		if(stripos($path, $this->blogPath) === 0){
			$ext = $event->getExtension() ?: 'html';
			$subPath = substr($path, strlen($this->blogPath));
			//-! bypass all of this if not htmlish or textish and file exists, just serve file directly
			//--regex to determine page type, if not match, isBlog should be false, let regular WikiSite processing happen
			if($this->getCategoryPath() !== $this->blogPath && preg_match(":^{$this->getFeedPath()}/?$:i", $path, $matches)){
				$type = $generalType = 'feed';
			}elseif(preg_match(":^({$this->getMediaPath()}/.*)$:i", $path, $matches)){
				return $this->getMediaResponse($matches[1]);
			}elseif($this->getCategoryPath() !== $this->blogPath && preg_match(":^{$this->getCategoryPath()}/?$:i", $path, $matches)){
				$generalType = 'terms';
				$type = 'categories';
				$name = 'Categories';
			}elseif(preg_match(":^{$this->getCategoryPath()}/([\w-]+)(\.[\w-]+)?/?$:i", $path, $matches)){
				$generalType = 'list';
				$type = 'category';
				$event->setExtra('blogCat', $matches[1]);
			}elseif($this->getTagPath() !== $this->blogPath && preg_match(":^{$this->getTagPath()}/?$:i", $path, $matches)){
				$generalType = 'terms';
				$type = 'tags';
				$name = "Tags";
			}elseif(preg_match(":^{$this->getTagPath()}/([\w-]+)(\.[\w-]+)?/?$:i", $path, $matches)){
				$generalType = 'list';
				$type = 'tag';
				$event->setExtra('blogTag', $matches[1]);
			}elseif(preg_match(":^/([\d]{2,4})(\.[\w-]+)?/?$:i", $subPath, $matches)){
				$generalType = 'list';
				$type = 'year';
				$event->setExtra('blogYear', $matches[1]);
			}elseif(preg_match(":^/([\d]{2,4})/([\d]{2})(\.[\w-]+)?/?$:i", $subPath, $matches)){
				$generalType = 'list';
				$type = 'month';
				$event->setExtra([
					'blogYear'=> $matches[1],
					'blogMonth'=> $matches[2],
				]);
			}elseif(preg_match(":^/([\d]{2,4})/([\d]{2})/([\d]{2})(\.[\w-]+)?/?$:i", $subPath, $matches)){
				$generalType = 'list';
				$type = 'day';
				$event->setExtra([
					'blogYear'=> $matches[1],
					'blogMonth'=> $matches[2],
					'blogDay'=> $matches[3],
				]);
			}elseif(preg_match(":^/([\d]{2,4})/?([\d]{2})?/?([\d]{2})?/([\w\-\.]+)/?$:i", $subPath, $matches)){
				$generalType = 'detail';
				$type = 'detail';
				$event->setExtra([
					'blogYear'=> $matches[1],
					'blogMonth'=> $matches[2],
					'blogDay'=> $matches[3],
					'blogPost'=> $matches[4],
				]);
			}elseif(strlen($subPath) === 0 || $subPath === '/' || substr($subPath, 0, 1) === '.'){
				$generalType = 'list';
				$type = 'index';
			}else{
				return;
			}
			$event->setExtra('blogType', $type);
			//--if have trailing slash, redirect to without
			if(substr($subPath, -1) === '/'){
				$event->setCanonical(substr($path, 0, -1));
				return;
			}
			switch($generalType){
				case 'feed':
					$event->setResponse($this->getRssFeedResponse());
					return;
				break;
				case 'list':
					//-! support search with grep opts. from querystring, should work for all lists that use getPosts
					switch($type){
						case 'index':
							$posts = $this->getPosts($ext, $this->blogPath, null, null, null, $this->indexCount ?? $this->maxCount);
						break;
						case 'day':
						case 'month':
						case 'year':
							$getPath = $this->blogPath;
							if($event->getExtra('blogYear')){
								$getPath .= '/' . $event->getExtra('blogYear');
							}
							if($event->getExtra('blogMonth')){
								$getPath .= '/' . $event->getExtra('blogMonth');
							}
							if($event->getExtra('blogDay')){
								$getPath .= '/' . $event->getExtra('blogDay');
							}
							$posts = $this->getPosts($ext, $getPath);
						break;
						case 'category':
							$posts = $this->getPosts($ext, null, null, 'categories:.\\+' . $event->getExtra('blogCat'));
						break;
						case 'tag':
							$posts = $this->getPosts($ext, null, null, 'tags:.\\+' . $event->getExtra('blogTag'));
						break;
					}
					if(empty($posts)){
						throw new NotFoundHttpException();
					}

					//--set fake file to prevent normal file loading attempt for list and getting extension
					$fakePath = $this->wiki->getPath() . $path;
					if(!pathinfo($fakePath, PATHINFO_EXTENSION)){
						$fakePath .= '.' . ($event->getExtension() ?: 'html');
					}
					$file = new File($fakePath);
					$event->setFile($file);
					switch($type){
						case 'day':
							$name = (new DateTime($event->getExtra('blogYear') . $event->getExtra('blogMonth') . $event->getExtra('blogDay')))->format('F j, Y');
							$event->setData('relNext', $this->getAdjacentDateRelNav($event->getExtra('blogYear'), $event->getExtra('blogMonth'), $event->getExtra('blogDay')));
							$event->setData('relPrev', $this->getAdjacentDateRelNav($event->getExtra('blogYear'), $event->getExtra('blogMonth'), $event->getExtra('blogDay'), true));
						break;
						case 'month':
							$name = (new DateTime($event->getExtra('blogYear') . $event->getExtra('blogMonth') . '01'))->format('F Y');
							$event->setData('relNext', $this->getAdjacentDateRelNav($event->getExtra('blogYear'), $event->getExtra('blogMonth'), null));
							$event->setData('relPrev', $this->getAdjacentDateRelNav($event->getExtra('blogYear'), $event->getExtra('blogMonth'), null, true));
						break;
						case 'year':
							$name = $event->getExtra('blogYear');
							$event->setData('relNext', $this->getAdjacentDateRelNav($event->getExtra('blogYear')));
							$event->setData('relPrev', $this->getAdjacentDateRelNav($event->getExtra('blogYear'), null, null, true));
						break;
						case 'tag':
							$name = $event->getExtra('blogTag');
						break;
						case 'category':
							$cat = $this->getPost($path, $event->getPagePath(), $ext);
							if($cat && $cat->getFile()){
								$event->setFile($cat->getFile());
								$name = $cat->getName();
								$content = $cat->getContent();
							}else{
								throw new NotFoundHttpException();
							}
						break;
						case 'index':
							$name = $this->name;
							if($this->description){
								$content = $this->description;
							}
							$event->setData('relNext', $this->getDateRelNav(date('Y')));
						break;
						default:
							$name = ucwords($type);
						break;
					}
					switch($type){
						case 'index':
							$title = $name;
						break;
						default:
							$name .= ' posts';
							$title = $name . ' - ' . $this->getShortName();
							if($type !== 'category'){
								$event->setData('robots', 'noindex, follow');
							}
						break;
					}
					if(!empty($content)){
						$event->setContent($content);
					}
					$event->setName($name);
					$event->setData('posts', $posts);
					$event->setData('title', $title);
					$event->setExtra('isBlog', true);
				break;
				case 'detail':
					$post = $this->getPost($path, $event->getPagePath(), $ext);
					if($post && $post->getFile()){
						$event->setFile($post->getFile());
						$event->setExtra('post', $post);
						$event->setExtra('isBlog', true);
						$event->setData('relPrev', $this->getAdjacentPostRelNav($post));
						$event->setData('relNext', $this->getAdjacentPostRelNav($post, true));
						//--prevent double converting
						$event->setContent(' ');
						$event->setName($post->getName());
						$event->setData('title', $post->getName() . ' - ' . $this->getShortName());
					}
				break;
				case 'terms':
					throw new NotFoundHttpException();
					//-!! not implemented
					$title = $name . ' - ' . $this->getShortName();
					switch($type){
						case 'categories':
						break;
						case 'tags':
						break;
					}
					$event->setExtra('isBlog', true);
				break;
			}
		}
	}
	public function onName(ViewNameEvent $event){
		if(!$event->getExtra('isBlog')){
			return;
		}
		$type = $event->getExtra('blogType');
		if($event->isHtmlish()){
			$ext = 'html';
		}elseif($event->isTextish()){
			$ext = 'txt';
		}else{
			$ext = $event->getExtension() ?: 'html';
		}
		//-# is single in WP parlance
		$isDetail = $type === 'detail';
		if($isDetail){
			$post = $event->getExtra('post');
			if($this->detailTemplate){
				$event->setTemplate("{$this->detailTemplate}.{$ext}.twig");
			}
		}else{
			if($this->listTemplate){
				$event->setTemplate("{$this->listTemplate}.{$ext}.twig");
			}
		}
		if(empty($event->getData('title')) && $event->getName()){
			$event->setData('title', $event->getName() . " - {$this->getShortName()}");
		}

		$postsCount = $isDetail ? 0 : count($event->getData('posts'));
		$event->setData([
			'postsCount'=> $postsCount,
			'postTemplate'=> "{$this->postTemplate}.{$ext}.twig",
		]);
		if($this->getFeedPath()){
			$event->setData('feedPath', $this->getFeedPath());
		}
		if($isDetail){
			$event->setData('post', $post);
			$event->setContent($post->getContent());
		}

		//--shorter date on list pages
		switch($type){
			case 'index':
				$y = ', Y';
			break;
			case 'year':
			case 'month':
			case 'day':
				$y = $event->getExtra('blogYear') == date('Y') ? '' : ', Y';
			break;
			case 'category':
			case 'categories':
			case 'tags':
			case 'tag':
				$y = ', Y';
			break;
			default:
				if(isset($post)){
					$y = $post->getDate()->format('Y') == date('Y') ? '' : ', Y';
				}else{
					$y = ', Y';
				}
			break;
		}
		$event->setData('dateFormat', $isDetail ? "F j$y \\a\\t H:i" : "M jS$y");

		//--prevent normal name addition from happening
		$event->setRenderContent(false);
	}
	public function onData(ViewDataEvent $event){
		if(!$event->getExtra('isBlog')){
			return;
		}
		//--after skipping name insertion, continue rendering
		$event->setRenderContent(true);
	}

	//==helpers
	//--posts
	protected function getPages(?string $path = null, ?string $find = null, $grep = null, ?int $sort = null, ?int $limit = null){
		if(!isset($path)){
			$path = $this->blogPath;
		}
		if(!isset($find)){
			$find .= " -not -path '*{$this->getCategoryPath()}/*' -not -path '*{$this->commentsPath}/*' -not -path '*{$this->mentionsPath}/*'";
		}
		if(!isset($sort)){
			$sort = Wiki::SORT_DESC | Wiki::SORT_DATE;
		}
		if(!isset($limit)){
			$limit = $this->maxCount;
		}
		return $this->wiki->getPages($path, $find, $grep, $sort, $limit);
	}
	protected function getPosts(string $ext, ?string $path = null, ?string $find = null, $grep = null, ?int $sort = null, ?int $limit = null){
		$pages = $this->getPages($path, $find, $grep, $sort, $limit);
		$posts = [];
		foreach($pages as $page){
			$posts[] = $this->createPost($page, null, $ext);
		}
		return $posts;
	}
	protected function createPost(File $file, ?string $path = null, ?string $ext = null){
		if(!isset($path)){
			$path = $file->getPath();
			if($ext !== $file->getExtension()){
				$path = substr($path, 0, -1 * strlen($file->getExtension()) - 1);
				if($ext !== 'html'){
					$path .= ".{$ext}";
				}
			}
			if(substr($path, 0, 1) !== '/'){
				$path = '/' . $path;
			}
		}
		$self = $this;
		$post = new Post([
			'blogPath'=> $this->blogPath,
			'categoryPath'=> $this->getCategoryPath(),
			'mediaPath'=> $this->getMediaPath(),
			'tagPath'=> $this->getTagPath(),
			'path'=> $this->site->getRoute($path),
			'url'=> $this->site->getRoute($path, null, UrlGeneratorInterface::ABSOLUTE_URL),
			'file'=> $file,
			'thumbnail'=> function($post) use($self){
				$imagePath = $post->getImagePath();
				if($imagePath){
					return $self->getImageThumbFile($imagePath);
				}
				return null;
			},
		]);
		if($ext && $this->site->canConvertFile($post->getFile(), $ext)){
			$site = $this->site;
			$post->setContent(function() use($post, $site, $ext){
				return $site->convertFile($post->getFile(), $ext);
			}, $ext);
			//--ensure we still get name event
			$post->processContent($post->getFile()->getContent(), $post->getFile()->getExtension());
		}
		return $post;
	}
	public function getPost(?string $path = null, ?string $pagePath = null, ?string $ext = null){
		if($pagePath && $this->wiki->hasPage($pagePath)){
			$file = $this->wiki->getPage($pagePath);
			$usePath = $pagePath;
		}elseif($path && $this->wiki->hasFile($path)){
			$file = $this->wiki->getFile($path);
			$usePath = $path;
		}
		if(empty($file)){
			//-! need to look in parent folders, if found and not in right place, redirect
			return;
		}
		return $this->createPost($file, $usePath, $ext);
	}
	protected function getLastPost(){
		$posts = $this->getPosts('md', $this->blogPath, null, null, null, 1);
		return $posts ? $posts[0] : null;
	}
	//--relnav
	protected function getDateRelNav(int $year, ?int $month = null, ?int $day = null){
		$file = $this->blogPath . '/' . $year;
		if($month){
			$file .= '/' . str_pad($month, 2, '0', STR_PAD_LEFT);
		}
		if($day){
			$file .= '/' . str_pad($day, 2, '0', STR_PAD_LEFT);
		}
		//-# getPagePaths to ensure dir is not empty or without posts
		if($this->wiki->hasDir($file) && $this->wiki->getPagePaths($file)){
			$str = '';
			if($day || $month){
				$str .= (DateTime::createFromFormat('!n', $month))->format('F') . ' ';
			}
			if($day){
				$str .= $day . ', ';
			}
			$str .= $year;
			return ['path'=> $file, 'name'=> $str . ' posts'];
		}
	}
	protected function getPostRelNav(Post $post, string $str = 'Next'){
		return ['path'=> $post->getPath(), 'name'=> $str . ' post: ' . $post->getName()];
	}
	protected function getAdjacentPostRelNav(Post $post, bool $newer = false){
		$checkPath = pathinfo($post->getPath(), PATHINFO_DIRNAME);
		$i = 0;
		$found = false;
		while(++$i < 30){
			$sort = $newer ? Wiki::SORT_ASC : Wiki::SORT_DESC;
			$pages = $this->getPages($checkPath, null, null, $sort);
			foreach($pages as $page){
				if($found){
					$adjPage = $page;
					break 2;
				}
				if($page->getMeta('id') == $post->getId()){
					$found = true;
				}
			}
			if(preg_match(':^' . $this->blogPath . '/([\d]+)/?([\d]+)?/?([\d]+)?/?$:', $checkPath, $matches)){
				$rel = $this->getAdjacentDateRelNav($matches[1], $matches[2] ?? null, $matches[3] ?? null, $newer);
				if($rel){
					$checkPath = $rel['path'];
					continue;
				}
			}
			break;
		}
		if(isset($adjPage)){
			$adjPost = $this->createPost($adjPage, null, $post->getExtension());
			return $this->getPostRelNav($adjPost, $newer ? 'Next' : 'Previous');
		}
	}
	protected function getAdjacentDateRelNav(int $year, ?int $month = null, ?int $day = null, bool $newer = false){
		$checkPath = $this->blogPath;
		$checkBit = $year;
		if($month){
			$checkPath .= '/' . $year;
			$checkBit = $month;
		}
		if($day){
			$checkPath .= '/' . str_pad($month, 2, '0', STR_PAD_LEFT);
			$checkBit = $day;
		}
		$getNewNav = function($check) use(&$checkPath){
			if(!$check) return;
			$newPath = $checkPath . '/' . str_pad($check, 2, '0', STR_PAD_LEFT);
			if(preg_match(':^' . $this->blogPath . '/([\d]+)/?([\d]+)?/?([\d]+)?/?$:', $newPath, $matches)){
				$nav = $this->getDateRelNav($matches[1], $matches[2] ?? null, $matches[3] ?? null);
				if($nav){
					return $nav;
				}
			}
		};
		$found = false;
		$list = $this->wiki->listDir($checkPath);
		if(!$newer){
			rsort($list);
		}
		foreach($list as $check){
			if($found){
				$nav = $getNewNav($check);
				if($nav){
					return $nav;
				}
			}
			if((int) $check === $checkBit){
				$found = true;
			}
		}
		$i = 0;
		while(++$i < 20){
			//--if we get here, we need to try adjacent up one level, unless we're doing years
			if($day){
				$upNav = $this->getAdjacentDateRelNav($year, $month, null, $newer);
			}elseif($month){
				$upNav = $this->getAdjacentDateRelNav($year, null, null, $newer);
			}else{
				break;
			}
			if($upNav){
				$subList = $this->wiki->listDir($upNav['path'], Wiki::LIST_WIKIPATH);
				if($newer){
					$upPath = reset($subList);
				}else{
					$upPath = end($subList);
				}
				if(preg_match(':^' . $this->blogPath . '/([\d]+)/?([\d]+)?/?([\d]+)?/?$:', $upPath, $matches)){
					$nav = $this->getDateRelNav($matches[1], $matches[2] ?? null, $matches[3] ?? null);
					if($nav){
						return $nav;
					}
				}
			}else{
				break;
			}
			if(!empty($nav)){
				return $nav;
			}
		}
		return;
	}
	//--dates
	protected function getCurrentDay(){
		if(empty($this->day)){
			$this->day = (int) date('j');
		}
		return $this->day;
	}
	protected function getCurrentMonth(){
		if(empty($this->month)){
			$this->month = (int) date('n');
		}
		return $this->month;
	}
	protected function getCurrentYear(){
		if(empty($this->year)){
			$this->year = (int) date('Y');
		}
		return $this->year;
	}

	//==media
	protected function getImageThumbFile(string $imagePath){
		//-# uses WordPress style "-123x123" to signify smaller variants
		$fname = pathinfo($imagePath, PATHINFO_FILENAME);
		$opts = [];
		foreach($this->wiki->listDir(pathinfo($imagePath, PATHINFO_DIRNAME), Wiki::LIST_WIKIPATH, $fname . '*') as $file){
			$fileFname = pathinfo($file, PATHINFO_FILENAME);
			if(preg_match(':^' . $fname . '\-([\d]+)x([\d]+)$:', $fileFname, $matches)){
				$opts[(int) $matches[1]] = $file;
			}
		}
		if(!empty($opts)){
			ksort($opts);
			foreach($opts as $key=> $opt){
				if((int) $key >= 620){
					return $opt;
				}
			}
			return end($opts);
		}
	}

	//==responses
	protected function getMediaResponse(string $path){
		$path = $this->wiki->getFilePath($path);
		if(file_exists($path)){
			return new BinaryFileResponse($path);
		}
		throw NotFoundHttpException();
	}
	protected function getRssFeedResponse(){
		$doc = new DomDocument('1.0', 'utf-8');
		$feed = $doc->createElement('rss');
		$feed->setAttribute('version', '2.0');
		$doc->appendChild($feed);
		$ch = $doc->createElement('channel');
		$ch->appendChild($doc->createElement('title', $this->name . ' - ' . $this->site->getName()));
		$ch->appendChild($doc->createElement('link', $this->site->getRoute($this->blogPath, null, UrlGeneratorInterface::ABSOLUTE_URL)));
		if($this->description && strpos($this->description, '<') !== false){
			$desc = $doc->createElement('description');
			$desc->appendChild($doc->createCDATASection($this->description));
			$ch->appendChild($desc);
		}else{
			$ch->appendChild($doc->createElement('description', $this->description ?? 'Blog feed'));
		}
		$feed->appendChild($ch);
		$posts = $this->getPosts('html', null, null, null, null, $this->feedCount ?? $this->indexCount ?? $this->maxCount);
		foreach($posts as $post){
			$item = $doc->createElement('item');
			$post->build();
			$item->appendChild($doc->createElement('title', $post->getName()));
			$link = $this->site->getRoute($post->getPath(), null, UrlGeneratorInterface::ABSOLUTE_URL);
			$item->appendChild($doc->createElement('link', $link));
			$desc = $doc->createElement('description');
			$desc->appendChild($doc->createCDATASection($post->getExcerpt()));
			$item->appendChild($desc);
			$item->appendChild($doc->createElement('pubDate', $post->getDate()->format('D, d M Y H:i:s O')));
			if($post->getGuid()){
				$guid = $doc->createElement('guid', $post->getGuid());
				$guid->setAttribute('isPermalink', 'false');
			}else{
				$guid = $doc->createElement('guid', $link);
			}
			foreach($post->getTerms() as $term){
				$item->appendChild($doc->createElement('category', $term['name']));
			}
			$item->appendChild($guid);
			$ch->appendChild($item);
		}
		$response = new Response();
		$response->setContent($doc->saveXML());
		$response->headers->set('Content-Type', 'application/xml');
		return $response;
	}
}
