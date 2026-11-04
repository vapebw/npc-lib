<?php

declare(strict_types=1);

namespace NpcLibHarness;

use LogicException;
use NpcLib\equipment\Equipment;
use NpcLib\npc\Npc;
use NpcLib\NpcManager;
use NpcLib\npc\PlayerNpc;
use NpcLib\npc\ZombieNpc;
use quark\entity\Entity;
use quark\entity\Living;
use quark\entity\Location;
use quark\entity\Skin;
use quark\item\VanillaItems;
use pocketmine\math\Vector3;
use quark\event\entity\EntityDamageByEntityEvent;
use quark\event\entity\EntityDamageEvent;
use quark\event\entity\EntityDeathEvent;
use quark\event\EventPriority;
use quark\event\HandlerListManager;
use quark\event\player\PlayerEntityInteractEvent;
use quark\event\server\DataPacketSendEvent;
use quark\network\mcpe\protocol\packet\PlayerListPacket;
use quark\player\Player;
use quark\plugin\PluginBase;
use RuntimeException;
use Throwable;
use WeakReference;

final class WireFixture{
	private NpcManager $manager;
	private ?\NpcLib\PersistentNpcs $permanent = null;
	/** @var array<int, Npc> */
	private array $handles = [];
	/** @var array<int, WeakReference<Entity>> */
	private array $entities = [];
	/** @var array<int, string> */
	private array $names = [];
	/** @var list<array<string, mixed>> */
	private array $events = [];
	private ?Location $home = null;
	private bool $precancel = false;
	private bool $uncancel = false;
	private bool $closeOnAttack = false;
	private bool $closeOnDeath = false;
	private string $lastCommand = '';
	private ?string $error = null;
	/** @var array<string, mixed> */
	private array $spawnProbe = [];
	/** @var null|WeakReference<Entity> */
	private ?WeakReference $spawnProbeEntity = null;

	public function __construct(private PluginBase $plugin){
		$events = $plugin->getServer()->getPluginManager();
		$events->registerEvent(PlayerEntityInteractEvent::class, function(PlayerEntityInteractEvent $event) : void{
			if(isset($this->handles[$event->getEntity()->getId()]) && $this->precancel){ $event->cancel(); }
		}, EventPriority::LOWEST, $plugin, true);
		$events->registerEvent(EntityDamageEvent::class, function(EntityDamageEvent $event) : void{
			if(isset($this->handles[$event->getEntity()->getId()]) && $this->precancel){ $event->cancel(); }
		}, EventPriority::LOWEST, $plugin, true);
		$this->manager = new NpcManager($plugin);
		$events->registerEvent(PlayerEntityInteractEvent::class, function(PlayerEntityInteractEvent $event) : void{
			$this->record('native-interact', $event->getEntity()->getId(), $event->getPlayer(), $event->isCancelled());
		}, EventPriority::MONITOR, $plugin, true);
		$events->registerEvent(EntityDamageEvent::class, function(EntityDamageEvent $event) : void{
			$damager = $event instanceof EntityDamageByEntityEvent ? $event->getDamager() : null;
			if($event instanceof EntityDamageByEntityEvent){ $this->record('native-attack', $event->getEntity()->getId(), $damager instanceof Player ? $damager : null, $event->isCancelled()); }
			$this->record('native-damage', $event->getEntity()->getId(), $damager instanceof Player ? $damager : null, $event->isCancelled());
		}, EventPriority::MONITOR, $plugin, true);
		$events->registerEvent(EntityDeathEvent::class, function(EntityDeathEvent $event) : void{
			$cause = $event->getEntity()->getLastDamageCause();
			$damager = $cause instanceof EntityDamageByEntityEvent ? $cause->getDamager() : null;
			$this->record('native-death', $event->getEntity()->getId(), $damager instanceof Player ? $damager : null, null);
		}, EventPriority::MONITOR, $plugin);
		$this->write();
	}

