<?php

namespace App\AlphaForge\Backtesting\Optimization;

use App\AlphaForge\Backtesting\Dto\BacktestConfiguration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

use function Safe\file;
use function Safe\fclose;
use function Safe\fopen;
use function Safe\fwrite;
use function Safe\json_decode;
use function Safe\json_encode;
use function Safe\unlink;

class ForkParallelRunner
{
    /** @var array<string, ScoredResult> */
    private array $errorResults = [];

    public function __construct(
        private readonly int $workerCount,
        private readonly string $storageDir,
    ) {}

    /**
     * Run backtest configs in parallel using pcntl_fork.
     *
     * @param  BacktestConfiguration[]  $configs
     * @param  callable(BacktestConfiguration, MarketDataSnapshot): array  $singleRunner
     * @return array<int, array{params: array, statistics: array, final_capital: string, error?: string}>
     */
    public function run(array $configs, MarketDataSnapshot $data, callable $singleRunner): array
    {
        $totalConfigs = count($configs);

        if ($totalConfigs === 0) {
            return [];
        }

        if ($this->workerCount <= 1 || $totalConfigs <= 1 || ! function_exists('pcntl_fork')) {
            return $this->runSequential($configs, $data, $singleRunner);
        }

        $chunkSize = (int) ceil($totalConfigs / $this->workerCount);
        $chunks = array_chunk($configs, $chunkSize);

        $runId = uniqid('fork_', true);
        $pids = [];
        $childFiles = [];

        foreach ($chunks as $idx => $chunk) {
            $childFile = "{$this->storageDir}/{$runId}_{$idx}.jsonl";
            $childFiles[] = $childFile;

            $pid = pcntl_fork();

            if ($pid === -1) {
                Log::error('ForkParallelRunner: fork failed', ['worker' => $idx]);

                return $this->runSequential($configs, $data, $singleRunner);
            }

            if ($pid === 0) {
                $this->runChild($chunk, $data, $singleRunner, $childFile);
                exit(0);
            }

            $pids[] = $pid;
        }

        $results = [];

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        foreach ($childFiles as $childFile) {
            $results = array_merge($results, $this->readResults($childFile));
            @unlink($childFile);
        }

        return $results;
    }

    /**
     * @param  callable(BacktestConfiguration, MarketDataSnapshot): array  $singleRunner
     * @return array<int, array>
     */
    private function runSequential(array $configs, MarketDataSnapshot $data, callable $singleRunner): array
    {
        $results = [];

        foreach ($configs as $config) {
            try {
                $result = $singleRunner($config, $data);
                $results[] = [
                    'params' => $config->strategyInputs,
                    'statistics' => $result['statistics'],
                    'final_capital' => (string) ($result['final_capital'] ?? '0'),
                ];
            } catch (\Throwable $e) {
                $results[] = [
                    'params' => $config->strategyInputs,
                    'statistics' => [],
                    'final_capital' => '0',
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    /**
     * @param  BacktestConfiguration[]  $configs
     * @param  callable(BacktestConfiguration, MarketDataSnapshot): array  $runner
     */
    private function runChild(array $configs, MarketDataSnapshot $data, callable $runner, string $outputFile): void
    {
        try {
            DB::reconnect();
        } catch (\Throwable $e) {
            // Reconnect may fail in some setups; continue with inherited connection
        }

        try {
            $fp = fopen($outputFile, 'w');

            if ($fp === false) {
                exit(1);
            }

            foreach ($configs as $config) {
                try {
                    $result = $runner($config, $data);
                    $line = json_encode([
                        'params' => $config->strategyInputs,
                        'statistics' => $result['statistics'] ?? [],
                        'final_capital' => (string) ($result['final_capital'] ?? '0'),
                    ]);
                } catch (\Throwable $e) {
                    $line = json_encode([
                        'params' => $config->strategyInputs,
                        'statistics' => [],
                        'final_capital' => '0',
                        'error' => $e->getMessage(),
                    ]);
                }

                fwrite($fp, $line . "\n");
            }

            fclose($fp);
        } catch (\Throwable $e) {
            exit(1);
        }
    }

    /**
     * @return array<int, array>
     */
    private function readResults(string $filePath): array
    {
        if (! file_exists($filePath)) {
            return [];
        }

        $results = [];
        $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($lines as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $results[] = $decoded;
            }
        }

        return $results;
    }
}
