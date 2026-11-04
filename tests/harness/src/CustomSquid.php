<?php

declare(strict_types=1);

namespace NpcLibHarness;

use NpcLib\entity\EntityLifecycle;
use NpcLib\entity\NpcEntity;
use quark\entity\NeverSavedWithChunkEntity;
use quark\entity\Squid;

final class CustomSquid extends Squid implements NpcEntity, NeverSavedWithChunkEntity{
	use EntityLifecycle;
}
