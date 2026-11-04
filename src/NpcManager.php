<?php

declare(strict_types=1);

namespace NpcLib;

use Closure;
use InvalidArgumentException;
use LogicException;
use NpcLib\entity\NpcEntity;
use NpcLib\equipment\Equipment;
use NpcLib\internal\dispatch\NpcEventRouter;
use NpcLib\internal\entity\PlayerEntity;
use NpcLib\internal\entity\VillagerEntity;
use NpcLib\internal\entity\ZombieEntity;
use NpcLib\npc\EntityNpc;
use NpcLib\npc\Npc;
use NpcLib\npc\PlayerNpc;
use NpcLib\npc\VillagerNpc;
use NpcLib\npc\ZombieNpc;
use quark\entity\Human;
use quark\entity\Living;
use quark\entity\Location;
use quark\entity\NeverSavedWithChunkEntity;
use quark\entity\Skin;
use quark\plugin\Plugin;
use ReflectionClass;
use Throwable;

final class NpcManager{
	/** @var array<int, Npc> */
	private array $npcs = [];
	private ?NpcEventRouter $router = null;
	private ?Plugin $owner;

	public function __construct(Plugin $owner){
		if(!$owner->isEnabled()){ throw new LogicException('NPC owner must be enabled'); }
		$this->owner = $owner;
		$this->router = new NpcEventRouter($this, $owner);
	}

	private function requireOwner() : void{
		if($this->owner === null || !$this->owner->isEnabled()){ throw new LogicException('NPC manager is closed or owner disabled'); }
	}

	public function createPlayer(Location $location, Skin $skin, string $name = '', ?Equipment $equipment = null) : PlayerNpc{
		$this->requireOwner();
		$entity = new PlayerEntity($location, $skin);
		return $this->spawn($entity, fn() => new PlayerNpc($this, $entity), $name, static function(PlayerNpc $npc) use ($entity, $equipment) : void{
			$entity->getHungerManager()->setEnabled(false);
			if($equipment !== null){ $npc->equip($equipment); }
		});
	}

	public function createVillager(Location $location, string $name = '') : VillagerNpc{
		$this->requireOwner();
		$entity = new VillagerEntity($location);
		return $this->spawn($entity, fn() => new VillagerNpc($this, $entity), $name);
	}

	public function createZombie(Location $location, string $name = '', ?Equipment $equipment = null) : ZombieNpc{
		$this->requireOwner();
		if($equipment !== null && ($equipment->mainHand !== null || $equipment->offHand !== null)){ throw new InvalidArgumentException('Zombie NPCs only support armor'); }
		$entity = new ZombieEntity($location);
		return $this->spawn($entity, fn() => new ZombieNpc($this, $entity), $name, $equipment === null ? null : static fn(ZombieNpc $npc) => $npc->equip($equipment));
	}

	/** @param class-string $class */
	public function createMob(string $class, Location $location, string $name = '') : EntityNpc{
		$this->requireOwner();
		if(!is_subclass_of($class, Living::class) || !is_subclass_of($class, NpcEntity::class) || !is_subclass_of($class, NeverSavedWithChunkEntity::class) || is_subclass_of($class, Human::class) || !(new ReflectionClass($class))->isInstantiable()){
			throw new InvalidArgumentException('Mob class must be a concrete non-human Living entity implementing ' . NpcEntity::class . ' and ' . NeverSavedWithChunkEntity::class);
		}
		$entity = new $class($location);
		return $this->spawn($entity, fn() => new EntityNpc($this, $entity), $name);
	}

	/**
	 * @template T of Npc
	 * @param Closure(): T $wrap
	 * @param null|Closure(T): void $prepare
	 * @return T
	 */
	private function spawn(Living&NpcEntity $entity, Closure $wrap, string $name, ?Closure $prepare = null) : Npc{
		try{ $npc = $wrap(); }
		catch(Throwable $error){ $entity->requestClose(); throw $error; }
		try{
			$entity->setStationary(true);
			$entity->setNameTag($name);
			$entity->setNameTagVisible(true);
			$entity->setNameTagAlwaysVisible(true);
			$this->npcs[$npc->getId()] = $npc;
			$prepare?->__invoke($npc);
			$entity->spawnToAll();
			if($npc->isClosed() || $this->isClosed()){ throw new LogicException('NPC was closed during creation'); }
		}catch(Throwable $error){
			unset($this->npcs[$npc->getId()]);
			$npc->release(true);
			throw $error;
		}
		return $npc;
	}

	public function get(int $runtimeId) : ?Npc{ return $this->npcs[$runtimeId] ?? null; }
	/** @return array<int, Npc> */
	public function getAll() : array{ return $this->npcs; }
	public function count() : int{ return count($this->npcs); }
	public function isClosed() : bool{ return $this->owner === null; }

	public function remove(int $runtimeId) : bool{
		$npc = $this->npcs[$runtimeId] ?? null;
		if($npc === null){ return false; }
		unset($this->npcs[$runtimeId]);
		$npc->release(true);
		return true;
	}

	/** @internal */
	public function forget(int $runtimeId) : void{
		$npc = $this->npcs[$runtimeId] ?? null;
		unset($this->npcs[$runtimeId]);
		$npc?->release(false);
	}

	public function close() : void{
		$this->owner = null;
		$failure = null;
		try{
			while($this->npcs !== []){
				try{ $this->remove(array_key_first($this->npcs)); }
				catch(Throwable $error){ $failure ??= $error; }
			}
		}finally{
			$this->router?->unregister();
			$this->router = null;
		}
		if($failure !== null){ throw $failure; }
	}
}
