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
	private const int MAX_BYTES = 16777216;
	private const int MAX_RECORDS = 1000;

	/** @var resource|null */
	private $lock = null;
	private string $path;

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
				($data['version'] ?? null) !== 1 ||
				($data['owner'] ?? null) !== $this->owner ||
				!is_array($data['npcs'] ?? null) ||
				count($data['npcs']) > self::MAX_RECORDS
			){
				throw new UnexpectedValueException('Invalid NPC file version, owner or records');
			}
			$result = [];
			foreach($data['npcs'] as $id => $state){
				$result[self::validateId($id)] = StateCodec::validate($state);
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
		$json = json_encode(['version' => 1, 'owner' => $this->owner, 'npcs' => (object) $records], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
		if(count($records) > self::MAX_RECORDS || strlen($json) > self::MAX_BYTES){ throw new RuntimeException('NPC storage limit exceeded'); }
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
		}finally{
			if(is_file($temp)){ unlink($temp); }
		}
	}

	public function close() : void{
		if($this->lock === null){ return; }
		fclose($this->lock);
		$this->lock = null;
	}

	public function __destruct(){ $this->close(); }
}
