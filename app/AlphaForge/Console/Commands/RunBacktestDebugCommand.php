<?php

namespace App\AlphaForge\Console\Commands;

use App\AlphaForge\Common\Enum\TimeframeEnum;
use App\AlphaForge\Data\Service\BinaryStorageInterface;
use App\AlphaForge\Services\MarketDataFileService;
use App\AlphaForge\Strategy\Service\StrategyRegistryInterface;
use Illuminate\Console\Command;

class RunBacktestDebugCommand extends Command
{
    protected $signature = 'alphaforge:backtest:debug
        {strategy : The strategy alias}
        {symbol : Trading symbol}
        {--exchange=binance : Exchange identifier}
        {--timeframe=1h : Timeframe}';

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
        $this->line('Strategy class: '.get_class($strategy));

        // Check file path
        $filePath = $fileService->generateFilePath($exchange, $symbol, $timeframeValue);
        $this->line('Looking for file: '.$filePath);
        $this->line('File exists: '.(file_exists($filePath) ? 'YES' : 'NO'));

        if (file_exists($filePath)) {
            $records = iterator_to_array($binaryStorage->readRecordsSequentially($filePath));
            $this->line('Records loaded: '.count($records));

            if (count($records) > 0) {
                $first = $records[0];
                $last = $records[count($records) - 1];
                $this->line('First timestamp: '.date('Y-m-d H:i:s', $first['timestamp']));
                $this->line('Last timestamp: '.date('Y-m-d H:i:s', $last['timestamp']));
                $this->line('First close: '.$first['close']);
                $this->line('Last close: '.$last['close']);
            }
        }

        return 0;
    }
}
