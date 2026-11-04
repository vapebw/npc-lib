<?php

declare(strict_types=1);

namespace NpcLibBench\Probe;

use quark\event\EventPriority;
use quark\event\HandlerListManager;
use quark\event\RegisteredListener;
use quark\event\server\DataPacketSendEvent;
use quark\plugin\PluginBase;

/**
 * Transport bytes come from Quark's bandwidth tracker (RakNet level, after compression).
 * Logical packets are counted per recipient only when the listener is enabled, which adds its own cost.
 */
final class NetworkProbe{
	/** @var array<string, int> */
	private array $packets = [];
	/** @var null|RegisteredListener<DataPacketSendEvent> */
	private ?RegisteredListener $listener = null;
	private int $sent = 0;
	private int $received = 0;

	public function __construct(private readonly PluginBase $plugin, private readonly bool $countPackets){}

	public function start() : void{
		$this->packets = [];
		$this->sent = $this->tracker()->getSend()->getTotalBytes();
		$this->received = $this->tracker()->getReceive()->getTotalBytes();
		if($this->countPackets){
			$this->listener = $this->plugin->getServer()->getPluginManager()->registerEvent(DataPacketSendEvent::class, function(DataPacketSendEvent $event) : void{
				$targets = count($event->getTargets());
				foreach($event->getPackets() as $packet){
					$name = substr($packet::class, (int) strrpos($packet::class, '\\') + 1);
					$this->packets[$name] = ($this->packets[$name] ?? 0) + $targets;
				}
			}, EventPriority::MONITOR, $this->plugin, true);
		}
	}

	/** @return array{transport_sent_bytes: int, transport_received_bytes: int, logical_packets: array<string, int>|null} */
	public function stop() : array{
		if($this->listener !== null){
			HandlerListManager::global()->unregisterAll($this->listener);
			$this->listener = null;
		}
		ksort($this->packets);
		return [
			'transport_sent_bytes' => $this->tracker()->getSend()->getTotalBytes() - $this->sent,
			'transport_received_bytes' => $this->tracker()->getReceive()->getTotalBytes() - $this->received,
			'logical_packets' => $this->countPackets ? $this->packets : null,
		];
	}

	private function tracker() : \quark\network\BidirectionalBandwidthStatsTracker{ return $this->plugin->getServer()->getNetwork()->getBandwidthTracker(); }
}
