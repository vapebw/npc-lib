<?php

declare(strict_types=1);

namespace NpcLib\Internal;

use quark\entity\Human;
use quark\entity\NeverSavedWithChunkEntity;

final class PlayerEntity extends Human implements NeverSavedWithChunkEntity{
	use EntityLifecycle;
}
