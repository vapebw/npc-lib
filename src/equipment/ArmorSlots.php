<?php

declare(strict_types=1);

namespace NpcLib\equipment;

use InvalidArgumentException;
use quark\inventory\ArmorInventory;
use quark\item\Item;
use quark\item\VanillaItems;

trait ArmorSlots{
	public function getHelmet() : Item{ return $this->requireEntity()->getArmorInventory()->getHelmet(); }
	public function getChestplate() : Item{ return $this->requireEntity()->getArmorInventory()->getChestplate(); }
	public function getLeggings() : Item{ return $this->requireEntity()->getArmorInventory()->getLeggings(); }
	public function getBoots() : Item{ return $this->requireEntity()->getArmorInventory()->getBoots(); }
	public function setHelmet(?Item $item) : void{ $this->setArmorSlot(ArmorInventory::SLOT_HEAD, $item); }
	public function setChestplate(?Item $item) : void{ $this->setArmorSlot(ArmorInventory::SLOT_CHEST, $item); }
	public function setLeggings(?Item $item) : void{ $this->setArmorSlot(ArmorInventory::SLOT_LEGS, $item); }
	public function setBoots(?Item $item) : void{ $this->setArmorSlot(ArmorInventory::SLOT_FEET, $item); }

	private function applyArmor(Equipment $equipment) : void{
		$this->requireEntity();
		$slots = array_filter([ArmorInventory::SLOT_HEAD => $equipment->helmet, ArmorInventory::SLOT_CHEST => $equipment->chestplate, ArmorInventory::SLOT_LEGS => $equipment->leggings, ArmorInventory::SLOT_FEET => $equipment->boots], static fn(?Item $item) : bool => $item !== null);
		foreach($slots as $slot => $item){ $this->validateArmorSlot($slot, $item); }
		foreach($slots as $slot => $item){ $this->setArmorSlot($slot, $item); }
	}

	private function validateArmorSlot(int $slot, Item $item) : void{
		$inventory = $this->requireEntity()->getArmorInventory();
		if($item->isNull()){ return; }
		foreach($inventory->getSlotValidators() as $validator){
			$error = $validator->validate($inventory, $item, $slot);
			if($error !== null){ throw new InvalidArgumentException($error->getMessage(), 0, $error); }
		}
	}

	private function setArmorSlot(int $slot, ?Item $item) : void{
		$entity = $this->requireEntity();
		$inventory = $entity->getArmorInventory();
		$item ??= VanillaItems::AIR();
		$this->validateArmorSlot($slot, $item);
		$entity->guarded(static fn() => $inventory->setItem($slot, $item));
	}
}
