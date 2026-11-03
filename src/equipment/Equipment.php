<?php

declare(strict_types=1);

namespace NpcLib\equipment;

use quark\item\Item;

final readonly class Equipment{
	public function __construct(
		public ?Item $mainHand = null,
		public ?Item $offHand = null,
		public ?Item $helmet = null,
		public ?Item $chestplate = null,
		public ?Item $leggings = null,
		public ?Item $boots = null,
	){}
}
