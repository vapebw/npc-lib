<?php

declare(strict_types=1);

namespace NpcLibBench\Workload;

use Closure;
use InvalidArgumentException;
use NpcLibBench\Population\Population;
use quark\entity\Location;

final class WorkloadFactory{
	/**
	 * @param array<string, mixed> $spec
	 * @param Closure(int): Location $location
	 */
	public static function create(array $spec, ?Population $population, ?Population $foreign, Closure $location, string $type) : Workload{
		$kind = $spec['kind'] ?? 'idle';
		$perTick = self::int($spec, 'per_tick', 1);
		return match($kind){
			'idle' => new IdleWorkload(),
			'churn' => new ChurnWorkload(self::population($population), $location, $type, $perTick),
			'mutate' => new MutateWorkload(self::population($population), is_string($spec['operation'] ?? null) ? $spec['operation'] : throw new InvalidArgumentException('Missing operation'), $perTick, (bool) ($spec['wake'] ?? false)),
			'damage' => new DamageWorkload(self::population($population), (bool) ($spec['callbacks'] ?? false), $perTick, (bool) ($spec['wake'] ?? true)),
			'composite' => self::composite($spec, $population, $foreign, $location, $type),
			'burn' => new BurnWorkload(is_int($spec['milliseconds'] ?? null) || is_float($spec['milliseconds'] ?? null) ? (float) $spec['milliseconds'] : throw new InvalidArgumentException('Missing milliseconds')),
			'foreign_damage' => new DamageWorkload($foreign ?? throw new InvalidArgumentException('foreign_damage needs foreign entities'), false, $perTick),
			default => throw new InvalidArgumentException('Unknown workload ' . (is_string($kind) ? $kind : 'value')),
		};
	}

	/**
	 * @param array<string, mixed> $spec
	 * @param Closure(int): Location $location
	 */
	private static function composite(array $spec, ?Population $population, ?Population $foreign, Closure $location, string $type) : Workload{
		$parts = $spec['parts'] ?? null;
		if(!is_array($parts)){ throw new InvalidArgumentException('Missing parts'); }
		$workloads = [];
		foreach($parts as $part){
			if(!is_array($part)){ throw new InvalidArgumentException('Invalid part'); }
			$workloads[] = self::create(array_filter($part, is_string(...), ARRAY_FILTER_USE_KEY), $population, $foreign, $location, $type);
		}
		return new CompositeWorkload($workloads);
	}

	private static function population(?Population $population) : Population{ return $population ?? throw new InvalidArgumentException('Workload needs a populated mode'); }

	/** @param array<string, mixed> $spec */
	private static function int(array $spec, string $key, int $default) : int{
		$value = $spec[$key] ?? $default;
		return is_int($value) ? $value : throw new InvalidArgumentException('Expected integer ' . $key);
	}
}
