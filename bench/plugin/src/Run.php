<?php

declare(strict_types=1);

namespace NpcLibBench;

use NpcLib\NpcManager;
use NpcLibBench\Population\NativePopulation;
use NpcLibBench\Population\NpcLibPopulation;
use NpcLibBench\Population\Population;
use NpcLibBench\Probe\MemoryProbe;
use NpcLibBench\Probe\Measurement;
use NpcLibBench\Workload\Workload;
use NpcLibBench\Workload\WorkloadFactory;
use quark\entity\Location;
use quark\entity\Skin;
use quark\plugin\PluginBase;
use quark\world\ChunkLoader;
use quark\world\World;
use RuntimeException;
use Throwable;
use WeakReference;

/**
 * One scenario, one process: wait for viewers, prepare chunks, populate, warm up, measure, tear down, write the result.
 * Phase boundaries use wall-clock time so a lagging server still measures the requested duration.
 */
final class Run{
	private const FOREIGN_ENTITIES = 50;
	private const SAMPLE_SECONDS = 1.0;
	private const ROLLOVER_SECONDS = 30.0;
	private const COOLDOWN_SECONDS = 2.0;

	private readonly Timeline $timeline;
	private readonly World $world;
	private readonly int $bootNs;
	private ?Population $population = null;
	private ?Population $foreign = null;
	/** @var list<NpcManager> */
	private array $managers = [];
	/** @var list<WeakReference<object>> */
	private array $weak = [];
	private ?ChunkLoader $pin = null;
	private ?Scene $scene = null;
	/** @var array<string, mixed> */
	private array $result = [];
	/** @var array<string, mixed> */
	private array $phases = [];
	/** @var list<string> */
	private array $errors = [];
	private bool $finished = false;

	public function __construct(private readonly PluginBase $plugin, private readonly Config $config){
		$this->bootNs = hrtime(true);
		$world = $plugin->getServer()->getWorldManager()->getDefaultWorld() ?? throw new RuntimeException('No default world');
		$this->world = $world;
		$this->timeline = new Timeline($plugin, $this->fail(...));
		$this->result['config'] = ['id' => $config->id, 'mode' => $config->mode->value, 'type' => $config->type, 'count' => $config->count, 'layout' => $config->layout, 'viewers' => $config->viewers, 'warmup_seconds' => $config->warmupSeconds, 'window_seconds' => $config->windowSeconds, 'count_packets' => $config->countPackets, 'pulse' => $config->pulse, 'managers' => $config->managers, 'workload' => $config->workload];
	}

	public function start() : void{
		$this->timeline->every(function() : bool{
			if($this->finished){ return false; }
			if((hrtime(true) - $this->bootNs) / 1e9 > $this->config->maxSeconds){ throw new RuntimeException('Scenario exceeded max_seconds'); }
			if(memory_get_usage(true) > $this->config->maxMemoryMb * 1048576){ throw new RuntimeException('Scenario exceeded max_memory_mb'); }
			return true;
		});
		$server = $this->plugin->getServer();
		$viewers = $this->config->viewers;
		$this->timeline->until(static fn() : bool => count($server->getOnlinePlayers()) >= $viewers, $this->prepareWorld(...), 120, 'viewers');
	}

	private function prepareWorld() : void{
		$spawn = $this->world->getSpawnLocation();
		$this->scene = new Scene($this->world, $this->config->layout, $spawn->getFloorX() >> 4, $spawn->getFloorZ() >> 4);
		$pin = new class implements ChunkLoader{};
		$this->pin = $pin;
		$pending = count($this->scene->chunks());
		foreach($this->scene->chunks() as [$chunkX, $chunkZ]){
			$this->world->registerChunkLoader($pin, $chunkX, $chunkZ, true);
			$this->world->orderChunkPopulation($chunkX, $chunkZ, null)->onCompletion(
				static function() use (&$pending) : void{ --$pending; },
				fn() => $this->fail(new RuntimeException('Chunk population failed'))
			);
		}
		$this->timeline->until(static function() use (&$pending) : bool{ return $pending === 0; }, $this->populate(...), 120, 'chunks');
	}

	private function populate() : void{
		$scene = $this->scene ?? throw new RuntimeException('Scene missing');
		$skin = new Skin('bench', str_repeat("\xff", 64 * 64 * 4));
		$this->phases['memory_before_populate'] = MemoryProbe::snapshot();
		if($this->config->mode->populated()){
			$this->population = $this->createPopulation($skin);
			$locations = [];
			for($i = 0; $i < $this->config->count; ++$i){ $locations[] = $scene->location($i); }
			$perEntity = [];
			$start = hrtime(true);
			foreach($locations as $i => $at){
				$before = hrtime(true);
				$this->population->spawn(Kind::forIndex($this->config->type, $i), $at);
				$perEntity[] = hrtime(true) - $before;
			}
			$this->phases['create'] = ['count' => count($locations), 'total_ns' => hrtime(true) - $start, 'per_entity_ns' => $perEntity];
			foreach($this->population->slots() as $slot){ $this->weak[] = WeakReference::create($this->population->entity($slot)); }
		}
		if(($this->config->workload['kind'] ?? '') === 'foreign_damage'){
			$this->foreign = new NativePopulation($this->plugin, $skin, Mode::Native);
			for($i = 0; $i < self::FOREIGN_ENTITIES; ++$i){ $this->foreign->spawn(Kind::Zombie, $scene->location($i)); }
		}
		$this->phases['memory_after_populate'] = MemoryProbe::snapshot();
		$this->timeline->wait($this->config->warmupSeconds, $this->measure(...));
	}

