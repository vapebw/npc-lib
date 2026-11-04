<?php

declare(strict_types=1);

namespace NpcLibBench;

use Closure;
use quark\plugin\PluginBase;
use quark\scheduler\CancelTaskException;
use quark\scheduler\ClosureTask;
use Throwable;

/** Thin scheduler helpers. Every callback failure is routed to one error handler. */
final class Timeline{
	/** @param Closure(Throwable): void $onError */
	public function __construct(private readonly PluginBase $plugin, private readonly Closure $onError){}

	/** @param Closure(): void $then */
	public function next(Closure $then) : void{
		$this->plugin->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($then) : void{
			try{ $then(); }catch(Throwable $error){ ($this->onError)($error); }
		}), 1);
	}

	/**
	 * Calls $callback every tick until it returns false.
	 * @param Closure(): bool $callback
	 */
	public function every(Closure $callback) : void{
		$this->plugin->getScheduler()->scheduleRepeatingTask(new ClosureTask(function() use ($callback) : void{
			try{
				if(!$callback()){ throw new CancelTaskException(); }
			}catch(CancelTaskException $stop){
				throw $stop;
			}catch(Throwable $error){
				($this->onError)($error);
				throw new CancelTaskException();
			}
		}), 1);
	}

	/**
	 * @param Closure(): bool $condition
	 * @param Closure(): void $then
	 */
	public function until(Closure $condition, Closure $then, float $timeoutSeconds, string $what) : void{
		$deadline = hrtime(true) + (int) ($timeoutSeconds * 1e9);
		$this->every(function() use ($condition, $then, $deadline, $what) : bool{
			if($condition()){
				$then();
				return false;
			}
			if(hrtime(true) > $deadline){ throw new \RuntimeException('Timed out waiting for ' . $what); }
			return true;
		});
	}

	/** @param Closure(): void $then */
	public function wait(float $seconds, Closure $then) : void{
		$deadline = hrtime(true) + (int) ($seconds * 1e9);
		$this->until(static fn() : bool => hrtime(true) >= $deadline, $then, $seconds + 30, 'timer');
	}
}
