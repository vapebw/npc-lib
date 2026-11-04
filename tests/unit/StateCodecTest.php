<?php

declare(strict_types=1);

namespace NpcLibTests\Unit;

use NpcLib\internal\persistence\StateCodec;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

final class StateCodecTest extends TestCase{
	/** @return array<string, mixed> */
	private function villager() : array{
		return [
			'type' => 'villager', 'world' => 'world', 'position' => [1.5, 64.0, -2.5, 90.0, 0.0],
			'name' => 'Guide', 'score' => '', 'scale' => 1.0,
			'flags' => [true, false, true, false, true, false], 'cancelDamage' => true,
			'profession' => 2, 'sneaking' => false, 'skin' => [], 'items' => []
		];
	}

	/** @param array<string, mixed> $changes */
	private function reject(array $changes) : void{
		$this->expectException(UnexpectedValueException::class);
		StateCodec::validate($changes + $this->villager());
	}

	public function testValidRecordRoundTrips() : void{
		$state = StateCodec::validate($this->villager());
		self::assertSame('Guide', $state['name']);
		self::assertSame(2, $state['profession']);
	}

	public function testInvalidUtf8NameIsRejected() : void{
		$this->reject(['name' => "bad\xff"]);
	}

	public function testOversizedNameIsRejected() : void{
		$this->reject(['name' => str_repeat('x', 4097)]);
	}

	public function testNonFinitePositionIsRejected() : void{
		$this->reject(['position' => [INF, 64.0, 0.0, 0.0, 0.0]]);
	}

	public function testOutOfRangeScaleIsRejected() : void{
		$this->reject(['scale' => 0.0]);
	}

	public function testWorldNameCannotEscapeTheWorldsFolder() : void{
		$this->reject(['world' => '../escape']);
	}

	public function testPayloadMustMatchTheType() : void{
		$this->reject(['items' => ['']]);
	}

	public function testUnknownProfessionIsRejected() : void{
		$this->reject(['profession' => 9]);
	}
}
