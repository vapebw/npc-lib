<?php

declare(strict_types=1);

namespace NpcLibBench;

use RuntimeException;

final class ResultWriter{
	/** @param array<string, mixed> $result */
	public static function write(string $path, array $result) : void{
		$temporary = $path . '.tmp';
		$json = json_encode($result, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
		if(file_put_contents($temporary, $json) === false || !rename($temporary, $path)){ throw new RuntimeException('Cannot write result ' . $path); }
	}
}
