<?php

declare(strict_types=1);

namespace NpcLib\internal\persistence;

use AttachableLogger;
use Closure;
use InvalidArgumentException;
use NpcLib\npc\Npc;
use NpcLib\NpcManager;
use quark\entity\Entity;
use quark\entity\Location;
use quark\scheduler\ClosureTask;
use quark\scheduler\TaskHandler;
use quark\scheduler\TaskScheduler;
use quark\Server;
use Throwable;
use WeakReference;

/** @internal */
final class Restorer{
	private const array BUILTIN = ['player', 'villager', 'zombie'];

	/** @var array<string, class-string> */
	private array $mobs = [];
	/** @var array<int, WeakReference<Entity>> */
	private array $closing = [];
	/** @var array<string, true> */
	private array $unloading = [];
	private bool $running = false;
	/** @var TaskHandler<ClosureTask>|null */
	private ?TaskHandler $retry = null;

	public function __construct(
		private readonly NpcManager $manager,
		private readonly DefinitionStore $store,
		private ?TaskScheduler $scheduler,
		private ?AttachableLogger $logger,
		private ?Closure $onRestore
	){}

	/** @param class-string $class */
	public function registerMob(string $type, string $class) : void{
		if(in_array($type, self::BUILTIN, true) || preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $type) !== 1 || isset($this->mobs[$type])){
			throw new InvalidArgumentException('Invalid or duplicate custom NPC type');
		}
		NpcManager::validateMobClass($class);
		$this->mobs[$type] = $class;
	}

	public function knows(string $type) : bool{ return in_array($type, self::BUILTIN, true) || isset($this->mobs[$type]); }

	/** @return class-string|null */
	public function mobClass(string $type) : ?string{ return $this->mobs[$type] ?? null; }

	public function owns(string $id, Npc $npc) : bool{ return !$this->manager->isClosed() && $this->store->owns($id, $npc); }

	public function notify(string $id, Npc $npc) : void{ ($this->onRestore)?->__invoke($id, $npc); }

	public function isUnloading(string $chunk) : bool{ return isset($this->unloading[$chunk]); }

	public function chunkClosing(string $chunk) : void{
		$this->unloading[$chunk] = true;
		$this->defer();
	}

	public function chunkLoaded(string $chunk) : void{
		unset($this->unloading[$chunk]);
		$this->restore($this->store->dormantIdsIn($chunk), false);
	}

	public function observeClose(Npc $npc) : void{
		$this->closeInFlight(); // drops finished observations
		if($npc->isClosed()){ return; }
		$native = $npc->getLocation()->getWorld()->getEntity($npc->getId());
		if($native !== null){ $this->closing[$native->getId()] = WeakReference::create($native); }
	}

	/** @param list<string> $ids */
	public function restore(array $ids, bool $strict) : void{
		if($this->running || $this->closeInFlight()){
			$this->defer();
			return;
		}
		$this->running = true;
		try{
			foreach($ids as $id){
				if($this->store->isClosed()){ break; }
				// runs inside quark's loader so one bad one can't starve the rest
				try{ $this->restoreOne($id); }
				catch(Throwable $error){
					if($strict){ throw $error; }
					$this->logger?->logException($error);
				}
			}
		}finally{ $this->running = false; }
	}

	public function dispose() : void{
		$this->mobs = []; $this->onRestore = null; $this->logger = null;
		$this->retry?->cancel(); $this->retry = null; $this->scheduler = null;
		$this->closing = []; $this->unloading = [];
	}

	private function restoreOne(string $id) : void{
		$state = $this->store->state($id);
		if($state === null || $this->store->failed($id) || !$this->knows($state['type'])){ return; }
		$revision = $this->store->revision($id);
		$p = $state['position'];
		$x = ((int) floor($p[0])) >> 4;
		$z = ((int) floor($p[2])) >> 4;
		if($this->isUnloading(DefinitionStore::chunkKey($state['world'], $x, $z))){ return; }
		$world = Server::getInstance()->getWorldManager()->getWorldByName($state['world']);
		if($world === null || !$world->isChunkLoaded($x, $z)){ return; }
		$location = new Location($p[0], $p[1], $p[2], $world, $p[3], $p[4]);
		$npc = StateCodec::spawn($this->manager, $location, $state, $this->mobClass($state['type']));
		// native spawn hooks can delete or replace this definition
		if($this->manager->isClosed() || $this->store->revision($id) !== $revision){
			$npc->close();
			return;
		}
		$this->store->wake($id, $npc, $state['type']);
		try{
			$this->notify($id, $npc);
		}catch(Throwable $error){
			// keep the definition so a fixed callback can retry
			if($this->owns($id, $npc)){
				$this->store->park($id, $npc, $state);
				$this->observeClose($npc);
				$npc->close();
			}
			throw $error;
		}
	}

	private function defer() : void{
		// one retry at a time, also covers cancelled unloads and nested loads
		$this->retry ??= $this->scheduler?->scheduleDelayedTask(new ClosureTask(function() : void{
			$this->retry = null;
			if(!$this->store->isClosed()){
				$this->unloading = [];
				$this->restore($this->store->dormantIds(), false);
			}
		}), 1);
	}

	private function closeInFlight() : bool{
		foreach($this->closing as $id => $weak){
			$entity = $weak->get();
			if($entity === null || $entity->isClosed()){ unset($this->closing[$id]); }
		}
		return $this->closing !== [];
	}
}
