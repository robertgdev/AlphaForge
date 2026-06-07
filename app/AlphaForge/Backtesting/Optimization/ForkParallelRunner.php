<?php

namespace App\AlphaForge\Backtesting\Optimization;

use App\AlphaForge\Backtesting\Dto\BacktestConfiguration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
     * @param  callable(array): void|null  $progressCallback  Called with each completed result as it arrives from a child
     * @return array<int, array{params: array, statistics: array, final_capital: string, error?: string}>
     */
    public function run(array $configs, MarketDataSnapshot $data, callable $singleRunner, ?callable $progressCallback = null): array
    {
        $totalConfigs = count($configs);

        if ($totalConfigs === 0) {
            return [];
        }

        if ($this->workerCount <= 1 || $totalConfigs <= 1 || ! function_exists('pcntl_fork')) {
            return $this->runSequential($configs, $data, $singleRunner, $progressCallback);
        }

        $chunkSize = max(1, (int) ceil($totalConfigs / $this->workerCount));
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

                return $this->runSequential($configs, $data, $singleRunner, $progressCallback);
            }

            if ($pid === 0) {
                $this->runChild($chunk, $data, $singleRunner, $childFile);
                exit(0);
            }

            $pids[$pid] = $childFile;
        }

        $results = $this->collectResultsWithPolling($pids, $childFiles, $progressCallback);

        foreach ($childFiles as $childFile) {
            @unlink($childFile);
        }

        return $results;
    }

    /**
     * @param  callable(BacktestConfiguration, MarketDataSnapshot): array  $singleRunner
     * @param  callable(array): void|null  $progressCallback
     * @return array<int, array>
     */
    private function runSequential(array $configs, MarketDataSnapshot $data, callable $singleRunner, ?callable $progressCallback = null): array
    {
        $results = [];

        foreach ($configs as $config) {
            try {
                $result = $singleRunner($config, $data);
                $entry = [
                    'params' => $config->strategyInputs,
                    'statistics' => $result['statistics'],
                    'final_capital' => (string) ($result['final_capital'] ?? '0'),
                ];
                $results[] = $entry;

                if ($progressCallback !== null) {
                    $progressCallback($entry);
                }
            } catch (\Throwable $e) {
                $entry = [
                    'params' => $config->strategyInputs,
                    'statistics' => [],
                    'final_capital' => '0',
                    'error' => $e->getMessage(),
                ];
                $results[] = $entry;

                if ($progressCallback !== null) {
                    $progressCallback($entry);
                }
            }
        }

        return $results;
    }

    /**
     * @param  array<int, string>  $pids  Map of pid => childFile
     * @param  string[]  $childFiles
     * @param  callable(array): void|null  $progressCallback
     * @return array<int, array>
     */
    private function collectResultsWithPolling(array $pids, array $childFiles, ?callable $progressCallback): array
    {
        $results = [];
        /** @var array<string, int> */
        $filePositions = array_fill_keys($childFiles, 0);

        while (! empty($pids)) {
            foreach ($pids as $pid => $childFile) {
                $waited = pcntl_waitpid($pid, $status, WNOHANG);
                if ($waited === $pid) {
                    unset($pids[$pid]);
                }
            }

            foreach ($childFiles as $childFile) {
                $newResults = $this->readNewResults($childFile, $filePositions[$childFile]);
                foreach ($newResults as [$entry, $newPosition]) {
                    $results[] = $entry;
                    $filePositions[$childFile] = $newPosition;

                    if ($progressCallback !== null) {
                        $progressCallback($entry);
                    }
                }
            }

            if (! empty($pids)) {
                usleep(100000);
            }
        }

        foreach ($childFiles as $childFile) {
            $tailResults = $this->readNewResults($childFile, $filePositions[$childFile]);
            foreach ($tailResults as [$entry, $newPosition]) {
                $results[] = $entry;
                $filePositions[$childFile] = $newPosition;

                if ($progressCallback !== null) {
                    $progressCallback($entry);
                }
            }
        }

        return $results;
    }

    /**
     * @return array<int, array{array, int}> List of [entry, newPosition] pairs
     */
    private function readNewResults(string $filePath, int $offset): array
    {
        if (! file_exists($filePath)) {
            return [];
        }

        $size = filesize($filePath);
        if ($size === false || $size <= $offset) {
            return [];
        }

        $fp = fopen($filePath, 'r');
        fseek($fp, $offset);

        $results = [];
        $currentPos = $offset;

        while (! feof($fp)) {
            $line = fgets($fp);
            if ($line === false) {
                break;
            }

            $bytesRead = strlen($line);
            $currentPos += $bytesRead;

            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $results[] = [$decoded, $currentPos];
            }
        }

        fclose($fp);

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

                fwrite($fp, $line."\n");
            }

            fclose($fp);
        } catch (\Throwable $e) {
            exit(1);
        }
    }
}
