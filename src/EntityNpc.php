<?php

declare(strict_types=1);

namespace NpcLib;

use LogicException;
use NpcLib\Internal\VillagerEntity;

final class EntityNpc extends Npc{
	protected function requireEntity() : VillagerEntity{
		$entity = parent::requireEntity();
		if(!$entity instanceof VillagerEntity){ throw new LogicException('Expected villager NPC entity'); }
		return $entity;
	}

	public function getProfession() : int{ return $this->requireEntity()->getProfession(); }
	public function setProfession(int $profession) : void{
		$entity = $this->requireEntity();
		$entity->setProfession($profession);
		$entity->scheduleUpdate();
	}
}
