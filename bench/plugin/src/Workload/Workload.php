<?php

declare(strict_types=1);

namespace NpcLibBench\Workload;

interface Workload{
	/** Runs once before the measured window so setup cost is not attributed to it. */
	public function prepare() : void;

	/** Runs once per tick inside the window. */
	public function step() : void;

	/** @return array<string, int|float|string> */
	public function report() : array;
}
