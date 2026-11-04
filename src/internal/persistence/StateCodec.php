<?php

declare(strict_types=1);

namespace NpcLib\internal\persistence;

use LogicException;
use NpcLib\npc\Npc;
use NpcLib\npc\PlayerNpc;
use NpcLib\npc\VillagerNpc;
use NpcLib\npc\ZombieNpc;
use NpcLib\NpcManager;
use pocketmine\nbt\LittleEndianNbtSerializer;
use pocketmine\nbt\TreeRoot;
use quark\entity\Location;
use quark\entity\Skin;
use quark\item\Item;
use quark\item\VanillaItems;
use Throwable;
use UnexpectedValueException;

/**
 * @internal
 * @phpstan-type State array{
 *     type: string,
 *     world: string,
 *     position: array{float, float, float, float, float},
 *     name: string,
 *     score: string,
 *     scale: float,
 *     flags: array{bool, bool, bool, bool, bool, bool},
 *     cancelDamage: bool,
 *     profession: int,
 *     sneaking: bool,
 *     skin: list<string>,
 *     items: list<string>
 * }
 */
final class StateCodec{
	private const int MAX_TEXT = 4096;
	private const int MAX_BLOB = 262144;
	private const int MAX_ITEM = 131072;
	private const int MAX_COORDINATE = 30000000;
	private const int MAX_HEIGHT = 4096;

	private const int FLAG_NAME_VISIBLE = 0;
	private const int FLAG_INVISIBLE = 1;
	private const int FLAG_STATIC = 2;
	private const int FLAG_ENVIRONMENTAL = 3;
	private const int FLAG_INVULNERABLE = 4;
	private const int FLAG_CANCEL_INTERACTIONS = 5;

	/** @return State */
	public static function capture(Npc $npc, string $type) : array{
		$location = $npc->getLocation();
		$skin = [];
		$items = [];
		if($npc instanceof PlayerNpc){
			$source = $npc->getSkin();
			$skin = array_map(base64_encode(...), [
				$source->getSkinId(),
				$source->getSkinData(),
				$source->getCapeData(),
				$source->getGeometryName(),
				$source->getGeometryData()
			]);
			$items = [$npc->getMainHand(), $npc->getOffHand()];
		}
		if($npc instanceof PlayerNpc || $npc instanceof ZombieNpc){
			array_push($items, $npc->getHelmet(), $npc->getChestplate(), $npc->getLeggings(), $npc->getBoots());
		}
		return self::validate([
			'type' => $type,
			'world' => $location->getWorld()->getFolderName(),
			'position' => [$location->x, $location->y, $location->z, $location->yaw, $location->pitch],
			'name' => $npc->getName(),
			'score' => $npc->getScoreTag() ?? '',
			'scale' => $npc->getScale(),
			'flags' => [
				self::FLAG_NAME_VISIBLE => $npc->isNameVisible(),
				self::FLAG_INVISIBLE => $npc->isInvisible(),
				self::FLAG_STATIC => $npc->isStatic(),
				self::FLAG_ENVIRONMENTAL => $npc->isEnvironmental(),
				self::FLAG_INVULNERABLE => $npc->isInvulnerable(),
				self::FLAG_CANCEL_INTERACTIONS => $npc->callbacks()->cancelInteractions
			],
			'cancelDamage' => $npc->callbacks()->cancelDamage,
			'profession' => $npc instanceof VillagerNpc ? $npc->getProfession() : 0,
			'sneaking' => $npc instanceof PlayerNpc && $npc->isSneaking(),
			'skin' => $skin,
			'items' => array_map(self::encodeItem(...), $items)
		]);
	}

