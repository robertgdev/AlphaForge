<?php

use App\AlphaForge\Common\Service\FormattingService;
use App\AlphaForge\Data\Service\BinaryStorage;

describe('FormattingService', function () {
    beforeEach(function () {
        $this->service = new FormattingService;
    });

    describe('formatTimeSpan', function () {
        it('formats seconds only', function () {
            expect($this->service->formatTimeSpan(45))->toBe('45 seconds');
        });

        it('formats single second', function () {
            expect($this->service->formatTimeSpan(1))->toBe('1 second');
        });

        it('formats minutes and seconds', function () {
            expect($this->service->formatTimeSpan(125))->toBe('2 minutes, 5 seconds');
        });

        it('formats hours, minutes, and seconds', function () {
            expect($this->service->formatTimeSpan(3725))->toBe('1 hour, 2 minutes, 5 seconds');
        });

        it('formats days, hours, minutes, and seconds', function () {
            expect($this->service->formatTimeSpan(90125))->toBe('1 day, 1 hour, 2 minutes, 5 seconds');
        });

        it('formats multiple days', function () {
            expect($this->service->formatTimeSpan(172800))->toBe('2 days');
        });

        it('formats zero as zero seconds', function () {
            expect($this->service->formatTimeSpan(0))->toBe('0 seconds');
        });

        it('formats only hours', function () {
            expect($this->service->formatTimeSpan(7200))->toBe('2 hours');
        });
    });

    describe('formatNumber', function () {
        it('formats large numbers with 2 decimals', function () {
            expect($this->service->formatNumber(1500000.0))->toBe('1,500,000.00');
        });

        it('formats numbers >= 1 with 4 decimals', function () {
            expect($this->service->formatNumber(42.5))->toBe('42.5000');
        });

        it('formats numbers >= 0.0001 with 6 decimals', function () {
            expect($this->service->formatNumber(0.005))->toBe('0.005000');
        });

        it('formats very small numbers with 8 decimals', function () {
            expect($this->service->formatNumber(0.00001))->toBe('0.00001000');
        });

        it('formats exactly 1 with 4 decimals', function () {
            expect($this->service->formatNumber(1.0))->toBe('1.0000');
        });

        it('formats exactly 0.0001 with 6 decimals', function () {
            expect($this->service->formatNumber(0.0001))->toBe('0.000100');
        });
    });

    describe('formatFileSize', function () {
        it('formats bytes', function () {
            expect($this->service->formatFileSize(500))->toBe('500 B');
        });

        it('formats kilobytes', function () {
            expect($this->service->formatFileSize(1536))->toBe('1.5 KB');
        });

        it('formats megabytes', function () {
            expect($this->service->formatFileSize(1048576))->toBe('1 MB');
        });

        it('formats gigabytes', function () {
            expect($this->service->formatFileSize(1073741824))->toBe('1 GB');
        });

        it('formats zero bytes', function () {
            expect($this->service->formatFileSize(0))->toBe('0 B');
        });
    });

    describe('formatDataTypeLabel', function () {
        it('formats OHLCV type', function () {
            expect($this->service->formatDataTypeLabel(BinaryStorage::DATA_TYPE_OHLCV))->toBe('OHLCV');
        });

        it('formats Heiken-Ashi type', function () {
            expect($this->service->formatDataTypeLabel(BinaryStorage::DATA_TYPE_HEIKEN_ASHI))->toBe('Heiken-Ashi');
        });

        it('formats Renko type without brick size', function () {
            expect($this->service->formatDataTypeLabel(BinaryStorage::DATA_TYPE_RENKO))->toBe('Renko');
        });

        it('formats Renko type with integer brick size', function () {
            expect($this->service->formatDataTypeLabel(BinaryStorage::DATA_TYPE_RENKO, 100.0))->toBe('Renko (100)');
        });

        it('formats Renko type with decimal brick size', function () {
            expect($this->service->formatDataTypeLabel(BinaryStorage::DATA_TYPE_RENKO, 0.001))->toBe('Renko (0.001)');
        });

        it('formats ATR-Renko type with period', function () {
            expect($this->service->formatDataTypeLabel(BinaryStorage::DATA_TYPE_ATR_RENKO, 14.0))->toBe('ATR-Renko (14)');
        });

        it('formats ATR-Renko type without period', function () {
            expect($this->service->formatDataTypeLabel(BinaryStorage::DATA_TYPE_ATR_RENKO))->toBe('ATR-Renko');
        });

        it('formats unknown type', function () {
            expect($this->service->formatDataTypeLabel(99))->toBe('Unknown (99)');
        });
    });
});
