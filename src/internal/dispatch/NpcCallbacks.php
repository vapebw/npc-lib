<?php

declare(strict_types=1);

namespace NpcLib\internal\dispatch;

use Closure;
use NpcLib\npc\Npc;
use quark\event\entity\EntityDamageByEntityEvent;
use quark\event\entity\EntityDamageEvent;
use quark\event\entity\EntityDeathEvent;
use quark\event\player\PlayerEntityInteractEvent;
use quark\player\Player;

/** @internal */
final class NpcCallbacks{
	public bool $invulnerable = true;
	public bool $cancelInteractions = true;
	public bool $cancelDamage = false;
	/** @var null|Closure(Npc, Player, PlayerEntityInteractEvent): void */
	public ?Closure $onInteract = null;
	/** @var null|Closure(Npc, ?Player, EntityDamageByEntityEvent): void */
	public ?Closure $onAttack = null;
	/** @var null|Closure(Npc, EntityDamageEvent): void */
	public ?Closure $onDamage = null;
	/** @var null|Closure(Npc, EntityDeathEvent): void */
	public ?Closure $onDeath = null;

	public function interact(Npc $npc, PlayerEntityInteractEvent $event) : void{
		if($event->isCancelled()){ return; }
		$cancelled = $this->blocksInteractions();
		if($cancelled){ $event->cancel(); }
		try{ ($this->onInteract)?->__invoke($npc, $event->getPlayer(), $event); }
		finally{
			if($cancelled || $this->blocksInteractions() || $npc->isClosed()){ $event->cancel(); }
		}
	}

	public function damage(Npc $npc, EntityDamageEvent $event) : void{
		if($event->isCancelled()){ return; }
		$cancelled = $this->blocksDamage();
		if($cancelled){ $event->cancel(); }
		try{
			if($event instanceof EntityDamageByEntityEvent){
				$cancelled = $this->attack($npc, $event) || $cancelled;
			}
			if(!$npc->isClosed()){ ($this->onDamage)?->__invoke($npc, $event); }
		}finally{
			if($cancelled || $this->blocksDamage() || $npc->isClosed()){ $event->cancel(); }
		}
	}

	public function death(Npc $npc, EntityDeathEvent $event) : void{ ($this->onDeath)?->__invoke($npc, $event); }

	public function clear() : void{ $this->onInteract = $this->onAttack = $this->onDamage = $this->onDeath = null; }

	private function attack(Npc $npc, EntityDamageByEntityEvent $event) : bool{
		$damager = $event->getDamager();
		($this->onAttack)?->__invoke($npc, $damager instanceof Player ? $damager : null, $event);
		return $event->isCancelled();
	}

	private function blocksInteractions() : bool{ return $this->cancelInteractions; }
	private function blocksDamage() : bool{ return $this->invulnerable || $this->cancelDamage; }
}
