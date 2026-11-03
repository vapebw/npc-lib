<?php

declare(strict_types=1);

namespace NpcLib\npc;

use InvalidArgumentException;
use NpcLib\equipment\ArmorSlots;
use NpcLib\equipment\Equipment;
use quark\entity\animation\ArmSwingAnimation;

final class ZombieNpc extends EntityNpc{
	use ArmorSlots;

	public function swingArm() : void{
		$entity = $this->requireEntity();
		$entity->broadcastAnimation(new ArmSwingAnimation($entity));
	}

	public function equip(Equipment $equipment) : void{
		if($equipment->mainHand !== null || $equipment->offHand !== null){ throw new InvalidArgumentException('Zombie NPCs only support armor'); }
		$this->applyArmor($equipment);
	}
}
