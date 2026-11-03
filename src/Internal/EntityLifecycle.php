<?php

declare(strict_types=1);

namespace NpcLib\Internal;

use LogicException;
use pocketmine\math\Vector3;
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
