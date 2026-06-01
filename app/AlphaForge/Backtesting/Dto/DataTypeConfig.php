<?php

namespace App\AlphaForge\Backtesting\Dto;

class DataTypeConfig
{
    private const VALID_TYPES = ['ohlcv', 'heikenashi', 'renko', 'atr_renko'];

    public readonly string $dataType;
    public readonly ?float $brickSize;
    public readonly ?int $atrPeriod;

    /** @var list<string> */
    public readonly array $warnings;

    private function __construct(string $dataType, ?float $brickSize, ?int $atrPeriod, array $warnings = [])
    {
        $this->dataType = $dataType;
        $this->brickSize = $brickSize;
        $this->atrPeriod = $atrPeriod;
        $this->warnings = $warnings;
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws \InvalidArgumentException on invalid data-type or missing required params
     */
    public static function fromArray(array $data): self
    {
        $rawDataType = $data['data_type'] ?? $data['dataType'] ?? 'ohlcv';
        $rawBrickSize = self::extractNumeric($data, 'brick_size', 'brickSize');
        $rawAtrPeriod = self::extractNumeric($data, 'atr_period', 'atrPeriod');

        return self::resolve($rawDataType, $rawBrickSize, $rawAtrPeriod, false);
    }

    /**
     * Build from raw CLI option strings.
     *
     * @return self
     *
     * @throws \InvalidArgumentException on invalid data-type or missing required params
     */
    public static function fromOptions(?string $rawDataType, ?string $rawBrickSize, ?string $rawAtrPeriod): self
    {
        $dataType = ($rawDataType !== null && $rawDataType !== '') ? $rawDataType : 'ohlcv';
        $brickSize = $rawBrickSize !== null && $rawBrickSize !== '' ? (float) $rawBrickSize : null;
        $atrPeriod = $rawAtrPeriod !== null && $rawAtrPeriod !== '' ? (int) $rawAtrPeriod : null;

        return self::resolve($dataType, $brickSize, $atrPeriod, true);
    }

    private static function resolve(string $dataType, ?float $brickSize, ?int $atrPeriod, bool $collectWarnings): self
    {
        $warnings = [];

        if (! in_array($dataType, self::VALID_TYPES, true)) {
            throw new \InvalidArgumentException(
                "Invalid data-type '{$dataType}'. Valid values: ".implode(', ', self::VALID_TYPES)
            );
        }

        if ($dataType === 'renko' && $atrPeriod !== null
            && ($brickSize === null || $brickSize <= 0)) {
            $dataType = 'atr_renko';
            if ($collectWarnings) {
                $warnings[] = 'Auto-upgraded data-type from renko to atr_renko based on --atr-period being set.';
            }
        }

        if ($dataType === 'renko') {
            if ($brickSize === null || $brickSize <= 0) {
                throw new \InvalidArgumentException(
                    'data-type=renko requires --brick-size with a positive numeric value (e.g., 0.001, 10, 100).'
                );
            }
        } elseif ($dataType === 'atr_renko') {
            if ($atrPeriod === null || $atrPeriod <= 0) {
                throw new \InvalidArgumentException(
                    'data-type=atr_renko requires --atr-period with a positive integer value (e.g., 14).'
                );
            }
        } else {
            if ($collectWarnings) {
                if ($brickSize !== null) {
                    $warnings[] = '--brick-size is ignored for data-type '.$dataType;
                }
                if ($atrPeriod !== null) {
                    $warnings[] = '--atr-period is ignored for data-type '.$dataType;
                }
            }
        }

        return new self(
            $dataType,
            $dataType === 'renko' ? (float) $brickSize : null,
            $dataType === 'atr_renko' ? (int) $atrPeriod : null,
            $warnings,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function extractNumeric(array $data, string $snake, string $camel): mixed
    {
        if (array_key_exists($snake, $data)) {
            return $data[$snake];
        }
        if (array_key_exists($camel, $data)) {
            return $data[$camel];
        }

        return null;
    }
}