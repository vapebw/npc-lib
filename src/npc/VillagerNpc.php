<?php

declare(strict_types=1);

namespace NpcLib\npc;

use LogicException;
use NpcLib\internal\entity\VillagerEntity;

final class VillagerNpc extends EntityNpc{
	protected function requireEntity() : VillagerEntity{
		$entity = parent::requireEntity();
		if(!$entity instanceof VillagerEntity){ throw new LogicException('Expected villager NPC entity'); }
		return $entity;
	}

	public function getProfession() : int{ return $this->requireEntity()->getProfession(); }
	public function setProfession(int $profession) : void{ $this->requireEntity()->setProfession($profession); }
}
