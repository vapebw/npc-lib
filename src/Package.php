<?php

declare(strict_types=1);

namespace NpcLib;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use RuntimeException;
use SplFileInfo;

final class Package{
	public const string VERSION = '0.1.0';
	public const string QUARK_API = '6.0.0';

	private function __construct(){}

	public static function assertBundledBuild(string $bundledSourceRoot) : void{
		// same version string can still hide different source so compare file hashes
		$loadedFile = (new ReflectionClass(self::class))->getFileName();
		if($loadedFile === false){ throw new RuntimeException('Cannot identify loaded npc-lib build'); }
		$loaded = self::sourceHashes(dirname($loadedFile));
		$bundled = self::sourceHashes($bundledSourceRoot);
		if($loaded !== $bundled){
			throw new RuntimeException('Incompatible npc-lib source build: plugins must bundle identical files');
		}
	}

	/** @return array<string, string> */
	private static function sourceHashes(string $root) : array{
		if(!is_dir($root)){ throw new RuntimeException('Missing bundled npc-lib source directory'); }
		$result = [];
		foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file){
			if(!$file instanceof SplFileInfo){ continue; }
			if(!$file->isFile() || strtolower($file->getExtension()) !== 'php'){ continue; }
			$name = str_replace('\\', '/', substr($file->getPathname(), strlen(rtrim($root, '/\\')) + 1));
			$hash = hash_file('sha256', $file->getPathname());
			if($hash === false){ throw new RuntimeException('Cannot fingerprint npc-lib source ' . $name); }
			$result[$name] = $hash;
		}
		if(!isset($result['Package.php'])){ throw new RuntimeException('Invalid npc-lib source tree'); }
		ksort($result);
		return $result;
	}
}
