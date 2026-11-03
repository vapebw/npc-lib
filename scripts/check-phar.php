<?php

declare(strict_types=1);

$path = dirname(__DIR__) . '/work/artifacts/NpcLibHarness.phar';
$phar = new Phar($path);
foreach(['plugin.toml', 'src/Main.php', 'vendor/autoload.php', 'vendor/vapebw/npc-lib/src/Package.php'] as $name){
	if(!isset($phar[$name])){
		throw new RuntimeException('Missing PHAR entry ' . $name);
	}
}
foreach(new RecursiveIteratorIterator($phar) as $entry){
	if(!$entry instanceof SplFileInfo){
		continue;
	}
	$name = str_replace('\\', '/', $entry->getPathname());
	$relative = substr($name, strlen('phar://' . str_replace('\\', '/', $path)) + 1);
	if(preg_match('#(^|/)(work|tests|phpunit|phpstan)/#', $relative) === 1){
		throw new RuntimeException('Development files leaked into PHAR');
	}
}
require 'phar://' . $path . '/vendor/autoload.php';
if(!class_exists(\NpcLib\Package::class)){
	throw new RuntimeException('Library PSR-4 resolution failed');
}
$file = (new ReflectionClass(\NpcLib\Package::class))->getFileName();
if($file === false || !str_starts_with($file, 'phar://') || !str_contains($file, '/vendor/vapebw/npc-lib/')){
	throw new RuntimeException('Library was resolved outside the PHAR');
}
$sourceRoot = dirname(__DIR__) . '/src';
$packaged = 0;
foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sourceRoot, FilesystemIterator::SKIP_DOTS)) as $source){
	if(!$source instanceof SplFileInfo || !$source->isFile()){
		continue;
	}
	$relative = str_replace('\\', '/', substr($source->getPathname(), strlen($sourceRoot) + 1));
	$entry = 'vendor/vapebw/npc-lib/src/' . $relative;
	if(!isset($phar[$entry]) || hash('sha256', $phar[$entry]->getContent()) !== hash_file('sha256', $source->getPathname())){
		throw new RuntimeException('PHAR copy differs from final source ' . $relative);
	}
	++$packaged;
}
foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator('phar://' . $path . '/vendor/vapebw/npc-lib/src', FilesystemIterator::SKIP_DOTS)) as $entry){
	if($entry instanceof SplFileInfo && $entry->isFile()){
		--$packaged;
	}
}
if($packaged !== 0){
	throw new RuntimeException('PHAR contains files not present in final source');
}
echo 'PHAR Composer autoload PASS ' . $file . PHP_EOL;
