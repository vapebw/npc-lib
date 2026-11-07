<?php

declare(strict_types=1);

namespace NpcLibHarness;

use Closure;
use InvalidArgumentException;
use LogicException;
use NpcLib\internal\entity\ZombieEntity;
use NpcLib\npc\Npc;
use NpcLib\npc\PlayerNpc;
use NpcLib\npc\VillagerNpc;
use NpcLib\npc\ZombieNpc;
use NpcLib\NpcManager;
use quark\entity\Location;
use quark\entity\Skin;
use quark\entity\Villager;
use quark\event\entity\EntityDespawnEvent;
use quark\event\EventPriority;
use quark\event\HandlerListManager;
use quark\event\world\ChunkLoadEvent;
use quark\item\VanillaItems;
use quark\plugin\PluginBase;
use quark\scheduler\ClosureTask;
use quark\world\World;
use RuntimeException;
use stdClass;
use Throwable;
use UnexpectedValueException;
use WeakReference;

final class PersistenceSuite{
	/** @var list<string> */
	private array $passed = [];
	/** @var array<int, Npc> */
	private array $preclose = [];

	private function check(bool $condition, string $name) : void{
		if(!$condition){ throw new RuntimeException($name); }
		$this->passed[] = $name;
	}

	public function __construct(PluginBase $plugin, Closure $done){
		$earlyDespawn = $plugin->getServer()->getPluginManager()->registerEvent(EntityDespawnEvent::class, function(EntityDespawnEvent $event) : void{
			($this->preclose[$event->getEntity()->getId()] ?? null)?->close();
		}, EventPriority::LOWEST, $plugin);
		$manager = new NpcManager($plugin);
		$error = null;
		try{
			$world = $plugin->getServer()->getWorldManager()->getDefaultWorld() ?? throw new RuntimeException('Missing world');
			$file = $plugin->getDataFolder() . 'npcs.json';
			if(is_file($file)){ unlink($file); }
			$attached = 0;
			$attach = static function(string $id, Npc $npc) use (&$attached) : void{
				++$attached;
				$npc->onDamage(static function() : void{});
			};
			$store = $manager->enablePersistence($attach);
			$store->setRestoreBudget(100000, 100000.0);
			$unready = $manager->createVillager(new Location(16000, 16, 16000, $world, 0, 0), 'Not ready');
			try{ $store->remember('unready', $unready); throw new RuntimeException('Unloaded registration accepted'); }
			catch(LogicException){
				$this->check(
					!$world->isChunkLoaded(1000, 1000) && $store->ids() === [],
					'permanent registration rejects unloaded terrain without loading or retaining a definition'
				);
			}
			finally{ $unready->close(); }
			$npc = $manager->createVillager(new Location(8, 16, 8, $world, 75, 12), 'Permanent');
			$npc->setProfession(2);
			$npc->setScale(1.5);
			$npc->setInvisible(true);
			$npc->setNameVisible(false);
			$npc->setScoreTag('score');
			$npc->setEnvironmental(true);
			$npc->setInvulnerable(false);
			$npc->setCancelDamage(true);
			$npc->setCancelInteractions(false);
			$store->remember('guide', $npc);
			$this->check(
				is_file($file) && $store->get('guide') === $npc && $attached === 1,
				'creation saves definition and attaches callbacks'
			);

			// one write registers a whole batch and a failure keeps ownership with the caller
			$batchA = $manager->createVillager(new Location(8, 16, 8, $world, 0, 0), 'Batch A');
			$batchB = $manager->createVillager(new Location(9, 16, 8, $world, 0, 0), 'Batch B');
			$store->rememberBatch(['batchA' => ['npc' => $batchA], 'batchB' => ['npc' => $batchB]]);
			$this->check(
				$store->get('batchA') === $batchA && $store->get('batchB') === $batchB && $attached === 3,
				'batch commits two definitions and attaches callbacks'
			);
			$store->delete('batchA'); $store->delete('batchB');
			$this->check($store->ids() === ['guide'] && $manager->count() === 1, 'batch deletion retains existing definition');
			$batchA = $manager->createVillager(new Location(8, 16, 8, $world, 0, 0));
			$batchB = $manager->createVillager(new Location(9, 16, 8, $world, 0, 0));
			$beforeBatchFailure = hash_file('sha256', $file);
			rename($file, $file . '.backup'); mkdir($file);
			try{
				try{
					$store->rememberBatch(['batchFailedA' => ['npc' => $batchA], 'batchFailedB' => ['npc' => $batchB]]);
					throw new RuntimeException('Batch storage failure was accepted');
				}catch(Throwable $batchFailure){
					$this->check($batchFailure->getMessage() !== 'Batch storage failure was accepted' &&
						$store->ids() === ['guide'] && $store->get('batchFailedA') === null &&
						$store->get('batchFailedB') === null && !$batchA->isClosed() && !$batchB->isClosed() &&
						hash_file('sha256', $file . '.backup') === $beforeBatchFailure,
						'failed batch checkpoint rolls back all definitions and preserves original storage');
				}
			}finally{ rmdir($file); rename($file . '.backup', $file); }
			$batchA->close(); $batchB->close();
			// one npc that can't be captured must not hold the others hostage
			$sibling = $manager->createVillager(new Location(9, 16, 8, $world, 0, 0), 'Sibling');
			$store->remember('sibling', $sibling);
			$npc->setName(str_repeat('x', 4097));
			$sibling->setName('Sibling renamed');
			try{ $store->save(); throw new RuntimeException('Snapshot error hidden'); }
			catch(RuntimeException $e){ $this->check(str_contains($e->getMessage(), 'snapshot') && str_contains($e->getMessage(), 'guide'), 'save reports the npc it could not capture'); }
			$written = (string) file_get_contents($file);
			$this->check(str_contains($written, 'Sibling renamed') && str_contains($written, 'Permanent') && !str_contains($written, 'xxxx'), 'save keeps the last good state and still writes the others');
			$sibling->close();
			$this->check($sibling->isClosed() && !$store->has('sibling') && !str_contains((string) file_get_contents($file), 'Sibling'), 'an invalid npc does not block deleting another one');
			$npc->setName('Permanent');
			$npc->teleport(new Location(8, 16, 8, $world, 720, 12));
			$store->save();
			$this->check(true, 'teleport with several turns of yaw can still be saved');
			$npc->teleport(new Location(8, 16, 8, $world, 75, 12));
			touch($file, 946684800);
			$unrelated = false;
			foreach($world->getLoadedChunks() as $hash => $_){
				$cx = $cz = 0; World::getXZ($hash, $cx, $cz);
				if($cx !== 0 || $cz !== 0){ $world->unloadChunk($cx, $cz, false); $unrelated = true; break; }
			}
			clearstatcache(true, $file);
			$this->check($unrelated && filemtime($file) === 946684800, 'unrelated chunk unload performs no persistence write');
			$beforeFailure = hash_file('sha256', $file);
			rename($file, $file . '.backup'); mkdir($file);
			try{
				try{ $store->delete('guide'); throw new RuntimeException('Storage failure accepted'); }
				catch(Throwable $e){
					$this->check(
						$e->getMessage() !== 'Storage failure accepted' && $store->get('guide') === $npc && !$npc->isClosed() && hash_file('sha256', $file . '.backup') === $beforeFailure && glob($file . '.*.tmp') === [],
						'failed atomic replacement preserves prior file and deletion ownership'
					);
				}
			}finally{ rmdir($file); rename($file . '.backup', $file); }
			$old = $npc->getId();
			$world->save(true);
			$this->check($world->unloadChunk(0, 0, false), 'real chunk unload');
			$this->check(
				$npc->isClosed() && $manager->count() === 0 && $store->get('guide') === null,
				'unload releases runtime handle'
			);
			$store->restore();
			$this->check($manager->count() === 0 && !$world->isChunkLoaded(0, 0), 'restore does not force chunk loading');
			$world->loadChunk(0, 0);
			$restored = $store->get('guide') ?? throw new RuntimeException('No restored guide');
			$store->restore();
			$store->restore();
			$this->check(
				$manager->count() === 1 && $restored->getId() !== $old,
				'duplicate restoration is idempotent with new runtime ID'
			);
			$this->check(
				$restored instanceof VillagerNpc && $restored->getProfession() === 2,
				'villager type and profession restored'
			);
			$this->check(
				$restored->getName() === 'Permanent' && !$restored->isNameVisible() && $restored->isInvisible() && $restored->getScale() === 1.5 && $restored->getScoreTag() === 'score',
				'appearance restored'
			);
			$this->check(
				$restored->getLocation()->yaw === 75.0 && $restored->getLocation()->pitch === 12.0 && $restored->isStatic() && $restored->isEnvironmental() && !$restored->isInvulnerable(),
				'rotation and policies restored'
			);
			$this->check($attached === 5, 'callbacks reattached on restoration');
			$manager->close();
			$manager = new NpcManager($plugin);
			$store = $manager->enablePersistence($attach);
			$store->restore();
			$this->check($store->get('guide') !== null && $manager->count() === 1, 'manager restart restores saved definition');
			$store->get('guide')?->close();
			$store->restore();
			$this->check($manager->count() === 0 && $store->ids() === [], 'handle close permanently deletes definition');
			$this->check(!$store->delete('guide'), 'repeated deletion is idempotent');
			$manager->close();
			$manager = new NpcManager($plugin);
			$store = $manager->enablePersistence($attach);
			$store->restore();
			$this->check($store->ids() === [], 'deleted definition never resurrects');
			// earlier checks can unload chunks so load the ones needed here
			foreach([0, 1, 2] as $chunkX){ $world->loadChunk($chunkX, 0); }
			// equipment and skin, several chunks, ownership and callback failures
			$skin = new Skin('persistent-test', str_repeat("\x01\x02\x03\xff", 4096));
			$player = $manager->createPlayer(new Location(8, 16, 8, $world, 60, -10), $skin, 'Player');
			$player->setMainHand(VanillaItems::DIAMOND_SWORD()->setCustomName('Remember me'));
			$player->setOffHand(VanillaItems::IRON_SWORD());
			$player->setHelmet(VanillaItems::DIAMOND_HELMET());
			$player->setSneaking(true);
			$store->remember('player', $player);
			$zombie = $manager->createZombie(new Location(24, 16, 8, $world, 0, 0), 'Zombie');
			$zombie->setBoots(VanillaItems::LEATHER_BOOTS());
			$store->remember('zombie', $zombie);
			try{ $store->registerMob('bad', stdClass::class); throw new RuntimeException('Bad custom class accepted'); }
			catch(InvalidArgumentException){ $this->check(true, 'invalid custom restoration class rejected before registration'); }
			$store->registerMob('squid', CustomSquid::class);
			$custom = $manager->createMob(CustomSquid::class, new Location(40, 16, 8, $world, 0, 0), 'Custom');
			$custom->setScale(0.75);
			$store->registerMob('wrong', ZombieEntity::class);
			try{ $store->remember('wrongType', $custom, 'wrong'); throw new RuntimeException('Mismatched custom class accepted'); }
			catch(InvalidArgumentException){ $this->check(true, 'custom registration must match original native class'); }
			$store->remember('custom', $custom, 'squid');
			$weak = WeakReference::create($world->getEntity($player->getId()) ?? throw new RuntimeException('Missing entity'));
			$other = new NpcManager($plugin);
			try{
				try{ $other->enablePersistence($attach); throw new RuntimeException('Duplicate storage owner accepted'); }
				catch(RuntimeException $e){ $this->check(str_contains($e->getMessage(), 'already owned'), 'second manager cannot own same file'); }
				$foreign = $other->createVillager(new Location(8, 16, 8, $world, 0, 0));
				try{ $store->remember('foreign', $foreign); throw new RuntimeException('Foreign NPC accepted'); }
				catch(LogicException){ $this->check(true, 'foreign manager ownership rejected'); }
			}finally{ $other->close(); }
			$manager->close();
			gc_collect_cycles();
			$this->check($weak->get() === null, 'persistent runtime entities are not retained after shutdown');
			$manager = new NpcManager($plugin);
			$store = $manager->enablePersistence($attach);
			$store->restore();
			$this->check($store->get('custom') === null, 'unregistered custom type remains dormant');
			$store->registerMob('squid', CustomSquid::class);
			$store->restore();
			$p = $store->get('player'); $z = $store->get('zombie');
			$this->check(
				$p instanceof PlayerNpc && $p->getSkin()->getSkinData() === $skin->getSkinData() && $p->isSneaking() && $p->getMainHand()->getCustomName() === 'Remember me' && $p->getOffHand()->equals(VanillaItems::IRON_SWORD()) && $p->getHelmet()->equals(VanillaItems::DIAMOND_HELMET()),
				'player skin, pose and equipment exactly restored'
			);
			$this->check(
				$z instanceof ZombieNpc && $z->getBoots()->equals(VanillaItems::LEATHER_BOOTS()) && $store->get('custom')?->getScale() === 0.75,
				'multiple chunks, zombie equipment and registered custom restoration'
			);
			$originalPlayer = $store->get('player') ?? throw new RuntimeException('Missing player');
			$listener = $plugin->getServer()->getPluginManager()->registerEvent(EntityDespawnEvent::class, function(EntityDespawnEvent $event) use ($store, $originalPlayer) : void{
				if($event->getEntity()->getId() === $originalPlayer->getId()){ $store->restore(); }
			}, EventPriority::NORMAL, $plugin);
			try{ $world->unloadChunk(0, 0, false); }
			finally{ HandlerListManager::global()->unregisterAll($listener); }
			$this->check(
				$manager->count() === 2 && $store->get('player') === null,
				'reentrant restoration during native chunk disposal cannot leave a live entity in unloaded terrain'
			);
			$world->loadChunk(0, 0); $store->restore();
			$this->check($manager->count() === 3, 'restoration resumes after native disposal completes');
			$a = $manager->createVillager(new Location(9, 16, 8, $world, 0, 0));
			$b = $manager->createVillager(new Location(10, 16, 8, $world, 0, 0));
			$store->remember('earlyDeleteA', $a); $store->remember('earlyDeleteB', $b);
			$this->preclose = [$a->getId() => $a, $b->getId() => $b];
			$late = $plugin->getServer()->getPluginManager()->registerEvent(EntityDespawnEvent::class, function(EntityDespawnEvent $event) use ($store) : void{
				if(isset($this->preclose[$event->getEntity()->getId()])){ $store->restore(); }
			}, EventPriority::NORMAL, $plugin);
			try{ $world->unloadChunk(0, 0, false); }
			finally{ HandlerListManager::global()->unregisterAll($late); $this->preclose = []; }
			$this->check(
				$manager->count() === 2 && $store->get('player') === null && !in_array('earlyDeleteA', $store->ids(), true) && !in_array('earlyDeleteB', $store->ids(), true),
				'early explicit deletion cannot bypass disposal guard or restore into an unloading chunk'
			);
			$world->loadChunk(0, 0); $store->restore();
			$this->check($manager->count() === 3, 'early deletion remains permanent while unrelated definitions restore once');
			$foreignEntity = new Villager(new Location(9, 16, 8, $world, 0, 0));
			$foreignEntity->setCanSaveWithChunk(false);
			$foreignListener = $plugin->getServer()->getPluginManager()->registerEvent(EntityDespawnEvent::class, static function(EntityDespawnEvent $event) use ($store, $foreignEntity) : void{
				if($event->getEntity() === $foreignEntity){ $store->restore(); }
			}, EventPriority::NORMAL, $plugin);
			try{ $world->unloadChunk(0, 0, false); }
			finally{ HandlerListManager::global()->unregisterAll($foreignListener); }
			$this->check(
				$manager->count() === 2 && $store->get('player') === null && $foreignEntity->isClosed(),
				'foreign native despawn cannot restore an owned definition into an unloading chunk'
			);
			$world->loadChunk(0, 0); $store->restore();
			$this->check($manager->count() === 3, 'foreign entity cleanup does not suppress later owned restoration');
			$worlds = $plugin->getServer()->getWorldManager();
			// quark closes this one outside any chunk event because its chunk isn't loaded
			$straggler = $manager->createVillager(new Location(8, 16, 8, $world, 0, 0), 'Straggler');
			$store->remember('straggler', $straggler);
			$straggler->teleport(new Location(16000, 16, 16000, $world, 0, 0));
			$world->save(true);
			$this->check(
				$worlds->unloadWorld($world, true) && $manager->count() === 0,
				'real world unload releases persistent runtime entities'
			);
			$this->check($store->has('straggler'), 'world unload keeps a definition whose npc stood in an unloaded chunk');
			$this->check($worlds->loadWorld('world'), 'real world reload');
			$world = $worlds->getWorldByName('world') ?? throw new RuntimeException('Missing reloaded world');
			$worlds->setDefaultWorld($world);
			foreach([0, 1, 2] as $x){ $world->loadChunk($x, 0); }
			$store->restore();
			$this->check(
				$manager->count() === 3 && $store->get('player') !== null && $store->get('custom') !== null,
				'world and chunks in new load order restore without duplicates'
			);
			foreach($store->ids() as $id){ $store->delete($id); }
			$npc = $manager->createVillager(new Location(8, 16, 8, $world, 0, 0));
			$store->remember('callbackFailure', $npc);
			$store->remember('callbackHealthy', $manager->createVillager(new Location(8, 16, 8, $world, 0, 0)));
			$manager->close();
			$manager = new NpcManager($plugin);
			$store = $manager->enablePersistence(static function(string $id) : void{ if($id === 'callbackFailure'){ throw new RuntimeException('restore callback failed'); } });
			try{ $store->restore(); throw new RuntimeException('Callback failure hidden'); }
			catch(RuntimeException $e){
				$this->check(
					$e->getMessage() === 'restore callback failed' && $manager->count() === 1 && $store->has('callbackFailure') && $store->get('callbackFailure') === null,
					'restore callback error retains definition without leaking entity'
				);
				$this->check($store->get('callbackHealthy') !== null, 'a failing definition does not stop restore from reaching the rest');
			}
			$store->delete('callbackFailure');
			$store->delete('callbackHealthy');
			$manager->close();
			$manager = new NpcManager($plugin);
			$store = $manager->enablePersistence($attach);
			$store->remember('replacement', $manager->createVillager(new Location(8, 16, 8, $world, 0, 0), 'Original'));
			$manager->close();
			$manager = new NpcManager($plugin);
			$replacement = null;
			$store = null;
			$store = $manager->enablePersistence(function(string $id, Npc $npc) use (&$store, $manager, $world, &$replacement) : void{
				if($npc->getName() === 'Original'){
					$npc->close();
					$replacement = $manager->createVillager(new Location(8, 16, 8, $world, 0, 0), 'Replacement');
					$store?->remember($id, $replacement);
					throw new RuntimeException('replacement sentinel');
				}
			});
			try{ $store->restore(); throw new RuntimeException('Replacement failure hidden'); }
			catch(RuntimeException $e){
				$this->check(
					$e->getMessage() === 'replacement sentinel' && $store->get('replacement') === $replacement && $replacement?->getName() === 'Replacement',
					'throwing restore callback cannot resurrect a deleted and replaced definition'
				);
			}
			$store->delete('replacement');
			$manager->close();
			$manager = new NpcManager($plugin);
			$store = $manager->enablePersistence($attach);
			// a callback that throws closes the npc but the definition survives
			$fragile = $manager->createVillager(new Location(8, 16, 8, $world, 0, 0), 'Fragile');
			$store->remember('fragile', $fragile);
			$fragile->onDamage(static function() : void{ throw new RuntimeException('runtime callback sentinel'); });
			$fragile->damage(1.0);
			$this->check(
				$fragile->isClosed() && in_array('fragile', $store->ids(), true),
				'throwing runtime callback closes the npc but keeps its definition'
			);
			gc_collect_cycles();
			$store->restore();
			$this->check(
				$store->get('fragile')?->getName() === 'Fragile',
				'definition kept after a callback failure restores later'
			);
			// a native close outside an unload (death, other plugin) removes the definition
			$doomed = $manager->createVillager(new Location(8, 16, 8, $world, 0, 0), 'Doomed');
			$store->remember('doomed', $doomed);
			($world->getEntity($doomed->getId()) ?? throw new RuntimeException('Missing doomed entity'))->close();
			gc_collect_cycles();
			$store->restore();
			$this->check(
				$doomed->isClosed() && !in_array('doomed', $store->ids(), true) && !str_contains((string) file_get_contents($file), 'Doomed'),
				'native close outside an unload deletes the definition'
			);
			$dying = $manager->createVillager(new Location(8, 16, 8, $world, 0, 0), 'Dying');
			$store->remember('dying', $dying);
			$dying->setInvulnerable(false);
			$dying->damage(1000.0);
			$store->save();
			$this->check(!$store->has('dying') && !str_contains((string) file_get_contents($file), 'Dying'), 'an npc in its death animation is not saved back to life');
			$store->delete('fragile');
			// invalid utf-8 is refused up front instead of breaking every later save
			$garbled = $manager->createVillager(new Location(8, 16, 8, $world, 0, 0), "bad\xff");
			try{ $store->remember('garbled', $garbled); throw new RuntimeException('Invalid UTF-8 accepted'); }
			catch(Throwable $e){
				$this->check(
					$e->getMessage() !== 'Invalid UTF-8 accepted' && !in_array('garbled', $store->ids(), true),
					'invalid utf-8 name is rejected before any checkpoint'
				);
			}
			$garbled->close();
			$store->save();
			// a definition failing on chunk load stays dormant and the rest still restore
			$store->remember('brokenFirst', $manager->createVillager(new Location(8, 16, 8, $world, 0, 0), 'Broken'));
			$store->remember('healthySecond', $manager->createVillager(new Location(9, 16, 8, $world, 0, 0), 'Healthy'));
			$manager->close();
			$manager = new NpcManager($plugin);
			$store = $manager->enablePersistence(static function(string $id) : void{ if($id === 'brokenFirst'){ throw new RuntimeException('chunk restore sentinel'); } });
			$store->setRestoreBudget(100000, 100000.0);
			$world->unloadChunk(0, 0, false);
			$escaped = false;
			try{ $world->loadChunk(0, 0); }
			catch(Throwable){ $escaped = true; }
			$this->check(
				!$escaped && $store->get('healthySecond') !== null && in_array('brokenFirst', $store->ids(), true) && $store->get('brokenFirst') === null,
				'failed definition in a chunk load stays dormant without blocking the rest'
			);
			$store->delete('brokenFirst'); $store->delete('healthySecond');
			$manager->close();
			$manager = new NpcManager($plugin);
			$store = $manager->enablePersistence($attach);
			$store->setRestoreBudget(100000, 100000.0);
			$invalid = $manager->createVillager(new Location(8, 16, 8, $world, 0, 0), 'Good first');
			$store->remember('invalidState', $invalid);
			$secondInvalid = $manager->createVillager(new Location(8, 16, 8, $world, 0, 0), 'Good second');
			$store->remember('secondInvalidState', $secondInvalid);
			$invalid->setName(str_repeat('x', 4097));
			$secondInvalid->setName(str_repeat('y', 4097));
			$this->check(
				$world->unloadChunk(0, 0, false) && $invalid->isClosed() && $secondInvalid->isClosed() && $manager->count() === 0,
				'invalid state does not block the unload or native cleanup'
			);
			$world->loadChunk(0, 0);
			$this->check(
				$store->get('invalidState')?->getName() === 'Good first' && $store->get('secondInvalidState')?->getName() === 'Good second',
				'definitions come back with their last good state'
			);
			$store->save();
			$unrelatedNpc = $manager->createVillager(new Location(8, 16, 8, $world, 0, 0));
			$store->remember('unrelatedNpc', $unrelatedNpc);
			$this->check($store->get('unrelatedNpc') === $unrelatedNpc, 'registration keeps working after a snapshot failure');
			$store->delete('unrelatedNpc');
			$store->delete('invalidState');
			$store->delete('secondInvalidState');
			$store->remember('disableCallback', $manager->createVillager(new Location(8, 16, 8, $world, 0, 0)));
			$manager->close();
			$manager = new NpcManager($plugin);
			$store = $manager->enablePersistence(static function() use ($manager) : void{ $manager->close(); throw new RuntimeException('disable sentinel'); });
			try{ $store->restore(); throw new RuntimeException('Disable callback error hidden'); }
			catch(RuntimeException $e){
				$this->check(
					$e->getMessage() === 'disable sentinel' && $manager->isClosed() && $manager->count() === 0,
					'owner close during restore preserves original exception and releases resources'
				);
			}
			$manager = new NpcManager($plugin);
			$store = $manager->enablePersistence($attach); $store->delete('disableCallback');
			$events = $plugin->getServer()->getPluginManager();
			$peer = $events->getPlugin('NpcLibPeer') ?? throw new RuntimeException('Missing peer plugin');
			$events->enablePlugin($peer);
			$peerFile = $peer->getDataFolder() . 'npcs.json';
			if(is_file($peerFile)){ unlink($peerFile); }
			$peerManager = new NpcManager($peer);
			$peerAttached = 0;
			$peerAttach = static function() use (&$peerAttached) : void{ ++$peerAttached; };
			$peerStore = $peerManager->enablePersistence($peerAttach);
			$ownNpc = $manager->createVillager(new Location(8, 16, 8, $world, 0, 0), 'Main owner');
			$store->remember('sameStableId', $ownNpc);
			$peerNpc = $peerManager->createVillager(new Location(10, 16, 8, $world, 0, 0), 'Peer owner');
			$peerStore->remember('sameStableId', $peerNpc);
			$events->disablePlugin($peer);
			$this->check(
				$peerManager->isClosed() && $peerNpc->isClosed() && !$ownNpc->isClosed() && $store->get('sameStableId') === $ownNpc,
				'actual plugin disable saves only its own definitions and closes its own handles'
			);
			$events->enablePlugin($peer);
			$peerManager = new NpcManager($peer);
			$peerStore = $peerManager->enablePersistence($peerAttach); $peerStore->restore();
			$this->check(
				$peerStore->get('sameStableId')?->getName() === 'Peer owner' && $peerAttached === 2 && $peerManager->count() === 1,
				'actual plugin re-enable restores definition and reattaches callbacks'
			);
			$events->disablePlugin($peer); $events->enablePlugin($peer);
			$peerManager = new NpcManager($peer);
			$peerStore = $peerManager->enablePersistence(static function() use ($events, $peer) : void{ $events->disablePlugin($peer); });
			$peerStore->restore();
			$this->check(
				!$peer->isEnabled() && $peerManager->isClosed() && $peerManager->count() === 0,
				'native owner disable during restoration releases attempted entities'
			);
			$events->enablePlugin($peer); $peerManager = new NpcManager($peer);
			$peerStore = $peerManager->enablePersistence($peerAttach); $peerStore->restore();
			$peerStore->delete('sameStableId'); $peerManager->close(); $events->disablePlugin($peer);
			$store->delete('sameStableId');
			$this->check($manager->count() === 0, 'same stable ID across plugins has isolated deletion and cleanup');
			$manager->close();
			file_put_contents($file, '{corrupt');
			$manager = new NpcManager($plugin);
			try{ $manager->enablePersistence($attach); throw new RuntimeException('Corrupt file accepted'); }
			catch(UnexpectedValueException){ $this->check(file_get_contents($file) === '{corrupt', 'corruption rejected without overwriting data'); }
			unlink($file);
			$store = $manager->enablePersistence($attach);
			$lateNpc = $manager->createVillager(new Location(8, 16, 8, $world, 0, 0));
			$store->remember('lateClose', $lateNpc);
			$world->unloadChunk(1, 0, false);
			$closer = $plugin->getServer()->getPluginManager()->registerEvent(ChunkLoadEvent::class, static function(ChunkLoadEvent $event) use ($manager) : void{ $manager->close(); }, EventPriority::HIGHEST, $plugin);
			try{ $world->loadChunk(1, 0); }
			finally{ HandlerListManager::global()->unregisterAll($closer); }
			$this->check(
				$manager->isClosed() && $lateNpc->isClosed(),
				'event dispatch snapshot is harmless after an earlier listener closes the context'
			);
			$manager = new NpcManager($plugin); $store = $manager->enablePersistence($attach);
			$store->delete('lateClose');
		}catch(Throwable $failure){ $error = $failure::class . ': ' . $failure->getMessage(); }
		finally{ HandlerListManager::global()->unregisterAll($earlyDespawn); $this->preclose = []; $manager->close(); }
		$report = function(?string $failure) use ($plugin, $done) : void{
			$result = ['result' => $failure === null ? 'PASS' : 'FAIL', 'assertions' => count($this->passed), 'passed' => $this->passed, 'error' => $failure];
			file_put_contents($plugin->getDataFolder() . 'persistence-result.json', json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
			$plugin->getLogger()->info('persistence suite ' . $result['result'] . ' ' . ($failure ?? ''));
			$done();
		};
		if($error !== null){ $report($error); }
		else{ $this->readinessOrder($plugin, $report); }
	}

	/** @param Closure(?string): void $report */
	private function readinessOrder(PluginBase $plugin, Closure $report) : void{
		$manager = new NpcManager($plugin);
		try{
			$world = $plugin->getServer()->getWorldManager()->getDefaultWorld() ?? throw new RuntimeException('Missing world');
			$world->loadChunk(0, 0); $world->loadChunk(1, 0);
			$store = $manager->enablePersistence(static function() : void{});
			$store->remember('first', $manager->createVillager(new Location(24, 16, 8, $world, 0, 0)));
			$store->remember('second', $manager->createVillager(new Location(8, 16, 8, $world, 0, 0)));
			$manager->close(); $world->unloadChunk(1, 0, false);
			$manager = new NpcManager($plugin);
			$store = $manager->enablePersistence(static function(string $id) use ($world) : void{
				if($id === 'second'){ $world->loadChunk(1, 0); }
			});
			$store->restore();
			$plugin->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($plugin, $manager, $store, $report) : void{
				$failure = null;
				try{ $this->check($store->get('first') !== null && $store->get('second') !== null && $manager->count() === 2, 'nested chunk readiness during restoration is not lost and restores each definition once'); }
				catch(Throwable $error){ $failure = $error::class . ': ' . $error->getMessage(); }
				finally{
					foreach($store->ids() as $id){ $store->delete($id); }
					$manager->close();
				}
				if($failure !== null){ $report($failure); }
				else{ $this->deferredBehaviour($plugin, $report); }
			}), 2);
		}catch(Throwable $error){ $manager->close(); $report($error::class . ': ' . $error->getMessage()); }
	}

	/** @param Closure(?string): void $report */
	private function deferredBehaviour(PluginBase $plugin, Closure $report) : void{
		$manager = new NpcManager($plugin);
		try{
			$world = $plugin->getServer()->getWorldManager()->getDefaultWorld() ?? throw new RuntimeException('Missing world');
			$file = $plugin->getDataFolder() . 'npcs.json';
			$world->loadChunk(0, 0); $world->loadChunk(1, 0);
			$store = $manager->enablePersistence(static function() : void{});
			$store->setRestoreBudget(100000, 100000.0);
			$fragile = $manager->createVillager(new Location(8, 16, 8, $world, 0, 0), 'Fragile');
			$other = $manager->createVillager(new Location(24, 16, 8, $world, 0, 0), 'Other');
			$store->rememberBatch(['fragile' => ['npc' => $fragile], 'other' => ['npc' => $other]]);
			$fragile->onDamage(static function() : void{ throw new RuntimeException('runtime callback sentinel'); });
			$fragile->damage(1.0);
			$other->setName('Other renamed');
			$world->unloadChunk(1, 0, false);
			$this->check(!str_contains((string) file_get_contents($file), 'Other renamed'), 'chunk unload does not write during the unload');
			$plugin->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($plugin, $manager, $store, $world, $file, $report) : void{
				$failure = null;
				try{
					$this->check(str_contains((string) file_get_contents($file), 'Other renamed'), 'chunk unload state reaches the file on the next ticks');
					$this->check($store->has('fragile') && $store->get('fragile') === null, 'npc whose callback failed does not return on an unrelated unload');
					$store->restore();
					$this->check($store->get('fragile')?->getName() === 'Fragile', 'explicit restore brings the failed npc back');
					$world->loadChunk(1, 0);
					$this->check($store->get('other')?->getName() === 'Other renamed', 'unloaded npc returns with its captured state');
					foreach($store->ids() as $id){ $store->delete($id); }
					$manager->close();
					$this->sharedSkins($plugin, $world, $file);
				}catch(Throwable $error){ $failure = $error::class . ': ' . $error->getMessage(); }
				finally{ if(!$manager->isClosed()){ $manager->close(); } }
				if($failure !== null){ $report($failure); }
				else{ $this->gradualRestore($plugin, $report); }
			}), 3);
		}catch(Throwable $error){ $manager->close(); $report($error::class . ': ' . $error->getMessage()); }
	}

	/** @param Closure(?string): void $report */
	private function gradualRestore(PluginBase $plugin, Closure $report) : void{
		$manager = new NpcManager($plugin);
		try{
			$world = $plugin->getServer()->getWorldManager()->getDefaultWorld() ?? throw new RuntimeException('Missing world');
			$world->loadChunk(0, 0); $world->loadChunk(1, 0);
			$store = $manager->enablePersistence(static function() : void{});
			$entries = [];
			for($i = 0; $i < 30; ++$i){ $entries['queued' . $i] = ['npc' => $manager->createVillager(new Location(8, 16, 8, $world, 0, 0), 'Queued')]; }
			$entries['elsewhere'] = ['npc' => $manager->createVillager(new Location(24, 16, 8, $world, 0, 0), 'Elsewhere')];
			$store->rememberBatch($entries);
			$manager->close();
			$world->unloadChunk(1, 0, false);
			$manager = new NpcManager($plugin);
			$store = $manager->enablePersistence(static function(string $id) : void{ if($id === 'queued3'){ throw new RuntimeException('chunk restore sentinel'); } });
			$store->setRestoreBudget(5, 1000.0);
			$store->restoreGradually();
			$this->check($manager->count() <= 5 && $store->pendingRestores() > 0, 'gradual restore respects the npc budget in its first tick');
			$this->check(!$world->isChunkLoaded(1, 0), 'gradual restore does not force chunk loading');
			$store->deleteBatch(['queued25', 'queued26']);
			$plugin->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($plugin, $manager, $store, $world, $report) : void{
				$failure = null;
				try{
					$this->check($manager->count() < 28 && $store->pendingRestores() > 0, 'gradual restore spreads over several ticks');
				}catch(Throwable $error){ $failure = $error::class . ': ' . $error->getMessage(); }
				if($failure !== null){ $manager->close(); $report($failure); return; }
				$plugin->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($plugin, $manager, $store, $world, $report) : void{
					$failure = null;
					try{
						$this->check(
							$store->pendingRestores() === 0 && $manager->count() === 27 && $store->get('queued3') === null && $store->has('queued3') && $store->get('elsewhere') === null,
							'a failed callback and a deleted definition do not stop the rest, nothing is duplicated'
						);
						$world->loadChunk(1, 0);
						$this->check($store->get('elsewhere') !== null, 'a definition in an unloaded chunk comes back when its chunk loads');
						$world->unloadChunk(1, 0, false);
						$store->restoreGradually();
						$pending = $store->pendingRestores();
						$manager->close();
						$this->check($manager->isClosed() && $manager->count() === 0, 'closing during a gradual restore cancels it (' . $pending . ' waiting)');
						$this->deleteBatch($plugin, $world);
					}catch(Throwable $error){ $failure = $error::class . ': ' . $error->getMessage(); }
					finally{ if(!$manager->isClosed()){ $manager->close(); } }
					$report($failure);
				}), 8);
			}), 2);
		}catch(Throwable $error){ $manager->close(); $report($error::class . ': ' . $error->getMessage()); }
	}

	private function deleteBatch(PluginBase $plugin, World $world) : void{
		$file = $plugin->getDataFolder() . 'npcs.json';
		$manager = new NpcManager($plugin);
		try{
			$store = $manager->enablePersistence(static function() : void{});
			$store->deleteBatch($store->ids());
			$world->loadChunk(0, 0); $world->loadChunk(1, 0);
			$npcs = [];
			$entries = [];
			for($i = 0; $i < 20; ++$i){
				$npcs[$i] = $manager->createVillager(new Location(8, 16, 8, $world, 0, 0));
				$entries['bulk' . $i] = ['npc' => $npcs[$i]];
			}
			$store->rememberBatch($entries);
			$ids = array_keys($entries);
			rename($file, $file . '.backup'); mkdir($file);
			try{
				try{ $store->deleteBatch($ids); throw new RuntimeException('Batch delete failure hidden'); }
				catch(Throwable $e){
					$this->check($e->getMessage() !== 'Batch delete failure hidden' && count($store->ids()) === 20 && !$npcs[0]->isClosed() && $store->get('bulk0') === $npcs[0], 'failed batch delete changes nothing');
				}
			}finally{ rmdir($file); rename($file . '.backup', $file); }
			try{ $store->deleteBatch(['bulk0', 'not valid']); throw new RuntimeException('Invalid id accepted'); }
			catch(UnexpectedValueException){ $this->check(count($store->ids()) === 20, 'batch delete validates every id before touching anything'); }
			// a despawn hook that deletes another npc of the batch while it is being closed
			$hook = $plugin->getServer()->getPluginManager()->registerEvent(EntityDespawnEvent::class, function(EntityDespawnEvent $event) use ($store, $npcs) : void{
				if($event->getEntity()->getId() === $npcs[1]->getId()){ $store->delete('bulk2'); }
			}, EventPriority::NORMAL, $plugin);
			$npcs[5]->close();
			$before = $store->writes();
			try{ $removed = $store->deleteBatch([...$ids, 'bulk3', 'unknown']); }
			finally{ HandlerListManager::global()->unregisterAll($hook); }
			$this->check($removed === 19 && $store->writes() === $before + 1, 'batch delete is one write, skips unknown and duplicate ids');
			$this->check($store->ids() === [] && $manager->count() === 0 && $npcs[0]->isClosed() && $npcs[19]->isClosed(), 'batch delete closes every npc after the commit');
			$manager->close();
			$manager = new NpcManager($plugin);
			$store = $manager->enablePersistence(static function() : void{});
			$store->restore();
			$this->check($store->ids() === [] && $manager->count() === 0, 'batch deleted definitions never come back');
		}finally{ $manager->close(); }
	}

	private function sharedSkins(PluginBase $plugin, World $world, string $file) : void{
		$manager = new NpcManager($plugin);
		try{
			$store = $manager->enablePersistence(static function() : void{});
			$skin = new Skin('shared-test', str_repeat("\xff\xfe\xfd\xff", 4096));
			$store->rememberBatch([
				'first' => ['npc' => $manager->createPlayer(new Location(8, 16, 8, $world, 0, 0), $skin, 'First')],
				'second' => ['npc' => $manager->createPlayer(new Location(9, 16, 8, $world, 0, 0), $skin, 'Second')],
				'other' => ['npc' => $manager->createPlayer(new Location(10, 16, 8, $world, 0, 0), new Skin('other-test', str_repeat("\x01\x02\x03\xff", 4096)), 'Other')]
			]);
			$data = json_decode((string) file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);
			$this->check(is_array($data) && $data['version'] === 2 && is_array($data['skins']) && count($data['skins']) === 2, 'npcs sharing a skin store it once');
			$poisoned = $store->get('other') ?? throw new RuntimeException('Missing other');
			$poisoned->setName(str_repeat('z', 4097));
			$manager->close();
			$this->check($manager->isClosed(), 'closing with an invalid npc does not throw');
			$manager = new NpcManager($plugin);
			$store = $manager->enablePersistence(static function() : void{});
			$store->restore();
			$first = $store->get('first'); $second = $store->get('second');
			$this->check(
				$first instanceof PlayerNpc && $second instanceof PlayerNpc && $first->getSkin() === $second->getSkin() && $first->getSkin()->getSkinData() === $skin->getSkinData() && $store->get('other')?->getName() === 'Other',
				'restored npcs share one skin object and the invalid one keeps its last good name'
			);
			foreach($store->ids() as $id){ $store->delete($id); }
		}finally{ $manager->close(); }
	}
}
