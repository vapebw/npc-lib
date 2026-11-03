<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$source = $root . '/tests/harness';
if(!is_file($source . '/vendor/autoload.php')){
	throw new RuntimeException('Run composer install --no-dev in tests/harness first');
}
if(!is_dir($root . '/work/artifacts') && !mkdir($root . '/work/artifacts', 0777, true)){
	throw new RuntimeException('Cannot create artifact directory');
}
$output = $root . '/work/artifacts/NpcLibHarness.phar';
if(is_file($output) && !unlink($output)){
	throw new RuntimeException('Cannot replace harness PHAR');
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

$peerPath = $root . '/work/artifacts/NpcLibPeer.phar';
if(is_file($peerPath) && !unlink($peerPath)){ throw new RuntimeException('Cannot replace peer plugin'); }
$peer = new Phar($peerPath);
$peer->addFile($root . '/tests/peer/plugin.toml', 'plugin.toml');
$peer->addFile($root . '/tests/peer/src/Main.php', 'src/Main.php');
$peer->setStub('<?php __HALT_COMPILER();');
$peer->setSignatureAlgorithm(Phar::SHA256);
