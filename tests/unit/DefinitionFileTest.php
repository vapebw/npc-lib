<?php

declare(strict_types=1);

namespace NpcLibTests\Unit;

use NpcLib\internal\persistence\DefinitionFile;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use UnexpectedValueException;

final class DefinitionFileTest extends TestCase{
	private string $directory;

	protected function setUp() : void{ $this->directory = sys_get_temp_dir() . '/npc-store-' . bin2hex(random_bytes(8)); mkdir($this->directory); }

	protected function tearDown() : void{
		foreach(['npcs.json', 'npcs.json.lock'] as $file){ if(is_file($this->directory . '/' . $file)){ unlink($this->directory . '/' . $file); } }
		rmdir($this->directory);
	}

	public function testCommittedFileSurvivesInterruptedTemporaryWrite() : void{
		$file = new DefinitionFile($this->directory, 'Owner');
		$file->save([]);
		file_put_contents($this->directory . '/npcs.json.interrupted.tmp', '{partial');
		self::assertSame([], $file->load());
		unlink($this->directory . '/npcs.json.interrupted.tmp');
		$file->close();
	}

	public function testOpeningRemovesWritesInterruptedByACrash() : void{
		$file = new DefinitionFile($this->directory, 'Owner');
		$file->save(['a' => $this->villager('A')]);
		$file->close();
		$committed = file_get_contents($this->directory . '/npcs.json');
		$leftover = $this->directory . '/npcs.json.0123456789abcdef.tmp';
		$foreign = $this->directory . '/npcs.json.notours.tmp';
		file_put_contents($leftover, '{partial');
		file_put_contents($foreign, 'keep');
		$file = new DefinitionFile($this->directory, 'Owner');
		try{
			self::assertFileDoesNotExist($leftover);
			self::assertFileExists($foreign);
			self::assertSame(['a' => $this->villager('A')], $file->load());
			self::assertSame($committed, file_get_contents($this->directory . '/npcs.json'));
		}finally{ $file->close(); unlink($foreign); }
	}

	public function testAbandonedInstanceLeavesALoadableFile() : void{
		$file = new DefinitionFile($this->directory, 'Owner');
		$file->save(['a' => $this->villager('A')]);
		$file->save(['a' => $this->villager('A'), 'b' => $this->villager('B')]);
		// no close: everything that returned from save() must already be on disk
		$reader = file_get_contents($this->directory . '/npcs.json');
		$file->close();
		self::assertIsString($reader);
		$data = json_decode($reader, true, 32, JSON_THROW_ON_ERROR);
		self::assertIsArray($data);
		self::assertSame(2, $data['version']);
		self::assertIsArray($data['npcs']);
		self::assertSame(['a', 'b'], array_keys($data['npcs']));
	}

	public function testWritesCountsOnlyRealWrites() : void{
		$file = new DefinitionFile($this->directory, 'Owner');
		try{
			self::assertSame(0, $file->writes());
			$file->save(['a' => $this->villager('A')]);
			$file->save(['a' => $this->villager('A')]);
			self::assertSame(1, $file->writes());
			$file->save([]);
			self::assertSame(2, $file->writes());
		}finally{ $file->close(); }
	}

	public function testOwnershipMismatchPreservesOriginalFile() : void{
		$file = new DefinitionFile($this->directory, 'First'); $file->save([]); $file->close();
		$before = file_get_contents($this->directory . '/npcs.json');
		$other = new DefinitionFile($this->directory, 'Second');
		try{ $other->load(); self::fail('Wrong owner accepted'); }
		catch(UnexpectedValueException){ self::assertSame($before, file_get_contents($this->directory . '/npcs.json')); }
		finally{ $other->close(); }
	}

	public function testTwoManagersCannotWriteSameFile() : void{
		$file = new DefinitionFile($this->directory, 'Owner');
		try{
			$this->expectException(RuntimeException::class);
			new DefinitionFile($this->directory, 'Owner');
		}finally{ $file->close(); }
	}

	public function testUnknownSchemaFailsWithoutRewrite() : void{
		file_put_contents($this->directory . '/npcs.json', '{"version":999,"owner":"Owner","npcs":{}}');
		$file = new DefinitionFile($this->directory, 'Owner');
		try{ $this->expectException(UnexpectedValueException::class); $file->load(); }
		finally{ $file->close(); }
	}

	public function testOversizedFileRejectedBeforeJsonParsing() : void{
		file_put_contents($this->directory . '/npcs.json', str_repeat(' ', 33554433));
		$file = new DefinitionFile($this->directory, 'Owner');
		try{ $this->expectException(UnexpectedValueException::class); $file->load(); }
		finally{ $file->close(); }
	}

