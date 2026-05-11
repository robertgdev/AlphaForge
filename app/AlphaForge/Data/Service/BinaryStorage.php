<?php

namespace App\AlphaForge\Data\Service;

use App\AlphaForge\Data\Exception\StorageException;

final class BinaryStorage implements BinaryStorageInterface
{
    private const MAGIC_NUMBER = 'STCHXBF1';

    private const FORMAT_VERSION = 2;

    public const HEADER_LENGTH_V1 = 64;

    private const RECORD_LENGTH_V1 = 48;

    private const TIMESTAMP_FORMAT_V1 = 1;

    public const DATA_TYPE_OHLCV = 1;

    public const DATA_TYPE_HEIKEN_ASHI = 2;

    public const DATA_TYPE_RENKO = 3;

    public const DATA_TYPE_ATR_RENKO = 4;

    private const NUM_RECORDS_OFFSET = 16;

    private const HEADER_PACK_FORMAT = 'a8n3C2Ja16a4Ex12';

    private const HEADER_UNPACK_FORMAT = 'a8magic/nversion/nheaderLength/nrecordLength/CtsFormat/CdataType/JnumRecords/a16symbol/a4timeframe/EbrickSize/x12reserved';

    private const RECORD_PACK_FORMAT = 'JE5';

    private const RECORD_UNPACK_FORMAT = 'Jtimestamp/Eopen/Ehigh/Elow/Eclose/Evolume';

    private const UINT64_PACK_FORMAT = 'J';

    public function getTempFilePath(string $finalPath): string
    {
        return $finalPath.'.tmp';
    }

    public function getMergedTempFilePath(string $finalPath): string
    {
        return $finalPath.'.merged.tmp';
    }

    public function atomicRename(string $sourcePath, string $destinationPath): void
    {
        $this->ensureDirectoryExists(dirname($destinationPath));

        if (! file_exists($sourcePath)) {
            throw new StorageException("Source file '{$sourcePath}' does not exist for renaming.");
        }

        if (file_exists($destinationPath)) {
            if (! \unlink($destinationPath)) {
                throw new StorageException("Could not remove existing destination '{$destinationPath}' before renaming.");
            }
        }

        if (! \rename($sourcePath, $destinationPath)) {
            if (\copy($sourcePath, $destinationPath)) {
                \unlink($sourcePath);
            } else {
                throw new StorageException("Failed to atomically rename '{$sourcePath}' to '{$destinationPath}'.");
            }
        }
    }

    public function createFile(string $filePath, string $symbol, string $timeframe, int $dataType = self::DATA_TYPE_OHLCV, float $brickSize = 0.0): void
    {
        $this->ensureDirectoryExists(dirname($filePath));

        $handle = @fopen($filePath, 'wb');
        if ($handle === false) {
            throw new StorageException("Could not open file '{$filePath}' for writing.");
        }

        if (! \flock($handle, LOCK_EX)) {
            fclose($handle);
            throw new StorageException("Could not acquire exclusive lock on '{$filePath}'.");
        }

        try {
            $headerData = [
                self::MAGIC_NUMBER,
                self::FORMAT_VERSION,
                self::HEADER_LENGTH_V1,
                self::RECORD_LENGTH_V1,
                self::TIMESTAMP_FORMAT_V1,
                $dataType,
                0,
                $symbol,
                $timeframe,
                $brickSize,
            ];

            $packedHeader = pack(self::HEADER_PACK_FORMAT, ...$headerData);

            if (fwrite($handle, $packedHeader) !== self::HEADER_LENGTH_V1) {
                throw new StorageException("Failed to write complete header to '{$filePath}'.");
            }
            fflush($handle);
        } finally {
            \flock($handle, LOCK_UN);
            \fclose($handle);
        }
    }

