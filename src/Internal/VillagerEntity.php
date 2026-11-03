<?php

declare(strict_types=1);

namespace NpcLib\Internal;

use quark\entity\NeverSavedWithChunkEntity;
use quark\entity\Villager;

final class VillagerEntity extends Villager implements NeverSavedWithChunkEntity{
	use EntityLifecycle;
}
