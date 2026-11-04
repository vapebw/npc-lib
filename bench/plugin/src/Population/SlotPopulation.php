<?php

declare(strict_types=1);

namespace NpcLibBench\Population;

use LogicException;
use NpcLibBench\Kind;

abstract class SlotPopulation implements Population{
	/** @var array<int, Kind> */
	private array $kinds = [];
	private int $next = 0;

	final protected function register(Kind $kind) : int{
		$slot = $this->next++;
		$this->kinds[$slot] = $kind;
		return $slot;
	}

	final protected function unregister(int $slot) : void{ unset($this->kinds[$slot]); }

	final public function count() : int{ return count($this->kinds); }
	final public function slots() : array{ return array_keys($this->kinds); }
	final public function kind(int $slot) : Kind{ return $this->kinds[$slot] ?? throw new LogicException('Unknown slot ' . $slot); }

	final public function removeAll() : void{
		foreach($this->slots() as $slot){ $this->remove($slot); }
	}
}
