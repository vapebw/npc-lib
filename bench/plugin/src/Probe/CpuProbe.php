<?php

declare(strict_types=1);

namespace NpcLibBench\Probe;

use RuntimeException;

/** Process CPU time from getrusage, which Windows PHP reads from GetProcessTimes (all threads). */
final class CpuProbe{
	private int $user = 0;
	private int $system = 0;
	private int $wall = 0;

	public function start() : void{ [$this->user, $this->system, $this->wall] = $this->read(); }

	/** @return array{user_us: int, system_us: int, wall_ns: int} */
	public function stop() : array{
		[$user, $system, $wall] = $this->read();
		if($user < $this->user || $system < $this->system){ throw new RuntimeException('CPU counters went backwards'); }
		return ['user_us' => $user - $this->user, 'system_us' => $system - $this->system, 'wall_ns' => $wall - $this->wall];
	}

	/** @return array{int, int, int} */
	private function read() : array{
		$usage = getrusage();
		if($usage === false){ throw new RuntimeException('getrusage unavailable'); }
		return [
			self::field($usage, 'ru_utime.tv_sec') * 1000000 + self::field($usage, 'ru_utime.tv_usec'),
			self::field($usage, 'ru_stime.tv_sec') * 1000000 + self::field($usage, 'ru_stime.tv_usec'),
			hrtime(true),
		];
	}

	/** @param array<mixed> $usage */
	private static function field(array $usage, string $key) : int{
		$value = $usage[$key] ?? null;
		return is_int($value) ? $value : throw new RuntimeException('Unexpected getrusage field ' . $key);
	}
}
