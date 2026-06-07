<?php

namespace App\AlphaForge\Backtesting\Service;

use App\AlphaForge\Backtesting\Model\BacktestCursor;
use App\AlphaForge\Backtesting\Optimization\MarketDataSnapshot;
use App\AlphaForge\Common\Enum\TimeframeEnum;
use App\AlphaForge\Common\Model\MultiTimeframeOhlcvSeries;
use App\AlphaForge\Common\Model\OhlcvSeries;
use App\AlphaForge\Data\Service\BinaryStorageInterface;
use App\AlphaForge\Strategy\Dto\InitializeData;
use App\AlphaForge\Strategy\Service\StrategyRegistryInterface;
use Carbon\Carbon;
use Ds\Map;
use Ds\Vector;
use RuntimeException;

class Backtester
{
    private ?TimeframeEnum $signalTimeframe = null;

    private ?TimeframeEnum $executionTimeframe = null;

    private ?MultiTimeframeOhlcvSeries $multiTimeframeData = null;

    private object $strategy;

    private string $initialCapital;

    private string $currentCapital;

    /** @var callable|null */
    private $progressCallback = null;

    // ── Pre-extracted bar arrays (populated by data loading) ──

    /** @var array<int> */
    private array $barTimestamps = [];

    /** @var array<float> */
    private array $barOpens = [];

    /** @var array<float> */
    private array $barHighs = [];

    /** @var array<float> */
    private array $barLows = [];

    /** @var array<float> */
    private array $barCloses = [];

    /** @var array<float> */
    private array $barVolumes = [];

    /** @var array<int>|null */
    private ?array $execTimestamps = null;

    /** @var array<float>|null */
    private ?array $execOpens = null;

    /** @var array<float>|null */
    private ?array $execHighs = null;

    /** @var array<float>|null */
    private ?array $execLows = null;

    /** @var array<float>|null */
    private ?array $execCloses = null;

    /** @var array<float>|null */
    private ?array $execVolumes = null;

    /** @var Map<string, OhlcvSeries> */
    private Map $ohlcvData;

    /** @var Map<string, OhlcvSeries>|null */
    private ?Map $executionOhlcvData = null;

    public function __construct(
        private readonly StrategyRegistryInterface $strategyRegistry,
        private readonly BinaryStorageInterface $binaryStorage,
        private readonly StatisticsServiceInterface $statisticsService,
        private readonly SeriesMetricServiceInterface $seriesMetricService,
        private readonly MultiTimeframeDataServiceInterface $multiTimeframeDataService,
        private readonly string $marketDataPath
    ) {}

