<?php

namespace App\AlphaForge\Console\Commands;

use App\AlphaForge\Common\Enum\TimeframeEnum;
use App\AlphaForge\Console\Concerns\HasJsonOutput;
use App\AlphaForge\Data\Service\BinaryStorageInterface;
use App\AlphaForge\Services\MarketDataFileService;
use App\AlphaForge\Strategy\Service\StrategyRegistryInterface;
use Illuminate\Console\Command;

class RunBacktestDebugCommand extends Command
{
    use HasJsonOutput;

    protected $signature = 'alphaforge:backtest:debug
        {strategy : The strategy alias}
        {symbol : Trading symbol}
        {--exchange=binance : Exchange identifier}
        {--timeframe=1h : Timeframe}
        {--json : Output results as JSON}
        {--debug : Show peak memory usage on exit}';

    protected $description = 'Debug backtest command';

    public function handle(
        BinaryStorageInterface $binaryStorage,
        MarketDataFileService $fileService,
        StrategyRegistryInterface $strategyRegistry
    ): int {
        $strategyAlias = $this->argument('strategy');
        $symbol = strtoupper($this->argument('symbol'));
        $exchange = strtolower($this->option('exchange'));
        $timeframeValue = $this->option('timeframe');

        $timeframe = TimeframeEnum::tryFrom($timeframeValue);

        // Get strategy
        $strategy = $strategyRegistry->get($strategyAlias);
        $strategyClass = get_class($strategy);

        // Check file path
        $filePath = $fileService->generateFilePath($exchange, $symbol, $timeframeValue);
        $fileExists = file_exists($filePath);

        $records = [];
        $first = null;
        $last = null;

        if ($fileExists) {
            $records = iterator_to_array($binaryStorage->readRecordsSequentially($filePath));
            if (count($records) > 0) {
                $first = $records[0];
                $last = $records[count($records) - 1];
            }
        }

        if ($this->jsonEnabled()) {
            return $this->outputJson(true, [
                'strategy' => $strategyAlias,
                'class' => $strategyClass,
                'filePath' => $filePath,
                'fileExists' => $fileExists,
                'recordCount' => count($records),
                'firstTimestamp' => $first ? date('Y-m-d H:i:s', $first['timestamp']) : null,
                'lastTimestamp' => $last ? date('Y-m-d H:i:s', $last['timestamp']) : null,
                'firstClose' => $first ? $first['close'] : null,
                'lastClose' => $last ? $last['close'] : null,
            ]);
        }

        $this->line('Strategy class: '.$strategyClass);

        $this->line('Looking for file: '.$filePath);
        $this->line('File exists: '.($fileExists ? 'YES' : 'NO'));

        if ($fileExists) {
            $this->line('Records loaded: '.count($records));

            if (count($records) > 0) {
                $this->line('First timestamp: '.date('Y-m-d H:i:s', $first['timestamp']));
                $this->line('Last timestamp: '.date('Y-m-d H:i:s', $last['timestamp']));
                $this->line('First close: '.$first['close']);
                $this->line('Last close: '.$last['close']);
            }
        }

        $this->debugMemory();

        return 0;
    }
}
