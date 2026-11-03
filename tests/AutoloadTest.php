<?php

declare(strict_types=1);

namespace NpcLibTests;

use NpcLib\Package;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class AutoloadTest extends TestCase{
	public function testComposerResolvesLibraryFromItsOwnSource() : void{
		self::assertTrue(class_exists(Package::class));
		$file = (new ReflectionClass(Package::class))->getFileName();
		self::assertSame(realpath(__DIR__ . '/../src/Package.php'), $file);
	}
}
