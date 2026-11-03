<?php

declare(strict_types=1);

namespace NpcLibHarness;

use NpcLib\equipment\Equipment;
use NpcLib\npc\Npc;
use NpcLib\NpcManager;
use NpcLib\npc\PlayerNpc;
use NpcLib\npc\ZombieNpc;
use quark\entity\Location;
use quark\entity\Skin;
use quark\event\entity\EntityDamageByEntityEvent;
use quark\event\entity\EntityDamageEvent;
use quark\event\player\PlayerEntityInteractEvent;
use quark\item\VanillaItems;
use quark\player\Player;

final class Showcase{
	/** @return array{PlayerNpc, ZombieNpc} */
	public static function spawn(NpcManager $manager, Location $at, Skin $skin) : array{
		$guard = $manager->createPlayer($at, $skin, 'Guard', new Equipment(
			mainHand: VanillaItems::DIAMOND_SWORD(),
			helmet: VanillaItems::IRON_HELMET(),
			chestplate: VanillaItems::DIAMOND_CHESTPLATE(),
		));
		$guard->setScoreTag('click me');
		$guard->onInteract(static function(Npc $npc, Player $player, PlayerEntityInteractEvent $event) : void{
			$npc->lookAt($player->getLocation()->add(0, $player->getEyeHeight(), 0));
			$player->sendMessage('Halt, traveller');
		});

		$dummy = $manager->createZombie(new Location($at->x + 3, $at->y, $at->z, $at->getWorld(), 0, 0), 'Training dummy', new Equipment(helmet: VanillaItems::IRON_HELMET()));
		$dummy->setInvulnerable(false);
		$dummy->onAttack(static function(Npc $npc, ?Player $attacker, EntityDamageByEntityEvent $event) : void{
			if($attacker !== null){ $npc->lookAt($attacker->getLocation()->add(0, $attacker->getEyeHeight(), 0)); }
		});
		$dummy->onDamage(static function(Npc $npc, EntityDamageEvent $event) : void{
			if($npc->getHealth() - $event->getFinalDamage() <= 4.0){ $npc->setScale(0.75); }
		});
		return [$guard, $dummy];
	}
}
