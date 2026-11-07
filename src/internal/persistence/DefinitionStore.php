<?php

declare(strict_types=1);

namespace NpcLib\internal\persistence;

use AttachableLogger;
use NpcLib\npc\Npc;
use quark\scheduler\ClosureTask;
use quark\scheduler\TaskHandler;
use quark\scheduler\TaskScheduler;
use quark\Server;
use quark\world\World;
use RuntimeException;
use Throwable;

/**
 * @internal
 * @phpstan-import-type State from StateCodec
 */
final class DefinitionStore{
	private const int FLUSH_RETRY_TICKS = 600;

	/** @var array<string, State> */
	private array $dormant = [];
	/** @var array<string, array<string, true>> */
	private array $dormantChunks = [];
	/** @var array<string, string> */
	private array $dormantKeys = [];
	/** @var array<string, array{Npc, string}> */
	private array $active = [];
	/**
	 * Last good state of each active NPC, what gets written when it can't be captured
	 * @var array<string, State>
	 */
	private array $snapshots = [];
	/** @var array<int, string> */
	private array $runtime = [];
	/** @var array<string, true> */
	private array $quarantined = [];
	/** @var array<string, string> */
	private array $snapshotErrors = [];
	/** @var array<string, int> */
	private array $revisions = [];
	private int $sequence = 0;
	private bool $closed = false;
	private bool $dirty = false;
	private int $lastDiscardTick = -1;
	/** @var TaskHandler<ClosureTask>|null */
	private ?TaskHandler $flushTask = null;

	public function __construct(private readonly DefinitionFile $file, private ?AttachableLogger $logger, private ?TaskScheduler $scheduler){
		foreach($file->load() as $id => $state){
			$this->putDormant($id, $state);
			$this->revisions[$id] = ++$this->sequence;
		}
	}

	public function isClosed() : bool{ return $this->closed; }

	public function writes() : int{ return $this->file->writes(); }

	public static function chunkKey(string $world, int $x, int $z) : string{ return $world . ':' . $x . ':' . $z; }

	/** @return list<string> */
	public function ids() : array{ return array_keys($this->dormant + $this->active); }

	/** @return list<string> */
	public function dormantIds() : array{ return array_keys($this->dormant); }

	/** @return list<string> */
	public function dormantIdsIn(string $chunk) : array{ return array_keys($this->dormantChunks[$chunk] ?? []); }

	public function exists(string $id) : bool{ return isset($this->dormant[$id]) || isset($this->active[$id]); }

	public function isQuarantined(string $id) : bool{ return isset($this->quarantined[$id]); }

	public function npc(string $id) : ?Npc{ return $this->active[$id][0] ?? null; }

	public function idOf(Npc $npc) : ?string{ return $this->runtime[$npc->getId()] ?? null; }

	public function registered(Npc $npc) : bool{ return isset($this->runtime[$npc->getId()]); }

	public function owns(string $id, Npc $npc) : bool{ return ($this->active[$id][0] ?? null) === $npc; }

	/** @return State|null */
	public function state(string $id) : ?array{ return $this->dormant[$id] ?? null; }

	public function revision(string $id) : ?int{ return $this->revisions[$id] ?? null; }

	public function hasDefinitionIn(World $world, int $x, int $z) : bool{
		// the world already tracks entities per chunk, moves included, so no second index to keep in sync
		foreach($world->getChunkEntities($x, $z) as $runtimeId => $_){
			if(isset($this->runtime[$runtimeId])){ return true; }
		}
		return $this->dormantChunks !== [] && isset($this->dormantChunks[self::chunkKey($world->getFolderName(), $x, $z)]);
	}

	/**
	 * Captures every active NPC and writes, one that can't be captured keeps its last good state
	 * @return list<string> ids that kept an older state
	 */
	public function checkpoint() : array{
		$stale = [];
		foreach($this->active as $id => [$npc]){
			// mid death animation: saving it would bring it back healthy
			if(!$npc->isClosed() && $npc->getHealth() <= 0.0){
				$this->forget($id, $npc);
				continue;
			}
			if(!$this->refresh($id)){ $stale[] = $id; }
		}
		$this->write();
		return $stale;
	}

	public function save() : void{
		$stale = $this->checkpoint();
		if($stale !== []){ throw new RuntimeException('NPC snapshot failure; kept last good state of: ' . implode(', ', $stale)); }
	}

	public function flush() : void{
		if($this->closed || !$this->dirty){ return; }
		try{
			$this->write();
		}catch(Throwable $error){
			$this->logger?->error('NPC definitions could not be saved, retrying: ' . $error->getMessage());
			$this->scheduleFlush(self::FLUSH_RETRY_TICKS);
		}
	}

	/** @param array<string, array{Npc, string, State}> $staged */
	public function commit(array $staged) : void{
		$records = $this->records();
		foreach($staged as $id => [, , $state]){ $records[$id] = $state; }
		// nothing changes in memory unless the file write works
		$this->file->save($records);
		$this->dirty = false;
		foreach($staged as $id => [$npc, $type, $state]){
			$this->active[$id] = [$npc, $type];
			$this->snapshots[$id] = $state;
			$this->runtime[$npc->getId()] = $id;
			$this->revisions[$id] = ++$this->sequence;
			unset($this->quarantined[$id]);
		}
	}

