<?php

declare(strict_types=1);

$root = dirname(__DIR__);
foreach(['src', 'tests', 'tools', 'examples/consumer/src', 'examples/owner-probe/src'] as $directory){
	$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS));
	foreach($iterator as $file){
		if(!$file instanceof SplFileInfo || $file->getExtension() !== 'php'){
			continue;
		}
		passthru(escapeshellarg(PHP_BINARY) . ' -n -l ' . escapeshellarg($file->getPathname()), $status);
		if($status !== 0){
			exit($status);
		}
	}
}
