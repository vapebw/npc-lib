<?php

declare(strict_types=1);

namespace NpcLibBench\Workload;

final class CompositeWorkload implements Workload{
	/** @param list<Workload> $parts */
	public function __construct(private readonly array $parts){}

	public function prepare() : void{
		foreach($this->parts as $part){ $part->prepare(); }
	}

	public function step() : void{
		foreach($this->parts as $part){ $part->step(); }
	}

	public function report() : array{
		$report = [];
		foreach($this->parts as $index => $part){
			foreach($part->report() as $key => $value){ $report[$index . '.' . $key] = $value; }
		}
		return $report;
	}
}
