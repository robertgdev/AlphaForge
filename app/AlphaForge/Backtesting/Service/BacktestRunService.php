<?php

namespace App\AlphaForge\Backtesting\Service;

use App\AlphaForge\Backtesting\Model\BacktestRun;
use App\AlphaForge\Common\Enum\TimeframeEnum;
use App\AlphaForge\Events\BacktestProgress;
use App\AlphaForge\Jobs\RunBacktestJob;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

use function Safe\json_encode;

/**
 * Service responsible for orchestrating backtest execution.
 *
 * This service provides a single entry point for running backtests,
 * whether asynchronously via a job or synchronously for CLI/scripts.
 */
class BacktestRunService
{
    public function __construct(
        private readonly Backtester $backtester,
    ) {}

    /**
     * Queue a backtest for async execution.
     *
     * @param  array{
     *     user_id: int|null,
     *     strategy: string,
     *     symbols: array<string>,
     *     timeframe: string,
     *     execution_timeframe?: string|null,
     *     exchange: string,
     *     initial_capital: float,
     *     stake_currency?: string,
     *     strategy_inputs?: array,
     *     commission_config?: array,
     *     start_date?: string|null,
     *     end_date?: string|null
     * }  $data
     */
    public function queue(array $data): BacktestRun
    {
        $backtestRun = $this->createBacktestRun($data);

        RunBacktestJob::dispatch($backtestRun);

        return $backtestRun;
    }

    /**
     * Find a completed backtest run with the same parameters.
     *
     * @param  array{
     *     strategy: string,
     *     symbols: array<string>,
     *     timeframe: string,
     *     execution_timeframe?: string|null,
     *     exchange: string,
     *     initial_capital: float,
     *     stake_currency?: string,
     *     strategy_inputs?: array,
     *     start_date?: string|null,
     *     end_date?: string|null,
     *     data_type?: string,
     *     brick_size?: float|null,
     *     atr_period?: int|null,
     *     sizing_model?: string,
     * }  $data
     */
    public function findCompletedDuplicate(array $data): ?BacktestRun
    {
        $symbols = $data['symbols'];
        sort($symbols);

        $inputs = $data['strategy_inputs'] ?? [];
        ksort($inputs);

        $query = BacktestRun::where('status', 'completed')
            ->where('strategy_alias', $data['strategy'])
            ->where('symbols', json_encode($symbols))
            ->where('timeframe', $data['timeframe'])
            ->where('exchange', $data['exchange'])
            ->where('initial_capital', number_format((float) $data['initial_capital'], 8, '.', ''))
            ->where('stake_currency', $data['stake_currency'] ?? 'USDT')
            ->where('strategy_inputs', json_encode($inputs))
            ->where('data_type', $data['data_type'] ?? 'ohlcv')
            ->where('sizing_model', $data['sizing_model'] ?? 'percent_of_equity');

        foreach (['execution_timeframe', 'brick_size', 'atr_period', 'start_date', 'end_date'] as $nullableField) {
            $value = $data[$nullableField] ?? null;
            if ($value === null) {
                $query->whereNull($nullableField);
            } elseif ($nullableField === 'brick_size') {
                $query->where($nullableField, number_format((float) $value, 8, '.', ''));
            } else {
                $query->where($nullableField, $value);
            }
        }

        return $query->first();
    }

    /**
     * Run a backtest synchronously (for CLI/scripts).
     *
     * @param  array{
     *     user_id?: int|null,
     *     strategy: string,
     *     symbols: array<string>,
     *     timeframe: string,
     *     execution_timeframe?: string|null,
     *     exchange: string,
     *     initial_capital: float,
     *     stake_currency?: string,
     *     strategy_inputs?: array,
     *     commission_config?: array,
     *     start_date?: string|null,
     *     end_date?: string|null
     * }  $data
     * @return array{backtest_run_id: string, strategy: string, symbols: array, timeframe: string, execution_timeframe: string|null, exchange: string, initial_capital: string, final_capital: string, positions: array, statistics: array}
     */
    public function runSync(array $data, ?callable $progressCallback = null): array
    {
        $backtestRun = $this->createBacktestRun($data);

        return $this->execute($backtestRun, $progressCallback);
    }

