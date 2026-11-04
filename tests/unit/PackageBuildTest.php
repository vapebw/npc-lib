<?php

declare(strict_types=1);

namespace NpcLibTests\Unit;

use FilesystemIterator;
use NpcLib\Package;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

final class PackageBuildTest extends TestCase{
	private string $copy;

	protected function setUp() : void{
		$this->copy = sys_get_temp_dir() . '/npc-lib-build-' . bin2hex(random_bytes(8));
		mkdir($this->copy, 0700, true);
		$source = dirname(__DIR__, 2) . '/src';
		foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS)) as $file){
			if(!$file instanceof SplFileInfo){ continue; }
			if(!$file->isFile() || $file->getExtension() !== 'php'){ continue; }
			$relative = substr($file->getPathname(), strlen($source) + 1);
			$destination = $this->copy . '/' . $relative;
			$directory = dirname($destination);
			if(!is_dir($directory)){ mkdir($directory, 0700, true); }
			copy($file->getPathname(), $destination);
		}
	}

	protected function tearDown() : void{
		$entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->copy, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
		foreach($entries as $entry){
			if(!$entry instanceof SplFileInfo){ continue; }
			$entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
		}
		rmdir($this->copy);
	}

	public function testIdenticalBundledBuildPasses() : void{
		Package::assertBundledBuild($this->copy);
		self::assertNotEmpty(scandir($this->copy));
	}

	public function testSameVersionWithModifiedSourceIsRejected() : void{
		$file = $this->copy . '/npc/Npc.php';
		file_put_contents($file, (string) file_get_contents($file) . "\n// Different build with the same VERSION constant\n");
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Incompatible npc-lib source build');
		Package::assertBundledBuild($this->copy);
	}

	public function testMissingBundledSourcesAreRejected() : void{
		$this->expectException(RuntimeException::class);
		Package::assertBundledBuild($this->copy . '/missing');
	}

	public function testExtraPhpFileInBundledBuildIsRejected() : void{
		file_put_contents($this->copy . '/Extra.php', "<?php
");
		$this->expectException(RuntimeException::class);
		Package::assertBundledBuild($this->copy);
	}

	public function testNonPhpFilesDoNotCountAsADifferentBuild() : void{
		file_put_contents($this->copy . '/notes.txt', 'ignored');
		Package::assertBundledBuild($this->copy);
		self::assertFileExists($this->copy . '/notes.txt');
		unlink($this->copy . '/notes.txt');
	}
}
