<?php

declare(strict_types=1);

namespace NpcLibBench;

use quark\entity\Location;
use quark\world\World;
use RuntimeException;

/** Chunk set and deterministic ground-level positions for a layout. */
final class Scene{
	private const SPREAD_RADIUS = 3;
	private const CELLS = 14;

	/** @var list<array{int, int}> */
	private array $chunks = [];

	public function __construct(private readonly World $world, private readonly string $layout, private readonly int $centerX, private readonly int $centerZ){
		if($layout === 'concentrated'){
			$this->chunks = [[$centerX, $centerZ]];
			return;
		}
		for($dx = -self::SPREAD_RADIUS; $dx <= self::SPREAD_RADIUS; ++$dx){
			for($dz = -self::SPREAD_RADIUS; $dz <= self::SPREAD_RADIUS; ++$dz){ $this->chunks[] = [$centerX + $dx, $centerZ + $dz]; }
		}
	}

	/** @return list<array{int, int}> */
	public function chunks() : array{ return $this->chunks; }

	public function location(int $index) : Location{
		[$chunkX, $chunkZ] = $this->chunks[$index % count($this->chunks)];
		$cell = intdiv($index, count($this->chunks));
		$x = $chunkX * 16 + 1 + ($cell % self::CELLS);
		$z = $chunkZ * 16 + 1 + (intdiv($cell, self::CELLS) % self::CELLS);
		$ground = $this->world->getHighestBlockAt($x, $z) ?? throw new RuntimeException('Chunk not generated at ' . $x . ',' . $z);
		return new Location($x + 0.5, $ground + 1, $z + 0.5, $this->world, 0, 0);
	}
}