    /**
     * Run a backtest with the given configuration.
     *
     * @param  callable|null  $progressCallback  (int $current, int $total, string $message)
     * @param  string  $dataType  ohlcv / heikenashi / renko / atr_renko
     * @return array Backtest results
     */
    public function run(
        string $strategyAlias,
        array $symbols,
        TimeframeEnum $timeframe,
        string $exchange,
        string $initialCapital,
        string $stakeCurrency,
        array $strategyInputs = [],
        array $commissionConfig = [],
        array $additionalTimeframes = [],
        ?Carbon $startDate = null,
        ?Carbon $endDate = null,
        ?TimeframeEnum $executionTimeframe = null,
        ?callable $progressCallback = null,
        string $dataType = 'ohlcv',
        ?float $brickSize = null,
        ?int $atrPeriod = null,
    ): array {
        if ($executionTimeframe !== null && $executionTimeframe->toSeconds() >= $timeframe->toSeconds()) {
            throw new RuntimeException(
                "Execution timeframe ({$executionTimeframe->value}) must be lower (finer) than the signal timeframe ({$timeframe->value})."
            );
        }

        $this->initialize($initialCapital);
        $this->progressCallback = $progressCallback;

        $this->emitProgress(0, 100, 'Initializing...');

        $this->strategy = $this->strategyRegistry->get($strategyAlias);
        $this->configureStrategy($strategyInputs);

        $this->signalTimeframe = $timeframe;
        $this->executionTimeframe = $executionTimeframe;

        $this->emitProgress(10, 100, 'Loading market data...');

        $this->loadMarketData($symbols, $timeframe, $exchange, $additionalTimeframes, $startDate, $endDate, $executionTimeframe, $dataType, $brickSize, $atrPeriod);

        $this->emitProgress(20, 100, 'Computing indicators...');
        $this->initializeStrategy($this->ohlcvData->get($symbols[0]));

        $this->emitProgress(30, 100, 'Running backtest...');

        $loopRunner = new BacktestLoopRunner(
            initialCapital: $this->initialCapital,
            commissionConfig: $commissionConfig,
            barTimestamps: $this->barTimestamps,
            barOpens: $this->barOpens,
            barHighs: $this->barHighs,
            barLows: $this->barLows,
            barCloses: $this->barCloses,
            barVolumes: $this->barVolumes,
            execTimestamps: $this->execTimestamps,
            execOpens: $this->execOpens,
            execHighs: $this->execHighs,
            execLows: $this->execLows,
            execCloses: $this->execCloses,
            execVolumes: $this->execVolumes,
            ohlcvData: $this->ohlcvData,
            executionOhlcvData: $this->executionOhlcvData,
            strategy: $this->strategy,
            signalTimeframe: $this->signalTimeframe,
            executionTimeframe: $this->executionTimeframe,
            multiTimeframeData: $this->multiTimeframeData,
            progressCallback: $this->progressCallback,
        );

        $loopRunner->run($symbols);

        $this->currentCapital = $loopRunner->currentCapital;

        $this->emitProgress(90, 100, 'Calculating statistics...');

        $barsPerYear = $this->computeBarsPerYear();
        $riskFreeRate = (string) config('alphaforge.backtesting.risk_free_rate', '0.02');
        $statistics = $this->statisticsService->calculate(
            $loopRunner->positions,
            $this->initialCapital,
            $this->currentCapital,
            riskFreeRate: $riskFreeRate,
            tradingDaysPerYear: $barsPerYear,
            barEquityCurve: $loopRunner->barEquityCurve,
        );
        $statistics['position_pnl_values'] = $this->extractClosedPositionPnl($loopRunner->positions);
        $statistics['position_trades'] = $loopRunner->positionTradeDetails;

        $this->emitProgress(100, 100, 'Backtest completed');

        return [
            'strategy' => $strategyAlias,
            'symbols' => $symbols,
            'timeframe' => $timeframe->value,
            'execution_timeframe' => $executionTimeframe?->value,
            'exchange' => $exchange,
            'initial_capital' => $this->initialCapital,
            'final_capital' => $this->currentCapital,
            'positions' => $loopRunner->positions->toArray(),
            'statistics' => $statistics,
        ];
    }

    /**
     * Run a backtest using preloaded market data (avoids redundant file I/O).
     *
     * @return array Backtest results
     */
    public function runWithPreloadedData(
        string $strategyAlias,
        array $symbols,
        TimeframeEnum $timeframe,
        string $initialCapital,
        string $stakeCurrency,
        array $strategyInputs,
        array $commissionConfig,
        array $additionalTimeframes,
        MarketDataSnapshot $data,
        ?TimeframeEnum $executionTimeframe = null,
        ?callable $progressCallback = null,
    ): array {
        if ($executionTimeframe !== null && $executionTimeframe->toSeconds() >= $timeframe->toSeconds()) {
            throw new RuntimeException(
                "Execution timeframe ({$executionTimeframe->value}) must be lower (finer) than the signal timeframe ({$timeframe->value})."
            );
        }

        $this->initialize($initialCapital);
        $this->progressCallback = $progressCallback;

        $this->emitProgress(0, 100, 'Initializing...');

        $this->strategy = $this->strategyRegistry->get($strategyAlias);
        $this->configureStrategy($strategyInputs);

        $this->signalTimeframe = $timeframe;
        $this->executionTimeframe = $executionTimeframe;

        $this->emitProgress(5, 100, 'Using preloaded market data...');

        $this->loadMarketDataFromSnapshot($symbols, $additionalTimeframes, $timeframe, $data);

        $this->emitProgress(20, 100, 'Computing indicators...');
        $this->initializeStrategy($this->ohlcvData->get($symbols[0]));

        $this->emitProgress(30, 100, 'Running backtest...');

        $loopRunner = new BacktestLoopRunner(
            initialCapital: $this->initialCapital,
            commissionConfig: $commissionConfig,
            barTimestamps: $this->barTimestamps,
            barOpens: $this->barOpens,
            barHighs: $this->barHighs,
            barLows: $this->barLows,
            barCloses: $this->barCloses,
            barVolumes: $this->barVolumes,
            execTimestamps: $this->execTimestamps,
            execOpens: $this->execOpens,
            execHighs: $this->execHighs,
            execLows: $this->execLows,
            execCloses: $this->execCloses,
            execVolumes: $this->execVolumes,
            ohlcvData: $this->ohlcvData,
            executionOhlcvData: $this->executionOhlcvData,
            strategy: $this->strategy,
            signalTimeframe: $this->signalTimeframe,
            executionTimeframe: $this->executionTimeframe,
            multiTimeframeData: $this->multiTimeframeData,
            progressCallback: $this->progressCallback,
        );

        $loopRunner->run($symbols);

        $this->currentCapital = $loopRunner->currentCapital;

        $this->emitProgress(90, 100, 'Calculating statistics...');

        $barsPerYear = $this->computeBarsPerYear();
        $riskFreeRate = (string) config('alphaforge.backtesting.risk_free_rate', '0.02');
        $statistics = $this->statisticsService->calculate(
            $loopRunner->positions,
            $this->initialCapital,
            $this->currentCapital,
            riskFreeRate: $riskFreeRate,
            tradingDaysPerYear: $barsPerYear,
            barEquityCurve: $loopRunner->barEquityCurve,
        );
        $statistics['position_pnl_values'] = $this->extractClosedPositionPnl($loopRunner->positions);
        $statistics['position_trades'] = $loopRunner->positionTradeDetails;

        $this->emitProgress(100, 100, 'Backtest completed');

        return [
            'strategy' => $strategyAlias,
            'symbols' => $symbols,
            'timeframe' => $timeframe->value,
            'execution_timeframe' => $executionTimeframe?->value,
            'initial_capital' => $this->initialCapital,
            'final_capital' => $this->currentCapital,
            'positions' => $loopRunner->positions->toArray(),
            'statistics' => $statistics,
        ];
    }

