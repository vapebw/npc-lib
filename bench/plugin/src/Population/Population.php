<?php

declare(strict_types=1);

namespace NpcLibBench\Population;

use Closure;
use NpcLibBench\Kind;
use quark\entity\Living;
use quark\entity\Location;
use quark\entity\Skin;
use quark\item\Item;

interface Population{
	public function spawn(Kind $kind, Location $at) : int;
	public function remove(int $slot) : void;
	public function removeAll() : void;
	public function count() : int;
	/** @return list<int> */
	public function slots() : array;
	public function kind(int $slot) : Kind;
	public function entity(int $slot) : Living;
	public function setHelmet(int $slot, Item $item) : void;
	public function setSkin(int $slot, Skin $skin) : void;
	public function setScale(int $slot, float $scale) : void;
	public function setRotation(int $slot, float $yaw, float $pitch) : void;
	public function teleport(int $slot, Location $to) : void;
	public function damage(int $slot, float $amount) : void;
	/** Schedules a tick for the entity when the population does not already do it after a hit. */
	public function wake(int $slot) : void;
	public function prepareForDamage(int $slot) : void;
	/** @param Closure(): void $callback */
	public function onDamage(int $slot, Closure $callback) : void;
}
