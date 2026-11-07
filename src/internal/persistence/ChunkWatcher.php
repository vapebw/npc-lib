<?php

declare(strict_types=1);

namespace NpcLib\internal\persistence;

use AttachableLogger;
use quark\event\EventPriority;
use quark\event\HandlerListManager;
use quark\event\RegisteredListener;
use quark\event\world\ChunkLoadEvent;
use quark\event\world\ChunkUnloadEvent;
use quark\event\world\WorldLoadEvent;
use quark\event\world\WorldUnloadEvent;
use quark\plugin\Plugin;
use quark\Server;
use quark\world\World;
use Throwable;

/** @internal */
final class ChunkWatcher{
	/** @var list<RegisteredListener<*>> */
	private array $listeners = [];

	public function __construct(
		Plugin $owner,
		private readonly DefinitionStore $store,
		private readonly Restorer $restorer,
		private ?AttachableLogger $logger
	){
		$events = Server::getInstance()->getPluginManager();
		try{
			$this->listeners[] = $events->registerEvent(ChunkLoadEvent::class, function(ChunkLoadEvent $event) : void{
				$this->chunkLoaded($event);
			}, EventPriority::MONITOR, $owner);
			$this->listeners[] = $events->registerEvent(WorldLoadEvent::class, function(WorldLoadEvent $event) : void{
				$this->worldLoaded($event);
			}, EventPriority::MONITOR, $owner);
			$this->listeners[] = $events->registerEvent(WorldUnloadEvent::class, function(WorldUnloadEvent $event) : void{
				if($this->store->isClosed()){ return; }
				$this->saveFor($event->getWorld());
				$this->restorer->worldClosing($event->getWorld()->getFolderName());
			}, EventPriority::MONITOR, $owner);
			$this->listeners[] = $events->registerEvent(ChunkUnloadEvent::class, function(ChunkUnloadEvent $event) : void{
				$this->chunkUnloading($event);
			}, EventPriority::HIGHEST, $owner);
		}catch(Throwable $error){
			$this->close();
			throw $error;
		}
	}

	public function close() : void{
		foreach($this->listeners as $listener){ HandlerListManager::global()->unregisterAll($listener); }
		$this->listeners = [];
		$this->logger = null;
	}

	private function chunkLoaded(ChunkLoadEvent $event) : void{
		if($this->store->isClosed()){ return; }
		$this->restorer->chunkLoaded(DefinitionStore::chunkKey($event->getWorld()->getFolderName(), $event->getChunkX(), $event->getChunkZ()));
	}

	private function worldLoaded(WorldLoadEvent $event) : void{
		if($this->store->isClosed()){ return; }
		$this->restorer->worldOpened($event->getWorld()->getFolderName());
		$this->restorer->restore($this->store->dormantIds(), false);
	}

	private function chunkUnloading(ChunkUnloadEvent $event) : void{
		$world = $event->getWorld();
		$x = $event->getChunkX();
		$z = $event->getChunkZ();
		if($this->store->isClosed() || !$this->store->hasDefinitionIn($world, $x, $z)){ return; }
		$this->saveFor($world, $x, $z);
		$this->restorer->chunkClosing(DefinitionStore::chunkKey($world->getFolderName(), $x, $z));
	}

	// a failed save is logged, it never holds an unload back
	private function saveFor(World $world, ?int $x = null, ?int $z = null) : void{
		if(!$this->store->hasActiveIn($world, $x, $z)){ return; }
		try{
			$this->store->checkpoint();
		}catch(Throwable $error){
			$this->logger?->error('NPC unload save failed: ' . $error->getMessage());
		}
	}
}
