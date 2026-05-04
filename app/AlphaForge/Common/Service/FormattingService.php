<?php

namespace App\AlphaForge\Common\Service;

use App\AlphaForge\Data\Service\BinaryStorage;

class FormattingService
{
    public function formatTimeSpan(int $seconds): string
    {
        $days = (int) floor($seconds / 86400);
        $hours = (int) floor(($seconds % 86400) / 3600);
        $minutes = (int) floor(($seconds % 3600) / 60);
        $secs = $seconds % 60;

        $parts = [];
        if ($days > 0) {
            $parts[] = "{$days} day".($days > 1 ? 's' : '');
        }
        if ($hours > 0) {
            $parts[] = "{$hours} hour".($hours > 1 ? 's' : '');
        }
        if ($minutes > 0) {
            $parts[] = "{$minutes} minute".($minutes > 1 ? 's' : '');
        }
        if ($secs > 0 || empty($parts)) {
            $parts[] = "{$secs} second".($secs !== 1 ? 's' : '');
        }

        return implode(', ', $parts);
    }

    public function formatNumber(float $number): string
    {
        if ($number >= 1000000) {
            return number_format($number, 2);
        }
        if ($number >= 1) {
            return number_format($number, 4);
        }
        if ($number >= 0.0001) {
            return number_format($number, 6);
        }

        return number_format($number, 8);
    }

    public function formatFileSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;

        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, 2).' '.$units[$i];
    }

    public function formatDataTypeLabel(int $dataType, float $brickSize = 0.0): string
    {
        $typeName = match ($dataType) {
            BinaryStorage::DATA_TYPE_OHLCV => 'OHLCV',
            BinaryStorage::DATA_TYPE_HEIKEN_ASHI => 'Heiken-Ashi',
            BinaryStorage::DATA_TYPE_RENKO => 'Renko',
            BinaryStorage::DATA_TYPE_ATR_RENKO => 'ATR-Renko',
            default => "Unknown ({$dataType})",
        };

        if ($dataType === BinaryStorage::DATA_TYPE_RENKO && $brickSize > 0) {
            $brickSizeStr = floor($brickSize) === $brickSize
                ? (string) (int) $brickSize
                : (string) $brickSize;

            return "Renko ({$brickSizeStr})";
        }

        if ($dataType === BinaryStorage::DATA_TYPE_ATR_RENKO && $brickSize > 0) {
            return "ATR-Renko (".(int) $brickSize.')';
        }

        return $typeName;
    }
}
