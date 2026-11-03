<?php

declare(strict_types=1);

namespace NpcLib\npc;

use LogicException;
use NpcLib\equipment\ArmorSlots;
use NpcLib\equipment\Equipment;
use NpcLib\internal\entity\PlayerEntity;
use quark\entity\animation\ArmSwingAnimation;
use quark\entity\Skin;
use quark\item\Item;
use quark\item\VanillaItems;

final class PlayerNpc extends Npc{
	use ArmorSlots;

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

	public function swingArm() : void{
		$entity = $this->requireEntity();
		$entity->broadcastAnimation(new ArmSwingAnimation($entity));
	}

	public function isSneaking() : bool{ return $this->requireEntity()->isSneaking(); }
	public function setSneaking(bool $value) : void{ $this->requireEntity()->setSneaking($value); }

	public function getMainHand() : Item{ return $this->requireEntity()->getInventory()->getItemInHand(); }
	public function setMainHand(?Item $item) : void{
		$entity = $this->requireEntity();
		$entity->guarded(static fn() => $entity->getInventory()->setItemInHand($item ?? VanillaItems::AIR()));
	}
	public function getOffHand() : Item{ return $this->requireEntity()->getOffHandInventory()->getItem(0); }
	public function setOffHand(?Item $item) : void{
		$entity = $this->requireEntity();
		$entity->guarded(static fn() => $entity->getOffHandInventory()->setItem(0, $item ?? VanillaItems::AIR()));
	}

	public function equip(Equipment $equipment) : void{
		$this->applyArmor($equipment);
		if($equipment->mainHand !== null){ $this->setMainHand($equipment->mainHand); }
		if($equipment->offHand !== null){ $this->setOffHand($equipment->offHand); }
	}
}
