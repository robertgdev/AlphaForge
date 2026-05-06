<?php

use App\AlphaForge\Data\Exception\StorageException;
use App\AlphaForge\Data\Service\BinaryStorage;

describe('BinaryStorage', function () {
    beforeEach(function () {
        $this->storage = new BinaryStorage;
        $this->tempDir = sys_get_temp_dir().'/alphaforge_test_'.uniqid();
        mkdir($this->tempDir, 0775, true);
    });

    afterEach(function () {
        $it = new RecursiveDirectoryIterator($this->tempDir, RecursiveDirectoryIterator::SKIP_DOTS);
        $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            if ($file->isDir()) {
                rmdir($file->getRealPath());
            } else {
                unlink($file->getRealPath());
            }
        }
        if (is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }
    });

    describe('getTempFilePath', function () {
        it('appends .tmp to path', function () {
            expect($this->storage->getTempFilePath('/data/test.stchx'))->toBe('/data/test.stchx.tmp');
        });
    });

    describe('getMergedTempFilePath', function () {
        it('appends .merged.tmp to path', function () {
            expect($this->storage->getMergedTempFilePath('/data/test.stchx'))->toBe('/data/test.stchx.merged.tmp');
        });
    });

    describe('createFile', function () {
        it('creates a new binary file with header', function () {
            $path = $this->tempDir.'/test.stchx';
            $this->storage->createFile($path, 'BTC/USDT', '1h');

            expect(file_exists($path))->toBeTrue()
                ->and(filesize($path))->toBe(64);
        });

        it('writes correct magic number in header', function () {
            $path = $this->tempDir.'/test.stchx';
            $this->storage->createFile($path, 'BTC/USDT', '1h');

            $header = $this->storage->readHeader($path);

            expect($header['magic'])->toBe('STCHXBF1');
        });

        it('writes symbol and timeframe in header', function () {
            $path = $this->tempDir.'/test.stchx';
            $this->storage->createFile($path, 'ETH/USDT', '4h');

            $header = $this->storage->readHeader($path);

            expect($header['symbol'])->toBe('ETH/USDT')
                ->and($header['timeframe'])->toBe('4h');
        });

        it('creates parent directories if needed', function () {
            $path = $this->tempDir.'/sub/dir/test.stchx';
            $this->storage->createFile($path, 'BTC/USDT', '1h');

            expect(file_exists($path))->toBeTrue();
        });

        it('initializes record count to zero', function () {
            $path = $this->tempDir.'/test.stchx';
            $this->storage->createFile($path, 'BTC/USDT', '1h');

            $header = $this->storage->readHeader($path);

            expect($header['numRecords'])->toBe(0);
        });
    });

    describe('appendRecords', function () {
        it('appends records and returns count', function () {
            $path = $this->tempDir.'/test.stchx';
            $this->storage->createFile($path, 'BTC/USDT', '1h');

            $records = [];
            for ($i = 0; $i < 3; $i++) {
                $records[] = ['timestamp' => 1704067200 + $i * 3600, 'open' => 50000.0 + $i, 'high' => 50100.0 + $i, 'low' => 49900.0 + $i, 'close' => 50050.0 + $i, 'volume' => 100.0 + $i];
            }

            $count = $this->storage->appendRecords($path, $records);

            expect($count)->toBe(3);
        });

        it('updates header record count after append', function () {
            $path = $this->tempDir.'/test.stchx';
            $this->storage->createFile($path, 'BTC/USDT', '1h');
            $this->storage->appendRecords($path, [
                ['timestamp' => 1, 'open' => 1, 'high' => 1, 'low' => 1, 'close' => 1, 'volume' => 1],
                ['timestamp' => 2, 'open' => 2, 'high' => 2, 'low' => 2, 'close' => 2, 'volume' => 2],
                ['timestamp' => 3, 'open' => 3, 'high' => 3, 'low' => 3, 'close' => 3, 'volume' => 3],
            ]);

            $header = $this->storage->readHeader($path);

            expect($header['numRecords'])->toBe(3);
        });

        it('accumulates header record count across multiple appends', function () {
            $path = $this->tempDir.'/test.stchx';
            $this->storage->createFile($path, 'BTC/USDT', '1h');
            $this->storage->appendRecords($path, [
                ['timestamp' => 100, 'open' => 1, 'high' => 1, 'low' => 1, 'close' => 1, 'volume' => 1],
                ['timestamp' => 200, 'open' => 2, 'high' => 2, 'low' => 2, 'close' => 2, 'volume' => 2],
            ]);
            $this->storage->appendRecords($path, [
                ['timestamp' => 300, 'open' => 3, 'high' => 3, 'low' => 3, 'close' => 3, 'volume' => 3],
            ]);

            $header = $this->storage->readHeader($path);
            expect($header['numRecords'])->toBe(3);
        });

        it('allows reading all records after multiple appends', function () {
            $path = $this->tempDir.'/test.stchx';
            $this->storage->createFile($path, 'BTC/USDT', '1h');
            $this->storage->appendRecords($path, [
                ['timestamp' => 100, 'open' => 1, 'high' => 1, 'low' => 1, 'close' => 1, 'volume' => 1],
                ['timestamp' => 200, 'open' => 2, 'high' => 2, 'low' => 2, 'close' => 2, 'volume' => 2],
            ]);
            $this->storage->appendRecords($path, [
                ['timestamp' => 300, 'open' => 3, 'high' => 3, 'low' => 3, 'close' => 3, 'volume' => 3],
            ]);

            $records = iterator_to_array($this->storage->readRecordsSequentially($path));
            expect($records)->toHaveCount(3)
                ->and($records[0]['timestamp'])->toBe(100)
                ->and($records[2]['timestamp'])->toBe(300);
        });

        it('throws StorageException for non-existent file', function () {
            expect(fn () => $this->storage->appendRecords('/nonexistent/path.stchx', [
                ['timestamp' => 1, 'open' => 1, 'high' => 1, 'low' => 1, 'close' => 1, 'volume' => 1],
            ]))->toThrow(StorageException::class);
        });
    });

    describe('readHeader', function () {
        it('reads header from valid file', function () {
            $path = $this->tempDir.'/test.stchx';
            $this->storage->createFile($path, 'BTC/USDT', '1h', BinaryStorage::DATA_TYPE_OHLCV);

            $header = $this->storage->readHeader($path);

            expect($header['version'])->toBe(2)
                ->and($header['headerLength'])->toBe(64)
                ->and($header['recordLength'])->toBe(48)
                ->and($header['dataType'])->toBe(1);
        });

        it('throws for non-existent file', function () {
            expect(fn () => $this->storage->readHeader('/nonexistent.stchx'))
                ->toThrow(StorageException::class);
        });

        it('throws for file smaller than header', function () {
            $path = $this->tempDir.'/tiny.stchx';
            file_put_contents($path, str_repeat('x', 10));

            expect(fn () => $this->storage->readHeader($path))
                ->toThrow(StorageException::class);
        });

        it('throws for invalid magic number', function () {
            $path = $this->tempDir.'/bad_magic.stchx';
            file_put_contents($path, str_repeat("\0", 64));

            expect(fn () => $this->storage->readHeader($path))
                ->toThrow(StorageException::class);
        });
    });

    describe('readRecordByIndex', function () {
        it('reads a record by index', function () {
            $path = $this->tempDir.'/test.stchx';
            $this->storage->createFile($path, 'BTC/USDT', '1h');
            $this->storage->appendRecords($path, [
                ['timestamp' => 100, 'open' => 50000.0, 'high' => 50100.0, 'low' => 49900.0, 'close' => 50050.0, 'volume' => 100.0],
                ['timestamp' => 200, 'open' => 50001.0, 'high' => 50101.0, 'low' => 49901.0, 'close' => 50051.0, 'volume' => 101.0],
                ['timestamp' => 300, 'open' => 50002.0, 'high' => 50102.0, 'low' => 49902.0, 'close' => 50052.0, 'volume' => 102.0],
            ]);

            $record = $this->storage->readRecordByIndex($path, 1);

            expect($record)->not->toBeNull()
                ->and($record['timestamp'])->toBe(200)
                ->and($record['open'])->toBe(50001.0);
        });

        it('returns null for out-of-range index', function () {
            $path = $this->tempDir.'/test.stchx';
            $this->storage->createFile($path, 'BTC/USDT', '1h');
            $this->storage->appendRecords($path, [
                ['timestamp' => 1, 'open' => 1, 'high' => 1, 'low' => 1, 'close' => 1, 'volume' => 1],
            ]);

            expect($this->storage->readRecordByIndex($path, 99))->toBeNull();
        });

        it('returns null for negative index', function () {
            $path = $this->tempDir.'/test.stchx';
            $this->storage->createFile($path, 'BTC/USDT', '1h');
            $this->storage->appendRecords($path, [
                ['timestamp' => 1, 'open' => 1, 'high' => 1, 'low' => 1, 'close' => 1, 'volume' => 1],
            ]);

            expect($this->storage->readRecordByIndex($path, -1))->toBeNull();
        });
    });

    describe('readRecordsSequentially', function () {
        it('yields all records in order', function () {
            $path = $this->tempDir.'/test.stchx';
            $this->storage->createFile($path, 'BTC/USDT', '1h');
            $this->storage->appendRecords($path, [
                ['timestamp' => 100, 'open' => 1, 'high' => 1, 'low' => 1, 'close' => 1, 'volume' => 1],
                ['timestamp' => 200, 'open' => 2, 'high' => 2, 'low' => 2, 'close' => 2, 'volume' => 2],
                ['timestamp' => 300, 'open' => 3, 'high' => 3, 'low' => 3, 'close' => 3, 'volume' => 3],
            ]);

            $records = iterator_to_array($this->storage->readRecordsSequentially($path));

            expect($records)->toHaveCount(3)
                ->and($records[0]['timestamp'])->toBe(100)
                ->and($records[2]['timestamp'])->toBe(300);
        });

        it('yields nothing for empty file', function () {
            $path = $this->tempDir.'/test.stchx';
            $this->storage->createFile($path, 'BTC/USDT', '1h');

            $records = iterator_to_array($this->storage->readRecordsSequentially($path));

            expect($records)->toBeEmpty();
        });

        it('yields nothing for non-existent file', function () {
            $records = iterator_to_array($this->storage->readRecordsSequentially('/nonexistent.stchx'));

            expect($records)->toBeEmpty();
        });
    });

    describe('atomicRename', function () {
        it('renames source to destination', function () {
            $src = $this->tempDir.'/source.stchx';
            $dst = $this->tempDir.'/dest.stchx';
            file_put_contents($src, 'test data');

            $this->storage->atomicRename($src, $dst);

            expect(file_exists($dst))->toBeTrue()
                ->and(file_exists($src))->toBeFalse();
        });

        it('throws for non-existent source', function () {
            expect(fn () => $this->storage->atomicRename('/nonexistent', $this->tempDir.'/dest.stchx'))
                ->toThrow(StorageException::class);
        });

        it('overwrites existing destination', function () {
            $src = $this->tempDir.'/source.stchx';
            $dst = $this->tempDir.'/dest.stchx';
            file_put_contents($src, 'new data');
            file_put_contents($dst, 'old data');

            $this->storage->atomicRename($src, $dst);

            expect(file_get_contents($dst))->toBe('new data');
        });
    });

    describe('overwriteLastRecord', function () {
        it('overwrites the last record', function () {
            $path = $this->tempDir.'/test.stchx';
            $this->storage->createFile($path, 'BTC/USDT', '1h');
            $this->storage->appendRecords($path, [
                ['timestamp' => 100, 'open' => 1, 'high' => 1, 'low' => 1, 'close' => 1, 'volume' => 1],
                ['timestamp' => 200, 'open' => 2, 'high' => 2, 'low' => 2, 'close' => 2, 'volume' => 2],
                ['timestamp' => 300, 'open' => 3, 'high' => 3, 'low' => 3, 'close' => 3, 'volume' => 3],
            ]);

            $this->storage->overwriteLastRecord($path, [
                'timestamp' => 300, 'open' => 99999.0, 'high' => 99999.0, 'low' => 99999.0, 'close' => 99999.0, 'volume' => 999.0,
            ]);

            $lastRecord = $this->storage->readRecordByIndex($path, 2);
            expect($lastRecord['open'])->toBe(99999.0);
        });

        it('throws when file has no records', function () {
            $path = $this->tempDir.'/test.stchx';
            $this->storage->createFile($path, 'BTC/USDT', '1h');

            expect(fn () => $this->storage->overwriteLastRecord($path, [
                'timestamp' => 1, 'open' => 1, 'high' => 1, 'low' => 1, 'close' => 1, 'volume' => 1,
            ]))->toThrow(StorageException::class);
        });
    });

    describe('updateRecordCount', function () {
        it('updates the record count in header', function () {
            $path = $this->tempDir.'/test.stchx';
            $this->storage->createFile($path, 'BTC/USDT', '1h');
            $this->storage->appendRecords($path, [
                ['timestamp' => 1, 'open' => 1, 'high' => 1, 'low' => 1, 'close' => 1, 'volume' => 1],
            ]);

            $this->storage->updateRecordCount($path, 42);

            $header = $this->storage->readHeader($path);
            expect($header['numRecords'])->toBe(42);
        });
    });

    describe('readRecordsByTimestampRange', function () {
        it('returns records within the range', function () {
            $path = $this->tempDir.'/test.stchx';
            $this->storage->createFile($path, 'BTC/USDT', '1h');
            $this->storage->appendRecords($path, [
                ['timestamp' => 100, 'open' => 1, 'high' => 1, 'low' => 1, 'close' => 1, 'volume' => 1],
                ['timestamp' => 200, 'open' => 2, 'high' => 2, 'low' => 2, 'close' => 2, 'volume' => 2],
                ['timestamp' => 300, 'open' => 3, 'high' => 3, 'low' => 3, 'close' => 3, 'volume' => 3],
                ['timestamp' => 400, 'open' => 4, 'high' => 4, 'low' => 4, 'close' => 4, 'volume' => 4],
                ['timestamp' => 500, 'open' => 5, 'high' => 5, 'low' => 5, 'close' => 5, 'volume' => 5],
            ]);

            $records = iterator_to_array($this->storage->readRecordsByTimestampRange($path, 200, 400));

            expect($records)->toHaveCount(3);
        });

        it('returns empty for range outside data', function () {
            $path = $this->tempDir.'/test.stchx';
            $this->storage->createFile($path, 'BTC/USDT', '1h');
            $this->storage->appendRecords($path, [
                ['timestamp' => 100, 'open' => 1, 'high' => 1, 'low' => 1, 'close' => 1, 'volume' => 1],
            ]);

            $records = iterator_to_array($this->storage->readRecordsByTimestampRange($path, 9999999999, 99999999999));

            expect($records)->toBeEmpty();
        });
    });

    describe('mergeAndWrite', function () {
        it('merges two files with sorted timestamps', function () {
            $origPath = $this->tempDir.'/orig.stchx';
            $newPath = $this->tempDir.'/new.stchx';
            $outPath = $this->tempDir.'/merged.stchx';

            $this->storage->createFile($origPath, 'BTC/USDT', '1h');
            $this->storage->appendRecords($origPath, [
                ['timestamp' => 100, 'open' => 1, 'high' => 1, 'low' => 1, 'close' => 1, 'volume' => 1],
                ['timestamp' => 300, 'open' => 3, 'high' => 3, 'low' => 3, 'close' => 3, 'volume' => 3],
            ]);

            $this->storage->createFile($newPath, 'BTC/USDT', '1h');
            $this->storage->appendRecords($newPath, [
                ['timestamp' => 200, 'open' => 2, 'high' => 2, 'low' => 2, 'close' => 2, 'volume' => 2],
                ['timestamp' => 400, 'open' => 4, 'high' => 4, 'low' => 4, 'close' => 4, 'volume' => 4],
            ]);

            $count = $this->storage->mergeAndWrite($origPath, $newPath, $outPath);

            expect($count)->toBe(4);

            $records = iterator_to_array($this->storage->readRecordsSequentially($outPath));
            expect($records[0]['timestamp'])->toBe(100)
                ->and($records[1]['timestamp'])->toBe(200)
                ->and($records[2]['timestamp'])->toBe(300)
                ->and($records[3]['timestamp'])->toBe(400);
        });

        it('deduplicates records with same timestamp', function () {
            $origPath = $this->tempDir.'/orig.stchx';
            $newPath = $this->tempDir.'/new.stchx';
            $outPath = $this->tempDir.'/merged.stchx';

            $this->storage->createFile($origPath, 'BTC/USDT', '1h');
            $this->storage->appendRecords($origPath, [
                ['timestamp' => 100, 'open' => 1, 'high' => 1, 'low' => 1, 'close' => 1, 'volume' => 1],
            ]);

            $this->storage->createFile($newPath, 'BTC/USDT', '1h');
            $this->storage->appendRecords($newPath, [
                ['timestamp' => 100, 'open' => 99, 'high' => 99, 'low' => 99, 'close' => 99, 'volume' => 99],
                ['timestamp' => 200, 'open' => 2, 'high' => 2, 'low' => 2, 'close' => 2, 'volume' => 2],
            ]);

            $count = $this->storage->mergeAndWrite($origPath, $newPath, $outPath);

            expect($count)->toBe(2);

            $records = iterator_to_array($this->storage->readRecordsSequentially($outPath));
            expect($records[0]['open'])->toBe(99.0)
                ->and($records[1]['timestamp'])->toBe(200);
        });

        it('creates empty file when neither source exists', function () {
            $outPath = $this->tempDir.'/empty_merged.stchx';
            $count = $this->storage->mergeAndWrite('/nonexistent1', '/nonexistent2', $outPath);

            expect($count)->toBe(0)
                ->and(file_exists($outPath))->toBeTrue();
        });
    });

    describe('streamAndCommitRecords', function () {
        it('streams records to an existing file', function () {
            $path = $this->tempDir.'/test.stchx';
            $this->storage->createFile($path, 'BTC/USDT', '1h');

            $records = [];
            for ($i = 0; $i < 5; $i++) {
                $records[] = ['timestamp' => 100 + $i, 'open' => 1, 'high' => 1, 'low' => 1, 'close' => 1, 'volume' => 1];
            }

            $count = $this->storage->streamAndCommitRecords($path, $records);

            expect($count)->toBe(5);

            $header = $this->storage->readHeader($path);
            expect($header['numRecords'])->toBe(5);
        });

        it('accumulates record count across multiple stream calls', function () {
            $path = $this->tempDir.'/test.stchx';
            $this->storage->createFile($path, 'BTC/USDT', '1h');

            $batch1 = [
                ['timestamp' => 100, 'open' => 1, 'high' => 1, 'low' => 1, 'close' => 1, 'volume' => 1],
                ['timestamp' => 200, 'open' => 2, 'high' => 2, 'low' => 2, 'close' => 2, 'volume' => 2],
            ];
            $batch2 = [
                ['timestamp' => 300, 'open' => 3, 'high' => 3, 'low' => 3, 'close' => 3, 'volume' => 3],
            ];

            $this->storage->streamAndCommitRecords($path, $batch1);
            $this->storage->streamAndCommitRecords($path, $batch2);

            $header = $this->storage->readHeader($path);
            expect($header['numRecords'])->toBe(3);

            $records = iterator_to_array($this->storage->readRecordsSequentially($path));
            expect($records)->toHaveCount(3);
        });

        it('accumulates count when streaming onto file with existing appendRecords data', function () {
            $path = $this->tempDir.'/test.stchx';
            $this->storage->createFile($path, 'BTC/USDT', '1h');
            $this->storage->appendRecords($path, [
                ['timestamp' => 100, 'open' => 1, 'high' => 1, 'low' => 1, 'close' => 1, 'volume' => 1],
                ['timestamp' => 200, 'open' => 2, 'high' => 2, 'low' => 2, 'close' => 2, 'volume' => 2],
            ]);

            $this->storage->streamAndCommitRecords($path, [
                ['timestamp' => 300, 'open' => 3, 'high' => 3, 'low' => 3, 'close' => 3, 'volume' => 3],
            ]);

            $header = $this->storage->readHeader($path);
            expect($header['numRecords'])->toBe(3);
        });
    });
});
