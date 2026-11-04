<?php

declare(strict_types=1);

namespace NpcLibBench\Workload;

final class IdleWorkload implements Workload{
	public function prepare() : void{}
	public function step() : void{}
	public function report() : array{ return []; }
}
