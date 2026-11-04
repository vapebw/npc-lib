<?php

declare(strict_types=1);

namespace NpcLibBench;

use quark\plugin\PluginBase;
use RuntimeException;

final class Main extends PluginBase{
	protected function onLoad() : void{
		require_once $this->getFile() . 'vendor/autoload.php';
	}

	protected function onEnable() : void{
		$path = getenv('NPCBENCH_SCENARIO');
		if($path === false || !is_file($path)){ throw new RuntimeException('NPCBENCH_SCENARIO must point to a scenario file'); }
		(new Run($this, Config::fromFile($path)))->start();
	}
}