    // ──────────────────────────────────────────────
    //  State initialization
    // ──────────────────────────────────────────────

    private function initialize(string $initialCapital): void
    {
        $this->initialCapital = $initialCapital;
        $this->currentCapital = $initialCapital;
        $this->ohlcvData = new Map;
        $this->executionOhlcvData = null;
        $this->executionTimeframe = null;
        $this->signalTimeframe = null;
        $this->multiTimeframeData = null;
        $this->progressCallback = null;
    }

    private function emitProgress(int $current, int $total, string $message): void
    {
        if ($this->progressCallback !== null) {
            ($this->progressCallback)($current, $total, $message);
        }
    }

    // ──────────────────────────────────────────────
    //  Strategy lifecycle
    // ──────────────────────────────────────────────

    private function configureStrategy(array $inputs): void
    {
        if (method_exists($this->strategy, 'configure')) {
            $this->strategy->configure($inputs);
        }
    }

    private function initializeStrategy(OhlcvSeries $ohlcv): void
    {
        if (! method_exists($this->strategy, 'initialize')) {
            return;
        }

        $this->strategy->initialize(new InitializeData(
            ohlcv: $ohlcv,
            initialCapital: $this->initialCapital,
            multiTimeframe: $this->multiTimeframeData,
        ));
    }

    // ──────────────────────────────────────────────
    //  Market data loading
    // ──────────────────────────────────────────────

    private function loadMarketData(
        array $symbols,
        TimeframeEnum $timeframe,
        string $exchange,
        array $additionalTimeframes,
        ?Carbon $startDate,
        ?Carbon $endDate,
        ?TimeframeEnum $executionTimeframe,
        string $dataType = 'ohlcv',
        ?float $brickSize = null,
        ?int $atrPeriod = null,
    ): void {
        foreach ($symbols as $symbol) {
            $filePath = $this->getMarketDataPath($symbol, $timeframe, $exchange, $dataType, $brickSize, $atrPeriod);
            $ohlcv = $this->loadOhlcvSeries($filePath);

            if ($startDate || $endDate) {
                $ohlcv = $this->filterByDateRange($ohlcv, $startDate, $endDate);
            }

            $this->ohlcvData->put($symbol, $ohlcv);
        }

        if ($executionTimeframe !== null) {
            $this->executionOhlcvData = new Map;

            foreach ($symbols as $symbol) {
                $filePath = $this->getMarketDataPath($symbol, $executionTimeframe, $exchange, 'ohlcv');

                if (! file_exists($filePath)) {
                    throw new RuntimeException(
                        "Execution timeframe data ({$executionTimeframe->value}) not found for {$symbol} on {$exchange}. "
                        .'Download the data first or remove the execution_timeframe setting.'
                    );
                }

                $ohlcv = $this->loadOhlcvSeries($filePath);

                if ($startDate || $endDate) {
                    $ohlcv = $this->filterByDateRange($ohlcv, $startDate, $endDate);
                }

                $this->executionOhlcvData->put($symbol, $ohlcv);
            }

            foreach ($symbols as $symbol) {
                $this->validateTimeAlignment(
                    $this->ohlcvData->get($symbol),
                    $this->executionOhlcvData->get($symbol),
                    $symbol
                );
            }
        }

        if (! empty($additionalTimeframes)) {
            $baseOhlcv = $this->ohlcvData->first()->value;
            $aggregated = $this->multiTimeframeDataService->aggregate($baseOhlcv, $additionalTimeframes);
            $this->multiTimeframeData = new MultiTimeframeOhlcvSeries(
                $baseOhlcv,
                new Map($aggregated),
                new BacktestCursor
            );
        }

        $this->preExtractBarArrays($symbols[0]);
    }

