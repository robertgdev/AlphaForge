<?php

namespace App\AlphaForge\Indicator\Model;

use App\AlphaForge\Common\Model\OhlcvSeries;
use App\AlphaForge\TimeSeries\ArrayTimeSeries;
use App\AlphaForge\TimeSeries\TimeSeriesInterface;
use TaLibHybrid\TaLibHybrid;

use function Safe\json_encode;

class IndicatorContext
{
    /** @var array<string, TimeSeriesInterface|IndicatorResultInterface> */
    private array $cache = [];

    /** @var array<string, ArrayTimeSeries> */
    private array $priceSeriesCache = [];

    private const VALID_PRICE_FIELDS = ['open', 'high', 'low', 'close', 'volume', 'hlc3'];

    public function __construct(
        private OhlcvSeries $ohlcv,
    ) {}

    public function priceSeries(string $field): ArrayTimeSeries
    {
        if (! in_array($field, self::VALID_PRICE_FIELDS, true)) {
            throw new \InvalidArgumentException(
                "Unknown price field '{$field}'. Valid fields: ".implode(', ', self::VALID_PRICE_FIELDS)
            );
        }

        if (isset($this->priceSeriesCache[$field])) {
            return $this->priceSeriesCache[$field];
        }

        if ($field === 'hlc3') {
            $data = $this->ohlcv->getHlc3()->getVector()->toArray();
        } else {
            $getter = 'get'.ucfirst($field).'s';
            $data = $this->ohlcv->{$getter}()->getVector()->toArray();
        }

        $series = new ArrayTimeSeries($data);
        $this->priceSeriesCache[$field] = $series;

        return $series;
    }

    public function indicator(string $name, array $params, array $inputOverrides = []): TimeSeriesInterface|IndicatorResultInterface
    {
        $overrideKey = '';
        if ($inputOverrides !== []) {
            $overrideIds = [];
            foreach ($inputOverrides as $inputName => $series) {
                $overrideIds[] = $inputName.':'.spl_object_id($series);
            }
            $overrideKey = ':'.implode(',', $overrideIds);
        }

        $key = $name.':'.md5(json_encode($params)).$overrideKey;

        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $definition = IndicatorRegistry::getDefinition($name);

        $inputArrays = $this->buildInputArrays($definition, $inputOverrides);

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

    public function sma(int $period, ?string $input = null): TimeSeriesInterface
    {
        /** @var TimeSeriesInterface $result */
        $result = $this->indicator('sma', ['period' => $period], $this->inputOverridesFor($input));

        return $result;
    }

    public function ema(int $period, ?string $input = null): TimeSeriesInterface
    {
        /** @var TimeSeriesInterface $result */
        $result = $this->indicator('ema', ['period' => $period], $this->inputOverridesFor($input));

        return $result;
    }

    public function rsi(int $period = 14, ?string $input = null): TimeSeriesInterface
    {
        /** @var TimeSeriesInterface $result */
        $result = $this->indicator('rsi', ['period' => $period], $this->inputOverridesFor($input));

        return $result;
    }

    public function macd(int $fastPeriod = 12, int $slowPeriod = 26, int $signalPeriod = 9): IndicatorResultInterface
    {
        /** @var IndicatorResultInterface $result */
        $result = $this->indicator('macd', [
            'fastPeriod' => $fastPeriod,
            'slowPeriod' => $slowPeriod,
            'signalPeriod' => $signalPeriod,
        ]);

        return $result;
    }

    public function bbands(int $period = 20, float $nbDevUp = 2.0, float $nbDevDn = 2.0, int $maType = 0): IndicatorResultInterface
    {
        /** @var IndicatorResultInterface $result */
        $result = $this->indicator('bbands', [
            'period' => $period,
            'nbDevUp' => $nbDevUp,
            'nbDevDn' => $nbDevDn,
            'maType' => $maType,
        ]);

        return $result;
    }

    public function atr(int $period = 14): TimeSeriesInterface
    {
        /** @var TimeSeriesInterface $result */
        $result = $this->indicator('atr', ['period' => $period]);

        return $result;
    }

    public function stoch(int $fastKPeriod = 5, int $slowKPeriod = 3, int $slowKMaType = 0, int $slowDPeriod = 3, int $slowDMaType = 0): IndicatorResultInterface
    {
        /** @var IndicatorResultInterface $result */
        $result = $this->indicator('stoch', [
            'fastKPeriod' => $fastKPeriod,
            'slowKPeriod' => $slowKPeriod,
            'slowKMaType' => $slowKMaType,
            'slowDPeriod' => $slowDPeriod,
            'slowDMaType' => $slowDMaType,
        ]);

        return $result;
    }

    public function adx(int $period = 14): TimeSeriesInterface
    {
        /** @var TimeSeriesInterface $result */
        $result = $this->indicator('adx', ['period' => $period]);

        return $result;
    }

    private function buildInputArrays(array $definition, array $inputOverrides = []): array
    {
        $inputArrays = [];
        $dualInput = $definition['dualInput'] ?? false;

        foreach ($definition['inputs'] as $input) {
            if (isset($inputOverrides[$input])) {
                $inputArrays[] = $inputOverrides[$input]->toArray();

                continue;
            }

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

    private function inputOverridesFor(?string $input): array
    {
        if ($input === null) {
            return [];
        }

        return ['close' => $this->priceSeries($input)];
    }
}
