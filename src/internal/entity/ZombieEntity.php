<?php

declare(strict_types=1);

namespace NpcLib\internal\entity;

use quark\entity\NeverSavedWithChunkEntity;
use quark\entity\Zombie;

final class ZombieEntity extends Zombie implements NpcEntity, NeverSavedWithChunkEntity{
	use EntityLifecycle;
}
