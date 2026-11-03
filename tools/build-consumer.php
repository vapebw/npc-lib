<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$source = $root . '/examples/consumer';
if(!is_file($source . '/vendor/autoload.php')){
	throw new RuntimeException('Run composer install --no-dev in examples/consumer first');
}
if(!is_dir($root . '/work/artifacts') && !mkdir($root . '/work/artifacts', 0777, true)){
	throw new RuntimeException('Cannot create artifact directory');
}
$output = $root . '/work/artifacts/NpcLibP0Consumer.phar';
if(is_file($output) && !unlink($output)){
	throw new RuntimeException('Cannot replace consumer PHAR');
}
$phar = new Phar($output);
$phar->startBuffering();
foreach(['plugin.toml', 'composer.json', 'composer.lock'] as $name){
	$phar->addFile($source . '/' . $name, $name);
}
foreach(['src', 'vendor'] as $directory){
	$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source . '/' . $directory, FilesystemIterator::SKIP_DOTS));
	foreach($iterator as $file){
		if(!$file instanceof SplFileInfo || !$file->isFile()){
			continue;
		}
		if($file->isLink()){
			throw new RuntimeException('Symlinks cannot be bundled');
		}
		$name = str_replace('\\', '/', substr($file->getPathname(), strlen($source) + 1));
		$phar->addFile($file->getPathname(), $name);
	}
}
$phar->setStub('<?php __HALT_COMPILER();');
$phar->setSignatureAlgorithm(Phar::SHA256);
$phar->stopBuffering();
echo $output . PHP_EOL;

$probe = $root . '/work/artifacts/NpcLibOwnerProbe.phar';
if(is_file($probe) && !unlink($probe)){ throw new RuntimeException('Cannot replace owner probe'); }
$owner = new Phar($probe);
$owner->addFile($root . '/examples/owner-probe/plugin.toml', 'plugin.toml');
$owner->addFile($root . '/examples/owner-probe/src/Main.php', 'src/Main.php');
$owner->setStub('<?php __HALT_COMPILER();');
$owner->setSignatureAlgorithm(Phar::SHA256);
