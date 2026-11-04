<?php

declare(strict_types=1);

namespace NpcLibBench\Population;

use Closure;
use LogicException;
use NpcLibBench\Kind;
use NpcLibBench\Mode;
use quark\entity\Human;
use quark\entity\Living;
use quark\entity\Location;
use quark\entity\Skin;
use quark\entity\Villager;
use quark\entity\Zombie;
use quark\event\entity\EntityDamageEvent;
use quark\event\EventPriority;
use quark\event\HandlerListManager;
use quark\event\RegisteredListener;
use quark\item\Item;
use quark\plugin\PluginBase;

final class NativePopulation extends SlotPopulation{
	/** @var array<int, Living> */
	private array $entities = [];
	/** @var array<int, Closure(): void> */
	private array $damageCallbacks = [];
	/** @var null|RegisteredListener<EntityDamageEvent> */
	private ?RegisteredListener $listener = null;

	public function __construct(private readonly PluginBase $plugin, private readonly Skin $skin, private readonly Mode $mode){}

	public function spawn(Kind $kind, Location $at) : int{
		$entity = match($kind){
			Kind::Player => new Human($at, $this->skin),
			Kind::Villager => new Villager($at),
			Kind::Zombie => new Zombie($at),
		};
		if($entity instanceof Human){ $entity->getHungerManager()->setEnabled(false); }
		if($this->mode === Mode::NativeNoGravity){ $entity->setHasGravity(false); }
		$entity->setCanSaveWithChunk(false);
		$entity->setNameTag('bench');
		$entity->setNameTagVisible(true);
		$entity->setNameTagAlwaysVisible(true);
		$entity->spawnToAll();
		$slot = $this->register($kind);
		$this->entities[$slot] = $entity;
		return $slot;
	}

	public function remove(int $slot) : void{
		$entity = $this->entity($slot);
		unset($this->entities[$slot], $this->damageCallbacks[$entity->getId()]);
		$this->unregister($slot);
		$entity->close();
		if($this->damageCallbacks === [] && $this->listener !== null){
			HandlerListManager::global()->unregisterAll($this->listener);
			$this->listener = null;
		}
	}

	public function entity(int $slot) : Living{ return $this->entities[$slot] ?? throw new LogicException('Unknown slot ' . $slot); }

	public function setHelmet(int $slot, Item $item) : void{ $this->entity($slot)->getArmorInventory()->setHelmet($item); }
	public function setSkin(int $slot, Skin $skin) : void{
		$entity = $this->entity($slot);
		if(!$entity instanceof Human){ throw new LogicException('Slot has no skin'); }
		$entity->setSkin($skin);
		$entity->sendSkin();
	}

	public function setScale(int $slot, float $scale) : void{ $this->entity($slot)->setScale($scale); }
	public function setRotation(int $slot, float $yaw, float $pitch) : void{ $this->entity($slot)->setRotation($yaw, $pitch); }
	public function teleport(int $slot, Location $to) : void{ $this->entity($slot)->teleport($to); }

	public function damage(int $slot, float $amount) : void{
		$entity = $this->entity($slot);
		$entity->attack(new EntityDamageEvent($entity, EntityDamageEvent::CAUSE_CUSTOM, $amount));
	}

	public function wake(int $slot) : void{ $this->entity($slot)->scheduleUpdate(); }

	public function prepareForDamage(int $slot) : void{
		$entity = $this->entity($slot);
		$entity->setMaxHealth(1000000);
		$entity->setHealth(1000000);
	}

	public function onDamage(int $slot, Closure $callback) : void{
		$this->damageCallbacks[$this->entity($slot)->getId()] = $callback;
		$this->listener ??= $this->plugin->getServer()->getPluginManager()->registerEvent(EntityDamageEvent::class, function(EntityDamageEvent $event) : void{
			($this->damageCallbacks[$event->getEntity()->getId()] ?? null)?->__invoke();
		}, EventPriority::HIGHEST, $this->plugin, true);
	}
}
