<?php

namespace App\AlphaForge\Common\Util;

final class MemoryHelper
{
    /**
     * Format a byte count into a human-readable string.
     *
     * Returns values like "1.23 KB", "45.67 MB", "2.00 GB".
     * Uses base-2 (1024) units, matching memory_get_peak_usage(true).
     */
    public static function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $abs = $bytes > 0 ? (float) $bytes : 0;

        if ($abs === 0.0) {
            return '0 B';
        }

        $unitIndex = (int) floor(log($abs, 1024));
        $unitIndex = min($unitIndex, count($units) - 1);

        return sprintf('%.2f %s', $abs / (1024 ** $unitIndex), $units[$unitIndex]);
    }
}
