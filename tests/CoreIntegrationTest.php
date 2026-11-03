<?php

declare(strict_types=1);

namespace NpcLibTests;

use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CoreIntegrationTest extends TestCase{
	public function testCoreInsideRealQuark() : void{
		$root = dirname(__DIR__);
		$name = 'phpunit-' . PHP_VERSION_ID;
		$log = $root . '/work/phpunit-runtime-' . PHP_VERSION_ID . '.log';
		$process = proc_open(['python', $root . '/tools/test-runtime.py', '--name', $name, '--suite'], [0 => ['pipe', 'r'], 1 => ['file', $log, 'w'], 2 => ['file', $log . '.err', 'w']], $pipes, $root);
		if(!is_resource($process)){ throw new RuntimeException('Cannot launch real Quark test'); }
		fclose($pipes[0]);
		$status = proc_close($process);
		self::assertSame(0, $status, (string) file_get_contents($log) . (string) file_get_contents($log . '.err'));
		$suite = json_decode((string) file_get_contents($root . '/work/integration-runs/' . $name . '/suite.json'), true, flags: JSON_THROW_ON_ERROR);
		self::assertIsArray($suite);
		self::assertSame('PASS', $suite['result']);
	}
}
