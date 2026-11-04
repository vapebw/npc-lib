<?php

declare(strict_types=1);

namespace NpcLibBench\Workload;

use InvalidArgumentException;
use NpcLibBench\Population\Population;
use quark\entity\Location;
use quark\entity\Skin;
use quark\item\VanillaItems;

/** Applies one kind of visible change to a fixed number of entities per tick, cycling through the population. */
final class MutateWorkload implements Workload{
	private int $cursor = 0;
	private int $applied = 0;
	private int $round = 0;

	public function __construct(private readonly Population $population, private readonly string $operation, private readonly int $perTick, private readonly bool $wake = false){
		if(!in_array($operation, ['equip', 'scale', 'rotate', 'teleport', 'skin'], true)){ throw new InvalidArgumentException('Unknown operation ' . $operation); }
	}

	public function prepare() : void{}

	public function step() : void{
		$slots = $this->population->slots();
		if($slots === []){ return; }
		for($i = 0; $i < $this->perTick; ++$i){
			$slot = $slots[$this->cursor++ % count($slots)];
			if($this->cursor % count($slots) === 0){ ++$this->round; }
			$this->apply($slot);
			if($this->wake){ $this->population->wake($slot); }
		}
	}

	public function report() : array{ return ['operation' => $this->operation, 'applied' => $this->applied]; }

	private function apply(int $slot) : void{
		$odd = $this->round % 2 === 1;
		switch($this->operation){
			case 'equip':
				if(!$this->population->kind($slot)->hasArmor()){ return; }
				$this->population->setHelmet($slot, $odd ? VanillaItems::DIAMOND_HELMET() : VanillaItems::IRON_HELMET());
				break;
			case 'skin':
				$this->population->setSkin($slot, new Skin('bench-' . ($odd ? 'a' : 'b'), str_repeat($odd ? "\x80" : "\xff", 64 * 64 * 4)));
				break;
			case 'scale':
				$this->population->setScale($slot, $odd ? 1.5 : 1.0);
				break;
			case 'rotate':
				$this->population->setRotation($slot, fmod($this->round * 37.0 + $slot, 360.0), 10.0);
				break;
			case 'teleport':
				$at = $this->population->entity($slot)->getLocation();
				$this->population->teleport($slot, new Location($at->x + ($odd ? -0.5 : 0.5), $at->y, $at->z, $at->getWorld(), $at->yaw, $at->pitch));
				break;
		}
		++$this->applied;
	}
}
