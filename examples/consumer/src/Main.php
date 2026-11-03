<?php

declare(strict_types=1);

namespace NpcLibP0Consumer;

use NpcLib\Package;
use quark\plugin\PluginBase;
use ReflectionClass;
use RuntimeException;

final class Main extends PluginBase{
	private ?CoreSuite $suite = null;
	private ?WireSuite $wire = null;
	protected function onLoad() : void{
		require_once $this->getFile() . 'vendor/autoload.php';
	}

	protected function onEnable() : void{
		if($this->getServer()->getName() !== 'Quark' || $this->getServer()->getApiVersion() !== Package::QUARK_API){
			throw new RuntimeException('npc-lib P0 requires Quark API 6.0.0');
		}
		$file = (new ReflectionClass(Package::class))->getFileName();
		$expected = str_replace('\\', '/', $this->getFile() . 'vendor/vapebw/npc-lib/src/Package.php');
		if($file === false || str_replace('\\', '/', $file) !== $expected){
			throw new RuntimeException('npc-lib was loaded outside the consumer PHAR');
		}
		$this->getLogger()->info('npc-lib P0 AUTOLOAD PASS api=' . Package::QUARK_API . ' source=' . $file);
		$this->getLogger()->info('npc-lib P0 LOAD PASS');
		if(getenv('NPCLIB_SUITE') === '1'){
			$this->suite = new CoreSuite($this);
		}
		if(getenv('NPCLIB_WIRE') === '1'){ $this->wire = new WireSuite($this); }
	}

	protected function onDisable() : void{
		$this->suite?->finish();
		$this->wire?->finish();
		$this->suite = null;
		$this->wire = null;
		$this->getLogger()->info('npc-lib P0 DISABLE PASS');
	}
	public function onCommand(\quark\command\CommandSender $sender, \quark\command\Command $command, string $label, array $args) : bool{
		if($command->getName() === 'npctest' && $this->wire !== null){ $this->wire->command(array_values($args)); return true; }
		return false;
	}
}