	/** @param list<string> $args */
	public function command(array $args) : void{
		$this->lastCommand = implode(' ', $args);
		$this->error = null;
		try{
			switch($args[0] ?? 'state'){
				case 'persist':
					$this->permanent ??= $this->manager->enablePersistence(function(string $id, Npc $npc) : void{ $this->watch($npc); });
					foreach($this->manager->getAll() as $npc){ $this->permanent->remember('wire' . $npc->getId(), $npc); }
					break;
				case 'persist-reload':
					$store = $this->permanent ?? throw new RuntimeException('Enable persistence first');
					// move observers away so no player sits in the chunks under test
					$home = $this->home ?? throw new RuntimeException('Create NPCs first');
					$world = $home->getWorld();
					$chunks = [];
					foreach($this->manager->getAll() as $npc){ $at = $npc->getLocation(); $chunks[$world::chunkHash($at->getFloorX() >> 4, $at->getFloorZ() >> 4)] = [$at->getFloorX() >> 4, $at->getFloorZ() >> 4]; }
					$world->save(true);
					foreach($chunks as [$x, $z]){ if(!$world->unloadChunk($x, $z, false)){ throw new RuntimeException('Chunk unload failed'); } }
					$store->restore();
					foreach($chunks as [$x, $z]){ $world->loadChunk($x, $z); }
					$store->restore(); $store->restore();
					break;
				case 'persist-delete':
					$store = $this->permanent ?? throw new RuntimeException('Enable persistence first');
					foreach($store->ids() as $id){ $store->delete($id); }
					$store->restore();
					break;
				case 'create': $this->create($args[1] ?? 'NpcProbeA'); break;
				case 'spawn-close': $this->spawnClose(); break;
				case 'kit-create': $this->createKit($args[1] ?? 'NpcProbeA'); break;
				case 'kit': $this->kit($args[1] ?? ''); break;
				case 'skin':
					foreach($this->manager->getAll() as $npc){
						if($npc instanceof PlayerNpc){ $npc->setSkin(new Skin('wire-npc-updated', str_repeat("\x40\x80\xc0\xff", 64 * 64))); }
					}
					break;
				case 'flags':
					$flag = $args[1] ?? 'protected';
					if(!in_array($flag, ['protected', 'open', 'cancel-damage', 'cancel-interact', 'allow-interact'], true)){ throw new RuntimeException('Unknown flag'); }
					foreach($this->manager->getAll() as $npc){
						$npc->setInvulnerable($flag === 'protected');
						$npc->setCancelDamage($flag === 'cancel-damage');
						$npc->setCancelInteractions($flag === 'protected' || $flag === 'cancel-interact');
					}
					break;
				case 'precancel': $this->precancel = $this->toggle($args[1] ?? ''); break;
				case 'uncancel': $this->uncancel = $this->toggle($args[1] ?? ''); break;
				case 'close-on-attack': $this->closeOnAttack = $this->toggle($args[1] ?? ''); break;
				case 'close-on-death': $this->closeOnDeath = $this->toggle($args[1] ?? ''); break;
				case 'lethal':
					foreach($this->manager->getAll() as $npc){
						$npc->setInvulnerable(false);
						$npc->setCancelDamage(false);
						$entity = $npc->getLocation()->getWorld()->getEntity($npc->getId());
						if(!$entity instanceof Living){ throw new RuntimeException('Missing living NPC'); }
						$entity->setHealth(1);
					}
					break;
				case 'move-npc':
					$location = $this->destination($args[1] ?? '');
					foreach($this->manager->getAll() as $npc){ $npc->teleport(clone $location); }
					break;
				case 'near': case 'far':
					$this->player($args[1] ?? 'NpcProbeB')->teleport($this->destination($args[0]));
					break;
				case 'remove': foreach($this->manager->getAll() as $npc){ $npc->close(); } break;
				case 'state': break;
				default: throw new RuntimeException('Unknown wire command');
			}
		}catch(Throwable $error){
			$this->error = $error::class . ': ' . $error->getMessage();
			$this->plugin->getLogger()->error('WireFixture ' . $this->error);
		}
		$this->write();
	}

	private function createKit(string $name) : void{
		foreach($this->manager->getAll() as $npc){ $npc->close(); }
		$player = $this->player($name);
		$location = $player->getLocation();
		$this->home = new Location($location->x, $location->y, $location->z, $location->getWorld(), 0, 0);
		$other = $this->plugin->getServer()->getPlayerExact('NpcProbeB');
		$other?->teleport($this->home);
		$skin = new Skin('wire-npc', str_repeat("\xff", 64 * 64 * 4));
		$world = $location->getWorld();
		$this->watch($this->manager->createPlayer(new Location($location->x + 2, $location->y, $location->z, $world, 0, 0), $skin, 'KitPlayer', new Equipment(mainHand: VanillaItems::DIAMOND_SWORD(), offHand: VanillaItems::IRON_SWORD(), helmet: VanillaItems::IRON_HELMET(), chestplate: VanillaItems::DIAMOND_CHESTPLATE(), boots: VanillaItems::LEATHER_BOOTS())));
		$this->watch($this->manager->createZombie(new Location($location->x - 2, $location->y, $location->z, $world, 0, 0), 'KitZombie', new Equipment(helmet: VanillaItems::IRON_HELMET(), leggings: VanillaItems::DIAMOND_LEGGINGS())));
		$this->watch($this->manager->createVillager(new Location($location->x, $location->y, $location->z + 3, $world, 0, 0), 'KitVillager'));
	}

