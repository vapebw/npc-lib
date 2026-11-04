<?php

declare(strict_types=1);

namespace NpcLibBench\Population;

use Closure;
use LogicException;
use NpcLib\npc\Npc;
use NpcLib\npc\PlayerNpc;
use NpcLib\npc\ZombieNpc;
use NpcLib\NpcManager;
use NpcLib\PersistentNpcs;
use NpcLibBench\Kind;
use quark\entity\Living;
use quark\entity\Location;
use quark\entity\Skin;
use quark\item\Item;
use quark\world\World;
use quark\plugin\Plugin;

final class NpcLibPopulation extends SlotPopulation{
	/** @var array<int, Npc> */
	private array $npcs = [];
	private int $registrationBytes = 0;
	private int $registrationNs = 0;

	public function __construct(private NpcManager $manager, private readonly World $world, private readonly Skin $skin, private readonly bool $environmental = false, private ?PersistentNpcs $permanent = null){}

	public function spawn(Kind $kind, Location $at) : int{
		$npc = match($kind){
			Kind::Player => $this->manager->createPlayer($at, $this->skin, 'bench'),
			Kind::Villager => $this->manager->createVillager($at, 'bench'),
			Kind::Zombie => $this->manager->createZombie($at, 'bench'),
		};
		if($this->environmental){ $npc->setEnvironmental(true); }
		$slot = $this->register($kind);
		$this->npcs[$slot] = $npc;
        if($this->permanent !== null){
            $bytes = memory_get_usage(); $start = hrtime(true);
            $this->permanent->remember('bench' . $slot, $npc);
            $this->registrationNs += hrtime(true) - $start;
            $this->registrationBytes += memory_get_usage() - $bytes;
        }
		return $slot;
	}

	public function remove(int $slot) : void{
		$this->npc($slot)->close();
		unset($this->npcs[$slot]);
		$this->unregister($slot);
	}

	public function entity(int $slot) : Living{
		$entity = $this->world->getEntity($this->npc($slot)->getId());
		return $entity instanceof Living ? $entity : throw new LogicException('Entity missing for slot ' . $slot);
	}

	public function setHelmet(int $slot, Item $item) : void{
		$npc = $this->npc($slot);
		if(!$npc instanceof PlayerNpc && !$npc instanceof ZombieNpc){ throw new LogicException('Slot has no armor'); }
		$npc->setHelmet($item);
	}

	public function setSkin(int $slot, Skin $skin) : void{
		$npc = $this->npc($slot);
		if(!$npc instanceof PlayerNpc){ throw new LogicException('Slot has no skin'); }
		$npc->setSkin($skin);
	}

	public function setScale(int $slot, float $scale) : void{ $this->npc($slot)->setScale($scale); }
	public function setRotation(int $slot, float $yaw, float $pitch) : void{ $this->npc($slot)->setRotation($yaw, $pitch); }
	public function teleport(int $slot, Location $to) : void{ $this->npc($slot)->teleport($to); }
	public function damage(int $slot, float $amount) : void{ $this->npc($slot)->damage($amount); }
	public function wake(int $slot) : void{}

	public function prepareForDamage(int $slot) : void{
		$this->npc($slot)->setInvulnerable(false);
		$entity = $this->entity($slot);
		$entity->setMaxHealth(1000000);
		$entity->setHealth(1000000);
	}

	public function onDamage(int $slot, Closure $callback) : void{
		$this->npc($slot)->onDamage(static function() use ($callback) : void{ $callback(); });
	}

    /** @return array<string, int> */
    public function profilePersistence(Plugin $owner) : array{
        $store = $this->permanent ?? throw new LogicException('Persistence not enabled');
        $slots = array_keys($this->npcs);
        $start = hrtime(true); $store->save(); $saveNs = hrtime(true) - $start;
        $this->manager->close(); $this->npcs = []; $this->permanent = null; unset($store);
        gc_collect_cycles();
        $this->manager = new NpcManager($owner);
        $before = memory_get_usage(); $start = hrtime(true);
        $this->permanent = $this->manager->enablePersistence(static function() : void{});
        $loadNs = hrtime(true) - $start; $dormantBytes = memory_get_usage() - $before;
        $start = hrtime(true); $this->permanent->restore(); $restoreNs = hrtime(true) - $start;
        foreach($slots as $slot){ $this->npcs[$slot] = $this->permanent->get('bench' . $slot) ?? throw new LogicException('Missing restored benchmark NPC'); }
        if($this->manager->count() !== count($slots)){ throw new LogicException('Duplicate restoration in benchmark'); }
        return ['count' => count($slots), 'registration_ns' => $this->registrationNs, 'active_registration_heap_bytes' => $this->registrationBytes, 'checkpoint_ns' => $saveNs, 'load_ns' => $loadNs, 'dormant_store_heap_bytes' => $dormantBytes, 'restore_ns' => $restoreNs];
    }
    public function manager() : NpcManager{ return $this->manager; }

	private function npc(int $slot) : Npc{ return $this->npcs[$slot] ?? throw new LogicException('Unknown slot ' . $slot); }
}