	private function createPopulation(Skin $skin) : Population{
		if($this->config->mode !== Mode::NpcLib){ return new NativePopulation($this->plugin, $skin, $this->config->mode); }
		for($i = 0; $i < max(1, $this->config->managers); ++$i){ $this->managers[] = new NpcManager($this->plugin); }
		return new NpcLibPopulation($this->managers[0], $this->world, $skin, $this->config->environmental);
	}

	private function measure() : void{
		$scene = $this->scene ?? throw new RuntimeException('Scene missing');
		$workload = $this->workload($scene);
		$workload->prepare();
		$measurement = new Measurement($this->plugin, $this->config->countPackets, $this->config->pulse);
		$this->result['window_start_state'] = $this->state();
		$stepNs = [];
		$samples = [];
		$begin = hrtime(true);
		$end = $begin + (int) ($this->config->windowSeconds * 1e9);
		$nextSample = $begin;
		$nextRollover = $begin + (int) (self::ROLLOVER_SECONDS * 1e9);
		$measurement->start();
		$this->timeline->every(function() use ($workload, $measurement, &$stepNs, &$samples, $begin, $end, &$nextSample, &$nextRollover) : bool{
			$now = hrtime(true);
			if($now >= $end){
				$this->result['window'] = $measurement->stop();
				$this->result['window']['harness_step_ns'] = $stepNs;
				$this->result['window']['workload'] = $workload->report();
				$this->result['window']['samples'] = $samples;
				$this->result['window_end_state'] = $this->state();
				$this->teardown();
				return false;
			}
			$measurement->resume();
			if($now >= $nextRollover){
				$measurement->rollover();
				$nextRollover = $now + (int) (self::ROLLOVER_SECONDS * 1e9);
			}
			$workload->step();
			$stepNs[] = hrtime(true) - $now;
			if($now >= $nextSample){
				$samples[] = ['t' => ($now - $begin) / 1e9, ...MemoryProbe::snapshot(), 'queued' => count($this->world->updateEntities)];
				$nextSample = $now + (int) (self::SAMPLE_SECONDS * 1e9);
			}
			return true;
		});
	}

	private function workload(Scene $scene) : Workload{
		return WorkloadFactory::create($this->config->workload, $this->population, $this->foreign, $scene->location(...), $this->config->type);
	}

	/** @return array<string, mixed> */
	private function state() : array{
		$server = $this->plugin->getServer();
		$state = [
			'online_players' => count($server->getOnlinePlayers()),
			'queued_entities' => count($this->world->updateEntities),
			'world_entities' => count($this->world->getEntities()),
			'tps_average' => $server->getTicksPerSecondAverage(),
			'tick_usage_average' => $server->getTickUsageAverage(),
		];
		if($this->population !== null){
			$slots = $this->population->slots();
			$queued = 0;
			$viewers = [];
			foreach($slots as $index => $slot){
				$entity = $this->population->entity($slot);
				if(isset($this->world->updateEntities[$entity->getId()])){ ++$queued; }
				if($index % max(1, intdiv(count($slots), 10)) === 0){ $viewers[] = count($entity->getViewers()); }
			}
			$state += ['population' => count($slots), 'population_queued' => $queued, 'viewer_counts_sample' => $viewers];
		}
		return $state;
	}

	private function teardown() : void{
		if($this->population !== null){
			$this->phases['memory_before_remove'] = MemoryProbe::snapshot();
			$perEntity = [];
			$start = hrtime(true);
			foreach($this->population->slots() as $slot){
				$before = hrtime(true);
				$this->population->remove($slot);
				$perEntity[] = hrtime(true) - $before;
			}
			$this->phases['remove'] = ['total_ns' => hrtime(true) - $start, 'per_entity_ns' => $perEntity];
		}
		$this->foreign?->removeAll();
		foreach($this->managers as $manager){ $manager->close(); }
		$this->managers = [];
		$this->population = null;
		$this->foreign = null;
		$this->phases['memory_after_remove'] = MemoryProbe::snapshot();
		$collected = gc_collect_cycles();
		$this->phases['memory_after_gc'] = MemoryProbe::snapshot() + ['collected_cycles' => $collected];
		$alive = 0;
		foreach($this->weak as $reference){ if($reference->get() !== null){ ++$alive; } }
		$this->phases['weak_references'] = ['tracked' => count($this->weak), 'alive_after_gc' => $alive];
		// external counters need a sample after the window
		$this->timeline->wait(self::COOLDOWN_SECONDS, $this->finish(...));
	}

	private function fail(Throwable $error) : void{
		$this->errors[] = $error::class . ': ' . $error->getMessage();
		$this->finish();
	}

	private function finish() : void{
		if($this->finished){ return; }
		$this->finished = true;
		if($this->pin !== null && $this->scene !== null){
			foreach($this->scene->chunks() as [$chunkX, $chunkZ]){ $this->world->unregisterChunkLoader($this->pin, $chunkX, $chunkZ); }
		}
		$this->result['elapsed_seconds'] = (hrtime(true) - $this->bootNs) / 1e9;
		ResultWriter::write($this->config->out, $this->result + ['phases' => $this->phases, 'errors' => $this->errors]);
		$this->plugin->getServer()->shutdown();
	}
}
