<?php

namespace App\AlphaForge\Indicator\Model;

use App\AlphaForge\Common\Model\OhlcvSeries;
use App\AlphaForge\TimeSeries\ArrayTimeSeries;
use App\AlphaForge\TimeSeries\TimeSeriesInterface;
use TaLibHybrid\TaLibHybrid;

class IndicatorContext
{
    private array $cache = [];

    public function __construct(
        private OhlcvSeries $ohlcv,
    ) {}

    public function indicator(string $name, array $params): TimeSeriesInterface|IndicatorResultInterface
    {
        $key = $name.':'.md5(json_encode($params));

        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $definition = IndicatorRegistry::getDefinition($name);

        $inputArrays = $this->buildInputArrays($definition);

        $args = $inputArrays;
        foreach ($definition['params'] as $paramName) {
            if (! array_key_exists($paramName, $params)) {
                throw new \InvalidArgumentException("Missing parameter '{$paramName}' for indicator '{$name}'");
            }
            $args[] = $params[$paramName];
        }

        $function = $definition['function'];
        $result = TaLibHybrid::{$function}(...$args);

        if (count($definition['outputs']) === 1) {
            $timeSeries = new ArrayTimeSeries($result);
            $this->cache[$key] = $timeSeries;

            return $timeSeries;
        }

        $series = [];
        foreach ($definition['outputs'] as $outputKey) {
            $series[$outputKey] = new ArrayTimeSeries($result[$outputKey]);
        }

        $indicatorResult = new IndicatorResult($series);
        $this->cache[$key] = $indicatorResult;

        return $indicatorResult;
    }

    public function sma(int $period): TimeSeriesInterface
    {
        return $this->indicator('sma', ['period' => $period]);
    }

    public function ema(int $period): TimeSeriesInterface
    {
        return $this->indicator('ema', ['period' => $period]);
    }

    public function rsi(int $period = 14): TimeSeriesInterface
    {
        return $this->indicator('rsi', ['period' => $period]);
    }

    public function macd(int $fastPeriod = 12, int $slowPeriod = 26, int $signalPeriod = 9): IndicatorResultInterface
    {
        return $this->indicator('macd', [
            'fastPeriod' => $fastPeriod,
            'slowPeriod' => $slowPeriod,
            'signalPeriod' => $signalPeriod,
        ]);
    }

    public function bbands(int $period = 20, float $nbDevUp = 2.0, float $nbDevDn = 2.0, int $maType = 0): IndicatorResultInterface
    {
        return $this->indicator('bbands', [
            'period' => $period,
            'nbDevUp' => $nbDevUp,
            'nbDevDn' => $nbDevDn,
            'maType' => $maType,
        ]);
    }

    public function atr(int $period = 14): TimeSeriesInterface
    {
        return $this->indicator('atr', ['period' => $period]);
    }

    public function stoch(int $fastKPeriod = 5, int $slowKPeriod = 3, int $slowKMaType = 0, int $slowDPeriod = 3, int $slowDMaType = 0): IndicatorResultInterface
    {
        return $this->indicator('stoch', [
            'fastKPeriod' => $fastKPeriod,
            'slowKPeriod' => $slowKPeriod,
            'slowKMaType' => $slowKMaType,
            'slowDPeriod' => $slowDPeriod,
            'slowDMaType' => $slowDMaType,
        ]);
    }

    public function adx(int $period = 14): TimeSeriesInterface
    {
        return $this->indicator('adx', ['period' => $period]);
    }

    private function buildInputArrays(array $definition): array
    {
        $inputArrays = [];
        $dualInput = $definition['dualInput'] ?? false;

        foreach ($definition['inputs'] as $input) {
            $getter = 'get'.ucfirst($input).'s';
            if (method_exists($this->ohlcv, $getter)) {
                $inputArrays[] = $this->ohlcv->{$getter}()->getVector()->toArray();
            } else {
                $getter2 = 'get'.ucfirst($input);
                if (method_exists($this->ohlcv, $getter2)) {
                    $inputArrays[] = $this->ohlcv->{$getter2}()->getVector()->toArray();
                } else {
                    throw new \InvalidArgumentException("Cannot extract input '{$input}' from OhlcvSeries");
                }
            }
        }

        if ($dualInput && count($inputArrays) === 2 && $inputArrays[0] === $inputArrays[1]) {
            // For dual-input indicators like beta/correl, if both inputs are 'close',
            // the caller must provide a second series via params or it stays as-is
        }

        return $inputArrays;
    }
}
