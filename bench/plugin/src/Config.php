<?php

declare(strict_types=1);

namespace NpcLibBench;

use InvalidArgumentException;
use JsonException;

final readonly class Config{
	/** @param array<string, mixed> $workload */
	public function __construct(
		public string $id,
		public string $out,
		public Mode $mode,
		public string $type,
		public int $count,
		public string $layout,
		public int $viewers,
		public float $warmupSeconds,
		public float $windowSeconds,
		public bool $countPackets,
		public bool $pulse,
		public int $managers,
		public array $workload,
		public int $maxSeconds,
		public int $maxMemoryMb,
		public bool $environmental = false,
		public bool $persistent = false,
	){
		if($count < 0 || $viewers < 0 || $windowSeconds <= 0 || $warmupSeconds < 0 || $managers < 0){ throw new InvalidArgumentException('Invalid scenario numbers'); }
		if(!in_array($layout, ['spread', 'concentrated'], true)){ throw new InvalidArgumentException('Unknown layout ' . $layout); }
	}

	public static function fromFile(string $path) : self{
		try{ $data = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR); }
		catch(JsonException $error){ throw new InvalidArgumentException('Scenario file is not valid JSON', 0, $error); }
		if(!is_array($data)){ throw new InvalidArgumentException('Scenario must be an object'); }
		$workload = $data['workload'] ?? ['kind' => 'idle'];
		if(!is_array($workload)){ throw new InvalidArgumentException('Workload must be an object'); }
		$workload = array_filter($workload, is_string(...), ARRAY_FILTER_USE_KEY);
		return new self(
			self::string($data, 'id'),
			self::string($data, 'out'),
			Mode::from(self::string($data, 'mode')),
			self::string($data, 'type', 'mixed'),
			self::int($data, 'count', 0),
			self::string($data, 'layout', 'spread'),
			self::int($data, 'viewers', 0),
			self::float($data, 'warmup_seconds', 5.0),
			self::float($data, 'window_seconds', 20.0),
			(bool) ($data['count_packets'] ?? false),
			(bool) ($data['pulse'] ?? true),
			self::int($data, 'managers', 0),
			$workload,
			self::int($data, 'max_seconds', 600),
			self::int($data, 'max_memory_mb', 3000),
			(bool) ($data['environmental'] ?? false),
			(bool) ($data['persistent'] ?? false),
		);
	}

	/** @param array<mixed> $data */
	private static function string(array $data, string $key, ?string $default = null) : string{
		$value = $data[$key] ?? $default;
		if(!is_string($value)){ throw new InvalidArgumentException('Missing string ' . $key); }
		return $value;
	}

	/** @param array<mixed> $data */
	private static function int(array $data, string $key, int $default) : int{
		$value = $data[$key] ?? $default;
		if(!is_int($value)){ throw new InvalidArgumentException('Expected integer ' . $key); }
		return $value;
	}

	/** @param array<mixed> $data */
	private static function float(array $data, string $key, float $default) : float{
		$value = $data[$key] ?? $default;
		if(!is_int($value) && !is_float($value)){ throw new InvalidArgumentException('Expected number ' . $key); }
		return (float) $value;
	}
}