    private function loadMarketDataFromSnapshot(
        array $symbols,
        array $additionalTimeframes,
        TimeframeEnum $timeframe,
        MarketDataSnapshot $snapshot,
    ): void {
        foreach ($symbols as $symbol) {
            if (! isset($snapshot->signalData[$symbol])) {
                throw new RuntimeException("Symbol {$symbol} not found in preloaded market data snapshot.");
            }

            $entry = $snapshot->signalData[$symbol];
            $ohlcv = new OhlcvSeries($entry['data'], new BacktestCursor, $entry['symbol'], $entry['timeframe']);
            $this->ohlcvData->put($symbol, $ohlcv);
        }

        if ($snapshot->executionData !== null) {
            $this->executionOhlcvData = new Map;

            foreach ($symbols as $symbol) {
                if (! isset($snapshot->executionData[$symbol])) {
                    throw new RuntimeException("Execution data for {$symbol} not found in preloaded market data snapshot.");
                }

                $entry = $snapshot->executionData[$symbol];
                $ohlcv = new OhlcvSeries($entry['data'], new BacktestCursor, $entry['symbol'], $entry['timeframe']);
                $this->executionOhlcvData->put($symbol, $ohlcv);
            }
        }

        if (! empty($additionalTimeframes)) {
            $baseOhlcv = $this->ohlcvData->first()->value;
            $aggregated = $this->multiTimeframeDataService->aggregate($baseOhlcv, $additionalTimeframes);
            $this->multiTimeframeData = new MultiTimeframeOhlcvSeries(
                $baseOhlcv,
                new Map($aggregated),
                new BacktestCursor
            );
        }

        $this->preExtractBarArrays($symbols[0]);
    }

    private function preExtractBarArrays(string $symbol): void
    {
        $ohlcv = $this->ohlcvData->get($symbol);
        $this->barTimestamps = $ohlcv->getTimestamps()->getVector()->toArray();
        $this->barOpens = $ohlcv->getOpens()->getVector()->toArray();
        $this->barHighs = $ohlcv->getHighs()->getVector()->toArray();
        $this->barLows = $ohlcv->getLows()->getVector()->toArray();
        $this->barCloses = $ohlcv->getCloses()->getVector()->toArray();
        $this->barVolumes = $ohlcv->getVolumes()->getVector()->toArray();

        if ($this->executionOhlcvData !== null) {
            $execOhlcv = $this->executionOhlcvData->get($symbol);
            $this->execTimestamps = $execOhlcv->getTimestamps()->getVector()->toArray();
            $this->execOpens = $execOhlcv->getOpens()->getVector()->toArray();
            $this->execHighs = $execOhlcv->getHighs()->getVector()->toArray();
            $this->execLows = $execOhlcv->getLows()->getVector()->toArray();
            $this->execCloses = $execOhlcv->getCloses()->getVector()->toArray();
            $this->execVolumes = $execOhlcv->getVolumes()->getVector()->toArray();
        }
    }

