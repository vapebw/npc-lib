<?php

declare(strict_types=1);

namespace NpcLib\internal\persistence;

use quark\event\EventPriority;
use quark\event\HandlerListManager;
use quark\event\RegisteredListener;
use quark\event\world\ChunkLoadEvent;
use quark\event\world\ChunkUnloadEvent;
use quark\event\world\WorldLoadEvent;
use quark\event\world\WorldUnloadEvent;
use quark\plugin\Plugin;
use quark\Server;
use Throwable;

/** @internal */
final class ChunkWatcher{
	/** @var list<RegisteredListener<*>> */
	private array $listeners = [];

	public function __construct(
		Plugin $owner,
		private readonly DefinitionStore $store,
		private readonly Restorer $restorer
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
				if(!$this->store->isClosed()){ $this->restorer->worldClosing($event->getWorld()->getFolderName()); }
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

	// no write here: each entity is captured as it closes and the file is written once next tick
	private function chunkUnloading(ChunkUnloadEvent $event) : void{
		$world = $event->getWorld();
		$x = $event->getChunkX();
		$z = $event->getChunkZ();
		if($this->store->isClosed() || !$this->store->hasDefinitionIn($world, $x, $z)){ return; }
		$this->restorer->chunkClosing(DefinitionStore::chunkKey($world->getFolderName(), $x, $z));
	}
}
