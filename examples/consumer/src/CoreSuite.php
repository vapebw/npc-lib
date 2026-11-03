<?php

declare(strict_types=1);

namespace NpcLibP0Consumer;

use Closure;
use NpcLib\EntityNpc;
use NpcLib\Npc;
use NpcLib\NpcManager;
use NpcLib\PlayerNpc;
use quark\entity\Human;
use quark\entity\Living;
use quark\entity\Location;
use quark\entity\Skin;
use quark\entity\Villager;
use quark\entity\effect\EffectInstance;
use quark\entity\effect\VanillaEffects;
use quark\event\entity\EntityDamageByEntityEvent;
use quark\event\entity\EntityDamageEvent;
use quark\event\entity\EntityDeathEvent;
use quark\event\EventPriority;
use quark\event\HandlerListManager;
use quark\plugin\PluginBase;
use quark\scheduler\ClosureTask;
use quark\world\World;
use RuntimeException;
use stdClass;
use Throwable;
use WeakReference;

final class CoreSuite{
	private NpcManager $manager;
	private PlayerNpc $player;
	private EntityNpc $villager;
	private EntityNpc $moving;
	private PlayerNpc $corpse;
	private int $corpseDeaths = 0;
	private int $corpseSpawns = 0;
	/** @var null|\quark\event\RegisteredListener<\quark\event\entity\EntitySpawnEvent> */
	private ?\quark\event\RegisteredListener $spawnObserver = null;
	private Skin $skin;
	private World $world;
	/** @var list<string> */
	private array $passed = [];
	private ?string $error = null;
	private bool $complete = false;
	/** @var array<string, int|float> */
	private array $metrics = [];
	/** @var list<Living> */
	private array $baseline = [];
	/** @var list<WeakReference<Living>> */
	private array $released = [];
	private int $sampleStart = 0;
	private int $cpuStart = 0;

