<?php
namespace TJM\WikiBlog;
use DateTime;
use TJM\Wiki\File;

class Post{
	protected bool $built = false;
	protected ?File $file = null;
	//--data
	protected $content = null;
	protected ?DateTime $date = null;
	protected ?string $excerpt = null;
	protected ?string $extension = null;
	protected ?string $guid = null;
	protected ?bool $hasMore = null;
	protected ?string $id = null;
	protected ?string $image = null;
	protected ?string $imageAlt = null;
	protected ?DateTime $modified = null;
	protected ?string $name = null;
	protected bool $nameIsId = false;
	protected ?string $path = null;
	protected ?string $permalinkTitle = null;
	protected $thumbnail = null;
	protected array $terms = [];
	//--paths
	protected string $blogPath = '/blog';
	protected string $categoryPath = '/blog/category';
	protected string $mediaPath = '/blog/media';
	protected string $tagPath = '/blog/tag';

	public function __construct($vals = []){
		if($vals instanceof File){
			$this->setFile($vals);
		}else{
			foreach($vals as $key=> $val){
				if($key === 'file'){
					$this->setFile($val);
				}else{
					$this->$key = $val;
				}
			}
		}
	}
	public function build($rebuild = false){
		if(!$rebuild && $this->built){
			return;
		}
		if(!$this->getContent()){
			$this->setContent($this->getFile()->getContent(), $this->getFile()->getExtension());
		}
		$meta = $this->getFile()->getMeta();
		$this->id = $meta['id'] ?? null;
		$this->guid = $meta['guid'] ?? null;
		$this->image = $meta['image'] ?? null;
		$this->imageAlt = $meta['image_alt'] ?? null;
		if(empty($this->name)){
			if(!empty($meta['name'])){
				if($meta['name'] == $meta['id']){
					$this->name = '#' . ucwords($meta['name']);
					$this->nameIsId = true;
				}else{
					$this->name = ucwords($meta['name']);
					$this->nameIsId = true;
				}
			}else{
				$fileName = pathinfo($this->file->getPath(), PATHINFO_FILENAME);
				if(is_numeric($fileName)){
					$this->name = '#' . $fileName;
				}else{
					$this->name = ucwords(str_replace('-', ' ', $fileName));
				}
			}
		}
		if(!empty($meta['date'])){
			$this->date = $this->createDateTime($meta['date']);
		}
		if(!empty($meta['modified'])){
			$this->modified = $this->createDateTime($meta['modified']);
		}

		//--build tag terms
		$tagTerms = [];
		if(!empty($meta['categories'])){
			if(!is_array($meta['categories'])){
				$meta['categories'] = [$meta['categories']];
			}
			if($meta['categories']){
				foreach($meta['categories'] as $category){
					//-! need to get logic to remove selected cat somewhere
					$tagTerms[] = [
						'url'=> "{$this->categoryPath}/{$category}",
						'name'=> $category,
						'type'=> 'category',
					];
				}
			}
		}
		if(!empty($meta['tags'])){
			if(!is_array($meta['tags'])){
				$meta['tags'] = [$meta['tags']];
			}
			if($meta['tags']){
				foreach($meta['tags'] as $tag){
					$tagTerms[] = [
						'url'=> "{$this->tagPath}/{$tag}",
						'name'=> $tag,
						'type'=> 'tag',
					];
				}
			}
		}
		$this->terms = $tagTerms;
		$this->built = true;
	}
	public function getContent(){
		if(!is_string($this->content) && is_callable($this->content)){
			$this->content = $this->processContent(call_user_func($this->content, $this));
		}
		return $this->content;
	}
	public function processContent(?string $content, $ext = null){
		if(empty($content)){
			return;
		}
		if(empty($ext)){
			$ext = $this->getExtension();
		}
		$htmlPattern = ':<h1.*>(.*)</h1>:i';
		$txtPattern = ":([^\n]+)\n={3,}\n:m";
		if(in_array($ext, ['html', 'xhtml'])){
			if(preg_match($htmlPattern, $content, $matches)){
				if(empty($this->name)){
					$this->name = $matches[1];
				}
				$content = preg_replace($htmlPattern, '', $content);
			}
		}elseif(preg_match($txtPattern, $content, $matches)){
			if(empty($this->name)){
				$this->name = $matches[1];
			}
			$content = preg_replace($txtPattern, '', $content);
		}
		return $content;
	}
	public function setContent($content, ?string $ext = null){
		if(is_string($content)){
			$content = $this->processContent($content, $ext);
		}
		$this->content = $content;
		$this->extension = $ext;
	}
	public function getDate(){
		return $this->date;
	}
	public function getExcerpt($forceShort = false){
		if(!$forceShort){
			if($this->excerpt){
				return $this->excerpt;
			}elseif(strpos($this->getContent(), '<!--more-->') !== false){
				return explode('<!--more-->', $this->getContent(), 2)[0];
			}
		}
		$stripped = strip_tags($this->getContent());
		if(strlen($stripped) > 80){
			$excerpt = substr($stripped, 0, 80) . '…';
		}else{
			$excerpt = $stripped;
		}
		if($this->getExtension() === 'html' || $this->getExtension() === 'xhtml'){
			$excerpt = '<p>' . $excerpt . '</p>';
		}
		return $excerpt;
	}
	public function getExtension(){
		return $this->extension ?? $this->file->getExtension();
	}
	public function getFile(){
		return $this->file;
	}
	public function setFile(File $file){
		$this->file = $file;
		$this->build(true);
	}
	public function getGuid(){
		return $this->guid;
	}
	public function getHasMore(){
		if(!isset($this->hasMore)){
			$this->hasMore = $this->getExcerpt() !== $this->getContent();
		}
		return $this->hasMore;
	}
	public function getId(){
		return $this->id;
	}
	public function getThumbPath(){
		if($this->thumbnail){
			if(!is_string($this->thumbnail) && is_callable($this->thumbnail)){
				$this->thumbnail = call_user_func($this->thumbnail, $this);
			}
			//--path relative to media folder unless leading slash
			if(empty($this->thumbnail) || substr($this->thumbnail, 0, 1) === '/'){
				return $this->thumbnail;
			}else{
				return $this->mediaPath . '/' . $this->thumbnail;
			}
		}
	}
	public function getImagePath(){
		if($this->image){
			//--path relative to media folder unless leading slash
			if(substr($this->image, 0, 1) === '/'){
				return $this->image;
			}else{
				return $this->mediaPath . '/' . $this->image;
			}
		}
	}
	public function getImageAlt(){
		return $this->imageAlt;
	}
	public function getModified(){
		return $this->modified;
	}
	public function getName(){
		return $this->name;
	}
	public function getNameIsId(){
		return $this->nameIsId;
	}
	public function getPath(){
		return $this->path;
	}
	public function getPermalinkTitle(){
		return $this->permalinkTitle;
	}
	public function setPath(string $path){
		$this->path = $path;
	}
	public function getTerms(){
		return $this->terms;
	}

	//--helpers
	protected function createDateTime($input){
		if($input instanceof DateTime){
			$date = $input;
		}else{
			if(is_int($input)){
				$date = new DateTime('@' . $input);
			}else{
				$date = new DateTime($input);
			}
		}
		return $date;
	}
}
