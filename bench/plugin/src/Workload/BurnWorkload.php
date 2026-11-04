<?php

declare(strict_types=1);

namespace NpcLibBench\Workload;

/** Busy-waits a fixed time every tick. Only used to check that the probes report a known cost correctly. */
final class BurnWorkload implements Workload{
	private int $ticks = 0;

	public function __construct(private readonly float $milliseconds){}

	public function prepare() : void{}

	public function step() : void{
		$until = hrtime(true) + (int) ($this->milliseconds * 1e6);
		while(hrtime(true) < $until){}
		++$this->ticks;
	}

	public function report() : array{ return ['ticks' => $this->ticks, 'burn_ms' => $this->milliseconds]; }
}
