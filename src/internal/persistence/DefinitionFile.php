<?php

declare(strict_types=1);

namespace NpcLib\internal\persistence;

use RuntimeException;
use Throwable;
use UnexpectedValueException;

/**
 * @internal
 * @phpstan-import-type State from StateCodec
 */
final class DefinitionFile{
	private const int MAX_BYTES = 33554432;
	private const int MAX_RECORDS = 1000;
	private const int VERSION = 2;
	private const int JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES;

	/** @var resource|null */
	private $lock = null;
	private string $path;
	/**
	 * Encoded form of each record, unchanged NPCs are not encoded again
	 * @var array<string, array{State, string, string}>
	 */
	private array $encoded = [];
	/** @var array<string, string> */
	private array $encodedSkins = [];
	private ?string $written = null;
	private int $writes = 0;

	public function __construct(string $directory, private readonly string $owner){
		if(!is_dir($directory) && !mkdir($directory, 0777, true)){ throw new RuntimeException('Cannot create NPC data directory'); }
		$this->path = rtrim($directory, '/\\') . '/npcs.json';
		if(is_link($this->path) || is_link($this->path . '.lock')){ throw new RuntimeException('NPC storage cannot be a symlink'); }
		$lock = fopen($this->path . '.lock', 'c+b');
		if($lock === false){ throw new RuntimeException('Cannot open NPC storage lock'); }
		if(!flock($lock, LOCK_EX | LOCK_NB)){
			fclose($lock);
			throw new RuntimeException('NPC storage already owned by another manager');
		}
		$this->lock = $lock;
		$this->removeInterruptedWrites(rtrim($directory, '/\\'));
	}

	/** @return array<string, State> */
	public function load() : array{
		if(!file_exists($this->path)){ return []; }
		try{
			$stream = fopen($this->path, 'rb');
			if($stream === false){ throw new RuntimeException('Cannot read NPC definitions'); }
			try{ $json = stream_get_contents($stream, self::MAX_BYTES + 1); }
			finally{ fclose($stream); }
			if($json === false || strlen($json) > self::MAX_BYTES){ throw new UnexpectedValueException('NPC definition file exceeds limit'); }
			$data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
			if(
				!is_array($data) ||
				!in_array($data['version'] ?? null, [1, self::VERSION], true) ||
				($data['owner'] ?? null) !== $this->owner ||
				!is_array($data['npcs'] ?? null) ||
				count($data['npcs']) > self::MAX_RECORDS
			){
				throw new UnexpectedValueException('Invalid NPC file version, owner or records');
			}
			$result = [];
			if($data['version'] === 1){
				foreach($data['npcs'] as $id => $state){ $result[self::validateId($id)] = StateCodec::validate($state); }
				return $result;
			}
			$skins = $data['skins'] ?? null;
			if(!is_array($skins) || count($skins) > self::MAX_RECORDS){ throw new UnexpectedValueException('Invalid NPC skin table'); }
			// each distinct skin is checked once and every record shares the same strings
			$shared = [];
			foreach($skins as $key => $parts){ $shared[$key] = StateCodec::validateSkin($parts); }
			foreach($data['npcs'] as $id => $state){
				if(!is_array($state)){ throw new UnexpectedValueException('Invalid NPC record'); }
				$key = $state['skin'] ?? null;
				if($key === ''){ $state['skin'] = []; }
				elseif((!is_string($key) && !is_int($key)) || !isset($shared[$key])){ throw new UnexpectedValueException('Unknown NPC skin reference'); }
				else{ $state['skin'] = $shared[$key]; }
				$result[self::validateId($id)] = StateCodec::validate($state, true);
			}
			return $result;
		}catch(Throwable $error){
			throw new UnexpectedValueException('Cannot load NPC definitions; original file preserved', 0, $error);
		}
	}

