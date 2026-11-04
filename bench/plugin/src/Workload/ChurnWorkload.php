<?php

declare(strict_types=1);

namespace NpcLibBench\Workload;

use Closure;
use NpcLibBench\Kind;
use NpcLibBench\Population\Population;
use quark\entity\Location;

/** Removes the oldest entities and spawns the same number again every tick, keeping the population size. */
final class ChurnWorkload implements Workload{
	private int $created = 0;
	private int $removed = 0;
	private int $cursor = 0;

	/** @param Closure(int): Location $location */
	public function __construct(private readonly Population $population, private readonly Closure $location, private readonly string $type, private readonly int $perTick){}

	public function prepare() : void{ $this->cursor = $this->population->count(); }

	public function step() : void{
		$slots = $this->population->slots();
		for($i = 0; $i < $this->perTick && isset($slots[$i]); ++$i){
			$this->population->remove($slots[$i]);
			++$this->removed;
		}
		for($i = 0; $i < $this->perTick; ++$i){
			$index = $this->cursor++;
			$this->population->spawn(Kind::forIndex($this->type, $index), ($this->location)($index));
			++$this->created;
		}
	}

	public function report() : array{ return ['created' => $this->created, 'removed' => $this->removed]; }
}
