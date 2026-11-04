<?php

declare(strict_types=1);

namespace NpcLibBench\Probe;

/** PHP heap figures: used is what the program holds, allocated is what the allocator reserved. */
final class MemoryProbe{
	private int $gcRuns = 0;
	private int $gcCollected = 0;

	public function start() : void{
		memory_reset_peak_usage();
		$gc = gc_status();
		$this->gcRuns = $gc['runs'];
		$this->gcCollected = $gc['collected'];
	}

	/** @return array{used: int, allocated: int} */
	public static function snapshot() : array{ return ['used' => memory_get_usage(false), 'allocated' => memory_get_usage(true)]; }

	/** @return array{used: int, allocated: int, peak_used: int, peak_allocated: int, gc_runs: int, gc_collected: int} */
	public function stop() : array{
		$gc = gc_status();
		return [
			...self::snapshot(),
			'peak_used' => memory_get_peak_usage(false),
			'peak_allocated' => memory_get_peak_usage(true),
			'gc_runs' => $gc['runs'] - $this->gcRuns,
			'gc_collected' => $gc['collected'] - $this->gcCollected,
		];
	}
}
