<?php

declare(strict_types=1);

namespace NpcLib\internal\dispatch;

use NpcLib\npc\Npc;
use NpcLib\NpcManager;
use quark\event\entity\EntityDamageEvent;
use quark\event\entity\EntityDeathEvent;
use quark\event\entity\EntityDespawnEvent;
use quark\event\EventPriority;
use quark\event\HandlerListManager;
use quark\event\player\PlayerEntityInteractEvent;
use quark\event\plugin\PluginDisableEvent;
use quark\event\RegisteredListener;
use quark\plugin\Plugin;
use quark\Server;
use Throwable;

/** @internal */
final class NpcEventRouter{
	/** @var list<RegisteredListener<*>> */
	private array $listeners = [];

	public function __construct(private readonly NpcManager $manager, private readonly Plugin $owner){
		$events = Server::getInstance()->getPluginManager();
		try{
			$this->listeners[] = $events->registerEvent(PluginDisableEvent::class, function(PluginDisableEvent $event) : void{
				if($event->getPlugin() === $this->owner){ $this->manager->close(); }
			}, EventPriority::LOWEST, $owner);
			$this->listeners[] = $events->registerEvent(EntityDespawnEvent::class, function(EntityDespawnEvent $event) : void{
				$this->manager->forget($event->getEntity()->getId());
			}, EventPriority::LOWEST, $owner);
			$this->listeners[] = $events->registerEvent(PlayerEntityInteractEvent::class, function(PlayerEntityInteractEvent $event) : void{
				$npc = $this->manager->get($event->getEntity()->getId());
				if($npc === null){ return; }
				try{ $npc->callbacks()->interact($npc, $event); }
				catch(Throwable $error){ $event->cancel(); $this->failed($npc, $error); }
			}, EventPriority::HIGHEST, $owner, true);
			$this->listeners[] = $events->registerEvent(EntityDamageEvent::class, function(EntityDamageEvent $event) : void{
				$npc = $this->manager->get($event->getEntity()->getId());
				if($npc === null){ return; }
				try{ $npc->callbacks()->damage($npc, $event); }
				catch(Throwable $error){ $event->cancel(); $this->failed($npc, $error); }
			}, EventPriority::HIGHEST, $owner, true);
			$this->listeners[] = $events->registerEvent(EntityDeathEvent::class, function(EntityDeathEvent $event) : void{
				$npc = $this->manager->get($event->getEntity()->getId());
				if($npc === null){ return; }
				try{ $npc->callbacks()->death($npc, $event); }
				catch(Throwable $error){ $this->failed($npc, $error); }
			}, EventPriority::HIGHEST, $owner);
		}catch(Throwable $error){ $this->unregister(); throw $error; }
	}

	public function unregister() : void{
		foreach($this->listeners as $listener){ HandlerListManager::global()->unregisterAll($listener); }
		$this->listeners = [];
	}

	private function failed(Npc $npc, Throwable $error) : void{
		$this->owner->getLogger()->logException($error);
		$this->manager->suspend($npc->getId());
	}
}
