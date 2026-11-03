<?php

declare(strict_types=1);

namespace NpcLibHarness;

use Closure;
use InvalidArgumentException;
use LogicException;
use NpcLib\equipment\Equipment;
use NpcLib\npc\EntityNpc;
use NpcLib\npc\Npc;
use NpcLib\NpcManager;
use NpcLib\npc\PlayerNpc;
use NpcLib\npc\VillagerNpc;
use NpcLib\npc\ZombieNpc;
use pocketmine\math\Vector3;
use quark\entity\effect\EffectInstance;
use quark\entity\effect\VanillaEffects;
use quark\entity\Entity;
use quark\entity\Human;
use quark\entity\Living;
use quark\entity\Location;
use quark\entity\object\ExperienceOrb;
use quark\entity\object\ItemEntity;
use quark\entity\Skin;
use quark\entity\Zombie;
use quark\event\entity\EntityDamageEvent;
use quark\event\entity\EntityDeathEvent;
use quark\event\EventPriority;
use quark\event\HandlerListManager;
use quark\item\VanillaItems;
use quark\plugin\PluginBase;
use quark\scheduler\ClosureTask;
use quark\world\World;
use ReflectionProperty;
use RuntimeException;
use Throwable;
use WeakReference;

final class FeatureSuite{
	private NpcManager $manager;
	private World $world;
	private Skin $skin;
	private int $baselineListeners = 0;
	private PlayerNpc $player;
	private VillagerNpc $villager;
	private ZombieNpc $zombie;
	private EntityNpc $squid;
	/** @var list<Npc> */
	private array $all = [];
	/** @var list<string> */
	private array $passed = [];
	private ?string $error = null;
	private bool $complete = false;
	/** @var array<string, int|float> */
	private array $metrics = [];

