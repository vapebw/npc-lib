<?php

declare(strict_types=1);

namespace NpcLibBench\Probe;

use quark\Server;

/**
 * Per-tick processing time from Quark's own Pulse profiler, which stores at most 1200 ticks per session.
 * Long windows call rollover() so earlier ticks are harvested before the ring overwrites them.
 */
final class TickProbe{
	/** @var list<array{int, int}> absolute start (ns) and duration (ns) of each tick */
	private array $ticks = [];
	/** @var array<string, array{calls: int, total_ns: int, self_ns: int}> */
	private array $zones = [];
	private int $count = 0;
	private int $totalNs = 0;
	private bool $paused = false;
	private int $gaps = 0;

	public function __construct(private readonly Server $server, private readonly bool $enabled){}

	public function start() : void{
		if($this->enabled){ $this->server->getPulse()->start(0); }
	}

	public function rollover() : void{
		if(!$this->enabled || $this->paused){ return; }
		$this->harvest();
		$this->paused = true;
		++$this->gaps;
	}

	/** Restarts a paused session once Quark's worker controls are idle. */
	public function resume() : void{
		if($this->paused && $this->server->getPulse()->getPendingOperations() === 0){
			$this->server->getPulse()->start(0);
			$this->paused = false;
		}
	}

	/** @return array{enabled: bool, tick_count: int, tick_total_ns: int, gaps: int, ticks: list<array{int, int}>, zones: array<string, array{calls: int, total_ns: int, self_ns: int}>} */
	public function stop() : array{
		if($this->enabled && !$this->paused){ $this->harvest(); }
		uasort($this->zones, static fn(array $a, array $b) : int => $b['self_ns'] <=> $a['self_ns']);
		return [
			'enabled' => $this->enabled,
			'tick_count' => $this->count,
			'tick_total_ns' => $this->totalNs,
			'gaps' => $this->gaps,
			'ticks' => $this->ticks,
			'zones' => array_slice($this->zones, 0, 40, true),
		];
	}

	private function harvest() : void{
		$pulse = $this->server->getPulse();
		$session = $pulse->getSession();
		$pulse->stop();
		if($session === null){ return; }
		$capture = $session->getCapture();
		$stats = $session->getStats();
		$this->count += $stats['tick_count'];
		$this->totalNs += $stats['tick_total_ns'];
		foreach($capture['ticks'] as [, $offset, $duration]){ $this->ticks[] = [$capture['started_ns'] + $offset, $duration]; }
		foreach($capture['nodes'] as $node){
			$name = $capture['zones'][$node[1]];
			$zone = $this->zones[$name] ?? ['calls' => 0, 'total_ns' => 0, 'self_ns' => 0];
			$this->zones[$name] = ['calls' => $zone['calls'] + $node[3], 'total_ns' => $zone['total_ns'] + $node[4], 'self_ns' => $zone['self_ns'] + $node[5]];
		}
	}
}
