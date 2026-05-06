<?php

use App\AlphaForge\Common\Service\DateParsingService;
use Carbon\Carbon;

describe('DateParsingService', function () {
    beforeEach(function () {
        $this->service = new DateParsingService;
    });

    it('parses Y-m-d format', function () {
        $result = $this->service->parseDate('2024-01-15');

        expect($result)->toBeInstanceOf(Carbon::class)
            ->and($result->year)->toBe(2024)
            ->and($result->month)->toBe(1)
            ->and($result->day)->toBe(15)
            ->and($result->hour)->toBe(0)
            ->and($result->minute)->toBe(0)
            ->and($result->second)->toBe(0);
    });

    it('parses Y-m-d H:i:s format', function () {
        $result = $this->service->parseDate('2024-01-15 14:30:00');

        expect($result)->toBeInstanceOf(Carbon::class)
            ->and($result->year)->toBe(2024)
            ->and($result->month)->toBe(1)
            ->and($result->day)->toBe(15)
            ->and($result->hour)->toBe(14)
            ->and($result->minute)->toBe(30)
            ->and($result->second)->toBe(0);
    });

    it('throws for invalid format', function () {
        $this->service->parseDate('15-01-2024');
    })->throws(InvalidArgumentException::class, 'Invalid date format');

    it('throws for partial date format', function () {
        $this->service->parseDate('2024/01/15');
    })->throws(InvalidArgumentException::class, 'Invalid date format');

    it('throws for empty string', function () {
        $this->service->parseDate('');
    })->throws(InvalidArgumentException::class, 'Invalid date format');

    it('throws for date with timezone', function () {
        $this->service->parseDate('2024-01-15T14:30:00Z');
    })->throws(InvalidArgumentException::class, 'Invalid date format');

    it('Y-m-d date is start of day', function () {
        $result = $this->service->parseDate('2024-06-15');

        expect($result->startOfDay()->eq($result))->toBeTrue();
    });
});
