<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$arguments = $_SERVER['argv'] ?? [];
if(!is_array($arguments)){ throw new InvalidArgumentException('Command line arguments unavailable'); }
$directory = $arguments[1] ?? null;
$pharName = $arguments[2] ?? null;
if(!is_string($directory) || !is_string($pharName)){
	throw new InvalidArgumentException('Usage: build-plugin.php <plugin directory> <phar name>');
}
$source = $root . '/' . $directory;
$vendored = is_file($source . '/composer.json');
if($vendored && !is_file($source . '/vendor/autoload.php')){
	throw new RuntimeException('Run composer install --no-dev in ' . $directory . ' first');
}
if(!is_dir($root . '/work/artifacts') && !mkdir($root . '/work/artifacts', 0777, true)){
	throw new RuntimeException('Cannot create artifact directory');
}
$output = $root . '/work/artifacts/' . $pharName . '.phar';
if(is_file($output) && !unlink($output)){
	throw new RuntimeException('Cannot replace ' . $pharName . ' PHAR');
}
$phar = new Phar($output);
$phar->startBuffering();
$phar->addFile($source . '/plugin.toml', 'plugin.toml');
$directories = ['src'];
if($vendored){
	$directories[] = 'vendor';
	$phar->addFile($source . '/composer.json', 'composer.json');
	$phar->addFile($source . '/composer.lock', 'composer.lock');
}
foreach($directories as $name){
	$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source . '/' . $name, FilesystemIterator::SKIP_DOTS));
	foreach($iterator as $file){
		if(!$file instanceof SplFileInfo || !$file->isFile()){
			continue;
		}
		if($file->isLink()){
			throw new RuntimeException('Symlinks cannot be bundled');
		}
		$phar->addFile($file->getPathname(), str_replace('\\', '/', substr($file->getPathname(), strlen($source) + 1)));
	}
}
$phar->setStub('<?php __HALT_COMPILER();');
$phar->setSignatureAlgorithm(Phar::SHA256);
$phar->stopBuffering();
echo $output . PHP_EOL;
