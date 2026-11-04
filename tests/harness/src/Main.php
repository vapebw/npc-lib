<?php

declare(strict_types=1);

namespace NpcLibHarness;

use NpcLib\Package;
use quark\plugin\PluginBase;
use ReflectionClass;
use RuntimeException;

final class Main extends PluginBase{
	private ?LifecycleSuite $lifecycle = null;
	private ?FeatureSuite $features = null;
	private ?EnvironmentSuite $environment = null;
	private ?WireFixture $wire = null;

	protected function onLoad() : void{
		require_once $this->getFile() . 'vendor/autoload.php';
	}

	protected function onEnable() : void{
		if($this->getServer()->getName() !== 'Quark' || $this->getServer()->getApiVersion() !== Package::QUARK_API){
			throw new RuntimeException('npc-lib harness requires Quark API 6.0.0');
		}
		$file = (new ReflectionClass(Package::class))->getFileName();
		$expected = str_replace('\\', '/', $this->getFile() . 'vendor/vapebw/npc-lib/src/Package.php');
		if($file === false || str_replace('\\', '/', $file) !== $expected){
			throw new RuntimeException('npc-lib was loaded outside the harness PHAR');
		}
		$this->getLogger()->info('harness AUTOLOAD PASS api=' . Package::QUARK_API . ' source=' . $file);
		$this->getLogger()->info('harness LOAD PASS');
		if(getenv('NPCLIB_SUITE') === '1'){
			$this->lifecycle = new LifecycleSuite($this, function() : void{
				$this->features = new FeatureSuite($this, function() : void{
					$this->environment = new EnvironmentSuite($this, function() : void{ $this->getLogger()->info('feature suite done'); });
				});
			});
		}
		if(getenv('NPCLIB_WIRE') === '1'){ $this->wire = new WireFixture($this); }
	}

	protected function onDisable() : void{
		$this->lifecycle?->finish();
		$this->features?->finish();
		$this->environment?->finish();
		$this->wire?->finish();
		$this->lifecycle = null;
		$this->features = null;
		$this->environment = null;
		$this->wire = null;
		$this->getLogger()->info('harness DISABLE PASS');
	}

	public function onCommand(\quark\command\CommandSender $sender, \quark\command\Command $command, string $label, array $args) : bool{
		if($command->getName() === 'npctest' && $this->wire !== null){ $this->wire->command(array_values($args)); return true; }
		return false;
	}
}