    private function validateTimeAlignment(OhlcvSeries $signalOhlcv, OhlcvSeries $execOhlcv, string $symbol): void
    {
        $signalTimestamps = $signalOhlcv->getTimestamps();
        $execTimestamps = $execOhlcv->getTimestamps();

        if ($signalTimestamps->count() === 0 || $execTimestamps->count() === 0) {
            throw new RuntimeException("No data available for time alignment validation on {$symbol}.");
        }

        $signalStart = $signalTimestamps->getVector()->get(0);
        $signalEnd = $signalTimestamps->getVector()->get($signalTimestamps->count() - 1);
        $execStart = $execTimestamps->getVector()->get(0);
        $execEnd = $execTimestamps->getVector()->get($execTimestamps->count() - 1);

        if ($execStart > $signalStart || $execEnd < $signalEnd) {
            throw new RuntimeException(
                "Execution timeframe data for {$symbol} does not cover the full signal timeframe date range. "
                ."Signal: {$signalStart}-{$signalEnd}, Execution: {$execStart}-{$execEnd}. "
                .'Download execution data that covers the full period.'
            );
        }
    }

    private function loadOhlcvSeries(string $filePath): OhlcvSeries
    {
        if (! file_exists($filePath)) {
            throw new RuntimeException("Market data file not found: {$filePath}. Download the data first using alphaforge:data:import.");
        }

        $records = iterator_to_array($this->binaryStorage->readRecordsSequentially($filePath));

        if (count($records) === 0) {
            throw new RuntimeException("Market data file is empty: {$filePath}. Download the data again.");
        }

        $timestamps = [];
        $opens = [];
        $highs = [];
        $lows = [];
        $closes = [];
        $volumes = [];

        foreach ($records as $record) {
            $timestamps[] = $record['timestamp'];
            $opens[] = $record['open'];
            $highs[] = $record['high'];
            $lows[] = $record['low'];
            $closes[] = $record['close'];
            $volumes[] = $record['volume'];
        }

        $marketData = [
            'timestamp' => $timestamps,
            'open' => $opens,
            'high' => $highs,
            'low' => $lows,
            'close' => $closes,
            'volume' => $volumes,
        ];

        return new OhlcvSeries($marketData, new BacktestCursor);
    }

    private function getMarketDataPath(
        string $symbol,
        TimeframeEnum $timeframe,
        string $exchange,
        string $dataType = 'ohlcv',
        ?float $brickSize = null,
        ?int $atrPeriod = null,
    ): string {
        $basePath = sprintf(
            '%s/%s/%s/%s',
            $this->marketDataPath,
            $exchange,
            strtoupper($symbol),
            $timeframe->value
        );

        return match ($dataType) {
            'heikenashi' => $basePath.'/heikenashi.stchx',
            'renko' => $basePath.'/renko_'.$this->formatBrickSize($brickSize ?? 10.0).'.stchx',
            'atr_renko' => $basePath.'/renko_atr_'.($atrPeriod ?? 14).'.stchx',
            default => $basePath.'/ohlcv.stchx',
        };
    }

    private function formatBrickSize(float $brickSize): string
    {
        if (floor($brickSize) === $brickSize) {
            return (string) (int) $brickSize;
        }

        return str_replace('.', '_', (string) $brickSize);
    }

    private function filterByDateRange(OhlcvSeries $ohlcv, ?Carbon $startDate, ?Carbon $endDate): OhlcvSeries
    {
        $timestamps = $ohlcv->getTimestamps();
        $startIndex = 0;
        $endIndex = $timestamps->count() - 1;

        if ($startDate) {
            for ($i = 0; $i < $timestamps->count(); $i++) {
                if ($timestamps->getVector()->get($i) >= $startDate->timestamp) {
                    $startIndex = $i;
                    break;
                }
            }
        }

        if ($endDate) {
            for ($i = $timestamps->count() - 1; $i >= 0; $i--) {
                if ($timestamps->getVector()->get($i) <= $endDate->timestamp) {
                    $endIndex = $i;
                    break;
                }
            }
        }

        return $ohlcv->slice($startIndex, $endIndex - $startIndex + 1);
    }

    // ──────────────────────────────────────────────
    //  Post-processing
    // ──────────────────────────────────────────────

    private function computeBarsPerYear(): int
    {
        if ($this->signalTimeframe === null) {
            return 252;
        }

        return max(1, (int) round(31536000 / $this->signalTimeframe->toSeconds()));
    }

    /**
     * @param  Vector<mixed>  $positions
     * @return array<int, string>
     */
    private function extractClosedPositionPnl(Vector $positions): array
    {
        $pnlValues = [];

        foreach ($positions as $position) {
            if ($position->exitTime !== null) {
                $pnlValues[] = $position->realizedPnl;
            }
        }

        return $pnlValues;
    }
}
