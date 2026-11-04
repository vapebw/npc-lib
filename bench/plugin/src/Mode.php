<?php

declare(strict_types=1);

namespace NpcLibBench;

enum Mode : string{
	case Empty = 'empty';
	case NpcLib = 'npclib';
	case Native = 'native';
	case NativeNoGravity = 'native_nogravity';

	public function populated() : bool{ return $this !== self::Empty; }
}
