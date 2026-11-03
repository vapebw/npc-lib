<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$destination = $root . '/work/package';
$archive = new PharData($root . '/work/dist/npc-lib.tar');
if(is_link($destination)){
	throw new RuntimeException('Package staging must not be a symlink');
}
if(is_dir($destination)){
	$resolved = realpath($destination);
	$work = realpath($root . '/work');
	if($resolved === false || $work === false || $resolved !== $work . DIRECTORY_SEPARATOR . 'package'){
		throw new RuntimeException('Staging path escaped workspace');
	}
	$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
	foreach($iterator as $entry){
		if(!$entry instanceof SplFileInfo){
			continue;
		}
		$removed = $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
		if(!$removed){
			throw new RuntimeException('Cannot clean package staging');
		}
	}
}
$archive->extractTo($destination, null, true);
echo 'Package staged in work/package' . PHP_EOL;