	private function kit(string $step) : void{
		$player = null;
		$zombie = null;
		$villager = null;
		foreach($this->manager->getAll() as $npc){
			if($npc instanceof PlayerNpc){ $player = $npc; }
			elseif($npc instanceof ZombieNpc){ $zombie = $npc; }
			else{ $villager = $npc; }
		}
		if($player === null || $zombie === null || $villager === null){ throw new RuntimeException('Create kit NPCs first'); }
		switch($step){
			case 'equip':
				$player->setHelmet(VanillaItems::DIAMOND_HELMET());
				$player->setMainHand(VanillaItems::IRON_SWORD());
				$player->setOffHand(null);
				$zombie->setChestplate(VanillaItems::DIAMOND_CHESTPLATE());
				break;
			case 'appearance':
				foreach([$player, $zombie, $villager] as $npc){ $npc->setScale(2.0); $npc->setScoreTag('score-' . $npc->getId()); }
				$player->setSneaking(true);
				$zombie->setInvisible(true);
				break;
			case 'rotate':
				$player->setRotation(90.0, 30.0);
				$home = $this->home ?? throw new RuntimeException('Create NPCs first');
				$zombie->lookAt(new Vector3($home->x + 10, $home->y + 1.62, $home->z));
				$villager->setRotation(180.0, -20.0);
				break;
			case 'swing':
				$player->swingArm();
				$zombie->swingArm();
				break;
			case 'reset':
				foreach([$player, $zombie, $villager] as $npc){ $npc->setScale(1.0); $npc->setRotation(0.0, 0.0); }
				$player->setSneaking(false);
				$zombie->setInvisible(false);
				break;
			default: throw new RuntimeException('Unknown kit step');
		}
	}

	private function toggle(string $value) : bool{
		if($value !== 'on' && $value !== 'off'){ throw new RuntimeException('Expected on or off'); }
		return $value === 'on';
	}

	private function spawnClose() : void{
		$home = $this->home ?? throw new RuntimeException('Create NPCs first');
		$scope = new NpcManager($this->plugin);
		$this->spawnProbe = ['triggered' => false, 'id' => null, 'count' => 0, 'expectedAbort' => false];
		$this->spawnProbeEntity = null;
		$listener = $this->plugin->getServer()->getPluginManager()->registerEvent(DataPacketSendEvent::class, function(DataPacketSendEvent $event) use ($scope, $home) : void{
			if($this->spawnProbe['triggered'] === true){ return; }
			foreach($event->getPackets() as $packet){
				if(!$packet instanceof PlayerListPacket){ continue; }
				foreach($scope->getAll() as $npc){
					$entity = $home->getWorld()->getEntity($npc->getId()) ?? throw new RuntimeException('Spawn probe missing from world');
					$this->spawnProbe['id'] = $npc->getId();
					$this->spawnProbeEntity = WeakReference::create($entity);
					$this->spawnProbe['triggered'] = true;
					$scope->close();
					return;
				}
			}
		}, EventPriority::HIGHEST, $this->plugin, true);
		try{
			try{
				$scope->createPlayer(new Location($home->x + 1, $home->y, $home->z + 1, $home->getWorld(), 0, 0), new Skin('spawn-close-probe', str_repeat("\xff", 64 * 64 * 4)), 'SpawnCloseProbe');
			}catch(LogicException $error){
				if($error->getMessage() !== 'NPC was closed during creation'){ throw $error; }
				$this->spawnProbe['expectedAbort'] = true;
			}
		}finally{
			HandlerListManager::global()->unregisterAll($listener);
			$scope->close();
			$this->spawnProbe['count'] = $scope->count();
		}
	}

	private function player(string $name) : Player{
		return $this->plugin->getServer()->getPlayerExact($name) ?? throw new RuntimeException('Player not connected: ' . $name);
	}

	private function destination(string $mode) : Location{
		$home = $this->home ?? throw new RuntimeException('Create NPCs first');
		if($mode !== 'near' && $mode !== 'far'){ throw new RuntimeException('Expected near or far'); }
		return new Location($home->x + ($mode === 'far' ? 256 : 0), $home->y, $home->z, $home->getWorld(), 0, 0);
	}

