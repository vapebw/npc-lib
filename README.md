# npc-lib

NPC library for Quark API 6.0.0. PHP 8.4/8.5, namespace `NpcLib\`. Private, not published.

- `NpcManager`: one per plugin, owns its NPCs and cleans them up on disable
- `PlayerNpc` (human with skin) and `EntityNpc` (villager)
- Name, visibility, invulnerability, cancellation, optional movement
- Typed callbacks for interact, attack, damage and death

```php
$npcs = new NpcManager($this);
$guide = $npcs->createVillager($location, 'Guide');
$guide->onInteract(static function(Npc $npc, Player $player, PlayerEntityInteractEvent $event) : void{
    $player->sendMessage('Hello');
});
$guide->close();
```

NPCs are static and invulnerable by default, and are not saved with chunks.
No AI, pathfinding or persistence.

Plugins on the same server must ship the exact same version of `NpcLib\`, since PHP shares classes across PHARs.
