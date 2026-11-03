<?php

declare(strict_types=1);

namespace NpcLib;

use LogicException;
use NpcLib\Internal\PlayerEntity;
use quark\entity\Skin;

final class PlayerNpc extends Npc{
	protected function requireEntity() : PlayerEntity{
		$entity = parent::requireEntity();
		if(!$entity instanceof PlayerEntity){ throw new LogicException('Expected player NPC entity'); }
		return $entity;
	}

	public function getSkin() : Skin{ return $this->requireEntity()->getSkin(); }
	public function setSkin(Skin $skin) : void{
		$entity = $this->requireEntity();
		$entity->setSkin($skin);
		$entity->sendSkin();
	}
}
