<?php

declare(strict_types=1);

namespace NpcLibHarness;

use NpcLib\internal\entity\EntityLifecycle;
use NpcLib\internal\entity\NpcEntity;
use quark\entity\NeverSavedWithChunkEntity;
use quark\entity\Squid;

final class CustomSquid extends Squid implements NpcEntity, NeverSavedWithChunkEntity{
	use EntityLifecycle;
}