	public function testMoreThanAThousandRecordsAreRejectedOnLoad() : void{
		$records = [];
		for($i = 0; $i < 1001; ++$i){ $records['npc' . $i] = []; }
		file_put_contents($this->directory . '/npcs.json', json_encode(['version' => 1, 'owner' => 'Owner', 'npcs' => $records], JSON_THROW_ON_ERROR));
		$file = new DefinitionFile($this->directory, 'Owner');
		try{ $this->expectException(UnexpectedValueException::class); $file->load(); }
		finally{ $file->close(); }
	}

	public function testBadRecordAndBadIdAreRejected() : void{
		foreach([['1starts-with-digit' => []], ['fine' => ['type' => 5]]] as $records){
			file_put_contents($this->directory . '/npcs.json', json_encode(['version' => 1, 'owner' => 'Owner', 'npcs' => $records], JSON_THROW_ON_ERROR));
			$file = new DefinitionFile($this->directory, 'Owner');
			try{
				try{ $file->load(); self::fail('Invalid record accepted'); }
				catch(UnexpectedValueException){ self::addToAssertionCount(1); }
			}finally{ $file->close(); }
		}
	}

	/** @return array{type: string, world: string, position: array{float, float, float, float, float}, name: string, score: string, scale: float, flags: array{bool, bool, bool, bool, bool, bool}, cancelDamage: bool, profession: int, sneaking: bool, skin: list<string>, items: list<string>} */
	private function villager(string $name) : array{
		return [
			'type' => 'villager', 'world' => 'world', 'position' => [1.5, 64.0, -2.5, 90.0, 0.0],
			'name' => $name, 'score' => '', 'scale' => 1.0,
			'flags' => [true, false, true, false, true, false], 'cancelDamage' => true,
			'profession' => 2, 'sneaking' => false, 'skin' => [], 'items' => []
		];
	}

	public function testRecordsSurviveARoundTrip() : void{
		$file = new DefinitionFile($this->directory, 'Owner');
		try{
			$records = ['a' => $this->villager('A/slash'), 'b' => $this->villager('B')];
			$file->save($records);
			self::assertSame($records, $file->load());
		}finally{ $file->close(); }
	}

	public function testVersionOneFilesStillLoad() : void{
		file_put_contents($this->directory . '/npcs.json', json_encode(['version' => 1, 'owner' => 'Owner', 'npcs' => ['a' => $this->villager('A')]], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
		$file = new DefinitionFile($this->directory, 'Owner');
		try{ self::assertSame(['a' => $this->villager('A')], $file->load()); }
		finally{ $file->close(); }
	}

	public function testIdenticalSaveDoesNotTouchTheDisk() : void{
		$path = $this->directory . '/npcs.json';
		$file = new DefinitionFile($this->directory, 'Owner');
		try{
			$file->save(['a' => $this->villager('A')]);
			touch($path, 946684800);
			$file->save(['a' => $this->villager('A')]);
			clearstatcache(true, $path);
			self::assertSame(946684800, filemtime($path));
			$file->save(['a' => $this->villager('Changed')]);
			clearstatcache(true, $path);
			self::assertNotSame(946684800, filemtime($path));
			unlink($path);
			$file->save(['a' => $this->villager('Changed')]);
			self::assertFileExists($path);
		}finally{ $file->close(); }
	}

	public function testSkinReferenceMustExist() : void{
		$state = $this->villager('A');
		$state['skin'] = 'missing';
		file_put_contents($this->directory . '/npcs.json', json_encode(['version' => 2, 'owner' => 'Owner', 'skins' => (object) [], 'npcs' => ['a' => $state]], JSON_THROW_ON_ERROR));
		$file = new DefinitionFile($this->directory, 'Owner');
		try{ $this->expectException(UnexpectedValueException::class); $file->load(); }
		finally{ $file->close(); }
	}

	public function testTooManyRecordsAreRefusedOnSave() : void{
		$file = new DefinitionFile($this->directory, 'Owner');
		try{
			$this->expectException(RuntimeException::class);
			$file->save(array_fill_keys(array_map(static fn(int $i) : string => 'n' . $i, range(0, 1000)), $this->villager('A')));
		}finally{ $file->close(); }
	}

	public function testSymlinkedStorageIsRefused() : void{
		$target = $this->directory . '/target.json';
		file_put_contents($target, '{}');
		if(!@symlink($target, $this->directory . '/npcs.json')){
			unlink($target);
			self::markTestSkipped('symlinks unavailable');
		}
		try{
			$this->expectException(RuntimeException::class);
			new DefinitionFile($this->directory, 'Owner');
		}finally{ unlink($this->directory . '/npcs.json'); unlink($target); }
	}
}
