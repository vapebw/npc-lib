<?php

declare(strict_types=1);

namespace NpcLib;

use Closure;
use InvalidArgumentException;
use LogicException;
use NpcLib\Internal\PlayerEntity;
use NpcLib\Internal\VillagerEntity;
use pocketmine\math\Vector3;
use quark\entity\Location;
use quark\event\entity\EntityDamageByEntityEvent;
use quark\event\entity\EntityDamageEvent;
use quark\event\entity\EntityDeathEvent;
use quark\event\player\PlayerEntityInteractEvent;
use quark\player\Player;

abstract class Npc{
	private readonly int $id;
	private ?NpcManager $manager;
	private PlayerEntity|VillagerEntity|null $entity;
	private bool $invulnerable = true;
	private bool $cancelInteractions = true;
	private bool $cancelDamage = false;
	/** @var null|Closure(Npc, Player, PlayerEntityInteractEvent): void */
	private ?Closure $interact = null;
	/** @var null|Closure(Npc, ?Player, EntityDamageByEntityEvent): void */
	private ?Closure $attack = null;
	/** @var null|Closure(Npc, EntityDamageEvent): void */
	private ?Closure $damage = null;
	/** @var null|Closure(Npc, EntityDeathEvent): void */
	private ?Closure $death = null;

	/** @internal */
	public function __construct(NpcManager $manager, PlayerEntity|VillagerEntity $entity){
		if($manager->isClosed() || $entity->isClosed()){ throw new LogicException('NPC requires an active owner and entity'); }
		$this->manager = $manager;
		$this->entity = $entity;
		$this->id = $entity->getId();
	}

	final public function getId() : int{ return $this->id; }
	final public function isClosed() : bool{ return $this->entity === null || $this->entity->isClosed(); }
	final public function close() : void{ $this->manager?->remove($this->id); }

	protected function requireEntity() : PlayerEntity|VillagerEntity{
		if($this->entity === null || $this->entity->isClosed()){ throw new LogicException('NPC is closed'); }
		return $this->entity;
	}

	final public function getName() : string{ return $this->requireEntity()->getNameTag(); }
	final public function setName(string $name) : void{
		$entity = $this->requireEntity();
		$entity->setNameTag($name);
		$entity->scheduleUpdate();
	}
	final public function setNameVisible(bool $visible) : void{
		$entity = $this->requireEntity();
		$entity->setNameTagVisible($visible);
		$entity->setNameTagAlwaysVisible($visible);
		$entity->scheduleUpdate();
	}
	final public function isNameVisible() : bool{ return $this->requireEntity()->isNameTagVisible(); }
	final public function getLocation() : Location{ return $this->requireEntity()->getLocation(); }
	final public function teleport(Location $location) : bool{ return $this->requireEntity()->teleport($location); }
	final public function getViewerCount() : int{ return count($this->requireEntity()->getViewers()); }
	final public function isStatic() : bool{ return $this->requireEntity()->isStationary(); }
	final public function setStatic(bool $value) : void{ $this->requireEntity()->setStationary($value); }
	final public function setMotion(Vector3 $motion) : bool{
		$entity = $this->requireEntity();
		if($entity->isStationary()){ throw new LogicException('Enable movement before setting motion'); }
		$result = $entity->setMotion($motion);
		if(!$entity->isClosed()){ $entity->scheduleUpdate(); }
		return $result;
	}
	final public function canSaveWithChunk() : bool{ return $this->requireEntity()->canSaveWithChunk(); }
	final public function getHealth() : float{ return $this->requireEntity()->getHealth(); }
	final public function damage(float $amount, int $cause = EntityDamageEvent::CAUSE_CUSTOM) : bool{
		if(!is_finite($amount) || $amount < 0){ throw new InvalidArgumentException('Damage must be finite and nonnegative'); }
		$entity = $this->requireEntity();
		$event = new EntityDamageEvent($entity, $cause, $amount);
		$entity->attack($event);
		return !$event->isCancelled();
	}
	final public function setInvulnerable(bool $value) : void{ $this->requireEntity(); $this->invulnerable = $value; }
	final public function isInvulnerable() : bool{ $this->requireEntity(); return $this->invulnerable; }
	final public function setCancelInteractions(bool $value) : void{ $this->requireEntity(); $this->cancelInteractions = $value; }
	final public function setCancelDamage(bool $value) : void{ $this->requireEntity(); $this->cancelDamage = $value; }

	/** @param null|Closure(Npc, Player, PlayerEntityInteractEvent): void $callback */
	final public function onInteract(?Closure $callback) : void{ $this->requireEntity(); $this->interact = $callback; }
	/** @param null|Closure(Npc, ?Player, EntityDamageByEntityEvent): void $callback */
	final public function onAttack(?Closure $callback) : void{ $this->requireEntity(); $this->attack = $callback; }
	/** @param null|Closure(Npc, EntityDamageEvent): void $callback */
	final public function onDamage(?Closure $callback) : void{ $this->requireEntity(); $this->damage = $callback; }
	/** @param null|Closure(Npc, EntityDeathEvent): void $callback */
	final public function onDeath(?Closure $callback) : void{ $this->requireEntity(); $this->death = $callback; }

	/** @internal */
	final public function dispatchInteract(PlayerEntityInteractEvent $event) : void{
		if($event->isCancelled()){ return; }
		$cancelled = $this->blocksInteractions();
		if($cancelled){ $event->cancel(); }
		try{ ($this->interact)?->__invoke($this, $event->getPlayer(), $event); }
		finally{
			if($cancelled || $this->blocksInteractions() || $this->isClosed()){ $event->cancel(); }
		}
	}

	/** @internal */
	final public function dispatchDamage(EntityDamageEvent $event) : void{
		if($event->isCancelled()){ return; }
		$cancelled = $this->blocksDamage();
		if($cancelled){ $event->cancel(); }
		try{
			if($event instanceof EntityDamageByEntityEvent){
				$cancelled = $this->invokeAttack($event) || $cancelled;
			}
			if(!$this->isClosed()){ ($this->damage)?->__invoke($this, $event); }
		}finally{
			if($cancelled || $this->blocksDamage() || $this->isClosed()){ $event->cancel(); }
		}
	}

	private function invokeAttack(EntityDamageByEntityEvent $event) : bool{
		$damager = $event->getDamager();
		($this->attack)?->__invoke($this, $damager instanceof Player ? $damager : null, $event);
		return $event->isCancelled();
	}

	private function blocksInteractions() : bool{ return $this->cancelInteractions; }
	private function blocksDamage() : bool{ return $this->invulnerable || $this->cancelDamage; }

	/** @internal */
	final public function dispatchDeath(EntityDeathEvent $event) : void{ ($this->death)?->__invoke($this, $event); }

	/** @internal */
	final public function release(bool $closeEntity) : void{
		$entity = $this->entity;
		$this->entity = null;
		$this->manager = null;
		$this->interact = $this->attack = $this->damage = $this->death = null;
		if($closeEntity){ $entity?->requestClose(); }
	}
}
