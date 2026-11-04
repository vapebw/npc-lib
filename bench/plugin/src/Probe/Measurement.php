<?php

declare(strict_types=1);

namespace NpcLibBench\Probe;

use quark\plugin\PluginBase;

/** Starts and stops every probe together so all of them cover exactly the same window. */
final class Measurement{
	private readonly CpuProbe $cpu;
	private readonly MemoryProbe $memory;
	private readonly NetworkProbe $network;
	private readonly TickProbe $ticks;
	private float $wallStart = 0.0;

	public function __construct(PluginBase $plugin, bool $countPackets, bool $pulse){
		$this->cpu = new CpuProbe();
		$this->memory = new MemoryProbe();
		$this->network = new NetworkProbe($plugin, $countPackets);
		$this->ticks = new TickProbe($plugin->getServer(), $pulse);
	}

	public function start() : void{
		$this->wallStart = microtime(true);
		$this->memory->start();
		$this->network->start();
		$this->ticks->start();
		$this->cpu->start();
	}

	public function rollover() : void{ $this->ticks->rollover(); }
	public function resume() : void{ $this->ticks->resume(); }

	/** @return array<string, mixed> */
	public function stop() : array{
		$cpu = $this->cpu->stop();
		$wallEnd = microtime(true);
		return [
			'wall_start' => $this->wallStart,
			'wall_end' => $wallEnd,
			'cpu' => $cpu,
			'memory' => $this->memory->stop(),
			'network' => $this->network->stop(),
			'ticks' => $this->ticks->stop(),
		];
	}
}
