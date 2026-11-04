<?php

declare(strict_types=1);

namespace NpcLibBench;

use InvalidArgumentException;

enum Kind : string{
	case Player = 'player';
	case Villager = 'villager';
	case Zombie = 'zombie';

	public static function forIndex(string $type, int $index) : self{
		return match($type){
			'player' => self::Player,
			'villager' => self::Villager,
			'zombie' => self::Zombie,
			'mixed' => self::cases()[$index % 3],
			default => throw new InvalidArgumentException('Unknown entity type ' . $type),
		};
	}

	public function hasArmor() : bool{ return $this !== self::Villager; }
}
