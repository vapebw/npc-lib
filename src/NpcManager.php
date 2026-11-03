<?php

declare(strict_types=1);

namespace NpcLib;

use LogicException;
use NpcLib\Internal\PlayerEntity;
use NpcLib\Internal\VillagerEntity;
use quark\entity\Location;
use quark\entity\Skin;
use quark\event\entity\EntityDamageEvent;
use quark\event\entity\EntityDeathEvent;
use quark\event\entity\EntityDespawnEvent;
use quark\event\EventPriority;
use quark\event\HandlerListManager;
use quark\event\player\PlayerEntityInteractEvent;
use quark\event\plugin\PluginDisableEvent;
use quark\event\RegisteredListener;
use quark\plugin\Plugin;
use quark\Server;
use Throwable;

final class NpcManager{
	/** @var array<int, Npc> */
	private array $npcs = [];
	/** @var list<RegisteredListener<*>> */
	private array $listeners = [];
	private ?Plugin $owner;

	public function __construct(Plugin $owner){
		$this->owner = $owner;
		if(!$owner->isEnabled()){ throw new LogicException('NPC owner must be enabled'); }
		$events = Server::getInstance()->getPluginManager();
		try{
			$this->listeners[] = $events->registerEvent(PluginDisableEvent::class, function(PluginDisableEvent $event) : void{
				if($event->getPlugin() === $this->owner){ $this->close(); }
			}, EventPriority::LOWEST, $owner);
			$this->listeners[] = $events->registerEvent(EntityDespawnEvent::class, function(EntityDespawnEvent $event) : void{
				$id = $event->getEntity()->getId();
				$npc = $this->npcs[$id] ?? null;
				unset($this->npcs[$id]);
				$npc?->release(false);
			}, EventPriority::LOWEST, $owner);
			$this->listeners[] = $events->registerEvent(PlayerEntityInteractEvent::class, function(PlayerEntityInteractEvent $event) use ($owner) : void{
				$npc = $this->get($event->getEntity()->getId());
				if($npc === null){ return; }
				try{ $npc->dispatchInteract($event); }
				catch(Throwable $error){ $event->cancel(); $this->failedCallback($owner, $npc, $error); }
			}, EventPriority::HIGHEST, $owner, true);
			$this->listeners[] = $events->registerEvent(EntityDamageEvent::class, function(EntityDamageEvent $event) use ($owner) : void{
				$npc = $this->get($event->getEntity()->getId());
				if($npc === null){ return; }
				try{ $npc->dispatchDamage($event); }
				catch(Throwable $error){ $event->cancel(); $this->failedCallback($owner, $npc, $error); }
			}, EventPriority::HIGHEST, $owner, true);
			$this->listeners[] = $events->registerEvent(EntityDeathEvent::class, function(EntityDeathEvent $event) use ($owner) : void{
				$npc = $this->get($event->getEntity()->getId());
				if($npc === null){ return; }
				try{ $npc->dispatchDeath($event); }
				catch(Throwable $error){ $this->failedCallback($owner, $npc, $error); }
			}, EventPriority::HIGHEST, $owner);
		}catch(Throwable $error){ $this->close(); throw $error; }
	}

	private function requireOwner() : Plugin{
		if($this->owner === null || !$this->owner->isEnabled()){ throw new LogicException('NPC manager is closed or owner disabled'); }
		return $this->owner;
	}

	public function createPlayer(Location $location, Skin $skin, string $name = '') : PlayerNpc{
		$this->requireOwner();
		$entity = new PlayerEntity($location, $skin);
		$npc = new PlayerNpc($this, $entity);
		$entity->getHungerManager()->setEnabled(false);
		$this->register($npc, $entity, $name);
		return $npc;
	}

	public function createVillager(Location $location, string $name = '') : EntityNpc{
		$this->requireOwner();
		$entity = new VillagerEntity($location);
		$npc = new EntityNpc($this, $entity);
		$this->register($npc, $entity, $name);
		return $npc;
	}

	private function register(Npc $npc, PlayerEntity|VillagerEntity $entity, string $name) : void{
		try{
			$entity->setCanSaveWithChunk(false);
			$entity->setStationary(true);
			$entity->setNameTag($name);
			$entity->setNameTagVisible(true);
			$entity->setNameTagAlwaysVisible(true);
			$this->npcs[$npc->getId()] = $npc;
			$entity->spawnToAll();
			if($npc->isClosed() || $this->isClosed()){ throw new LogicException('NPC was closed during creation'); }
		}catch(Throwable $error){
			unset($this->npcs[$npc->getId()]);
			$npc->release(true);
			throw $error;
		}
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

	public function close() : void{
		$this->owner = null;
		$failure = null;
		try{
			while($this->npcs !== []){
				try{ $this->remove(array_key_first($this->npcs)); }
				catch(Throwable $error){ $failure ??= $error; }
			}
		}finally{
			foreach($this->listeners as $listener){ HandlerListManager::global()->unregisterAll($listener); }
			$this->listeners = [];
		}
		if($failure !== null){ throw $failure; }
	}

	private function failedCallback(Plugin $owner, Npc $npc, Throwable $error) : void{
		$owner->getLogger()->logException($error);
		$npc->close();
	}
}