<?php
namespace TJM\WikiBlog\Command;
use DateTime;
use Exception;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Question\ChoiceQuestion;
use TJM\WikiBlog\Blog;

class PublishPostCommand extends Command{
	static public $defaultName = 'blog:publish';
	protected Blog $blog;
	public function __construct(Blog $blog){
		$this->blog = $blog;
		parent::__construct();
	}
	protected function configure(){
		$this
			->setAliases(['publish'])
			->setDescription("Publish a blog post.  Will move it into place, set meta, git commit, and push.")
			->addArgument('post', InputArgument::OPTIONAL, 'Path to post.  If not in current path, will look in blog/drafts folder.', 'draft.md')
			->addOption('cats', 'c', InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'Categorize post.')
			->addOption('tags', 't', InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'Tag post.')
		;
	}
	protected function execute(InputInterface $input, OutputInterface $output): int{
		$path = $input->getArgument('post');
		$meta = [];
		$qHelper = $this->getHelper('question');
		$cats = $input->getOption('cats');
		$catOpts = $this->blog->getCatSlugs();
		if(empty($cats)){
			$q = new Question('Categorize (space separated categories): ');
			$q->setAutocompleterValues($catOpts);
			$q->setAutocompleterCallback(function(string $in) use($catOpts){
				if(empty($in)){
					return $catOpts;
				}
				preg_match('/^(.* +)?(.+)$/', $in, $matches);
				$test = $matches[2];
				$opts = array_filter($catOpts, fn($val)=> substr($val, 0, strlen($test)) === $test);
				$opts = preg_filter('/^/', $matches[1], $opts);
				return $opts;
			});
			$output->writeln('Categories: ' . implode(' ', $catOpts));
			$cats = $qHelper->ask($input, $output, $q);
			if(!empty($cats)){
				$cats = preg_split('/[, ]+/', $cats);
			}
		}
		if(!empty($cats)){
			foreach($cats as $cat){
				if(!in_array($cat, $catOpts)){
					throw new Exception("Category {$cat} does not exist currently, options are: " . implode(' ', $catOpts));
				}
			}
			$meta['categories'] = $cats;

		}
		$tags = $input->getOption('tags');
		$tagOpts = $this->blog->getTagSlugs();
		if(empty($tags)){
			$q = new Question('Tag (space separated tags): ');
			$q->setAutocompleterValues($tagOpts);
			$q->setAutocompleterCallback(function(string $in) use($tagOpts){
				if(empty($in)){
					return $tagOpts;
				}
				preg_match('/^(.* +)?(.+)$/', $in, $matches);
				$test = $matches[2];
				$opts = array_filter($tagOpts, fn($val)=> substr($val, 0, strlen($test)) === $test);
				$opts = preg_filter('/^/', $matches[1], $opts);
				return $opts;
			});
			$output->writeln(implode(' ', $tagOpts));
			$tags = $qHelper->ask($input, $output, $q);
			if(!empty($tags)){
				$tags = preg_split('/[, ]+/', $tags);
			}
		}
		if(!empty($tags)){
			foreach($tags as $tag){
				if(!in_array($tag, $tagOpts)){
					throw new Exception("tag {$tag} does not exist currently");
				}
			}
			$meta['tags'] = $tags;
		}
		$this->blog->publish($path, $meta);
		return 0;
	}
}
