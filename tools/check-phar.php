<?php

declare(strict_types=1);

$path = dirname(__DIR__) . '/work/artifacts/NpcLibP0Consumer.phar';
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
	if(str_contains($relative, '/work/') || str_contains($relative, '/tests/') || str_contains($relative, '/phpunit/') || str_contains($relative, '/phpstan/')){
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
echo 'PHAR Composer autoload PASS ' . $file . PHP_EOL;
