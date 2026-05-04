<?php

namespace App\Analysis\Exception;

use RuntimeException;

/**
 * Exception thrown during analysis operations.
 */
final class AnalysisException extends RuntimeException
{
    /**
     * Create an exception for a file not found error.
     */
    public static function fileNotFound(string $path): self
    {
        return new self("Market data file not found: {$path}");
    }

    /**
     * Create an exception for invalid configuration.
     */
    public static function invalidConfiguration(string $message): self
    {
        return new self("Invalid configuration: {$message}");
    }

    /**
     * Create an exception for insufficient data.
     */
    public static function insufficientData(string $message): self
    {
        return new self("Insufficient data: {$message}");
    }

    /**
     * Create an exception for invalid timeframe.
     */
    public static function invalidTimeframe(string $expected, string $actual): self
    {
        return new self("Invalid timeframe: expected '{$expected}', got '{$actual}'. Only 1-minute data is supported.");
    }

    /**
     * Create an exception for empty data.
     */
    public static function emptyData(string $message = ''): self
    {
        if ($message !== '') {
            return new self($message);
        }

        return new self('No data available for analysis. The file may be empty or corrupted.');
    }

    /**
     * Create an exception for block alignment error.
     */
    public static function blockAlignmentError(string $message): self
    {
        return new self("Block alignment error: {$message}");
    }

    /**
     * Create an exception for volatility calculation error.
     */
    public static function volatilityCalculationError(string $message): self
    {
        return new self("Volatility calculation error: {$message}");
    }
}
