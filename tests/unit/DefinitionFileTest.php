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
		file_put_contents($this->directory . '/npcs.json', str_repeat(' ', 16777217));
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