	private function create(string $name) : void{
		foreach($this->manager->getAll() as $npc){ $npc->close(); }
		$player = $this->player($name);
		$location = $player->getLocation();
		$this->home = new Location($location->x, $location->y, $location->z, $location->getWorld(), 0, 0);
		$other = $this->plugin->getServer()->getPlayerExact('NpcProbeB');
		$other?->teleport($this->home);
		$skin = new Skin('wire-npc', str_repeat("\xff", 64 * 64 * 4));
		$this->watch($this->manager->createPlayer(new Location($location->x + 1, $location->y, $location->z, $location->getWorld(), 0, 0), $skin, 'WirePlayerNpc'));
		$this->watch($this->manager->createVillager(new Location($location->x, $location->y, $location->z + 2, $location->getWorld(), 0, 0), 'WireVillagerNpc'));
	}

	private function watch(Npc $npc) : void{
		$this->handles[$npc->getId()] = $npc;
		$this->names[$npc->getId()] = $npc->getName();
		$entity = $npc->getLocation()->getWorld()->getEntity($npc->getId()) ?? throw new RuntimeException('NPC missing from world');
		$this->entities[$npc->getId()] = WeakReference::create($entity);
		$npc->onInteract(function(Npc $handle, Player $player, PlayerEntityInteractEvent $event) : void{
			if($this->uncancel){ $event->uncancel(); }
			$this->record('callback-interact', $handle->getId(), $player, $event->isCancelled());
		});
		$npc->onAttack(function(Npc $handle, ?Player $player, EntityDamageByEntityEvent $event) : void{
			if($this->uncancel){ $event->uncancel(); }
			if($this->closeOnAttack){ $handle->close(); }
			$this->record('callback-attack', $handle->getId(), $player, $event->isCancelled());
		});
		$npc->onDamage(function(Npc $handle, EntityDamageEvent $event) : void{
			if($this->uncancel){ $event->uncancel(); }
			$damager = $event instanceof EntityDamageByEntityEvent ? $event->getDamager() : null;
			$this->record('callback-damage', $handle->getId(), $damager instanceof Player ? $damager : null, $event->isCancelled());
		});
		$npc->onDeath(function(Npc $handle, EntityDeathEvent $event) : void{
			if($this->closeOnDeath){ $handle->close(); }
			$cause = $event->getEntity()->getLastDamageCause();
			$damager = $cause instanceof EntityDamageByEntityEvent ? $cause->getDamager() : null;
			$this->record('callback-death', $handle->getId(), $damager instanceof Player ? $damager : null, null);
		});
	}

	private function record(string $type, int $id, ?Player $player, ?bool $cancelled) : void{
		if(!isset($this->handles[$id])){ return; }
		$this->events[] = ['type' => $type, 'runtimeId' => $id, 'player' => $player?->getName(), 'cancelled' => $cancelled, 'tick' => $this->plugin->getServer()->getTick()];
		$this->write();
	}

	private function write() : void{
		$rows = [];
		foreach($this->handles as $id => $handle){
			$entity = ($this->entities[$id] ?? null)?->get();
			$closed = $handle->isClosed();
			$location = $entity?->getLocation();
			$rows[] = ['id' => $id, 'name' => $this->names[$id] ?? null, 'health' => $entity instanceof Living ? $entity->getHealth() : null, 'closed' => $closed, 'position' => $location === null ? null : ['x' => $location->x, 'y' => $location->y, 'z' => $location->z], 'viewers' => $closed ? [] : array_values(array_map(static fn(Player $player) : string => $player->getName(), $entity?->getViewers() ?? [])), 'weakReleased' => $entity === null, 'queued' => $entity !== null && !$closed && isset($entity->getWorld()->updateEntities[$id])];
		}
		$probe = $this->spawnProbe;
		if($probe !== []){
			$entity = $this->spawnProbeEntity?->get();
			$probe['entityClosed'] = $entity === null || $entity->isClosed();
			$probe['weakReleased'] = $entity === null;
		}
		$state = ['tick' => $this->plugin->getServer()->getTick(), 'command' => $this->lastCommand, 'error' => $this->error, 'ownerClosed' => $this->manager->isClosed(), 'count' => $this->manager->count(), 'npcs' => $rows, 'events' => $this->events, 'precancel' => $this->precancel, 'uncancel' => $this->uncancel, 'closeOnAttack' => $this->closeOnAttack, 'closeOnDeath' => $this->closeOnDeath, 'spawnCloseProbe' => $probe];
		$result = file_put_contents($this->plugin->getDataFolder() . 'wire-state.json', json_encode($state, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
		if($result === false){ throw new RuntimeException('Cannot write wire-state.json'); }
	}

	public function finish() : void{
		gc_collect_cycles();
		$this->write();
	}
}
