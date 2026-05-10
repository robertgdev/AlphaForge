<?php

namespace App\AlphaForge\Indicator\Model;

class IndicatorRegistry
{
    private static array $definitions = [];

    private static bool $initialized = false;

    public static function getDefinition(string $name): array
    {
        self::ensureInitialized();

        if (! isset(self::$definitions[$name])) {
            throw new \InvalidArgumentException("Unknown indicator: '{$name}'");
        }

        return self::$definitions[$name];
    }

    public static function has(string $name): bool
    {
        self::ensureInitialized();

        return isset(self::$definitions[$name]);
    }

    public static function getAvailableIndicators(): array
    {
        self::ensureInitialized();

        return array_keys(self::$definitions);
    }

    public static function register(string $name, array $definition): void
    {
        self::ensureInitialized();

        $required = ['function', 'inputs', 'params', 'outputs'];
        foreach ($required as $field) {
            if (! isset($definition[$field])) {
                throw new \InvalidArgumentException("Indicator definition must contain '{$field}'");
            }
        }

        self::$definitions[$name] = $definition;
    }

    private static function ensureInitialized(): void
    {
        if (self::$initialized) {
            return;
        }

        self::$initialized = true;
        self::registerDefaults();
    }

    private static function registerDefaults(): void
    {
        $ohlc = ['open', 'high', 'low', 'close'];
        $hlcv = ['high', 'low', 'close', 'volume'];
        $hlc = ['high', 'low', 'close'];
        $hl = ['high', 'low'];
        $close = ['close'];

        // Overlap Studies
        self::reg('sma', 'sma', $close, ['period'], ['value']);
        self::reg('ema', 'ema', $close, ['period'], ['value']);
        self::reg('dema', 'dema', $close, ['period'], ['value']);
        self::reg('tema', 'tema', $close, ['period'], ['value']);
        self::reg('trima', 'trima', $close, ['period'], ['value']);
        self::reg('kama', 'kama', $close, ['period'], ['value']);
        self::reg('wma', 'wma', $close, ['period'], ['value']);
        self::reg('ma', 'ma', $close, ['period', 'maType'], ['value']);
        self::reg('t3', 't3', $close, ['period', 'vFactor'], ['value']);
        self::reg('mama', 'mama', $close, ['fastLimit', 'slowLimit'], ['mama', 'fama']);
        self::reg('mavp', 'mavp', $close, ['periods', 'minPeriod', 'maxPeriod', 'maType'], ['value']);
        self::reg('midpoint', 'midpoint', $close, ['period'], ['value']);
        self::reg('midprice', 'midprice', $hl, ['period'], ['value']);
        self::reg('sar', 'sar', $hl, ['acceleration', 'maximum'], ['value']);
        self::reg('sarext', 'sarext', $hl, ['startValue', 'offsetOnReverse', 'accelerationInitLong', 'accelerationLong', 'accelerationMaxLong', 'accelerationInitShort', 'accelerationShort', 'accelerationMaxShort'], ['value']);
        self::reg('ht_trendline', 'ht_trendline', $close, [], ['value']);
        self::reg('accbands', 'accbands', $hlc, ['period'], ['upper', 'middle', 'lower']);
        self::reg('bbands', 'bbands', $close, ['period', 'nbDevUp', 'nbDevDn', 'maType'], ['upper', 'middle', 'lower']);

        // Momentum Indicators
        self::reg('rsi', 'rsi', $close, ['period'], ['value']);
        self::reg('mom', 'mom', $close, ['period'], ['value']);
        self::reg('roc', 'roc', $close, ['period'], ['value']);
        self::reg('rocp', 'rocp', $close, ['period'], ['value']);
        self::reg('rocr', 'rocr', $close, ['period'], ['value']);
        self::reg('rocr100', 'rocr100', $close, ['period'], ['value']);
        self::reg('macd', 'macd', $close, ['fastPeriod', 'slowPeriod', 'signalPeriod'], ['macd', 'signal', 'histogram']);
        self::reg('macdext', 'macdext', $close, ['fastPeriod', 'fastMaType', 'slowPeriod', 'slowMaType', 'signalPeriod', 'signalMaType'], ['macd', 'signal', 'histogram']);
        self::reg('macdfix', 'macdfix', $close, ['signalPeriod'], ['macd', 'signal', 'histogram']);
        self::reg('stoch', 'stoch', $hlc, ['fastKPeriod', 'slowKPeriod', 'slowKMaType', 'slowDPeriod', 'slowDMaType'], ['slowK', 'slowD']);
        self::reg('stochf', 'stochf', $hlc, ['fastKPeriod', 'fastDPeriod', 'fastDMaType'], ['fastK', 'fastD']);
        self::reg('stochrsi', 'stochrsi', $close, ['period', 'fastKPeriod', 'fastDPeriod', 'fastDMaType'], ['fastK', 'fastD']);
        self::reg('cmo', 'cmo', $close, ['period'], ['value']);
        self::reg('apo', 'apo', $close, ['fastPeriod', 'slowPeriod', 'maType'], ['value']);
        self::reg('ppo', 'ppo', $close, ['fastPeriod', 'slowPeriod', 'maType'], ['value']);
        self::reg('willr', 'willr', $hlc, ['period'], ['value']);
        self::reg('adx', 'adx', $hlc, ['period'], ['value']);
        self::reg('adxr', 'adxr', $hlc, ['period'], ['value']);
        self::reg('aroon', 'aroon', $hl, ['period'], ['aroondown', 'aroonup']);
        self::reg('aroonosc', 'aroonosc', $hl, ['period'], ['value']);
        self::reg('bop', 'bop', $ohlc, [], ['value']);
        self::reg('cci', 'cci', $hlc, ['period'], ['value']);
        self::reg('dx', 'dx', $hlc, ['period'], ['value']);
        self::reg('mfi', 'mfi', $hlcv, ['period'], ['value']);
        self::reg('minus_di', 'minus_di', $hlc, ['period'], ['value']);
        self::reg('minus_dm', 'minus_dm', $hl, ['period'], ['value']);
        self::reg('plus_di', 'plus_di', $hlc, ['period'], ['value']);
        self::reg('plus_dm', 'plus_dm', $hl, ['period'], ['value']);
        self::reg('ultosc', 'ultosc', $hlc, ['period1', 'period2', 'period3'], ['value']);
        self::reg('trix', 'trix', $close, ['period'], ['value']);
        self::reg('imi', 'imi', ['open', 'close'], ['period'], ['value']);

        // Volatility Indicators
        self::reg('atr', 'atr', $hlc, ['period'], ['value']);
        self::reg('natr', 'natr', $hlc, ['period'], ['value']);
        self::reg('trange', 'trange', $hlc, [], ['value']);

        // Volume Indicators
        self::reg('ad', 'ad', $hlcv, [], ['value']);
        self::reg('adosc', 'adosc', $hlcv, ['fastPeriod', 'slowPeriod'], ['value']);
        self::reg('obv', 'obv', ['close', 'volume'], [], ['value']);

        // Cycle Indicators
        self::reg('ht_dcperiod', 'ht_dcperiod', $close, [], ['value']);
        self::reg('ht_dcphase', 'ht_dcphase', $close, [], ['value']);
        self::reg('ht_phasor', 'ht_phasor', $close, [], ['inphase', 'quadrature']);
        self::reg('ht_sine', 'ht_sine', $close, [], ['sine', 'leadsine']);
        self::reg('ht_trendmode', 'ht_trendmode', $close, [], ['value']);

        // Pattern Recognition
        $cdlInputs = $ohlc;
        $cdlNoPen = [
            'cdl2crows', 'cdl3blackcrows', 'cdl3inside', 'cdl3linestrike',
            'cdl3outside', 'cdl3starsinsouth', 'cdl3whitesoldiers',
            'cdladvanceblock', 'cdlbelthold', 'cdlbreakaway',
            'cdlclosingmarubozu', 'cdlconcealbabyswall', 'cdlcounterattack',
            'cdldoji', 'cdldojistar', 'cdldragonflydoji', 'cdlengulfing',
            'cdlgapsidesidewhite', 'cdlgravestonedoji', 'cdlhammer',
            'cdlhangingman', 'cdlharami', 'cdlharamicross', 'cdlhighwave',
            'cdlhikkake', 'cdlhikkakemod', 'cdlhomingpigeon',
            'cdlidentical3crows', 'cdlinneck', 'cdlinvertedhammer',
            'cdlkicking', 'cdlkickingbylength', 'cdlladderbottom',
            'cdllongleggeddoji', 'cdllongline', 'cdlmarubozu',
            'cdlmatchinglow', 'cdlonneck', 'cdlpiercing', 'cdlrickshawman',
            'cdlrisefall3methods', 'cdlseparatinglines', 'cdlshootingstar',
            'cdlshortline', 'cdlspinningtop', 'cdlstalledpattern',
            'cdlsticksandwich', 'cdltakuri', 'cdltasukigap', 'cdlthrusting',
            'cdltristar', 'cdlunique3river', 'cdlupsidegap2crows',
            'cdlxsidegap3methods',
        ];
        $cdlWithPen = [
            'cdlabandonedbaby', 'cdldarkcloudcover', 'cdleveningdojistar',
            'cdleveningstar', 'cdlmathold', 'cdlmorningdojistar', 'cdlmorningstar',
        ];

        foreach ($cdlNoPen as $fn) {
            self::reg($fn, $fn, $cdlInputs, [], ['value']);
        }
        foreach ($cdlWithPen as $fn) {
            self::reg($fn, $fn, $cdlInputs, ['penetration'], ['value']);
        }

        // Price Transform
        self::reg('avgprice', 'avgprice', $ohlc, [], ['value']);
        self::reg('medprice', 'medprice', $hl, [], ['value']);
        self::reg('typprice', 'typprice', $hlc, [], ['value']);
        self::reg('wclprice', 'wclprice', $hlc, [], ['value']);

        // Statistic Functions
        self::reg('beta', 'beta', ['close', 'close'], ['period'], ['value'], true);
        self::reg('correl', 'correl', ['close', 'close'], ['period'], ['value'], true);
        self::reg('linearreg', 'linearreg', $close, ['period'], ['value']);
        self::reg('linearreg_angle', 'linearreg_angle', $close, ['period'], ['value']);
        self::reg('linearreg_intercept', 'linearreg_intercept', $close, ['period'], ['value']);
        self::reg('linearreg_slope', 'linearreg_slope', $close, ['period'], ['value']);
        self::reg('stddev', 'stddev', $close, ['period', 'nbDev'], ['value']);
        self::reg('tsf', 'tsf', $close, ['period'], ['value']);
        self::reg('var', 'var', $close, ['period', 'nbDev'], ['value']);
        self::reg('avgdev', 'avgdev', $close, ['period'], ['value']);

        // Math Transform
        $mathTransform = ['acos', 'asin', 'atan', 'ceil', 'cos', 'cosh', 'exp', 'floor', 'ln', 'log10', 'sin', 'sinh', 'sqrt', 'tan', 'tanh'];
        foreach ($mathTransform as $fn) {
            self::reg($fn, $fn, $close, [], ['value']);
        }

        // Math Operators
        self::reg('add', 'add', ['close', 'close'], [], ['value'], true);
        self::reg('sub', 'sub', ['close', 'close'], [], ['value'], true);
        self::reg('mult', 'mult', ['close', 'close'], [], ['value'], true);
        self::reg('div', 'div', ['close', 'close'], [], ['value'], true);
        self::reg('sum', 'sum', $close, ['period'], ['value']);
        self::reg('max', 'max', $close, ['period'], ['max']);
        self::reg('min', 'min', $close, ['period'], ['min']);
        self::reg('maxindex', 'maxindex', $close, ['period'], ['value']);
        self::reg('minindex', 'minindex', $close, ['period'], ['value']);
        self::reg('minmax', 'minmax', $close, ['period'], ['min', 'max']);
        self::reg('minmaxindex', 'minmaxindex', $close, ['period'], ['minidx', 'maxidx']);
    }

    private static function reg(string $name, string $function, array $inputs, array $params, array $outputs, bool $dualInput = false): void
    {
        self::$definitions[$name] = [
            'function' => $function,
            'inputs' => $inputs,
            'params' => $params,
            'outputs' => $outputs,
            'dualInput' => $dualInput,
        ];
    }
}
