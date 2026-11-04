<?php

declare(strict_types=1);

namespace NpcLib\internal\entity;

use NpcLib\entity\EntityLifecycle;
use NpcLib\entity\NpcEntity;
use quark\entity\NeverSavedWithChunkEntity;
use quark\entity\Villager;

final class VillagerEntity extends Villager implements NpcEntity, NeverSavedWithChunkEntity{
	use EntityLifecycle;

	public function setProfession(int $profession) : void{
		parent::setProfession($profession);
		$this->wake();
	}
}