	public function __construct(private PluginBase $plugin){
		$world = $plugin->getServer()->getWorldManager()->getDefaultWorld();
		if($world === null){ throw new RuntimeException('Missing world'); }
		$this->world = $world;
		$this->skin = new Skin('npc-test', str_repeat("\xff", 64 * 64 * 4));
		$this->manager = new NpcManager($plugin);
		$this->stage($this->create(...), 5);
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
				$this->plugin->getLogger()->error('P1P2 suite FAIL ' . $this->error);
				$this->plugin->getLogger()->info('P1P2 CORE READY');
			}
		}), $delay);
	}

	private function location(float $x = 1.5) : Location{ return new Location($x, 16, 1.5, $this->world, 0, 0); }

	private function create() : void{
		$this->player = $this->manager->createPlayer($this->location(), $this->skin, 'PlayerProbe');
		$this->villager = $this->manager->createVillager($this->location(3.5), 'VillagerProbe');
		$this->check($this->manager->count() === 2 && $this->manager->get($this->player->getId()) === $this->player, 'player lookup');
		$this->check($this->manager->get($this->villager->getId()) === $this->villager && $this->manager->get(-1) === null, 'villager lookup and missing');
		$this->check($this->player->getId() !== $this->villager->getId(), 'unique runtime IDs');
		$this->check($this->world->getEntity($this->player->getId()) instanceof Human && $this->world->getEntity($this->villager->getId()) instanceof Villager, 'native entity types');
		$this->check(!$this->player->canSaveWithChunk() && !$this->villager->canSaveWithChunk(), 'transient chunks');
		$this->player->setName('Changed');
		$this->player->setNameVisible(false);
		$this->check($this->player->getName() === 'Changed' && !$this->player->isNameVisible(), 'name visibility');
		$this->player->setSkin($this->skin);
		$this->check($this->player->getSkin() === $this->skin, 'native skin update');
		$this->villager->setProfession(Villager::PROFESSION_LIBRARIAN);
		$this->check($this->villager->getProfession() === Villager::PROFESSION_LIBRARIAN, 'villager profession');
		$probe = $this->plugin->getServer()->getPluginManager()->getPlugin('NpcLibOwnerProbe');
		$this->check($probe !== null && $probe->isEnabled(), 'second plugin loaded');
		if($probe === null){ throw new RuntimeException('Missing ownership probe'); }
		$other = new NpcManager($probe);
		$owned = $other->createVillager($this->location(5.5));
		$this->check($other->get($this->player->getId()) === null && $this->manager->get($owned->getId()) === null, 'ownership isolation');
		$this->plugin->getServer()->getPluginManager()->disablePlugin($probe);
		$this->check($other->isClosed() && $owned->isClosed() && $other->count() === 0, 'foreign plugin automatic cleanup');
		$this->check(!$this->manager->isClosed() && !$this->player->isClosed(), 'foreign disable leaves owner intact');
		$this->moving = $this->manager->createVillager($this->location(6.5));
		$this->moving->setStatic(false);
		$this->moving->setMotion(new \pocketmine\math\Vector3(0.25, 0, 0));
		$this->stage($this->combat(...), 5);
	}

	private function combat() : void{
		$this->check(!isset($this->world->updateEntities[$this->player->getId()]) && !isset($this->world->updateEntities[$this->villager->getId()]), 'static airborne NPCs sleep');
		$this->check($this->player->isStatic() && $this->villager->isStatic(), 'static by default');
		$health = $this->villager->getHealth();
		$this->check(!$this->villager->damage(2) && $this->villager->getHealth() === $health, 'default invulnerability');
		$this->villager->setInvulnerable(false);
		$damageCalls = 0;
		$this->villager->onDamage(function(Npc $npc, EntityDamageEvent $event) use (&$damageCalls) : void{ ++$damageCalls; $event->cancel(); });
		$this->check(!$this->villager->damage(2) && $damageCalls === 1 && $this->villager->getHealth() === $health, 'damage callback cancellation');
		$this->villager->onDamage(null);
		$this->check($this->villager->damage(2) && $this->villager->getHealth() === $health - 2, 'native applied damage');
		$this->check(isset($this->world->updateEntities[$this->villager->getId()]), 'damage wakes native cooldown');
		$victim = $this->manager->createVillager($this->location(7.5));
		$victim->setInvulnerable(false);
		$entity = $this->world->getEntity($victim->getId());
		if(!$entity instanceof Living){ throw new RuntimeException('Missing victim'); }
		$prior = $this->plugin->getServer()->getPluginManager()->registerEvent(EntityDamageEvent::class, static function(EntityDamageEvent $event) use ($entity) : void{
			if($event->getEntity() === $entity){ $event->cancel(); }
		}, EventPriority::LOWEST, $this->plugin);
		$preCalls = 0;
		$victim->onDamage(static function(Npc $npc, EntityDamageEvent $event) use (&$preCalls) : void{ ++$preCalls; $event->uncancel(); });
		$this->check(!$victim->damage(2) && $preCalls === 0, 'preexisting cancellation skips callbacks and cannot be raised');
		HandlerListManager::global()->unregisterAll($prior);
		$victim->onDamage(null);
		$victim->setCancelDamage(true);
		$this->check(!$victim->damage(2), 'configured damage cancellation');
		$victim->setCancelDamage(false);
		$actor = $this->world->getEntity($this->player->getId());
		if(!$actor instanceof Human){ throw new RuntimeException('Missing actor'); }
		$attackCalls = 0;
		$victim->onAttack(function(Npc $npc, ?\quark\player\Player $player, EntityDamageByEntityEvent $event) use (&$attackCalls) : void{
			++$attackCalls;
			$this->check($player === null, 'nonplayer attacker identified as null');
			$npc->close();
		});
		$event = new EntityDamageByEntityEvent($actor, $entity, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 1);
		$entity->attack($event);
		$this->check($attackCalls === 1 && $event->isCancelled() && $victim->isClosed() && $entity->isClosed(), 'close during native attack');
		$this->check($this->manager->get($victim->getId()) === null, 'close removes registry immediately');
		$marker = new stdClass();
		$weak = WeakReference::create($marker);
		$temporary = $this->manager->createPlayer($this->location(9.5), $this->skin);
		$backend = $this->world->getEntity($temporary->getId());
		if(!$backend instanceof Living){ throw new RuntimeException('Missing backend'); }
		$entityWeak = WeakReference::create($backend);
		$temporary->onDamage(static function(Npc $npc, EntityDamageEvent $event) use ($marker) : void{ $marker->called = true; });
		unset($marker, $backend);
		$temporary->close();
		gc_collect_cycles();
		$this->check($weak->get() === null && $entityWeak->get() === null, 'close releases backend and captured closure');
		$this->check(!$this->manager->remove($temporary->getId()), 'remove is idempotent');
		try{ $temporary->getLocation(); $this->check(false, 'closed access rejected'); }
		catch(\LogicException){ $this->check(true, 'closed access rejected'); }
		$external = $this->manager->createVillager($this->location(11.5));
		$this->world->getEntity($external->getId())?->close();
		$this->check($external->isClosed() && $this->manager->get($external->getId()) === null, 'external native close invalidates handle');
		$chain = $this->manager->createVillager($this->location(12.5));
		$chain->setInvulnerable(false);
		$chain->onAttack(static function(Npc $npc, ?\quark\player\Player $player, EntityDamageByEntityEvent $event) : void{ $event->cancel(); });
		$chain->onDamage(static function(Npc $npc, EntityDamageEvent $event) : void{ $event->uncancel(); });
		$chainEntity = $this->world->getEntity($chain->getId());
		if(!$chainEntity instanceof Living){ throw new RuntimeException('Missing chain entity'); }
		$chainEvent = new EntityDamageByEntityEvent($actor, $chainEntity, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 1);
		$chainEntity->attack($chainEvent);
		$this->check($chainEvent->isCancelled() && $chain->getHealth() === 20.0, 'attack cancellation survives damage callback');
		$chain->close();
		$protected = $this->manager->createVillager($this->location(12.5));
		$protected->onDamage(static function(Npc $npc, EntityDamageEvent $event) : void{
			$npc->setName('Modified in callback');
			$npc->setInvulnerable(false);
			$event->uncancel();
		});
		$this->check(!$protected->damage(1) && $protected->getHealth() === 20.0 && $protected->getName() === 'Modified in callback', 'initial protection survives callback mutation');
		$this->check($protected->damage(1) && $protected->getHealth() === 19.0, 'policy mutation applies next event');
		$protected->close();
		$scope = new NpcManager($this->plugin);
		$scopeWeak = WeakReference::create($scope);
		$scope->createVillager($this->location(14.5));
		$scope->close();
		unset($scope);
		gc_collect_cycles();
		$this->check($scopeWeak->get() === null, 'closed manager has no listener retention');
		$spawn = $this->world->getSpawnLocation();
		$cx = ($spawn->getFloorX() >> 4) - 2;
		$cz = ($spawn->getFloorZ() >> 4) - 2;
		$this->world->orderChunkPopulation($cx, $cz, null)->onCompletion(
			function(\quark\world\format\Chunk $chunk) use ($cx, $cz) : void{
				$this->stage(function() use ($cx, $cz) : void{
					$this->check($this->world->loadChunk($cx, $cz) !== null, 'real chunk generated for unload');
					$chunkNpc = $this->manager->createVillager(new Location($cx * 16 + 0.5, 16, $cz * 16 + 0.5, $this->world, 0, 0));
					$this->check($this->world->unloadChunk($cx, $cz, false) && $chunkNpc->isClosed() && $this->manager->get($chunkNpc->getId()) === null, 'native chunk unload cleans owned NPC');
					$this->check($this->world->loadChunk($cx, $cz) !== null && $this->world->getChunkEntities($cx, $cz) === [], 'saved chunk does not restore NPC');
					$this->stage($this->dormantSetup(...), 25);
				}, 1);
			},
			function() : void{ $this->stage(static function() : void{ throw new RuntimeException('Native chunk population failed'); }, 1); }
		);

	}

	/** @var array<string, Npc> */
	private array $dormant = [];

	private function dormantSetup() : void{
		foreach(['fire', 'effect', 'meta', 'teleport'] as $i => $name){
			$this->dormant[$name] = $this->manager->createVillager($this->location(20.5 + $i), 'Dormant' . $name);
		}
		$spawn = $this->world->getSpawnLocation();
		$this->nativeControl = new Villager(new Location($spawn->x, $spawn->y, $spawn->z, $this->world, 0, 0));
		$this->nativeControl->setCanSaveWithChunk(false);
		$this->stage($this->dormantApply(...), 10);
	}

	private function dormantApply() : void{
		$asleep = true;
		foreach($this->dormant as $npc){ $asleep = $asleep && !isset($this->world->updateEntities[$npc->getId()]); }
		$this->check($asleep, 'dormant probes sleep before external changes');
		$fire = $this->world->getEntity($this->dormant['fire']->getId());
		$effect = $this->world->getEntity($this->dormant['effect']->getId());
		$meta = $this->world->getEntity($this->dormant['meta']->getId());
		if(!$fire instanceof Living || !$effect instanceof Living || !$meta instanceof Living){ throw new RuntimeException('Missing dormant entity'); }
		$fire->setOnFire(3);
		$effect->getEffects()->add(new EffectInstance(VanillaEffects::SPEED(), 30));
		$meta->setScale(2.0);
		$this->dormantFireBefore = $fire->getFireTicks();
		$this->nativeAsleep = $this->nativeControl !== null && !isset($this->world->updateEntities[$this->nativeControl->getId()]);
		$this->nativeControl?->setOnFire(3);
		$this->nativeControl?->getEffects()->add(new EffectInstance(VanillaEffects::SPEED(), 30));
		$this->stage($this->dormantObserve(...), 10);
	}

	private int $dormantFireBefore = 0;
	private ?Villager $nativeControl = null;
	private bool $nativeAsleep = false;

	private function dormantObserve() : void{
		$fire = $this->world->getEntity($this->dormant['fire']->getId());
		$effect = $this->world->getEntity($this->dormant['effect']->getId());
		$meta = $this->world->getEntity($this->dormant['meta']->getId());
		if(!$fire instanceof Living || !$effect instanceof Living || !$meta instanceof Living){ throw new RuntimeException('Missing dormant entity'); }
		$dirty = new \ReflectionProperty(\quark\entity\Entity::class, 'networkPropertiesDirty');
		$results = [
			'external fire ticks down' => $fire->getFireTicks() < $this->dormantFireBefore,
			'external effect ticks down' => ($effect->getEffects()->get(VanillaEffects::SPEED())?->getDuration() ?? 0) < 30,
			'external metadata synced' => $dirty->getValue($meta) === false,
		];
		$this->plugin->getLogger()->info('DORMANT ' . json_encode($results, JSON_THROW_ON_ERROR));
		$this->plugin->getLogger()->info('NATIVE-CONTROL ' . json_encode(['fire' => $this->nativeControl?->getFireTicks(), 'speed' => $this->nativeControl?->getEffects()->get(VanillaEffects::SPEED())?->getDuration(), 'asleep_before' => $this->nativeAsleep, 'queued' => $this->nativeControl !== null && isset($this->world->updateEntities[$this->nativeControl->getId()])], JSON_THROW_ON_ERROR));
		foreach($results as $name => $ok){ $this->check($ok, 'dormant ' . $name); }
		$this->stage($this->dormantExpire(...), 70);
	}

	private function dormantExpire() : void{
		$fire = $this->world->getEntity($this->dormant['fire']->getId());
		$effect = $this->world->getEntity($this->dormant['effect']->getId());
		if(!$fire instanceof Living || !$effect instanceof Living){ throw new RuntimeException('Missing dormant entity'); }
		$this->check(!$fire->isOnFire() && !$effect->getEffects()->has(VanillaEffects::SPEED()), 'dormant fire and effect expire');
		$asleep = true;
		foreach($this->dormant as $npc){ $asleep = $asleep && !isset($this->world->updateEntities[$npc->getId()]); }
		$this->check($asleep, 'dormant probes sleep again');
		$teleport = $this->world->getEntity($this->dormant['teleport']->getId());
		if(!$teleport instanceof Living){ throw new RuntimeException('Missing teleport entity'); }
		$target = new Location(30.5, 16, 1.5, $this->world, 90, 0);
		$teleport->teleport($target);
		$this->check(isset($this->world->updateEntities[$teleport->getId()]), 'external teleport wakes entity');
		foreach($this->dormant as $npc){ $npc->close(); }
		$this->dormant = [];
		$this->nativeControl?->close();
		$this->nativeControl = null;
		$this->stage($this->deathAndLoad(...), 5);
	}

	private function deathAndLoad() : void{
		$this->check(!$this->moving->isStatic() && $this->moving->getLocation()->x > 6.5, 'optional movement uses native physics');
		$this->moving->close();
		$this->check(!isset($this->world->updateEntities[$this->villager->getId()]), 'native cooldown completes and sleeps');
		$this->check($this->villager->damage(1), 'damage works after dormant cooldown');
		$dying = $this->manager->createPlayer($this->location(13.5), $this->skin);
		$entity = $this->world->getEntity($dying->getId());
		if(!$entity instanceof Living){ throw new RuntimeException('Missing dying entity'); }
		$death = 0;
		$dying->onDeath(static function(Npc $npc, EntityDeathEvent $event) use (&$death) : void{ ++$death; $npc->close(); });
		$entity->onUpdate($this->plugin->getServer()->getTick() + 1);
		$entity->setHealth(0);
		$this->check($death === 1 && $dying->isClosed() && $entity->isClosed(), 'close during native death and health setter');
		$instant = $this->manager->createVillager($this->location(14.5));
		$instant->setInvulnerable(false);
		$instantDeaths = 0;
		$instant->onDeath(static function(Npc $npc, EntityDeathEvent $event) use (&$instantDeaths) : void{ ++$instantDeaths; $npc->close(); });
		$instant->damage(100);
		$this->check($instantDeaths === 1 && $instant->isClosed(), 'lethal damage before first update fires death once');
		$this->corpse = $this->manager->createPlayer($this->location(15.5), $this->skin);
		$this->corpse->setInvulnerable(false);
		$this->corpse->onDeath(function(Npc $npc, EntityDeathEvent $event) : void{ ++$this->corpseDeaths; });
		$this->spawnObserver = $this->plugin->getServer()->getPluginManager()->registerEvent(\quark\event\entity\EntitySpawnEvent::class, function(\quark\event\entity\EntitySpawnEvent $event) : void{
			if($event->getEntity()->getId() === $this->corpse->getId()){ ++$this->corpseSpawns; }
		}, EventPriority::MONITOR, $this->plugin);
		$this->corpse->damage(100);
		$this->stage($this->startBaseline(...), 45);
	}

	private function startBaseline() : void{
		$this->check($this->corpseDeaths === 1, 'first frame human death emitted once');
		$this->check($this->corpseSpawns === 1, 'first update spawn emitted once while dead');
		$this->check($this->corpse->isClosed() && $this->manager->get($this->corpse->getId()) === null, 'native death animation despawns without manual close');
		if($this->spawnObserver !== null){ HandlerListManager::global()->unregisterAll($this->spawnObserver); $this->spawnObserver = null; }
		$this->player->close();
		$this->villager->close();
		$this->metrics['baseline_count'] = 200;
		for($i = 0; $i < 200; ++$i){
			$entity = $i % 2 === 0 ? new Human($this->location(), $this->skin) : new Villager($this->location());
			$entity->setHasGravity(false);
			$entity->setCanSaveWithChunk(false);
			$this->baseline[] = $entity;
		}
		$this->sampleStart = hrtime(true);
		$this->cpuStart = $this->cpuMicros();
		$this->stage($this->measureBaseline(...), 20);
	}

	private function measureBaseline() : void{
		$this->metrics['native_idle_wall_ns'] = hrtime(true) - $this->sampleStart;
		$this->metrics['native_idle_cpu_us'] = $this->cpuMicros() - $this->cpuStart;
		$queued = 0;
		foreach($this->baseline as $entity){ if(isset($this->world->updateEntities[$entity->getId()])){ ++$queued; } $entity->close(); }
		$this->metrics['native_queued_after_idle'] = $queued;
		$this->baseline = [];
		gc_collect_cycles();
		$this->metrics['memory_before_npcs'] = memory_get_usage();
		$start = hrtime(true);
		for($i = 0; $i < 200; ++$i){
			$npc = $i % 2 === 0 ? $this->manager->createPlayer($this->location(), $this->skin) : $this->manager->createVillager($this->location());
			$entity = $this->world->getEntity($npc->getId());
			if(!$entity instanceof Living){ throw new RuntimeException('Missing load entity'); }
			$this->released[] = WeakReference::create($entity);
		}
		$this->metrics['create_200_ns'] = hrtime(true) - $start;
		$this->metrics['memory_with_npcs'] = memory_get_usage();
		$this->sampleStart = hrtime(true);
		$this->cpuStart = $this->cpuMicros();
		$this->stage($this->measureNpcs(...), 20);
	}

	private function measureNpcs() : void{
		$this->metrics['npc_idle_wall_ns'] = hrtime(true) - $this->sampleStart;
		$this->metrics['npc_idle_cpu_us'] = $this->cpuMicros() - $this->cpuStart;
		$queued = 0;
		foreach($this->manager->getAll() as $candidate){ if(isset($this->world->updateEntities[$candidate->getId()])){ ++$queued; } }
		$this->metrics['npc_queued_after_idle'] = $queued;
		$this->check($queued === 0, '200 NPCs dormant');
		$start = hrtime(true);
		foreach($this->manager->getAll() as $closing){ $closing->close(); }
		unset($candidate, $closing);
		gc_collect_cycles();
		$this->metrics['remove_200_ns'] = hrtime(true) - $start;
		$this->metrics['memory_after_close'] = memory_get_usage();
		foreach($this->released as $weak){ $this->check($weak->get() === null, 'load entity released'); }
		$this->check($this->manager->count() === 0, 'mass cleanup');
		$this->player = $this->manager->createPlayer($this->location(), $this->skin, 'ShutdownProbe');
		$this->complete = true;
		$this->plugin->getLogger()->info('P1P2 CORE READY');
	}

	private function cpuMicros() : int{
		$usage = getrusage();
		if($usage === false){ return 0; }
		$total = 0;
		foreach(['ru_utime.tv_sec' => 1000000, 'ru_stime.tv_sec' => 1000000, 'ru_utime.tv_usec' => 1, 'ru_stime.tv_usec' => 1] as $key => $multiplier){
			$value = $usage[$key] ?? null;
			if(!is_int($value)){ throw new RuntimeException('Unsupported CPU counter'); }
			$total += $value * $multiplier;
		}
		return $total;
	}

	public function finish() : void{
		try{
			$this->check($this->manager->isClosed() && $this->manager->count() === 0 && $this->player->isClosed(), 'shutdown automatically closes owner context');
		}catch(Throwable $error){ $this->error ??= $error::class . ': ' . $error->getMessage(); }
		$result = ['result' => $this->complete && $this->error === null ? 'PASS' : 'FAIL', 'assertions' => count($this->passed), 'passed' => $this->passed, 'error' => $this->error, 'metrics' => $this->metrics];
		file_put_contents($this->plugin->getDataFolder() . 'p1p2-result.json', json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
	}
}
