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
	private const int DEFAULT_MAX_PER_TICK = 64;
	private const int DEFAULT_MAX_NANOS_PER_TICK = 5_000_000;

	/** @var array<string, class-string> */
	private array $mobs = [];
	/** @var array<int, WeakReference<Entity>> */
	private array $closing = [];
	/** @var array<string, true> */
	private array $unloading = [];
	/** @var array<string, true> */
	private array $closingWorlds = [];
	private bool $running = false;
	/**
	 * Definitions waiting for budget, the one task below drains them
	 * @var array<string, true>
	 */
	private array $queue = [];
	/** @var TaskHandler<ClosureTask>|null */
	private ?TaskHandler $task = null;
	private bool $settleUnloads = false;
	private int $maxPerTick = self::DEFAULT_MAX_PER_TICK;
	private int $maxNanosPerTick = self::DEFAULT_MAX_NANOS_PER_TICK;
	private int $budgetTick = -1;
	private int $spentCount = 0;
	private int $spentNanos = 0;

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

	public function isWorldClosing(string $world) : bool{ return isset($this->closingWorlds[$world]); }

	// quark also closes entities outside any chunk event while a world unloads
	public function worldClosing(string $world) : void{
		$this->closingWorlds[$world] = true;
		$this->defer();
	}

	public function worldOpened(string $world) : void{ unset($this->closingWorlds[$world]); }

	public function chunkLoaded(string $chunk) : void{
		unset($this->unloading[$chunk]);
		$this->enqueue($this->store->dormantIdsIn($chunk));
	}

	public function setBudget(int $maxPerTick, float $maxMillisPerTick) : void{
		if($maxPerTick < 1 || !is_finite($maxMillisPerTick) || $maxMillisPerTick <= 0.0){ throw new InvalidArgumentException('Restore budget must be positive'); }
		$this->maxPerTick = $maxPerTick;
		$this->maxNanosPerTick = (int) ($maxMillisPerTick * 1_000_000);
	}

	public function pending() : int{ return count($this->queue); }

	/**
	 * Restores what fits in this tick's budget and leaves the rest to the task. Definitions in
	 * chunks that aren't loaded cost no budget and leave the queue: their chunk load queues them again.
	 * @param list<string> $ids
	 */
	public function enqueue(array $ids) : void{
		if($ids === []){ return; }
		foreach($ids as $id){ $this->queue[$id] = true; }
		$this->drain();
	}

	public function observeClose(Npc $npc) : void{
		$this->closeInFlight(); // drops finished observations
		if($npc->isClosed()){ return; }
		$native = $npc->getLocation()->getWorld()->getEntity($npc->getId());
		if($native !== null){ $this->closing[$native->getId()] = WeakReference::create($native); }
	}

	/**
	 * Restores everything now and reports the first failure at the end
	 * @param list<string> $ids
	 */
	public function restoreNow(array $ids) : void{
		if($this->running || $this->closeInFlight()){
			foreach($ids as $id){ $this->queue[$id] = true; }
			$this->arm();
			return;
		}
		$this->running = true;
		$failure = null;
		try{
			foreach($ids as $id){
				if($this->store->isClosed()){ break; }
				try{ $this->restoreOne($id); }
				catch(Throwable $error){ $failure ??= $error; }
			}
		}finally{ $this->running = false; }
		if($failure !== null){ throw $failure; }
	}

	public function dispose() : void{
		$this->mobs = []; $this->onRestore = null; $this->logger = null;
		$this->task?->cancel(); $this->task = null; $this->scheduler = null;
		$this->closing = []; $this->unloading = []; $this->closingWorlds = []; $this->queue = [];
	}

	/** @return bool whether it spawned something */
	private function restoreOne(string $id) : bool{
		$state = $this->store->state($id);
		if($state === null || $this->store->isQuarantined($id) || !$this->knows($state['type'])){ return false; }
		$revision = $this->store->revision($id);
		$p = $state['position'];
		$x = ((int) floor($p[0])) >> 4;
		$z = ((int) floor($p[2])) >> 4;
		if($this->isUnloading(DefinitionStore::chunkKey($state['world'], $x, $z))){ return false; }
		$world = Server::getInstance()->getWorldManager()->getWorldByName($state['world']);
		if($world === null || !$world->isChunkLoaded($x, $z)){ return false; }
		$location = new Location($p[0], $p[1], $p[2], $world, $p[3], $p[4]);
		try{ $npc = StateCodec::spawn($this->manager, $location, $state, $this->mobClass($state['type'])); }
		catch(Throwable $error){
			// waits for an explicit restore() instead of failing again on every chunk event
			if(!$this->store->isClosed() && $this->store->revision($id) === $revision){ $this->store->quarantine($id); }
			throw $error;
		}
		// native spawn hooks can delete or replace this definition
		if($this->manager->isClosed() || $this->store->revision($id) !== $revision){
			$npc->close();
			return true;
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
		return true;
	}

	private function defer() : void{
		$this->settleUnloads = true;
		$this->arm();
	}

	private function arm() : void{
		$this->task ??= $this->scheduler?->scheduleDelayedTask(new ClosureTask(function() : void{
			$this->task = null;
			if($this->store->isClosed()){ return; }
			if($this->settleUnloads){
				// the unloads are over or were cancelled, whatever they held back can go now
				$this->settleUnloads = false;
				foreach($this->unloading as $chunk => $_){
					foreach($this->store->dormantIdsIn($chunk) as $id){ $this->queue[$id] = true; }
				}
				$this->unloading = [];
				$this->closingWorlds = [];
			}
			$this->drain();
		}), 1);
	}

	private function drain() : void{
		if($this->queue === []){ return; }
		if($this->running || $this->closeInFlight()){
			$this->arm();
			return;
		}
		$tick = Server::getInstance()->getTick();
		if($tick !== $this->budgetTick){
			$this->budgetTick = $tick;
			$this->spentCount = 0;
			$this->spentNanos = 0;
		}
		$started = hrtime(true);
		$this->running = true;
		try{
			foreach($this->queue as $id => $_){
				if($this->store->isClosed()){ break; }
				if($this->spentCount >= $this->maxPerTick || $this->spentNanos + hrtime(true) - $started >= $this->maxNanosPerTick){ break; }
				unset($this->queue[$id]);
				// runs inside quark's loader and the task, one bad definition can't starve the rest
				try{
					if($this->restoreOne($id)){ ++$this->spentCount; }
				}catch(Throwable $error){
					++$this->spentCount;
					$this->logger?->logException($error);
				}
			}
		}finally{
			$this->running = false;
			$this->spentNanos += hrtime(true) - $started;
		}
		if($this->queue !== []){ $this->arm(); }
	}

	private function closeInFlight() : bool{
		foreach($this->closing as $id => $weak){
			$entity = $weak->get();
			if($entity === null || $entity->isClosed()){ unset($this->closing[$id]); }
		}
		return $this->closing !== [];
	}
}