	public static function validateId(mixed $id) : string{
		// letter prefix keeps php from turning numeric ids into int keys
		if(!is_string($id) || preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,63}$/D', $id) !== 1){
			throw new UnexpectedValueException('NPC IDs must start with a letter and contain at most 64 letters, digits, _ or -');
		}
		return $id;
	}

	/** @param array<string, State> $records */
	public function save(array $records) : void{
		if($this->lock === null){ throw new RuntimeException('NPC storage is closed'); }
		if(count($records) > self::MAX_RECORDS){ throw new RuntimeException('NPC storage limit exceeded'); }
		$json = $this->encode($records);
		if(strlen($json) > self::MAX_BYTES){ throw new RuntimeException('NPC storage limit exceeded'); }
		$digest = hash('xxh128', $json);
		// same bytes as the last write: no disk work, static NPCs reloading their chunk end up here
		if($digest === $this->written && is_file($this->path)){ return; }
		$temp = $this->path . '.' . bin2hex(random_bytes(8)) . '.tmp';
		$stream = fopen($temp, 'x+b');
		if($stream === false){ throw new RuntimeException('Cannot create NPC temporary file'); }
		try{
			try{
				if(fwrite($stream, $json) !== strlen($json) || !fflush($stream) || !fsync($stream)){
					throw new RuntimeException('Cannot flush NPC definitions');
				}
			}finally{ fclose($stream); }
			if(is_link($this->path) || !rename($temp, $this->path)){ throw new RuntimeException('Cannot atomically replace NPC definitions'); }
			$this->syncDirectory();
			$this->written = $digest;
			++$this->writes;
		}finally{
			if(is_file($temp)){ unlink($temp); }
		}
	}

	/** @return int files written by this instance */
	public function writes() : int{ return $this->writes; }

	public function close() : void{
		if($this->lock === null){ return; }
		fclose($this->lock);
		$this->lock = null;
		$this->encoded = []; $this->encodedSkins = [];
	}

	public function __destruct(){ $this->close(); }

	// we hold the lock, so a temporary file here was left by a crash
	private function removeInterruptedWrites(string $directory) : void{
		$names = scandir($directory);
		foreach($names === false ? [] : $names as $name){
			if(preg_match('/^npcs\.json\.[0-9a-f]{16}\.tmp$/D', $name) === 1){ @unlink($directory . '/' . $name); }
		}
	}

	// makes the rename survive an OS crash where the platform allows it, php can't open a directory on windows
	private function syncDirectory() : void{
		if(PHP_OS_FAMILY === 'Windows'){ return; }
		$directory = @fopen(dirname($this->path), 'r');
		if($directory === false){ return; }
		@fsync($directory);
		fclose($directory);
	}

	/** @param array<string, State> $records */
	private function encode(array $records) : string{
		$encoded = [];
		$npcs = [];
		$skins = [];
		foreach($records as $id => $state){
			$entry = $this->encoded[$id] ?? null;
			if($entry === null || $entry[0] !== $state){ $entry = $this->encodeRecord($id, $state); }
			$encoded[$id] = $entry;
			$npcs[] = $entry[1];
			if($entry[2] !== ''){ $skins[$entry[2]] = $this->encodedSkins[$entry[2]]; }
		}
		$this->encoded = $encoded;
		$this->encodedSkins = $skins;
		return '{"version":' . self::VERSION . ',"owner":' . json_encode($this->owner, self::JSON_FLAGS)
			. ",\n\"skins\":{\n" . implode(",\n", $skins)
			. "\n},\n\"npcs\":{\n" . implode(",\n", $npcs) . "\n}}\n";
	}

	/**
	 * @param State $state
	 * @return array{State, string, string}
	 */
	private function encodeRecord(string $id, array $state) : array{
		$key = '';
		if($state['skin'] !== []){
			$key = hash('xxh128', implode("\0", $state['skin']));
			$this->encodedSkins[$key] ??= json_encode($key, self::JSON_FLAGS) . ':' . json_encode($state['skin'], self::JSON_FLAGS);
		}
		$record = $state;
		$record['skin'] = $key;
		return [$state, json_encode($id, self::JSON_FLAGS) . ':' . json_encode($record, self::JSON_FLAGS), $key];
	}
}
