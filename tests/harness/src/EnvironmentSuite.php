<?php

declare(strict_types=1);

namespace NpcLibHarness;

use Closure;
use NpcLib\npc\Npc;
use NpcLib\NpcManager;
use quark\block\Block;
use quark\block\VanillaBlocks;
use quark\entity\Living;
use quark\entity\Location;
use quark\event\entity\EntityDamageEvent;
use quark\plugin\PluginBase;
use quark\scheduler\ClosureTask;
use quark\world\ChunkTicker;
use quark\world\World;
use RuntimeException;
use Throwable;

/** Environmental hazards against npcs that sleep between explicit changes. */
final class EnvironmentSuite{
	private NpcManager $manager;
	private World $world;
	/** @var list<string> */
	private array $passed = [];
	/** @var array<string, array<int, int>> */
	private array $causes = [];
	/** @var array<string, Npc> */
	private array $npcs = [];
	private ?string $error = null;
	private bool $complete = false;
	private int $pending = 0;
	/** @var array{int, int} */
	private array $lived = [0, 0];
	private ?object $pin = null;
	private ChunkTicker $ticker;

	public function __construct(private PluginBase $plugin, private Closure $done){
		$world = $plugin->getServer()->getWorldManager()->getDefaultWorld();
		if($world === null){ throw new RuntimeException('Missing world'); }
		$this->world = $world;
		$this->manager = new NpcManager($plugin);
		$this->ticker = new ChunkTicker();
		$this->pin = new class implements \quark\world\ChunkLoader{};
		// quark only ticks a chunk when its eight neighbours are loaded too
		foreach(range(-1, 3) as $chunkX){
			foreach(range(-1, 1) as $chunkZ){
				++$this->pending;
				$this->world->registerChunkLoader($this->pin, $chunkX, $chunkZ, true);
				$this->world->registerTickingChunk($this->ticker, $chunkX, $chunkZ);
				$this->world->orderChunkPopulation($chunkX, $chunkZ, null)->onCompletion(function() : void{ --$this->pending; }, static function() : void{});
			}
		}
		$this->stage($this->setup(...), 5);
	}

	private function check(bool $condition, string $name) : void{
		if(!$condition){ throw new RuntimeException($name); }
		$this->passed[] = $name;
	}