	public function remove(string $id) : ?Npc{
		$records = $this->records();
		unset($records[$id]);
		// explicit deletions stay durable, a failure leaves the definition with its owner
		$this->file->save($records);
		$this->dirty = false;
		$active = $this->active[$id] ?? null;
		unset($this->active[$id], $this->snapshots[$id], $this->revisions[$id], $this->quarantined[$id], $this->snapshotErrors[$id]);
		$this->dropDormant($id);
		if($active === null){ return null; }
		unset($this->runtime[$active[0]->getId()]);
		return $active[0];
	}

	public function wake(string $id, Npc $npc, string $type) : void{
		$state = $this->dormant[$id] ?? throw new RuntimeException('Missing dormant NPC ' . $id);
		$this->dropDormant($id);
		$this->active[$id] = [$npc, $type];
		$this->snapshots[$id] = $state;
		$this->runtime[$npc->getId()] = $id;
	}

	/** @param State $state */
	public function park(string $id, Npc $npc, array $state) : void{
		unset($this->active[$id], $this->snapshots[$id], $this->runtime[$npc->getId()], $this->snapshotErrors[$id]);
		$this->putDormant($id, $state);
		$this->quarantined[$id] = true;
	}

	public function quarantine(string $id) : void{ $this->quarantined[$id] = true; }

	public function releaseQuarantine() : void{ $this->quarantined = []; }

	public function suspend(string $id, Npc $npc) : void{
		$written = $this->snapshots[$id] ?? throw new RuntimeException('Missing NPC snapshot ' . $id);
		// native close can't take an exception from us, refresh only logs
		$this->refresh($id);
		$state = $this->snapshots[$id];
		unset($this->active[$id], $this->snapshots[$id], $this->runtime[$npc->getId()], $this->snapshotErrors[$id]);
		$this->putDormant($id, $state);
		// the definition is already on disk, only a changed state is worth a write and it can wait a tick
		if($state !== $written){ $this->scheduleFlush(1); }
	}

	/**
	 * Death or a close by another plugin. The first one of a tick is written now so a crash can't
	 * bring it back, a burst shares one more write on the next tick.
	 */
	public function discard(string $id, Npc $npc) : void{
		$this->forget($id, $npc);
		$tick = Server::getInstance()->getTick();
		if($tick === $this->lastDiscardTick){
			$this->scheduleFlush(1);
			return;
		}
		$this->lastDiscardTick = $tick;
		$this->flush();
	}

	public function dispose() : void{
		$this->closed = true;
		$this->flushTask?->cancel();
		$this->flushTask = null; $this->scheduler = null; $this->logger = null; $this->dirty = false;
		$this->active = []; $this->snapshots = []; $this->runtime = []; $this->dormant = []; $this->dormantChunks = []; $this->dormantKeys = [];
		$this->quarantined = []; $this->snapshotErrors = []; $this->revisions = [];
		$this->file->close();
	}

	private function forget(string $id, Npc $npc) : void{
		unset($this->active[$id], $this->snapshots[$id], $this->runtime[$npc->getId()], $this->revisions[$id], $this->quarantined[$id], $this->snapshotErrors[$id]);
		$this->dirty = true;
	}

	/** @return array<string, State> */
	private function records() : array{ return $this->dormant + $this->snapshots; }

	private function write() : void{
		$this->file->save($this->records());
		$this->dirty = false;
	}

	private function refresh(string $id) : bool{
		$entry = $this->active[$id] ?? throw new RuntimeException('Missing active NPC ' . $id);
		try{
			$this->snapshots[$id] = StateCodec::capture($entry[0], $entry[1]);
			unset($this->snapshotErrors[$id]);
			return true;
		}catch(Throwable $error){
			$message = $error->getMessage();
			if(($this->snapshotErrors[$id] ?? null) !== $message){
				$this->snapshotErrors[$id] = $message;
				$this->logger?->error('NPC snapshot failed for ' . $id . '; keeping its last good state: ' . $message);
			}
			return false;
		}
	}

	private function scheduleFlush(int $delay) : void{
		$this->dirty = true;
		$this->flushTask ??= $this->scheduler?->scheduleDelayedTask(new ClosureTask(function() : void{
			$this->flushTask = null;
			$this->flush();
		}), $delay);
	}

	/** @param State $state */
	private function putDormant(string $id, array $state) : void{
		$this->dropDormant($id);
		$p = $state['position'];
		$key = self::chunkKey($state['world'], ((int) floor($p[0])) >> 4, ((int) floor($p[2])) >> 4);
		$this->dormant[$id] = $state;
		$this->dormantChunks[$key][$id] = true;
		$this->dormantKeys[$id] = $key;
	}

	private function dropDormant(string $id) : void{
		$key = $this->dormantKeys[$id] ?? null;
		if($key !== null){
			unset($this->dormantChunks[$key][$id], $this->dormantKeys[$id]);
			if($this->dormantChunks[$key] === []){ unset($this->dormantChunks[$key]); }
		}
		unset($this->dormant[$id]);
	}
}
