<?php

declare(strict_types=1);

namespace NpcLib;

use Closure;
use InvalidArgumentException;
use LogicException;
use NpcLib\internal\persistence\ChunkWatcher;
use NpcLib\internal\persistence\DefinitionFile;
use NpcLib\internal\persistence\DefinitionStore;
use NpcLib\internal\persistence\Restorer;
use NpcLib\internal\persistence\StateCodec;
use NpcLib\npc\Npc;
use NpcLib\npc\PlayerNpc;
use NpcLib\npc\VillagerNpc;
use NpcLib\npc\ZombieNpc;
use quark\plugin\Plugin;
use Throwable;

/** @phpstan-import-type State from StateCodec */
final class PersistentNpcs{
	private readonly DefinitionStore $store;
	private readonly Restorer $restorer;
	private readonly ChunkWatcher $watcher;

	/**
	 * @internal
	 * @param Closure(string, Npc): void $onRestore
	 */
	public function __construct(private readonly NpcManager $manager, Plugin $owner, Closure $onRestore){
		$file = new DefinitionFile($owner->getDataFolder(), $owner->getName());
		try{
			$store = new DefinitionStore($file, $owner->getLogger());
			$restorer = new Restorer($manager, $store, $owner->getScheduler(), $owner->getLogger(), $onRestore);
			$watcher = new ChunkWatcher($owner, $store, $restorer, $owner->getLogger());
		}catch(Throwable $error){
			$file->close();
			throw $error;
		}
		$this->store = $store;
		$this->restorer = $restorer;
		$this->watcher = $watcher;
	}

	/** @param class-string $class */
	public function registerMob(string $type, string $class) : void{
		$this->requireOpen();
		$this->restorer->registerMob($type, $class);
	}

	public function remember(string $id, Npc $npc, ?string $customType = null) : void{
		$this->rememberBatch([$id => ['npc' => $npc, 'type' => $customType]]);
	}

	/** @param array<string, array{npc: Npc, type?: ?string}> $entries */
	public function rememberBatch(array $entries) : void{
		$this->requireOpen();
		if($entries === []){ return; }
		/** @var array<string, array{Npc, string, State}> $staged */
		$staged = [];
		/** @var array<int, true> $seen */
		$seen = [];
		foreach($entries as $id => $entry){
			DefinitionFile::validateId($id);
			$npc = $entry['npc'];
			$customType = $entry['type'] ?? null;
			if(
				$this->store->exists($id) ||
				$this->store->registered($npc) ||
				isset($seen[$npc->getId()]) ||
				$this->manager->get($npc->getId()) !== $npc
			){
				throw new LogicException('Definition ID or NPC already registered, or NPC belongs to another manager');
			}
			$at = $npc->getLocation();
			$world = $at->getWorld();
			$chunkX = $at->getFloorX() >> 4;
			$chunkZ = $at->getFloorZ() >> 4;
			$chunk = DefinitionStore::chunkKey($world->getFolderName(), $chunkX, $chunkZ);
			if(!$world->isChunkLoaded($chunkX, $chunkZ) || $this->restorer->isUnloading($chunk)){
				throw new LogicException('Permanent NPC registration requires loaded terrain');
			}
			$type = match(true){
				$npc instanceof PlayerNpc => 'player',
				$npc instanceof VillagerNpc => 'villager',
				$npc instanceof ZombieNpc => 'zombie',
				default => $customType
			};
			if($type === null || ($customType !== null && $type !== $customType) || !$this->restorer->knows($type)){
				throw new InvalidArgumentException('Custom NPCs require a registered restoration type');
			}
			$class = $this->restorer->mobClass($type);
			if($class !== null){
				$native = $world->getEntity($npc->getId());
				if($native === null || $native::class !== $class){
					throw new InvalidArgumentException('NPC class does not match registered restoration type');
				}
			}
			$staged[$id] = [$npc, $type, StateCodec::capture($npc, $type)];
			$seen[$npc->getId()] = true;
		}
		$this->store->commit($staged);
		$failure = null;
		foreach($staged as $id => [$npc]){
			if(!$this->restorer->owns($id, $npc)){ continue; }
			try{
				$this->restorer->notify($id, $npc);
			}catch(Throwable $error){
				$failure ??= $error;
			}
		}
		if($failure !== null){ throw $failure; }
	}

	/** @return list<string> */
	public function ids() : array{
		$this->requireOpen();
		return $this->store->ids();
	}

	public function has(string $id) : bool{
		$this->requireOpen();
		return $this->store->exists($id);
	}

	public function get(string $id) : ?Npc{
		$this->requireOpen();
		return $this->store->npc($id);
	}

	public function delete(string $id) : bool{
		$this->requireOpen();
		if(!$this->store->exists($id)){ return false; }
		$active = $this->store->remove($id);
		if($active !== null){
			$this->restorer->observeClose($active);
			$active->close();
		}
		return true;
	}

	public function save() : void{
		$this->requireOpen();
		$this->store->save();
	}

	/** The only way back for a definition whose callback failed */
	public function restore() : void{
		$this->requireOpen();
		$this->store->releaseQuarantine();
		$this->restorer->restore($this->store->dormantIds(), true);
	}

	/** @internal */
	public function unloaded(Npc $npc) : void{
		$id = $this->store->idOf($npc);
		if($id === null){ return; }
		$at = $npc->getLocation();
		$world = $at->getWorld()->getFolderName();
		$chunk = DefinitionStore::chunkKey($world, $at->getFloorX() >> 4, $at->getFloorZ() >> 4);
		if($npc->getHealth() <= 0.0 || (!$this->restorer->isUnloading($chunk) && !$this->restorer->isWorldClosing($world))){
			// dead, or closed outside an unload by another plugin, so it's gone and not asleep
			$this->store->discard($id, $npc);
			return;
		}
		$this->restorer->observeClose($npc);
		$this->store->suspend($id, $npc);
	}

	/** @internal */
	public function suspended(Npc $npc) : void{
		$id = $this->store->idOf($npc);
		if($id === null){ return; }
		$this->restorer->observeClose($npc);
		$this->store->suspend($id, $npc);
		$this->store->quarantine($id);
	}

	/** @internal */
	public function removing(Npc $npc) : void{
		$id = $this->store->idOf($npc);
		if($id !== null){ $this->delete($id); }
	}

	/** @internal */
	public function shutdown() : void{
		try{
			// an NPC that can't be captured was logged and keeps its last good state
			$this->store->checkpoint();
		}finally{
			$this->watcher->close();
			$this->restorer->dispose();
			$this->store->dispose();
		}
	}

	private function requireOpen() : void{
		if($this->store->isClosed() || $this->manager->isClosed()){ throw new LogicException('Persistent NPC context is closed'); }
	}
}
