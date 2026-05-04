<?php

namespace App\AlphaForge\Common\Service;

use Carbon\Carbon;
use InvalidArgumentException;

use function Safe\preg_match;

class DateParsingService
{
    public function parseDate(string $date): Carbon
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $date)) {
            return Carbon::createFromFormat('Y-m-d H:i:s', $date)
                ?? throw new InvalidArgumentException("Invalid date format: {$date}. Use Y-m-d or Y-m-d H:i:s format.");
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return Carbon::createFromFormat('Y-m-d', $date)?->startOfDay()
                ?? throw new InvalidArgumentException("Invalid date format: {$date}. Use Y-m-d or Y-m-d H:i:s format.");
        }

        throw new InvalidArgumentException("Invalid date format: {$date}. Use Y-m-d or Y-m-d H:i:s format.");
    }
}
