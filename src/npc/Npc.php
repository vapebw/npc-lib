<?php

declare(strict_types=1);

namespace NpcLib\npc;

use Closure;
use InvalidArgumentException;
use LogicException;
use NpcLib\internal\dispatch\NpcCallbacks;
use NpcLib\internal\entity\NpcEntity;
use NpcLib\NpcManager;
use pocketmine\math\Vector3;
use quark\entity\Living;
use quark\entity\Location;
use quark\event\entity\EntityDamageByEntityEvent;
use quark\event\entity\EntityDamageEvent;
use quark\event\entity\EntityDeathEvent;
use quark\event\player\PlayerEntityInteractEvent;
use quark\player\Player;

abstract class Npc{
	public const float MAX_SCALE = 16.0;

	private readonly int $id;
	private ?NpcManager $manager;
	private (Living&NpcEntity)|null $entity;
	private readonly NpcCallbacks $callbacks;

	/** @internal */
	public function __construct(NpcManager $manager, Living&NpcEntity $entity){
		if($manager->isClosed() || $entity->isClosed()){ throw new LogicException('NPC requires an active owner and entity'); }
		$this->manager = $manager;
		$this->entity = $entity;
		$this->id = $entity->getId();
		$this->callbacks = new NpcCallbacks();
	}

	final public function getId() : int{ return $this->id; }
	final public function isClosed() : bool{ return $this->entity === null || $this->entity->isClosed(); }
	final public function close() : void{ $this->manager?->remove($this->id); }

	protected function requireEntity() : Living&NpcEntity{
		if($this->entity === null || $this->entity->isClosed()){ throw new LogicException('NPC is closed'); }
		return $this->entity;
	}

	final public function getName() : string{ return $this->requireEntity()->getNameTag(); }
	final public function setName(string $name) : void{ $this->requireEntity()->setNameTag($name); }
	final public function setNameVisible(bool $visible) : void{
		$entity = $this->requireEntity();
		$entity->setNameTagVisible($visible);
		$entity->setNameTagAlwaysVisible($visible);
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
	final public function getScale() : float{ return $this->requireEntity()->getScale(); }
	final public function setScale(float $scale) : void{
		if(!is_finite($scale) || $scale <= 0 || $scale > self::MAX_SCALE){ throw new InvalidArgumentException('Scale must be finite in (0, ' . self::MAX_SCALE . ']'); }
		$this->requireEntity()->setScale($scale);
	}
	final public function isInvisible() : bool{ return $this->requireEntity()->isInvisible(); }
	final public function setInvisible(bool $value) : void{ $this->requireEntity()->setInvisible($value); }
	final public function getScoreTag() : ?string{ return $this->requireEntity()->getScoreTag(); }
	final public function setScoreTag(string $tag) : void{ $this->requireEntity()->setScoreTag($tag); }

	final public function setRotation(float $yaw, float $pitch) : void{
		if(!is_finite($yaw) || !is_finite($pitch) || $pitch < -90.0 || $pitch > 90.0){ throw new InvalidArgumentException('Rotation must be finite with pitch in [-90, 90]'); }
		$yaw = fmod(fmod($yaw, 360.0) + 360.0, 360.0);
		$this->requireEntity()->setRotation($yaw >= 360.0 ? 0.0 : $yaw, $pitch);
	}

	final public function lookAt(Vector3 $target) : void{
		if(!is_finite($target->x) || !is_finite($target->y) || !is_finite($target->z)){ throw new InvalidArgumentException('Target must be finite'); }
		$entity = $this->requireEntity();
		$location = $entity->getLocation();
		$eye = $location->y + $entity->getEyeHeight();
		if($target->x === $location->x && $target->z === $location->z){
			if($target->y === $eye){ throw new InvalidArgumentException('Target coincides with the NPC eye position'); }
			$entity->setRotation($location->yaw, $target->y > $eye ? -90.0 : 90.0);
			return;
		}
		$entity->lookAt($target);
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
	final public function setInvulnerable(bool $value) : void{ $this->requireEntity(); $this->callbacks->invulnerable = $value; }
	final public function isInvulnerable() : bool{ $this->requireEntity(); return $this->callbacks->invulnerable; }
	final public function setCancelInteractions(bool $value) : void{ $this->requireEntity(); $this->callbacks->cancelInteractions = $value; }
	final public function setCancelDamage(bool $value) : void{ $this->requireEntity(); $this->callbacks->cancelDamage = $value; }

	/** @param null|Closure(Npc, Player, PlayerEntityInteractEvent): void $callback */
	final public function onInteract(?Closure $callback) : void{ $this->requireEntity(); $this->callbacks->onInteract = $callback; }
	/** @param null|Closure(Npc, ?Player, EntityDamageByEntityEvent): void $callback */
	final public function onAttack(?Closure $callback) : void{ $this->requireEntity(); $this->callbacks->onAttack = $callback; }
	/** @param null|Closure(Npc, EntityDamageEvent): void $callback */
	final public function onDamage(?Closure $callback) : void{ $this->requireEntity(); $this->callbacks->onDamage = $callback; }
	/** @param null|Closure(Npc, EntityDeathEvent): void $callback */
	final public function onDeath(?Closure $callback) : void{ $this->requireEntity(); $this->callbacks->onDeath = $callback; }

	/** @internal */
	final public function callbacks() : NpcCallbacks{ return $this->callbacks; }

	/** @internal */
	final public function release(bool $closeEntity) : void{
		$entity = $this->entity;
		$this->entity = null;
		$this->manager = null;
		$this->callbacks->clear();
		if($closeEntity){ $entity?->requestClose(); }
	}
}