	public function __construct(private PluginBase $plugin, private Closure $done){
		$world = $plugin->getServer()->getWorldManager()->getDefaultWorld();
		if($world === null){ throw new RuntimeException('Missing world'); }
		$this->world = $world;
		$this->skin = new Skin('npc-feature', str_repeat("\xff", 64 * 64 * 4));
		$this->manager = new NpcManager($plugin);
		$this->baselineListeners = $this->listenerTotal();
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
				$this->plugin->getLogger()->error('feature suite FAIL ' . $this->error);
				($this->done)();
			}
		}), $delay);
	}

	private function location(float $x) : Location{ return new Location($x, 16, 1.5, $this->world, 0, 0); }

	private function listenerTotal() : int{
		$total = 0;
		foreach([EntityDamageEvent::class, EntityDeathEvent::class, \quark\event\entity\EntityDespawnEvent::class, \quark\event\player\PlayerEntityInteractEvent::class, \quark\event\plugin\PluginDisableEvent::class] as $event){
			foreach(EventPriority::ALL as $priority){ $total += count(HandlerListManager::global()->getListFor($event)->getListenersByPriority($priority)); }
		}
		return $total;
	}

	private function entity(Npc $npc) : Living{
		$entity = $this->world->getEntity($npc->getId());
		if(!$entity instanceof Living){ throw new RuntimeException('Missing entity'); }
		return $entity;
	}

	/**
	 * @param Closure(): mixed $operation
	 * @param class-string<Throwable> $type
	 */
	private function throws(Closure $operation, string $type, string $name) : void{
		try{ $operation(); }
		catch(Throwable $error){ $this->check($error instanceof $type, $name); return; }
		$this->check(false, $name);
	}

	private function asleep(Npc ...$npcs) : bool{
		foreach($npcs as $npc){ if(isset($this->world->updateEntities[$npc->getId()])){ return false; } }
		return true;
	}

	private function entityCount() : int{ return count($this->world->getEntities()); }

	private function create() : void{
		$sword = VanillaItems::DIAMOND_SWORD();
		$equipment = new Equipment(mainHand: $sword, offHand: VanillaItems::IRON_SWORD(), helmet: VanillaItems::IRON_HELMET(), chestplate: VanillaItems::DIAMOND_CHESTPLATE(), boots: VanillaItems::LEATHER_BOOTS());
		$this->player = $this->manager->createPlayer($this->location(1.5), $this->skin, 'FeaturePlayer', $equipment);
		$playerEntity = $this->entity($this->player);
		$this->check($playerEntity instanceof Human && $playerEntity->getInventory()->getItemInHand()->equals($sword) && $this->player->getMainHand()->equals($sword), 'player spawns with main hand');
		$this->check($this->player->getOffHand()->equals(VanillaItems::IRON_SWORD()) && $this->player->getHelmet()->equals(VanillaItems::IRON_HELMET()) && $this->player->getChestplate()->equals(VanillaItems::DIAMOND_CHESTPLATE()) && $this->player->getLeggings()->isNull() && $this->player->getBoots()->equals(VanillaItems::LEATHER_BOOTS()), 'player spawns with offhand and armor');
		$this->check($playerEntity instanceof Human && $playerEntity->getArmorInventory()->getHelmet()->equals($this->player->getHelmet()), 'equipment reuses native inventories');
		$this->villager = $this->manager->createVillager($this->location(3.5), 'FeatureVillager');
		$this->zombie = $this->manager->createZombie($this->location(5.5), 'FeatureZombie', new Equipment(helmet: VanillaItems::IRON_HELMET(), boots: VanillaItems::LEATHER_BOOTS()));
		$this->check($this->entity($this->zombie) instanceof Zombie && $this->zombie->getHelmet()->equals(VanillaItems::IRON_HELMET()) && $this->zombie->getChestplate()->isNull(), 'zombie spawns with armor');
		$this->squid = $this->manager->createMob(CustomSquid::class, $this->location(7.5), 'FeatureSquid');
		$this->check($this->entity($this->squid) instanceof CustomSquid, 'custom mob class through createMob');
		$this->all = [$this->player, $this->villager, $this->zombie];
		$this->check($this->manager->count() === 4, 'four types registered');
		foreach([...$this->all, $this->squid] as $npc){
			$this->check($this->manager->get($npc->getId()) === $npc && !$npc->canSaveWithChunk() && $npc->isStatic(), 'shared lifecycle ' . $npc::class);
		}
		$ids = array_map(static fn(Npc $npc) : int => $npc->getId(), [...$this->all, $this->squid]);
		$this->check(count(array_unique($ids)) === 4, 'unique runtime IDs across types');

		$before = $this->entityCount();
		$this->throws(fn() => $this->manager->createMob(Zombie::class, $this->location(9.5)), InvalidArgumentException::class, 'createMob rejects plain Quark entity');
		$this->throws(fn() => $this->manager->createMob(\NpcLib\internal\entity\PlayerEntity::class, $this->location(9.5)), InvalidArgumentException::class, 'createMob rejects human entity');
		$this->throws(fn() => $this->manager->createMob(\stdClass::class, $this->location(9.5)), InvalidArgumentException::class, 'createMob rejects non entity');
		$this->throws(fn() => $this->manager->createZombie($this->location(9.5), '', new Equipment(helmet: VanillaItems::DIAMOND_SWORD())), InvalidArgumentException::class, 'invalid initial armor aborts creation');
		$this->throws(fn() => $this->manager->createZombie($this->location(9.5), '', new Equipment(mainHand: VanillaItems::DIAMOND_SWORD())), InvalidArgumentException::class, 'zombie rejects hands at creation');
		$this->throws(fn() => $this->manager->createPlayer($this->location(9.5), $this->skin, '', new Equipment(chestplate: VanillaItems::IRON_HELMET())), InvalidArgumentException::class, 'wrong armor slot aborts player creation');
		$this->check($this->manager->count() === 4 && $this->entityCount() === $before, 'failed creations leave no entity or registry entry');

		$this->throws(fn() => $this->zombie->equip(new Equipment(offHand: VanillaItems::DIAMOND_SWORD())), InvalidArgumentException::class, 'zombie equip rejects hands');
		$this->throws(fn() => $this->player->setHelmet(VanillaItems::DIAMOND_SWORD()), InvalidArgumentException::class, 'helmet slot rejects sword');
		$this->throws(fn() => $this->player->setBoots(VanillaItems::IRON_HELMET()), InvalidArgumentException::class, 'boots slot rejects helmet');
		$this->check($this->player->getBoots()->equals(VanillaItems::LEATHER_BOOTS()), 'rejected armor leaves slot unchanged');
		$mainBefore = $this->player->getMainHand();
		$this->throws(fn() => $this->player->equip(new Equipment(mainHand: VanillaItems::IRON_SWORD(), helmet: VanillaItems::DIAMOND_HELMET(), leggings: VanillaItems::DIAMOND_SWORD())), InvalidArgumentException::class, 'equip with an invalid slot throws');
		$this->check($this->player->getMainHand()->equals($mainBefore) && $this->player->getHelmet()->equals(VanillaItems::IRON_HELMET()), 'failed equip changes nothing');
		[$guard, $dummy] = Showcase::spawn($this->manager, $this->location(30.5), $this->skin);
		$this->check($guard->getHelmet()->equals(VanillaItems::IRON_HELMET()) && $guard->getScoreTag() === 'click me' && $dummy->getHelmet()->equals(VanillaItems::IRON_HELMET()) && !$dummy->isInvulnerable(), 'showcase example spawns');
		$dummy->damage(18);
		$this->check($dummy->getScale() === 0.75, 'showcase mob callback reacts to damage');
		$guard->close();
		$dummy->close();
		$this->stage($this->appearance(...), 15);
	}

	private function appearance() : void{
		$this->check($this->asleep(...$this->all), 'player villager and zombie sleep after spawn');
		$this->check($this->listenerTotal() === $this->baselineListeners, 'no listeners added per entity');
		foreach($this->all as $npc){
			$name = $npc::class;
			$this->throws(fn() => $npc->setScale(NAN), InvalidArgumentException::class, 'scale rejects NaN ' . $name);
			$this->throws(fn() => $npc->setScale(INF), InvalidArgumentException::class, 'scale rejects infinity ' . $name);
			$this->throws(fn() => $npc->setScale(0), InvalidArgumentException::class, 'scale rejects zero ' . $name);
			$this->throws(fn() => $npc->setScale(Npc::MAX_SCALE + 1), InvalidArgumentException::class, 'scale rejects oversize ' . $name);
			$this->throws(fn() => $npc->setRotation(NAN, 0), InvalidArgumentException::class, 'rotation rejects NaN yaw ' . $name);
			$this->throws(fn() => $npc->setRotation(0, 91), InvalidArgumentException::class, 'rotation rejects pitch above 90 ' . $name);
			$this->throws(fn() => $npc->setRotation(0, -91), InvalidArgumentException::class, 'rotation rejects pitch below -90 ' . $name);
			$this->throws(fn() => $npc->lookAt(new Vector3(INF, 0, 0)), InvalidArgumentException::class, 'lookAt rejects infinity ' . $name);
			$location = $npc->getLocation();
			$eye = new Vector3($location->x, $location->y + $this->entity($npc)->getEyeHeight(), $location->z);
			$this->throws(fn() => $npc->lookAt($eye), InvalidArgumentException::class, 'lookAt rejects coincident target ' . $name);
			$this->check($npc->getScale() === 1.0 && $npc->getLocation()->yaw === 0.0, 'rejected values change nothing ' . $name);

			$npc->setScale(2.0);
			$npc->setInvisible(true);
			$npc->setScoreTag('score-' . $npc->getId());
			$npc->setRotation(370.0, 45.0);
			$this->check($npc->getScale() === 2.0 && $npc->isInvisible() && $npc->getScoreTag() === 'score-' . $npc->getId(), 'appearance getters ' . $name);
			$this->check($npc->getLocation()->yaw === 10.0 && $npc->getLocation()->pitch === 45.0, 'yaw normalized into range ' . $name);
			$npc->setRotation(-1e-20, 0.0);
			$this->check($npc->getLocation()->yaw === 0.0, 'tiny negative yaw stays below 360 ' . $name);
			$npc->setRotation(-90.0, -90.0);
			$this->check($npc->getLocation()->yaw === 270.0 && $npc->getLocation()->pitch === -90.0, 'negative yaw wraps ' . $name);
			$this->check(isset($this->world->updateEntities[$npc->getId()]), 'appearance change wakes dormant entity ' . $name);
		}
		$this->player->setSneaking(true);
		$this->check($this->player->isSneaking(), 'player sneaking');
		$this->zombie->lookAt(new Vector3(105.5, 16, 1.5));
		$east = $this->zombie->getLocation();
		$this->check(abs($east->yaw - 270.0) < 0.001, 'lookAt east yaw');
		$this->zombie->lookAt(new Vector3(5.5, 80, 1.5));
		$up = $this->zombie->getLocation();
		$this->check($up->pitch === -90.0 && $up->yaw === $east->yaw, 'lookAt straight up keeps yaw');
		$this->zombie->lookAt(new Vector3(5.5, -40, 1.5));
		$this->check($this->zombie->getLocation()->pitch === 90.0, 'lookAt straight down');
		$this->stage($this->appearanceSettled(...), 6);
	}

	private function appearanceSettled() : void{
		$dirty = new ReflectionProperty(Entity::class, 'networkPropertiesDirty');
		foreach($this->all as $npc){
			$this->check($dirty->getValue($this->entity($npc)) === false, 'metadata flushed after wake ' . $npc::class);
		}
		$this->check($this->asleep(...$this->all), 'types sleep again after appearance changes');
		$villagerEntity = $this->world->getEntity($this->villager->getId());
		if(!$villagerEntity instanceof \quark\entity\Villager){ throw new RuntimeException('Missing villager entity'); }
		$villagerEntity->setProfession(\quark\entity\Villager::PROFESSION_LIBRARIAN);
		$this->check(isset($this->world->updateEntities[$this->villager->getId()]), 'external profession change wakes villager');
		$this->player->setSneaking(false);
		foreach($this->all as $npc){
			$npc->setScale(1.0);
			$npc->setInvisible(false);
			$npc->setRotation(0.0, 0.0);
		}
		$this->stage($this->equipmentApi(...), 6);
	}

	private function equipmentApi() : void{
		$p = $this->player;
		$p->setMainHand(null);
		$p->setOffHand(null);
		$p->setHelmet(null);
		$this->check($p->getMainHand()->isNull() && $p->getOffHand()->isNull() && $p->getHelmet()->isNull(), 'player equipment clears');
		$p->setMainHand(VanillaItems::DIAMOND_SWORD());
		$p->setOffHand(VanillaItems::IRON_SWORD());
		$p->setLeggings(VanillaItems::DIAMOND_LEGGINGS());
		$this->check($p->getMainHand()->equals(VanillaItems::DIAMOND_SWORD()) && $p->getOffHand()->equals(VanillaItems::IRON_SWORD()) && $p->getLeggings()->equals(VanillaItems::DIAMOND_LEGGINGS()), 'player equipment updates without recreate');
		$p->equip(new Equipment(helmet: VanillaItems::DIAMOND_HELMET()));
		$this->check($p->getHelmet()->equals(VanillaItems::DIAMOND_HELMET()) && $p->getMainHand()->equals(VanillaItems::DIAMOND_SWORD()), 'equip leaves unspecified slots unchanged');
		$held = VanillaItems::DIAMOND_SWORD();
		$p->setMainHand($held);
		$held->setCount(5);
		$this->check($p->getMainHand()->getCount() === 1, 'equipment is copied not shared');
		$this->check($this->asleep($p), 'equipment changes do not keep NPC awake');
		$this->zombie->setChestplate(VanillaItems::DIAMOND_CHESTPLATE());
		$this->zombie->setChestplate(null);
		$this->check($this->zombie->getChestplate()->isNull(), 'zombie chestplate clear');

		$closer = $this->manager->createPlayer($this->location(11.5), $this->skin, 'ListenerCloser');
		$closerEntity = $this->entity($closer);
		$calls = 0;
		if(!$closerEntity instanceof Human){ throw new RuntimeException('Missing human'); }
		$closerEntity->getArmorInventory()->getListeners()->add(\quark\inventory\CallbackInventoryListener::onAnyChange(function() use ($closer, &$calls) : void{ ++$calls; $closer->close(); }));
		$weak = WeakReference::create($closerEntity);
		unset($closerEntity);
		$closer->setHelmet(VanillaItems::IRON_HELMET());
		$this->check($calls === 1 && $closer->isClosed() && $this->manager->get($closer->getId()) === null, 'close during armor inventory update');
		gc_collect_cycles();
		$this->check($weak->get() === null, 'entity released after close in inventory listener');
		$hand = $this->manager->createPlayer($this->location(11.5), $this->skin, 'HandCloser');
		$handEntity = $this->entity($hand);
		if(!$handEntity instanceof Human){ throw new RuntimeException('Missing human'); }
		$handCalls = 0;
		$handEntity->getInventory()->getListeners()->add(\quark\inventory\CallbackInventoryListener::onAnyChange(function() use ($hand, &$handCalls) : void{ ++$handCalls; $hand->close(); }));
		unset($handEntity);
		$hand->setMainHand(VanillaItems::DIAMOND_SWORD());
		$this->check($handCalls >= 1 && $hand->isClosed(), 'close during main hand update');
		$this->throws(fn() => $hand->getMainHand(), LogicException::class, 'closed NPC rejects equipment access');
		$this->throws(fn() => $closer->setScale(2.0), LogicException::class, 'closed NPC rejects appearance');
		$this->throws(fn() => $closer->lookAt(new Vector3(1, 1, 1)), LogicException::class, 'closed NPC rejects lookAt');
		$this->stage($this->combat(...), 3);
	}

	private function combat() : void{
		$hits = ['player' => 0, 'villager' => 0, 'zombie' => 0, 'squid' => 0];
		$labels = ['player' => $this->player, 'villager' => $this->villager, 'zombie' => $this->zombie, 'squid' => $this->squid];
		foreach($labels as $label => $npc){
			$health = $npc->getHealth();
			$this->check(!$npc->damage(1) && $npc->getHealth() === $health, 'default invulnerability ' . $label);
			$npc->setInvulnerable(false);
			$npc->onDamage(function(Npc $handle, EntityDamageEvent $event) use (&$hits, $label) : void{ ++$hits[$label]; });
			$this->check($npc->damage(1) && $npc->getHealth() < $health && $hits[$label] === 1, 'damage applies and callback fires ' . $label);
			$this->check(isset($this->world->updateEntities[$npc->getId()]), 'damage wakes cooldown ' . $label);
		}
		$this->stage(function() use ($labels) : void{ $this->deathAndDrops($labels); }, 25);
	}

	/** @param array<string, Npc> $labels */
	private function deathAndDrops(array $labels) : void{
		$deaths = 0;
		$dropsSeen = [];
		foreach($labels as $label => $npc){
			$npc->onDeath(function(Npc $handle, EntityDeathEvent $event) use (&$deaths, &$dropsSeen, $label) : void{
				++$deaths;
				$dropsSeen[$label] = [count($event->getDrops()), $event->getXpDropAmount()];
				$handle->close();
			});
		}
		$this->player->setMainHand(VanillaItems::DIAMOND_SWORD());
		$this->zombie->setHelmet(VanillaItems::IRON_HELMET());
		foreach($labels as $npc){ $npc->damage(1000); }
		$this->check($deaths === 4, 'death callback fires once per type');
		$this->check($dropsSeen === ['player' => [0, 0], 'villager' => [0, 0], 'zombie' => [0, 0], 'squid' => [0, 0]], 'death exposes no drops or xp');
		$leaks = 0;
		foreach($this->world->getEntities() as $entity){ if($entity instanceof ItemEntity || $entity instanceof ExperienceOrb){ ++$leaks; } }
		$this->check($leaks === 0, 'equipment and xp are not dropped on death');
		$this->check($this->manager->count() === 0 && array_filter($labels, static fn(Npc $npc) : bool => !$npc->isClosed()) === [], 'reentrant close during death for all types');
		$this->stage($this->nativeWake(...), 10);
	}

	private function nativeWake() : void{
		$zombie = $this->manager->createZombie($this->location(1.5), 'WakeZombie', new Equipment(helmet: VanillaItems::IRON_HELMET()));
		$player = $this->manager->createPlayer($this->location(3.5), $this->skin, 'WakePlayer');
		$this->all = [$zombie, $player];
		$this->stage(function() use ($zombie, $player) : void{
			$this->check($this->asleep($zombie, $player), 'new zombie and player sleep');
			$zombieEntity = $this->entity($zombie);
			$playerEntity = $this->entity($player);
			$zombieEntity->setOnFire(3);
			$zombieEntity->getEffects()->add(new EffectInstance(VanillaEffects::SPEED(), 30));
			$playerEntity->setOnFire(3);
			$playerEntity->getEffects()->add(new EffectInstance(VanillaEffects::SPEED(), 30));
			$before = [$zombieEntity->getFireTicks(), $playerEntity->getFireTicks()];
			$this->stage(function() use ($zombie, $player, $before) : void{
				$zombieEntity = $this->entity($zombie);
				$playerEntity = $this->entity($player);
				$this->check($zombieEntity->getFireTicks() < $before[0] && $playerEntity->getFireTicks() < $before[1], 'external fire ticks on zombie and player');
				$this->check(($zombieEntity->getEffects()->get(VanillaEffects::SPEED())?->getDuration() ?? 0) < 30, 'external effect ticks on zombie');
				$this->stage($this->staticToggle(...), 70);
			}, 10);
		}, 10);
	}

	private function staticToggle() : void{
		[$zombie, $player] = $this->all;
		if(!$zombie instanceof ZombieNpc || !$player instanceof PlayerNpc){ throw new RuntimeException('Unexpected handles'); }
		$zombieEntity = $this->entity($zombie);
		$this->check(!$zombieEntity->isOnFire() && !$zombieEntity->getEffects()->has(VanillaEffects::SPEED()), 'fire and effect expire on zombie');
		$this->check($this->asleep($zombie, $player), 'zombie and player sleep after timers');
		$this->throws(fn() => $zombie->setMotion(new Vector3(0.2, 0, 0)), LogicException::class, 'motion refused while static');
		$zombie->setStatic(false);
		$start = $zombie->getLocation()->x;
		$zombie->setMotion(new Vector3(0.25, 0, 0));
		$this->stage(function() use ($zombie, $start) : void{
			$this->check($zombie->getLocation()->x > $start, 'dynamic zombie moves with native physics');
			$zombie->setStatic(true);
			$this->check($this->entity($zombie)->getMotion()->equals(Vector3::zero()) && $zombie->isStatic(), 'static again zeroes motion');
			$at = $zombie->getLocation();
			$zombie->teleport(new Location(40.5, 16, 1.5, $this->world, 123.0, 12.0));
			$moved = $zombie->getLocation();
			$this->check($moved->x === 40.5 && $moved->yaw === 123.0 && $moved->pitch === 12.0 && $at->x !== $moved->x, 'teleport keeps requested rotation');
			$this->stage($this->multiManager(...), 12);
		}, 15);
	}

	private function multiManager() : void{
		$pluginManager = $this->plugin->getServer()->getPluginManager();
		$probe = $pluginManager->getPlugin('NpcLibPeer');
		if($probe === null){ throw new RuntimeException('Missing probe'); }
		if(!$probe->isEnabled()){ $pluginManager->enablePlugin($probe); }
		$this->check($probe->isEnabled(), 'second plugin enabled');
		$other = new NpcManager($probe);
		$owned = $other->createZombie($this->location(20.5), 'Foreign', new Equipment(helmet: VanillaItems::IRON_HELMET()));
		[$zombie, $player] = $this->all;
		$foreignHits = 0;
		$owned->setInvulnerable(false);
		$owned->onDamage(function() use (&$foreignHits) : void{ ++$foreignHits; });
		$mine = 0;
		$zombie->onDamage(function() use (&$mine) : void{ ++$mine; });
		$zombie->setInvulnerable(false);
		$zombie->damage(1);
		$this->check($mine === 1 && $foreignHits === 0 && $this->manager->get($owned->getId()) === null && $other->get($zombie->getId()) === null, 'managers dispatch only owned entities');
		$ownedWeak = WeakReference::create($this->entity($owned));
		$pluginManager->disablePlugin($probe);
		$this->check($other->isClosed() && $owned->isClosed() && !$zombie->isClosed() && !$player->isClosed(), 'foreign plugin disable closes only its armored zombie');
		unset($other, $owned);
		gc_collect_cycles();
		$this->check($ownedWeak->get() === null, 'foreign zombie released');
		$this->stage($this->chunkUnload(...), 5);
	}

	private function chunkUnload() : void{
		$spawn = $this->world->getSpawnLocation();
		$cx = ($spawn->getFloorX() >> 4) + 3;
		$cz = ($spawn->getFloorZ() >> 4) + 3;
		$this->world->orderChunkPopulation($cx, $cz, null)->onCompletion(
			function() use ($cx, $cz) : void{
				$this->stage(function() use ($cx, $cz) : void{
					$this->check($this->world->loadChunk($cx, $cz) !== null, 'chunk for zombie unload generated');
					$chunkZombie = $this->manager->createZombie(new Location($cx * 16 + 0.5, 16, $cz * 16 + 0.5, $this->world, 0, 0), '', new Equipment(helmet: VanillaItems::IRON_HELMET()));
					$weak = WeakReference::create($this->entity($chunkZombie));
					$this->check($this->world->unloadChunk($cx, $cz, false) && $chunkZombie->isClosed() && $this->manager->get($chunkZombie->getId()) === null, 'chunk unload closes armored zombie');
					unset($chunkZombie);
					gc_collect_cycles();
					$this->check($weak->get() === null, 'chunk unloaded zombie released');
					$this->stage($this->mixedLoad(...), 5);
				}, 1);
			},
			function() : void{ $this->stage(static function() : void{ throw new RuntimeException('Chunk population failed'); }, 1); }
		);
	}

	private function mixedLoad() : void{
		foreach($this->manager->getAll() as $npc){ $npc->close(); }
		gc_collect_cycles();
		$this->metrics['mixed_memory_before'] = memory_get_usage();
		$weaks = [];
		$start = hrtime(true);
		for($i = 0; $i < 200; ++$i){
			$location = $this->location(1.5 + ($i % 20));
			$npc = match($i % 4){
				0 => $this->manager->createPlayer($location, $this->skin, '', new Equipment(mainHand: VanillaItems::DIAMOND_SWORD(), helmet: VanillaItems::IRON_HELMET())),
				1 => $this->manager->createVillager($location),
				2 => $this->manager->createZombie($location, '', new Equipment(helmet: VanillaItems::IRON_HELMET())),
				default => $this->manager->createMob(\NpcLib\internal\entity\ZombieEntity::class, $location),
			};
			$weaks[] = WeakReference::create($this->entity($npc));
		}
		$this->metrics['mixed_create_200_ns'] = hrtime(true) - $start;
		$this->metrics['mixed_memory_with_npcs'] = memory_get_usage();
		$this->all = array_values($this->manager->getAll());
		$this->check(count($this->all) === 200, 'mixed 200 NPCs created');
		$this->stage(function() use ($weaks) : void{
			$queued = 0;
			foreach($this->all as $npc){ if(isset($this->world->updateEntities[$npc->getId()])){ ++$queued; } }
			$this->metrics['mixed_queued_after_idle'] = $queued;
			$this->check($queued === 0, 'mixed 200 NPCs dormant without viewers');
			$this->check($this->listenerTotal() === $this->baselineListeners, 'listeners constant with 200 NPCs');
			$start = hrtime(true);
			foreach($this->all as $npc){ $npc->close(); }
			$this->all = [];
			gc_collect_cycles();
			$this->metrics['mixed_remove_200_ns'] = hrtime(true) - $start;
			$this->metrics['mixed_memory_after_close'] = memory_get_usage();
			foreach($weaks as $weak){ $this->check($weak->get() === null, 'mixed entity released'); }
			$this->check($this->manager->count() === 0, 'mixed cleanup');
			$this->player = $this->manager->createPlayer($this->location(1.5), $this->skin, 'FeatureShutdown', new Equipment(helmet: VanillaItems::IRON_HELMET()));
			$this->complete = true;
			($this->done)();
		}, 20);
	}

	public function finish() : void{
		try{
			$this->check($this->manager->isClosed() && $this->manager->count() === 0 && $this->player->isClosed(), 'feature shutdown automatically closes owner context');
		}catch(Throwable $error){ $this->error ??= $error::class . ': ' . $error->getMessage(); }
		$result = ['result' => $this->complete && $this->error === null ? 'PASS' : 'FAIL', 'assertions' => count($this->passed), 'passed' => $this->passed, 'error' => $this->error, 'metrics' => $this->metrics];
		file_put_contents($this->plugin->getDataFolder() . 'feature-result.json', json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
	}
}
