<?php

declare(strict_types=1);

namespace NpcLib\entity;

use Closure;

interface NpcEntity{
	public function requestClose() : void;
	public function setStationary(bool $value) : void;
	public function isStationary() : bool;
	public function setEnvironmental(bool $value) : void;
	public function isEnvironmental() : bool;

	/**
	 * @template T
	 * @param Closure(): T $operation
	 * @return T
	 */
	public function guarded(Closure $operation) : mixed;
}
