<?php

declare(strict_types=1);

namespace NpcLibBench\Workload;

use NpcLibBench\Population\Population;

/**
 * Sends real damage events to a population, optionally with one callback registered per entity.
 * Entities have a huge health pool so none of them dies during the window.
 * `applied` counts the hits that actually lowered health, so arms where damage is silently suppressed show up.
 */
final class DamageWorkload implements Workload{
	private int $cursor = 0;
	private int $hits = 0;
	private int $applied = 0;
	private int $callbacks = 0;

	public function __construct(private readonly Population $population, private readonly bool $withCallbacks, private readonly int $perTick, private readonly bool $wake = true){}

	public function prepare() : void{
		foreach($this->population->slots() as $slot){
			$this->population->prepareForDamage($slot);
			if($this->withCallbacks){ $this->population->onDamage($slot, function() : void{ ++$this->callbacks; }); }
		}
	}

	public function step() : void{
		$slots = $this->population->slots();
		if($slots === []){ return; }
		for($i = 0; $i < $this->perTick; ++$i){
			$slot = $slots[$this->cursor++ % count($slots)];
			$before = $this->population->entity($slot)->getHealth();
			$this->population->damage($slot, 1.0);
			if($this->wake){ $this->population->wake($slot); }
			++$this->hits;
			if($this->population->entity($slot)->getHealth() < $before){ ++$this->applied; }
		}
	}

	public function report() : array{ return ['hits' => $this->hits, 'applied' => $this->applied, 'callbacks' => $this->callbacks]; }
}