    public function appendRecords(string $filePath, iterable $records): int
    {
        $handle = @\fopen($filePath, 'ab');
        if ($handle === false) {
            throw new StorageException("Could not open file '{$filePath}' for appending.");
        }

        if (! \flock($handle, LOCK_EX)) {
            \fclose($handle);
            throw new StorageException("Could not acquire exclusive lock on '{$filePath}'.");
        }

        $writtenCount = 0;
        try {
            foreach ($records as $record) {
                if (! is_array($record)) {
                    throw new StorageException('Invalid record format: expected array.');
                }
                $packedRecord = pack(
                    self::RECORD_PACK_FORMAT,
                    $record['timestamp'],
                    $record['open'],
                    $record['high'],
                    $record['low'],
                    $record['close'],
                    $record['volume']
                );

                if (\fwrite($handle, $packedRecord) !== self::RECORD_LENGTH_V1) {
                    throw new StorageException("Failed to write complete record to '{$filePath}'.");
                }

                $writtenCount++;
            }
        } finally {
            \fflush($handle);
            \flock($handle, LOCK_UN);
            \fclose($handle);
        }

        $this->updateRecordCountAccumulated($filePath, $writtenCount);

        return $writtenCount;
    }

    private function updateRecordCountAccumulated(string $filePath, int $additionalCount): void
    {
        $currentHeader = $this->readHeader($filePath);
        $newCount = $currentHeader['numRecords'] + $additionalCount;

        $handle = @fopen($filePath, 'r+b');
        if ($handle === false) {
            throw new StorageException("Could not open file '{$filePath}' for updating record count.");
        }

        if (! \flock($handle, LOCK_EX)) {
            fclose($handle);
            throw new StorageException("Could not acquire exclusive lock on '{$filePath}'.");
        }

        try {
            if (fseek($handle, self::NUM_RECORDS_OFFSET) !== 0) {
                throw new StorageException("Could not seek to record count position in '{$filePath}'.");
            }

            $packedCount = pack(self::UINT64_PACK_FORMAT, $newCount);

            if (fwrite($handle, $packedCount) !== 8) {
                throw new StorageException("Failed to write record count to '{$filePath}'.");
            }
            fflush($handle);
        } finally {
            \flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function updateRecordCount(string $filePath, int $recordCount): void
    {
        $handle = @fopen($filePath, 'r+b');
        if ($handle === false) {
            throw new StorageException("Could not open file '{$filePath}' for updating record count.");
        }

        if (! \flock($handle, LOCK_EX)) {
            fclose($handle);
            throw new StorageException("Could not acquire exclusive lock on '{$filePath}'.");
        }

        try {
            if (fseek($handle, self::NUM_RECORDS_OFFSET) !== 0) {
                throw new StorageException("Could not seek to record count position in '{$filePath}'.");
            }

            $packedCount = pack(self::UINT64_PACK_FORMAT, $recordCount);

            if (fwrite($handle, $packedCount) !== 8) {
                throw new StorageException("Failed to write record count to '{$filePath}'.");
            }
            fflush($handle);
        } finally {
            \flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function overwriteLastRecord(string $filePath, array $record): void
    {
        $header = $this->readHeader($filePath);

        if ($header['numRecords'] === 0) {
            throw new StorageException("Cannot overwrite last record: file '{$filePath}' has no records.");
        }

        $lastIndex = $header['numRecords'] - 1;
        $offset = $header['headerLength'] + ($lastIndex * $header['recordLength']);

        $handle = @fopen($filePath, 'r+b');
        if ($handle === false) {
            throw new StorageException("Could not open file '{$filePath}' for overwriting last record.");
        }

        if (! \flock($handle, LOCK_EX)) {
            fclose($handle);
            throw new StorageException("Could not acquire exclusive lock on '{$filePath}'.");
        }

        try {
            if (fseek($handle, $offset) !== 0) {
                throw new StorageException("Could not seek to last record in '{$filePath}'.");
            }

            $packedRecord = pack(
                self::RECORD_PACK_FORMAT,
                $record['timestamp'],
                $record['open'],
                $record['high'],
                $record['low'],
                $record['close'],
                $record['volume']
            );

            if (fwrite($handle, $packedRecord) !== self::RECORD_LENGTH_V1) {
                throw new StorageException("Failed to overwrite last record in '{$filePath}'.");
            }
            fflush($handle);
        } finally {
            \flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function readHeader(string $filePath): array
    {
        if (! file_exists($filePath)) {
            throw new StorageException("File not found for reading header: '{$filePath}'.");
        }
        if (filesize($filePath) < self::HEADER_LENGTH_V1) {
            throw new StorageException("File '{$filePath}' is smaller than the minimum header size.");
        }

        $handle = @fopen($filePath, 'rb');
        if ($handle === false) {
            throw new StorageException("Could not open file '{$filePath}' for reading header.");
        }

        try {
            $headerBytes = @fread($handle, self::HEADER_LENGTH_V1);
            if ($headerBytes === false || strlen($headerBytes) !== self::HEADER_LENGTH_V1) {
                throw new StorageException("Could not read complete header from '{$filePath}'.");
            }

            $header = unpack(self::HEADER_UNPACK_FORMAT, $headerBytes);
            if ($header === false || $header === null) {
                throw new StorageException("Could not unpack header from '{$filePath}'.");
            }

            $header['magic'] = rtrim($header['magic'], "\0");
            $header['symbol'] = rtrim($header['symbol'], "\0");
            $header['timeframe'] = rtrim($header['timeframe'], "\0");

            if ($header['magic'] !== self::MAGIC_NUMBER) {
                throw new StorageException("Invalid magic number. Not an STCHXBF1 file: '{$filePath}'.");
            }
            if ($header['version'] !== self::FORMAT_VERSION) {
                throw new StorageException("Unsupported format version '{$header['version']}' in '{$filePath}'.");
            }
            if ($header['headerLength'] !== self::HEADER_LENGTH_V1) {
                throw new StorageException("Invalid header length '{$header['headerLength']}' in '{$filePath}'.");
            }
            if ($header['recordLength'] !== self::RECORD_LENGTH_V1) {
                throw new StorageException("Invalid record length '{$header['recordLength']}' in '{$filePath}'.");
            }

            return $header;
        } finally {
            fclose($handle);
        }
    }

    public function readRecordByIndex(string $filePath, int $index): ?array
    {
        $header = $this->readHeader($filePath);

        if ($index < 0 || $index >= $header['numRecords']) {
            return null;
        }

        $handle = @fopen($filePath, 'rb');
        if ($handle === false) {
            throw new StorageException("Could not open file '{$filePath}' for reading by index.");
        }

        try {
            $offset = $header['headerLength'] + ($index * $header['recordLength']);

            if (fseek($handle, $offset) !== 0) {
                throw new StorageException("Could not seek to index {$index} in '{$filePath}'.");
            }

            $recordBytes = @fread($handle, $header['recordLength']);
            if ($recordBytes === false || strlen($recordBytes) !== $header['recordLength']) {
                throw new StorageException("Could not read record at index {$index} from '{$filePath}'.");
            }

            $record = unpack(self::RECORD_UNPACK_FORMAT, $recordBytes);
            if ($record === false || $record === null) {
                throw new StorageException("Could not unpack record at index {$index} from '{$filePath}'.");
            }

            return $record;
        } finally {
            fclose($handle);
        }
    }

    public function readRecordsSequentially(string $filePath): \Generator
    {
        if (! file_exists($filePath) || filesize($filePath) <= self::HEADER_LENGTH_V1) {
            yield from [];

            return;
        }

        $header = $this->readHeader($filePath);
        $recordCount = $header['numRecords'];
        $headerLength = $header['headerLength'];
        $recordLength = $header['recordLength'];

        if ($recordCount === 0) {
            yield from [];

            return;
        }

        $handle = @fopen($filePath, 'rb');
        if ($handle === false) {
            throw new StorageException("Could not open file '{$filePath}' for sequential reading.");
        }

        if (! \flock($handle, LOCK_SH)) {
            \fclose($handle);
            throw new StorageException("Could not acquire shared lock on '{$filePath}'.");
        }

        try {
            if (fseek($handle, $headerLength) !== 0) {
                throw new StorageException("Could not seek to data start in '{$filePath}'.");
            }

            for ($i = 0; $i < $recordCount; $i++) {
                $recordBytes = @fread($handle, $recordLength);

                if ($recordBytes === false || strlen($recordBytes) === 0) {
                    break;
                }

                if (strlen($recordBytes) !== $recordLength) {
                    throw new StorageException("Could not read complete record at index {$i} from '{$filePath}'. Expected {$recordLength} bytes, got ".strlen($recordBytes).'.');
                }

                $record = unpack(self::RECORD_UNPACK_FORMAT, $recordBytes);
                if ($record === false || $record === null) {
                    throw new StorageException("Failed to unpack record at index {$i} in '{$filePath}'.");
                }

                yield $record;
            }
        } finally {
            \flock($handle, LOCK_UN);
            \fclose($handle);
        }
    }

    public function readRecordsSequentiallyWithFallback(string $filePath): \Generator
    {
        if (! file_exists($filePath) || filesize($filePath) <= self::HEADER_LENGTH_V1) {
            yield from [];

            return;
        }

        $header = $this->readHeader($filePath);
        $headerRecordCount = $header['numRecords'];
        $headerLength = $header['headerLength'];
        $recordLength = $header['recordLength'];

        $fileSize = filesize($filePath);
        $actualRecordCount = (int) (($fileSize - $headerLength) / $recordLength);

        $recordCount = $headerRecordCount > 0 ? $headerRecordCount : ($actualRecordCount > 0 ? $actualRecordCount : 0);

        if ($recordCount <= 0) {
            yield from [];

            return;
        }

        $handle = @fopen($filePath, 'rb');
        if ($handle === false) {
            throw new StorageException("Could not open file '{$filePath}' for sequential reading.");
        }

        if (! \flock($handle, LOCK_SH)) {
            fclose($handle);
            throw new StorageException("Could not acquire shared lock on '{$filePath}'.");
        }

        try {
            if (fseek($handle, $headerLength) !== 0) {
                throw new StorageException("Could not seek to data start in '{$filePath}'.");
            }

            for ($i = 0; $i < $recordCount; $i++) {
                $recordBytes = @fread($handle, $recordLength);

                if ($recordBytes === false || strlen($recordBytes) === 0) {
                    break;
                }

                if (strlen($recordBytes) !== $recordLength) {
                    throw new StorageException("Could not read complete record at index {$i} from '{$filePath}'. Expected {$recordLength} bytes, got ".strlen($recordBytes).'.');
                }

                $record = unpack(self::RECORD_UNPACK_FORMAT, $recordBytes);
                if ($record === false) {
                    throw new StorageException("Failed to unpack record at index {$i} in '{$filePath}'.");
                }

                yield $record;
            }
        } finally {
            \flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function mergeAndWrite(string $originalPath, string $newDataPath, string $outputPath): int
    {
        $origExists = file_exists($originalPath) && filesize($originalPath) > self::HEADER_LENGTH_V1;
        $newExists = file_exists($newDataPath) && filesize($newDataPath) > self::HEADER_LENGTH_V1;

        if (! $origExists && ! $newExists) {
            $this->createFile($outputPath, 'UNKNOWN', 'N/A');

            return 0;
        }

        $header = $newExists ? $this->readHeader($newDataPath) : $this->readHeader($originalPath);
        if ($origExists && $newExists) {
            $newHeader = $this->readHeader($newDataPath);
            if ($header['symbol'] !== $newHeader['symbol'] || $header['timeframe'] !== $newHeader['timeframe']) {
                throw new StorageException("Cannot merge files: Symbol/Timeframe mismatch ('{$header['symbol']}/{$header['timeframe']}' vs '{$newHeader['symbol']}/{$newHeader['timeframe']}').");
            }
            $header = $newHeader;
        }

        $this->createFile($outputPath, $header['symbol'], $header['timeframe'], $header['dataType'], $header['brickSize']);

        $genOrig = $origExists
            ? $this->readRecordsSequentiallyWithFallback($originalPath)
            : $this->readRecordsSequentially($originalPath);
        $genNew = $newExists
            ? $this->readRecordsSequentiallyWithFallback($newDataPath)
            : $this->readRecordsSequentially($newDataPath);

        $handle = @fopen($outputPath, 'ab');
        if ($handle === false) {
            throw new StorageException("Could not open output file '{$outputPath}' for merging.");
        }

        if (! \flock($handle, LOCK_EX)) {
            fclose($handle);
            throw new StorageException("Could not acquire exclusive lock on output file '{$outputPath}'.");
        }

        $recordCount = 0;
        $handleWrapper = null;

        try {
            $handleWrapper = $handle;

            $recOrig = $genOrig->valid() ? $genOrig->current() : null;
            $recNew = $genNew->valid() ? $genNew->current() : null;
            $lastTimestampWritten = -1;

            while ($recOrig !== null || $recNew !== null) {
                $writeRecord = null;
                $advanceOrig = false;
                $advanceNew = false;

                if ($recOrig !== null && ($recNew === null || $recOrig['timestamp'] < $recNew['timestamp'])) {
                    $writeRecord = $recOrig;
                    $advanceOrig = true;
                } elseif ($recNew !== null && ($recOrig === null || $recNew['timestamp'] < $recOrig['timestamp'])) {
                    $writeRecord = $recNew;
                    $advanceNew = true;
                } elseif ($recOrig !== null && $recNew !== null) {
                    $writeRecord = $recNew;
                    $advanceOrig = true;
                    $advanceNew = true;
                } else {
                    break;
                }

                if ($writeRecord !== null) {
                    if ($writeRecord['timestamp'] > $lastTimestampWritten) {
                        $packedRecord = pack(
                            self::RECORD_PACK_FORMAT,
                            $writeRecord['timestamp'],
                            $writeRecord['open'],
                            $writeRecord['high'],
                            $writeRecord['low'],
                            $writeRecord['close'],
                            $writeRecord['volume']
                        );

                        if (fwrite($handle, $packedRecord) !== self::RECORD_LENGTH_V1) {
                            throw new StorageException("Failed to write merged record to '{$outputPath}'.");
                        }
                        fflush($handle);

                        $lastTimestampWritten = $writeRecord['timestamp'];
                        $recordCount++;
                    }
                }

                if ($advanceOrig) {
                    $genOrig->next();
                    $recOrig = $genOrig->valid() ? $genOrig->current() : null;
                }
                if ($advanceNew) {
                    $genNew->next();
                    $recNew = $genNew->valid() ? $genNew->current() : null;
                }
            }
        } catch (\Throwable $e) {
            throw new StorageException('Error during merge process: '.$e->getMessage(), 0, $e);
        } finally {
            if ($handleWrapper) {
                \flock($handleWrapper, LOCK_UN);
                fclose($handleWrapper);
            }
        }

        $this->updateRecordCount($outputPath, $recordCount);

        return $recordCount;
    }

    public function readRecordsByTimestampRange(string $filePath, int $startTimestamp, int $endTimestamp): \Generator
    {
        $header = $this->readHeader($filePath);
        $recordCount = $header['numRecords'];
        $headerLength = $header['headerLength'];
        $recordLength = $header['recordLength'];

        if ($recordCount === 0) {
            return;
        }

        $handle = @fopen($filePath, 'rb');
        if ($handle === false) {
            throw new StorageException("Could not open file '{$filePath}' for reading range.");
        }

        try {
            $startIndex = $this->findStartIndexByTimestamp($handle, $startTimestamp, $header);

            if ($startIndex === -1 || $startIndex >= $recordCount) {
                return;
            }

            $offset = $headerLength + ($startIndex * $recordLength);
            if (fseek($handle, $offset) !== 0) {
                throw new StorageException("Could not seek to start index {$startIndex} in '{$filePath}'.");
            }

            for ($i = $startIndex; $i < $recordCount; $i++) {
                $recordBytes = @fread($handle, $recordLength);
                if ($recordBytes === false || strlen($recordBytes) !== $recordLength) {
                    break;
                }

                $record = unpack(self::RECORD_UNPACK_FORMAT, $recordBytes);
                if ($record === false) {
                    throw new StorageException("Failed to unpack record at index {$i} in '{$filePath}'.");
                }

                if ($record['timestamp'] > $endTimestamp) {
                    break;
                }

                if ($record['timestamp'] >= $startTimestamp) {
                    yield $record;
                }
            }
        } finally {
            fclose($handle);
        }
    }

    private function findStartIndexByTimestamp($handle, int $targetTimestamp, array $header): int
    {
        $low = 0;
        $high = $header['numRecords'] - 1;
        $startIndex = -1;
        $headerLength = $header['headerLength'];
        $recordLength = $header['recordLength'];

        if ($header['numRecords'] === 0) {
            return -1;
        }

        while ($low <= $high) {
            $mid = (int) ($low + (($high - $low) >> 1));

            $offset = $headerLength + ($mid * $recordLength);
            if (fseek($handle, $offset) !== 0) {
                throw new StorageException("Binary search seek failed at index {$mid}.");
            }

            $recordBytes = @fread($handle, 8);
            if ($recordBytes === false || strlen($recordBytes) < 8) {
                if ($low === $high) {
                    $high = $low - 1;

                    continue;
                }
                throw new StorageException("Binary search read failed at index {$mid}.");
            }

            $timestamp = unpack('J', $recordBytes)[1] ?? -1;

            if ($timestamp >= $targetTimestamp) {
                $startIndex = $mid;
                $high = $mid - 1;
            } else {
                $low = $mid + 1;
            }
        }

        return $startIndex;
    }

    private function ensureDirectoryExists(string $directory): void
    {
        if (! is_dir($directory) && ! \mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new StorageException(sprintf('Directory "%s" was not created', $directory));
        }
    }

    public function streamAndCommitRecords(string $filePath, iterable $records, int $commitInterval = 5000): int
    {
        $existingHeader = $this->readHeader($filePath);
        $existingCount = $existingHeader['numRecords'];

        $handle = @fopen($filePath, 'r+b');
        if ($handle === false) {
            throw new StorageException("Could not open file '{$filePath}' for streaming write.");
        }

        if (! \flock($handle, LOCK_EX)) {
            \fclose($handle);
            throw new StorageException("Could not acquire exclusive lock on '{$filePath}'.");
        }

        $writtenCount = 0;
        try {
            fseek($handle, 0, SEEK_END);

            try {
                foreach ($records as $record) {
                    if (! is_array($record)) {
                        throw new StorageException('Invalid record format: expected array.');
                    }
                    $packedRecord = pack(
                        self::RECORD_PACK_FORMAT,
                        $record['timestamp'],
                        $record['open'],
                        $record['high'],
                        $record['low'],
                        $record['close'],
                        $record['volume']
                    );

                    if (\fwrite($handle, $packedRecord) !== self::RECORD_LENGTH_V1) {
                        throw new StorageException("Failed to write complete record to '{$filePath}'.");
                    }
                    $writtenCount++;

                    if ($writtenCount >= $commitInterval) {
                        $this->updateHeaderCountInPlace($handle, $existingCount + $writtenCount);
                    }
                }
            } catch (\Throwable $e) {
                if ($writtenCount > 0) {
                    $this->updateHeaderCountInPlace($handle, $existingCount + $writtenCount);
                }
                throw $e;
            }

            if ($writtenCount > 0) {
                $this->updateHeaderCountInPlace($handle, $existingCount + $writtenCount);
            }
        } finally {
            \flock($handle, LOCK_UN);
            \fclose($handle);
        }

        return $writtenCount;
    }

    private function updateHeaderCountInPlace($fileHandle, int $recordCount): void
    {
        $currentPosition = ftell($fileHandle);
        if ($currentPosition === false) {
            throw new StorageException('Could not get current file position.');
        }

        if (fseek($fileHandle, self::NUM_RECORDS_OFFSET) !== 0) {
            throw new StorageException('Could not seek to record count position.');
        }

        $packedCount = pack(self::UINT64_PACK_FORMAT, $recordCount);

        if (fwrite($fileHandle, $packedCount) !== 8) {
            throw new StorageException('Failed to write record count.');
        }

        fflush($fileHandle);
        fseek($fileHandle, $currentPosition);
    }
}
