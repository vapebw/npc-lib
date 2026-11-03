<?php

declare(strict_types=1);

namespace NpcLib\Internal;

use LogicException;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use quark\event\entity\EntityDamageEvent;
use quark\player\Player;

trait EntityLifecycle{
	private int $nativeDepth = 0;
	private bool $closePending = false;
	private bool $stationary = true;

	public function requestClose() : void{
		// native code still needs inventories until the outer call returns
		$this->closePending = true;
		if($this->nativeDepth === 0 && !$this->isClosed()){
			$this->close();
		}
	}

	private function finishOperation() : void{
		--$this->nativeDepth;
		if($this->nativeDepth === 0 && $this->closePending && !$this->isClosed()){
			$this->close();
		}
	}

	private function wake() : void{
		if(!$this->isClosed()){ $this->scheduleUpdate(); }
	}

	protected function initEntity(CompoundTag $nbt) : void{
		parent::initEntity($nbt);
		// sleeping entities never tick effects or sync metadata
		$this->getEffects()->getEffectAddHooks()->add(function() : void{ $this->wake(); });
		$this->getEffects()->getEffectRemoveHooks()->add(function() : void{ $this->wake(); });
	}

	public function setFireTicks(int $fireTicks) : void{
		parent::setFireTicks($fireTicks);
		if($fireTicks > 0){ $this->wake(); }
	}

	public function setNameTag(string $name) : void{ parent::setNameTag($name); $this->wake(); }
	public function setNameTagVisible(bool $value = true) : void{ parent::setNameTagVisible($value); $this->wake(); }
	public function setNameTagAlwaysVisible(bool $value = true) : void{ parent::setNameTagAlwaysVisible($value); $this->wake(); }
	public function setScoreTag(string $score) : void{ parent::setScoreTag($score); $this->wake(); }
	public function setScale(float $value) : void{ parent::setScale($value); $this->wake(); }
	public function setInvisible(bool $value = true) : void{ parent::setInvisible($value); $this->wake(); }
	public function setSilent(bool $value = true) : void{ parent::setSilent($value); $this->wake(); }
	public function setSneaking(bool $value = true) : void{ parent::setSneaking($value); $this->wake(); }

	public function setStationary(bool $value) : void{
		$this->stationary = $value;
		$this->setHasGravity(!$value);
		$this->setNoClientPredictions($value);
		$this->setMotion(Vector3::zero());
		if(!$this->isClosed()){
			$this->scheduleUpdate();
		}
	}

	public function isStationary() : bool{
		return $this->stationary;
	}

	public function hasMovementUpdate() : bool{
		// quark keeps airborne zero-motion entities awake
		return $this->stationary ? $this->forceMovementUpdate : parent::hasMovementUpdate();
	}

	public function canSaveWithChunk() : bool{
		return false;
	}

	public function setCanSaveWithChunk(bool $value) : void{
		if($value){ throw new LogicException('NPCs are transient and cannot be saved with chunks'); }
		parent::setCanSaveWithChunk(false);
	}

	public function setMotion(Vector3 $motion) : bool{
		++$this->nativeDepth;
		try{
			return parent::setMotion($this->stationary ? Vector3::zero() : $motion);
		}finally{ $this->finishOperation(); }
	}

	public function attack(EntityDamageEvent $source) : void{
		if($this->closePending || $this->isClosed()){ $source->cancel(); return; }
		++$this->nativeDepth;
		try{
			parent::attack($source);
			if(!$source->isCancelled() && !$this->isClosed()){ $this->scheduleUpdate(); }
		}finally{ $this->finishOperation(); }
	}

	public function kill() : void{
		++$this->nativeDepth;
		try{ parent::kill(); }finally{ $this->finishOperation(); }
	}

	public function setHealth(float $amount) : void{
		++$this->nativeDepth;
		try{
			// native health initialization skips kill before the first update
			if($this->justCreated && $this->isAlive() && $amount <= 0){
				$this->kill();
				$amount = $this->getHealth();
			}
			parent::setHealth($amount);
		}finally{ $this->finishOperation(); }
	}

	public function onUpdate(int $currentTick) : bool{
		++$this->nativeDepth;
		try{
			$previousUpdate = $this->lastUpdate;
			$updated = parent::onUpdate($currentTick);
			// dead entities bypass the base tick that clears this flag; a same-tick call exits before the spawn event
			if($this->justCreated && !$this->isAlive() && $this->lastUpdate !== $previousUpdate){ $this->justCreated = false; }
			return $updated || $this->attackTime > 0 || $this->noDamageTicks > 0;
		}finally{ $this->finishOperation(); }
	}

	public function teleport(Vector3 $pos, ?float $yaw = null, ?float $pitch = null) : bool{
		++$this->nativeDepth;
		try{ return parent::teleport($pos, $yaw, $pitch); }finally{ $this->finishOperation(); }
	}

	public function spawnTo(Player $player) : void{
		if($this->closePending || $this->isClosed()){ return; }
		++$this->nativeDepth;
		try{ parent::spawnTo($player); }finally{ $this->finishOperation(); }
	}
}
