# npc-lib

NPC library for [Quark](https://github.com/Bedrock-Phanatics/Quark) API 6.0.0. Requires PHP 8.4 or 8.5 (64-bit).

- `NpcManager`: one per plugin, owns its NPCs and cleans them up on disable
- Player NPCs (skin, hands, armor), villagers, zombies, and any non-human `Living` through `createMob()`
- Name, visibility, scale, score tag, rotation and look-at, optional movement
- Invulnerability and cancellation of interactions and damage
- Typed callbacks for interact, attack, damage and death
- Optional permanent NPCs that come back after a chunk reload or a restart

NPCs are static, invulnerable and not saved with chunks by default.
Static NPCs sleep between changes, so thousands of them cost almost nothing per tick.

## Install

```
composer config repositories.npc-lib vcs https://github.com/vapebw/npc-lib
composer require vapebw/npc-lib:^0.1
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

## Permanent NPCs

NPCs are transient unless you ask otherwise. Enable persistence once per manager and give each NPC a stable id:

```php
$saved = $npcs->enablePersistence(static function(string $id, Npc $npc) : void{
	// reattach callbacks here, it runs on remember() and on every restore
});
$saved->restore();

if(!in_array('guide', $saved->ids(), true)){
	$saved->remember('guide', $npcs->createVillager($location, 'Guide'));
}
```

- Definitions live in your plugin's data folder as one JSON file (up to 1000 NPCs).
- Name, appearance, scale, equipment and the common settings are saved. Effects, health, velocity and callbacks are not.
- Custom mobs need `$saved->registerMob('type', MyMob::class)` before `restore()`.
- `$saved->delete($id)` or closing the NPC removes its definition.
- A callback that throws closes its NPC but keeps the definition, call `$saved->restore()` to bring it back.

## Custom mobs

`createMob()` takes any concrete non-human `Living` that implements `NpcLib\entity\NpcEntity` and Quark's `NeverSavedWithChunkEntity`, and uses the `NpcLib\entity\EntityLifecycle` trait.

## Notes

- Plugins on the same server must ship the exact same build of `NpcLib\`, since PHP shares classes across PHARs. Call `Package::assertBundledBuild($this->getFile() . 'vendor/vapebw/npc-lib/src')` in `onEnable()` to check it.
- Public API is `NpcManager`, `Npc` and its typed subclasses, `PersistentNpcs` and the `NpcLib\entity` contract. Anything under `NpcLib\internal` can change.
- `composer check` runs the lint, unit tests and PHPStan. See [CONTRIBUTING.md](CONTRIBUTING.md).

## License

[MIT](LICENSE). Quark is licensed separately under the LGPL-3.0.