	/** @return State */
	public static function validate(mixed $data) : array{
		if(!is_array($data)){ throw new UnexpectedValueException('Invalid NPC record'); }
		$position = $data['position'] ?? null;
		$flags = $data['flags'] ?? null;
		$skin = $data['skin'] ?? null;
		$items = $data['items'] ?? null;
		if(!self::isList($position, 5) || !self::isList($flags, 6) || !self::isList($skin) || !self::isList($items)){
			throw new UnexpectedValueException('Invalid NPC state structure');
		}
		$type = self::text($data['type'] ?? null, 64);
		if(preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $type) !== 1){ throw new UnexpectedValueException('Invalid NPC type'); }
		$world = self::text($data['world'] ?? null, 255);
		if($world === '' || $world === '.' || $world === '..' || strpbrk($world, "/\\\0") !== false){
			throw new UnexpectedValueException('Invalid NPC world name');
		}
		$expectedSkin = $type === 'player' ? 5 : 0;
		$expectedItems = match($type){
			'player' => 6,
			'zombie' => 4,
			default => 0
		};
		if(count($skin) !== $expectedSkin || count($items) !== $expectedItems){ throw new UnexpectedValueException('NPC type payload mismatch'); }
		$skin = array_map(static fn(mixed $value) : string => self::text($value, self::MAX_BLOB), $skin);
		if($type === 'player'){ self::decodeSkin($skin); }
		$items = array_map(static fn(mixed $value) : string => self::text($value, self::MAX_ITEM), $items);
		foreach($items as $item){ self::decodeItem($item); }
		$profession = $data['profession'] ?? null;
		if(!is_int($profession) || $profession < 0 || $profession > 4){ throw new UnexpectedValueException('Invalid NPC profession'); }
		return [
			'type' => $type,
			'world' => $world,
			'position' => [
				self::number($position[0], -self::MAX_COORDINATE, self::MAX_COORDINATE),
				self::number($position[1], -self::MAX_HEIGHT, self::MAX_HEIGHT),
				self::number($position[2], -self::MAX_COORDINATE, self::MAX_COORDINATE),
				self::number($position[3], -360, 360),
				self::number($position[4], -90, 90)
			],
			'name' => self::text($data['name'] ?? null),
			'score' => self::text($data['score'] ?? null),
			'scale' => self::number($data['scale'] ?? null, PHP_FLOAT_MIN * PHP_FLOAT_EPSILON, Npc::MAX_SCALE),
			'flags' => [
				self::flag($flags[0]),
				self::flag($flags[1]),
				self::flag($flags[2]),
				self::flag($flags[3]),
				self::flag($flags[4]),
				self::flag($flags[5])
			],
			'cancelDamage' => self::flag($data['cancelDamage'] ?? null),
			'profession' => $profession,
			'sneaking' => self::flag($data['sneaking'] ?? null),
			'skin' => $skin,
			'items' => $items
		];
	}

	/**
	 * @param State $state
	 * @param class-string|null $mobClass
	 */
	public static function spawn(NpcManager $manager, Location $location, array $state, ?string $mobClass) : Npc{
		$name = $state['name'];
		$npc = match($state['type']){
			'player' => $manager->createPlayer($location, self::decodeSkin($state['skin']), $name),
			'villager' => $manager->createVillager($location, $name),
			'zombie' => $manager->createZombie($location, $name),
			default => $manager->createMob($mobClass ?? throw new UnexpectedValueException('Unregistered custom NPC type'), $location, $name)
		};
		try{
			$flags = $state['flags'];
			$npc->setNameVisible($flags[self::FLAG_NAME_VISIBLE]);
			$npc->setInvisible($flags[self::FLAG_INVISIBLE]);
			$npc->setStatic($flags[self::FLAG_STATIC]);
			$npc->setEnvironmental($flags[self::FLAG_ENVIRONMENTAL]);
			$npc->setInvulnerable($flags[self::FLAG_INVULNERABLE]);
			$npc->setCancelInteractions($flags[self::FLAG_CANCEL_INTERACTIONS]);
			$npc->setCancelDamage($state['cancelDamage']);
			$npc->setScoreTag($state['score']);
			$npc->setScale($state['scale']);
			$items = $state['items'];
			if($npc instanceof VillagerNpc){ $npc->setProfession($state['profession']); }
			if($npc instanceof PlayerNpc){
				$npc->setSneaking($state['sneaking']);
				$npc->setMainHand(self::decodeItem($items[0]));
				$npc->setOffHand(self::decodeItem($items[1]));
			}
			if($npc instanceof PlayerNpc || $npc instanceof ZombieNpc){
				$armor = $npc instanceof PlayerNpc ? 2 : 0;
				$npc->setHelmet(self::decodeItem($items[$armor]));
				$npc->setChestplate(self::decodeItem($items[$armor + 1]));
				$npc->setLeggings(self::decodeItem($items[$armor + 2]));
				$npc->setBoots(self::decodeItem($items[$armor + 3]));
			}
			if($npc->isClosed()){ throw new LogicException('NPC closed while restoring configuration'); }
			return $npc;
		}catch(Throwable $error){
			$npc->close();
			throw $error;
		}
	}

	/** @phpstan-assert-if-true list<mixed> $value */
	private static function isList(mixed $value, ?int $size = null) : bool{
		return is_array($value) && array_is_list($value) && ($size === null || count($value) === $size);
	}

	private static function text(mixed $value, int $limit = self::MAX_TEXT) : string{
		if(!is_string($value) || strlen($value) > $limit || preg_match('//u', $value) !== 1){ throw new UnexpectedValueException('Invalid or oversized NPC string'); }
		return $value;
	}

	private static function number(mixed $value, float $min, float $max) : float{
		if((!is_float($value) && !is_int($value)) || !is_finite((float) $value) || $value < $min || $value > $max){ throw new UnexpectedValueException('Invalid NPC number'); }
		return (float) $value;
	}

	private static function flag(mixed $value) : bool{
		if(!is_bool($value)){ throw new UnexpectedValueException('Invalid NPC boolean'); }
		return $value;
	}

	private static function binary(mixed $value) : string{
		$decoded = base64_decode(self::text($value, self::MAX_BLOB), true);
		if($decoded === false){ throw new UnexpectedValueException('Invalid NPC binary data'); }
		return $decoded;
	}

	/** @param list<string> $parts */
	private static function decodeSkin(array $parts) : Skin{
		return new Skin(
			self::binary($parts[0]),
			self::binary($parts[1]),
			self::binary($parts[2]),
			self::binary($parts[3]),
			self::binary($parts[4])
		);
	}

	private static function encodeItem(Item $item) : string{
		if($item->isNull()){ return ''; }
		return base64_encode((new LittleEndianNbtSerializer())->write(new TreeRoot($item->nbtSerialize())));
	}

	private static function decodeItem(string $encoded) : Item{
		if($encoded === ''){ return VanillaItems::AIR(); }
		$bytes = self::binary($encoded);
		$offset = 0;
		$tag = (new LittleEndianNbtSerializer())->read($bytes, $offset, 16)->mustGetCompoundTag();
		if($offset !== strlen($bytes)){ throw new UnexpectedValueException('Trailing item data'); }
		return Item::nbtDeserialize($tag);
	}
}
