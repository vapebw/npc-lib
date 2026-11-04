<?php

declare(strict_types=1);

namespace NpcLib\internal\persistence;

use AttachableLogger;
use NpcLib\npc\Npc;
use quark\world\World;
use RuntimeException;
use Throwable;

/**
 * @internal
 * @phpstan-import-type State from StateCodec
 */
final class DefinitionStore{
	/** @var array<string, State> */
	private array $dormant = [];
	/** @var array<string, array<string, true>> */
	private array $dormantChunks = [];
	/** @var array<string, string> */
	private array $dormantKeys = [];
	/** @var array<string, array{Npc, string}> */
	private array $active = [];
	/** @var array<int, string> */
	private array $runtime = [];
	/** @var array<string, string> */
	private array $failures = [];
	/** @var array<string, int> */
	private array $revisions = [];
	private int $sequence = 0;
	private bool $closed = false;

	public function __construct(private readonly DefinitionFile $file, private ?AttachableLogger $logger){
		foreach($file->load() as $id => $state){
			$this->putDormant($id, $state);
			$this->revisions[$id] = ++$this->sequence;
		}
	}

	public function isClosed() : bool{ return $this->closed; }

	public static function chunkKey(string $world, int $x, int $z) : string{ return $world . ':' . $x . ':' . $z; }

	/** @return list<string> */
	public function ids() : array{ return array_keys($this->dormant + $this->active + $this->failures); }

	/** @return list<string> */
	public function dormantIds() : array{ return array_keys($this->dormant); }

	/** @return list<string> */
	public function dormantIdsIn(string $chunk) : array{ return array_keys($this->dormantChunks[$chunk] ?? []); }

	public function exists(string $id) : bool{ return isset($this->dormant[$id]) || isset($this->active[$id]) || isset($this->failures[$id]); }

	public function taken(string $id) : bool{ return isset($this->dormant[$id]) || isset($this->active[$id]); }

	public function failed(string $id) : bool{ return isset($this->failures[$id]); }

	public function npc(string $id) : ?Npc{ return $this->active[$id][0] ?? null; }

	public function idOf(Npc $npc) : ?string{ return $this->runtime[$npc->getId()] ?? null; }

	public function typeOf(string $id) : string{ return $this->active[$id][1] ?? throw new RuntimeException('Missing active NPC ' . $id); }

	public function registered(Npc $npc) : bool{ return isset($this->runtime[$npc->getId()]); }

	public function owns(string $id, Npc $npc) : bool{ return ($this->active[$id][0] ?? null) === $npc; }

	/** @return State|null */
	public function state(string $id) : ?array{ return $this->dormant[$id] ?? null; }

	public function revision(string $id) : ?int{ return $this->revisions[$id] ?? null; }

	public function hasActiveIn(World $world, ?int $x = null, ?int $z = null) : bool{
		foreach($this->active as [$npc]){
			$at = $npc->getLocation();
			if($at->getWorld() === $world && ($x === null || ($at->getFloorX() >> 4) === $x && ($at->getFloorZ() >> 4) === $z)){ return true; }
		}
		return false;
	}

	public function hasDefinitionIn(World $world, int $x, int $z) : bool{
		return $this->hasActiveIn($world, $x, $z) || isset($this->dormantChunks[self::chunkKey($world->getFolderName(), $x, $z)]);
	}

	public function assertWritable() : void{
		if($this->failures === []){ return; }
		throw new RuntimeException('NPC snapshot failure; original file preserved: ' . implode(', ', array_keys($this->failures)));
	}

	public function save() : void{
		$this->assertWritable();
		$this->write();
	}

	/** @param array<string, array{Npc, string, State}> $staged */
	public function commit(array $staged) : void{
		$records = $this->records();
		foreach($staged as $id => [, , $state]){ $records[$id] = $state; }
		// nothing changes in memory unless the file write works
		$this->file->save($records);
		foreach($staged as $id => [$npc, $type]){
			$this->active[$id] = [$npc, $type];
			$this->runtime[$npc->getId()] = $id;
			$this->revisions[$id] = ++$this->sequence;
		}
	}

	public function remove(string $id) : ?Npc{
		$active = $this->active[$id] ?? null;
		$state = $this->dormant[$id] ?? null;
		$failure = $this->failures[$id] ?? null;
		$revision = $this->revisions[$id] ?? null;
		unset($this->active[$id], $this->failures[$id], $this->revisions[$id]);
		$this->dropDormant($id);
		try{
			$this->write(true);
		}catch(Throwable $error){
			if($active !== null){ $this->active[$id] = $active; }
			if($state !== null){ $this->putDormant($id, $state); }
			if($failure !== null){ $this->failures[$id] = $failure; }
			if($revision !== null){ $this->revisions[$id] = $revision; }
			throw $error;
		}
		if($active === null){ return null; }
		unset($this->runtime[$active[0]->getId()]);
		return $active[0];
	}

	public function wake(string $id, Npc $npc, string $type) : void{
		$this->dropDormant($id);
		$this->active[$id] = [$npc, $type];
		$this->runtime[$npc->getId()] = $id;
	}

	/** @param State $state */
	public function park(string $id, Npc $npc, array $state) : void{
		unset($this->active[$id], $this->runtime[$npc->getId()]);
		$this->putDormant($id, $state);
	}

	public function suspend(string $id, Npc $npc) : void{
		try{
			$this->putDormant($id, StateCodec::capture($npc, $this->typeOf($id)));
		}catch(Throwable $error){
			$this->failures[$id] = $error->getMessage();
			// native close can't take an exception from us and a later save must not erase the last good file
			$this->logger?->error('NPC snapshot failed for ' . $id . '; original file preserved: ' . $error->getMessage());
		}finally{ unset($this->active[$id], $this->runtime[$npc->getId()]); }
	}

	public function discard(string $id, Npc $npc) : void{
		unset($this->active[$id], $this->runtime[$npc->getId()], $this->revisions[$id], $this->failures[$id]);
		try{
			$this->write(true);
		}catch(Throwable $error){
			$this->logger?->error('NPC definition ' . $id . ' could not be removed after its entity closed: ' . $error->getMessage());
		}
	}

	public function dispose() : void{
		$this->closed = true;
		$this->active = []; $this->runtime = []; $this->dormant = []; $this->dormantChunks = []; $this->dormantKeys = [];
		$this->failures = []; $this->revisions = []; $this->logger = null;
		$this->file->close();
	}

	/** @return array<string, State> */
	private function records(bool $keepFailed = false) : array{
		$records = $this->dormant;
		if($keepFailed && $this->failures !== []){
			$committed = $this->file->load();
			foreach($this->failures as $id => $_){
				$records[$id] = $committed[$id] ?? throw new RuntimeException('Missing committed NPC snapshot for ' . $id);
			}
		}
		foreach($this->active as $id => [$npc, $type]){ $records[$id] = StateCodec::capture($npc, $type); }
		return $records;
	}

	private function write(bool $keepFailed = false) : void{
		$this->file->save($this->records($keepFailed));
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
