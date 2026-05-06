<?php

use App\AlphaForge\Data\Exception\DataFileNotFoundException;
use App\AlphaForge\Data\Exception\DownloadCancelledException;
use App\AlphaForge\Data\Exception\DownloaderException;
use App\AlphaForge\Data\Exception\EmptyHistoryException;
use App\AlphaForge\Data\Exception\ExchangeException;
use App\AlphaForge\Data\Exception\StorageException;

describe('Data Exceptions', function () {
    it('DataFileNotFoundException extends Exception', function () {
        $e = new DataFileNotFoundException('test');
        expect($e)->toBeInstanceOf(\Exception::class)
            ->and($e->getMessage())->toBe('test');
    });

    it('DownloadCancelledException extends RuntimeException', function () {
        $e = new DownloadCancelledException('cancelled');
        expect($e)->toBeInstanceOf(\RuntimeException::class)
            ->and($e->getMessage())->toBe('cancelled');
    });

    it('DownloaderException extends RuntimeException', function () {
        $e = new DownloaderException('download failed');
        expect($e)->toBeInstanceOf(\RuntimeException::class)
            ->and($e->getMessage())->toBe('download failed');
    });

    it('EmptyHistoryException extends RuntimeException', function () {
        $e = new EmptyHistoryException('no data');
        expect($e)->toBeInstanceOf(\RuntimeException::class)
            ->and($e->getMessage())->toBe('no data');
    });

    it('ExchangeException extends RuntimeException', function () {
        $e = new ExchangeException('exchange error');
        expect($e)->toBeInstanceOf(\RuntimeException::class)
            ->and($e->getMessage())->toBe('exchange error');
    });

    it('StorageException extends RuntimeException', function () {
        $e = new StorageException('storage error');
        expect($e)->toBeInstanceOf(\RuntimeException::class)
            ->and($e->getMessage())->toBe('storage error');
    });

    it('exceptions support previous chain', function () {
        $previous = new \RuntimeException('original');
        $e = new StorageException('wrapped', 0, $previous);
        expect($e->getPrevious())->toBe($previous);
    });
});
