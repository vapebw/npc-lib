# npc-lib

NPC library for [Quark](https://github.com/Bedrock-Phanatics/Quark) API 6.0.0. Requires PHP 8.4 or 8.5 (64-bit).

- `NpcManager`: one per plugin, owns its NPCs and cleans them up on disable
- Player NPCs (skin, hands, armor), villagers, zombies, and any non-human `Living` through `createMob()`
- Name, visibility, scale, score tag, rotation and look-at, optional movement
- Invulnerability and cancellation of interactions and damage
- Typed callbacks for interact, attack, damage and death

NPCs are static, invulnerable and not saved with chunks by default.
Static NPCs sleep between changes, so thousands of them cost almost nothing per tick.

## Install

```
composer config repositories.npc-lib vcs https://github.com/vapebw/npc-lib
composer require vapebw/npc-lib:dev-main
```

Initialize `vendor/autoload.php` once in `onLoad()` and declare `api = ["6.0.0"]` in your `plugin.toml`.

## Usage

```php
use NpcLib\NpcManager;
use NpcLib\npc\Npc;

$npcs = new NpcManager($this);

$guide = $npcs->createVillager($location, 'Guide');
$guide->onInteract(static function(Npc $npc, Player $player, PlayerEntityInteractEvent $event) : void{
	$player->sendMessage('Hello');
});

$guide->close();
```

Closing the manager (it happens on its own when your plugin is disabled) removes every NPC it created.

## Notes

- Plugins on the same server must ship the exact same version of `NpcLib\`, since PHP shares classes across PHARs.
- Only `NpcManager`, `Npc` and its typed subclasses are public API. Anything under `NpcLib\internal` can change.
- `composer check` runs the lint, unit tests and PHPStan. See [CONTRIBUTING.md](CONTRIBUTING.md).

## License

[MIT](LICENSE). Quark is licensed separately under the LGPL-3.0.
