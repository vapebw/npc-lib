# npc-lib

Static, lightweight NPCs for [Quark](https://github.com/Bedrock-Phanatics/Quark) API 6.0.0. Requires PHP 8.4 or 8.5 (64-bit).

## Install

```
composer config repositories.npc-lib vcs https://github.com/vapebw/npc-lib
composer require vapebw/npc-lib:^0.1
```

Load `vendor/autoload.php` in `onLoad()`. If several plugins bundle the library, they must ship the same build.

## Usage

```php
use NpcLib\NpcManager;
use NpcLib\npc\Npc;

$npcs = new NpcManager($this);

$guide = $npcs->createVillager($location, 'Guide');
$guide->onInteract(static function(Npc $npc, Player $player, PlayerEntityInteractEvent $event) : void{
	$player->sendMessage('Hello');
});
```

Use `createPlayer()`, `createVillager()`, `createZombie()` or `createMob()`. NPCs are removed when your plugin is disabled.

## Permanent NPCs

```php
$saved = $npcs->enablePersistence(static function(string $id, Npc $npc) : void{
	// reattach callbacks here
});
$saved->restoreGradually();

if(!$saved->has('guide')){
	$saved->remember('guide', $npcs->createVillager($location, 'Guide'));
}
```

- Stores up to 1000 NPCs. Callbacks, effects and health are not saved.
- Call `save()` to write changes right away. Otherwise they are written when the NPC's chunk unloads or on shutdown.
- Closing an NPC or `delete()` removes it permanently. Use `deleteBatch()` for many.

## License

[MIT](LICENSE). Quark is licensed separately under the LGPL-3.0.