    /**
     * Execute a queued backtest. Called by RunBacktestJob.
     *
     * @return array{backtest_run_id: string, strategy: string, symbols: array, timeframe: string, execution_timeframe: string|null, exchange: string, initial_capital: string, final_capital: string, positions: array, statistics: array}
     */
    public function execute(BacktestRun $backtestRun, ?callable $progressCallback = null): array
    {
        $backtestRun->markAsRunning();
        $this->broadcastProgress($backtestRun, 0, 'Starting backtest...');

        try {
            $timeframe = TimeframeEnum::from($backtestRun->timeframe);
            $additionalTimeframes = $this->parseAdditionalTimeframes($backtestRun->strategy_inputs ?? []);
            $startDate = $backtestRun->start_date ? Carbon::parse($backtestRun->start_date) : null;
            $endDate = $backtestRun->end_date ? Carbon::parse($backtestRun->end_date) : null;

            // Parse execution timeframe if set
            $executionTimeframe = $backtestRun->execution_timeframe
                ? TimeframeEnum::from($backtestRun->execution_timeframe)
                : null;

            $this->broadcastProgress($backtestRun, 10, 'Loading market data...');

            $result = $this->backtester->run(
                strategyAlias: $backtestRun->strategy_alias,
                symbols: $backtestRun->symbols,
                timeframe: $timeframe,
                exchange: $backtestRun->exchange,
                initialCapital: (string) $backtestRun->initial_capital,
                stakeCurrency: $backtestRun->stake_currency,
                strategyInputs: $backtestRun->strategy_inputs ?? [],
                commissionConfig: $backtestRun->commission_config ?? [],
                additionalTimeframes: $additionalTimeframes,
                startDate: $startDate,
                endDate: $endDate,
                executionTimeframe: $executionTimeframe,
                progressCallback: $progressCallback,
                dataType: $backtestRun->data_type ?? 'ohlcv',
                brickSize: $backtestRun->brick_size !== null ? (float) $backtestRun->brick_size : null,
                atrPeriod: $backtestRun->atr_period ?? null,
                sizingModel: $backtestRun->sizing_model ?? 'percent_of_equity',
                sizingConfig: $backtestRun->sizing_config ?? [],
            );

            $this->broadcastProgress($backtestRun, 90, 'Calculating statistics...');

            $backtestRun->markAsCompleted(
                $result['final_capital'],
                $result['statistics']
            );

            $this->broadcastProgress($backtestRun, 100, 'Backtest completed successfully');

            Log::info('Backtest completed', [
                'backtest_id' => $backtestRun->id,
                'strategy' => $backtestRun->strategy_alias,
                'final_capital' => $result['final_capital'],
            ]);

            $result['backtest_run_id'] = $backtestRun->id;

            return $result;

        } catch (Throwable $e) {
            Log::error('Backtest failed', [
                'backtest_id' => $backtestRun->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $backtestRun->markAsFailed($e->getMessage());
            $this->broadcastProgress($backtestRun, 0, 'Backtest failed: '.$e->getMessage());

            throw $e;
        }
    }

    /**
     * Create a BacktestRun model from input data.
     *
     * @param  array{
     *     user_id?: int|null,
     *     strategy: string,
     *     symbols: array<string>,
     *     timeframe: string,
     *     execution_timeframe?: string|null,
     *     exchange: string,
     *     initial_capital: float,
     *     stake_currency?: string,
     *     strategy_inputs?: array,
     *     commission_config?: array,
     *     start_date?: string|null,
     *     end_date?: string|null,
     *     data_type?: string,
     *     brick_size?: float|null,
     *     atr_period?: int|null
     * }  $data
     */
    private function createBacktestRun(array $data): BacktestRun
    {
        $inputs = $data['strategy_inputs'] ?? [];
        ksort($inputs);

        return BacktestRun::create([
            'user_id' => $data['user_id'] ?? null,
            'strategy_alias' => $data['strategy'],
            'symbols' => $data['symbols'],
            'timeframe' => $data['timeframe'],
            'execution_timeframe' => $data['execution_timeframe'] ?? null,
            'exchange' => $data['exchange'],
            'initial_capital' => $data['initial_capital'],
            'stake_currency' => $data['stake_currency'] ?? 'USDT',
            'strategy_inputs' => $inputs,
            'commission_config' => $data['commission_config'] ?? [],
            'start_date' => $data['start_date'] ?? null,
            'end_date' => $data['end_date'] ?? null,
            'data_type' => $data['data_type'] ?? 'ohlcv',
            'brick_size' => $data['brick_size'] ?? null,
            'atr_period' => $data['atr_period'] ?? null,
            'sizing_model' => $data['sizing_model'] ?? 'percent_of_equity',
            'sizing_config' => $data['sizing_config'] ?? [],
            'status' => 'pending',
        ]);
    }

    /**
     * Parse additional timeframes from strategy inputs.
     *
     * @param  array<string, mixed>  $inputs
     * @return array<int, TimeframeEnum>
     */
    private function parseAdditionalTimeframes(array $inputs): array
    {
        $additionalTimeframes = [];

        if (! empty($inputs['additional_timeframes'] ?? [])) {
            foreach ($inputs['additional_timeframes'] as $tf) {
                $additionalTimeframes[] = TimeframeEnum::from($tf);
            }
        }

        return $additionalTimeframes;
    }

    /**
     * Broadcast progress to the frontend.
     */
    private function broadcastProgress(BacktestRun $backtestRun, int $percent, string $message): void
    {
        if ($backtestRun->user_id) {
            event(new BacktestProgress(
                $backtestRun->id,
                (string) $backtestRun->user_id,
                $percent,
                $message
            ));
        }
    }
}
