<?php

declare(strict_types=1);

namespace NpcLibTests\Integration;

use PHPUnit\Framework\TestCase;
use RuntimeException;

final class QuarkTest extends TestCase{
	public function testSuitesRunInsideRealQuark() : void{
		$root = dirname(__DIR__, 2);
		if(!is_dir($root . '/work/quark') || !is_file($root . '/work/artifacts/NpcLibHarness.phar')){
			self::markTestSkipped('Quark runtime or harness PHAR not built');
		}
		$name = 'phpunit-' . PHP_VERSION_ID;
		if(!is_dir($root . '/work/logs') && !mkdir($root . '/work/logs', 0777, true)){ throw new RuntimeException('Cannot create log directory'); }
		$log = $root . '/work/logs/phpunit-quark-' . PHP_VERSION_ID . '.log';
		$process = proc_open(['python', $root . '/scripts/run-quark.py', '--name', $name, '--suite'], [0 => ['pipe', 'r'], 1 => ['file', $log, 'w'], 2 => ['file', $log . '.err', 'w']], $pipes, $root);
		if(!is_resource($process)){ throw new RuntimeException('Cannot launch real Quark test'); }
		fclose($pipes[0]);
		$status = proc_close($process);
		self::assertSame(0, $status, (string) file_get_contents($log) . (string) file_get_contents($log . '.err'));
		$suite = json_decode((string) file_get_contents($root . '/work/runs/' . $name . '/lifecycle.json'), true, flags: JSON_THROW_ON_ERROR);
		self::assertIsArray($suite);
		self::assertSame('PASS', $suite['result']);
		$features = json_decode((string) file_get_contents($root . '/work/runs/' . $name . '/features.json'), true, flags: JSON_THROW_ON_ERROR);
		self::assertIsArray($features);
		self::assertSame('PASS', $features['result']);
	}
}
