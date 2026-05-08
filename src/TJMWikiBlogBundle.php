<?php
namespace TJM\WikiBlog;
use Symfony\Component\HttpKernel\Bundle\Bundle;

class TJMWikiBlogBundle extends Bundle{
	//-@ force use new directory structure
	public function getPath(): string{
		return \dirname(__DIR__);
	}
}
