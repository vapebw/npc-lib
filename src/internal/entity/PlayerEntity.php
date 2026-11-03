<?php

declare(strict_types=1);

namespace NpcLib\internal\entity;

use quark\entity\Human;
use quark\entity\NeverSavedWithChunkEntity;

final class PlayerEntity extends Human implements NpcEntity, NeverSavedWithChunkEntity{
	use EntityLifecycle;
}