	/** @param Closure(): void $callback */
	private function stage(Closure $callback, int $delay) : void{
		$this->plugin->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($callback) : void{
			try{ $callback(); }
			catch(Throwable $error){
				$this->error = $error::class . ': ' . $error->getMessage();
				$this->plugin->getLogger()->error('environment suite FAIL ' . $this->error);
				($this->done)();
			}
		}), $delay);
	}

	private function at(int $slot, float $y = 16.0) : Location{ return new Location(2.5 + $slot * 5, $y, 8.5, $this->world, 0, 0); }

	private function x(int $slot) : int{ return (int) floor(2.5 + $slot * 5); }

	private function spawn(string $name, int $slot, bool $environmental = false) : Npc{
		$npc = $this->manager->createVillager($this->at($slot), $name);
		$this->causes[$name] = [];
		$npc->onDamage(function(Npc $npc, EntityDamageEvent $event) use ($name) : void{ $this->causes[$name][$event->getCause()] = ($this->causes[$name][$event->getCause()] ?? 0) + 1; });
		if($environmental){ $npc->setEnvironmental(true); }
		return $this->npcs[$name] = $npc;
	}

	private function entity(string $name) : Living{
		$entity = $this->world->getEntity($this->npcs[$name]->getId());
		if(!$entity instanceof Living){ throw new RuntimeException('Missing entity ' . $name); }
		return $entity;
	}

	private function asleep(string $name) : bool{ return !isset($this->world->updateEntities[$this->npcs[$name]->getId()]); }

	private function hits(string $name, int $cause) : int{ return $this->causes[$name][$cause] ?? 0; }

	private function fill(int $slot, int $y, int $radius, Block $block) : void{
		for($dx = -$radius; $dx <= $radius; ++$dx){
			for($dy = -$radius; $dy <= $radius; ++$dy){
				for($dz = -$radius; $dz <= $radius; ++$dz){ $this->world->setBlockAt($this->x($slot) + $dx, $y + $dy, 8 + $dz, $block); }
			}
		}
	}

	private function body(int $slot, Block $block, bool $update = true) : void{
		$this->world->setBlockAt($this->x($slot), 16, 8, $block, $update);
		$this->world->setBlockAt($this->x($slot), 17, 8, $block, $update);
	}

	private function setup() : void{
		if($this->pending > 0){ $this->stage($this->setup(...), 5); return; }
		// clean air around every probe, then the hazards that exist before the npc does
		for($slot = 0; $slot < 10; ++$slot){ $this->fill($slot, 16, 2, VanillaBlocks::AIR()); }
		$this->body(1, VanillaBlocks::STONE());
		$slots = ['quiet' => 0, 'solid-spawn' => 1, 'solid-later' => 2, 'water-later' => 3, 'void-teleport' => 4, 'lava-later' => 5, 'silent-static' => 6];
		foreach($slots as $name => $slot){ $this->spawn($name, $slot); }
		$this->spawn('silent-environmental', 7, true);
		$this->spawn('quiet-environmental', 8, true);
		$this->stage($this->hazards(...), 30);
	}

	private function hazards() : void{
		foreach(['quiet', 'solid-later', 'water-later', 'void-teleport', 'lava-later', 'silent-static'] as $name){ $this->check($this->asleep($name), 'probe ' . $name . ' sleeps before hazards'); }
		$this->lived = [$this->entity('quiet')->ticksLived, $this->entity('quiet-environmental')->ticksLived];
		$this->check($this->hits('solid-spawn', EntityDamageEvent::CAUSE_SUFFOCATION) > 0, 'static npc spawned inside blocks suffocates');
		$this->body(2, VanillaBlocks::STONE());
		$this->fill(3, 16, 1, VanillaBlocks::WATER());
		$this->entity('water-later')->setAirSupplyTicks(1);
		$this->npcs['void-teleport']->teleport($this->at(4, -90.0));
		$this->world->setBlockAt($this->x(5), 16, 8, VanillaBlocks::LAVA());
		// changes nobody announces: quark never tells the entity
		$this->body(6, VanillaBlocks::STONE(), false);
		$this->body(7, VanillaBlocks::STONE(), false);
		$this->stage($this->observe(...), 80);
	}

	private function observe() : void{
		$this->check($this->causes['quiet'] === [] && $this->asleep('quiet'), 'quiet static npc takes no damage and keeps sleeping');
		$this->check($this->hits('solid-later', EntityDamageEvent::CAUSE_SUFFOCATION) >= 3, 'block placed over a static npc keeps suffocating it');
		$this->check($this->hits('water-later', EntityDamageEvent::CAUSE_DROWNING) > 0, 'water around a static npc drowns it');
		$this->check($this->hits('void-teleport', EntityDamageEvent::CAUSE_VOID) > 0, 'static npc teleported into the void takes void damage');
		$this->check($this->entity('lava-later')->isOnFire() || $this->hits('lava-later', EntityDamageEvent::CAUSE_LAVA) > 0, 'lava placed on a static npc burns it');
		// the engine has no event for silent changes, so only npcs that opt into chunk ticks see them
		$this->check($this->hits('silent-static', EntityDamageEvent::CAUSE_SUFFOCATION) === 0 && $this->asleep('silent-static'), 'static npc ignores changes the engine never announced');
		$this->check($this->hits('silent-environmental', EntityDamageEvent::CAUSE_SUFFOCATION) > 0, 'environmental npc notices silent changes');
		$this->check($this->causes['quiet-environmental'] === [], 'environmental npc in a clean place takes no damage');
		$still = $this->entity('silent-environmental');
		$this->check(!$still->hasGravity() && abs($still->getLocation()->y - 16.0) < 0.001 && $this->npcs['silent-environmental']->isStatic(), 'environmental npc stays static without gravity or motion');
		$this->check($this->entity('quiet')->ticksLived === $this->lived[0] && $this->entity('quiet-environmental')->ticksLived - $this->lived[1] >= 60, 'environmental npc ticks every cycle while the static one never does');
		$this->npcs['quiet-environmental']->setEnvironmental(false);
		$this->check(!$this->npcs['quiet-environmental']->isEnvironmental(), 'environmental flag turns off');
		$this->complete = true;
		($this->done)();
	}

	public function finish() : void{
		try{
			$this->check($this->manager->isClosed() && $this->manager->count() === 0, 'environment shutdown automatically closes owner context');
		}catch(Throwable $error){ $this->error ??= $error::class . ': ' . $error->getMessage(); }
		$result = ['result' => $this->complete && $this->error === null ? 'PASS' : 'FAIL', 'assertions' => count($this->passed), 'passed' => $this->passed, 'error' => $this->error];
		file_put_contents($this->plugin->getDataFolder() . 'environment-result.json', json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
	}
}
