<?php

namespace App\AlphaForge\Console\Commands;

use App\AlphaForge\Console\Concerns\ParsesMarketDataArgs;
use App\AlphaForge\Console\Commands\Concerns\DebugMemory;
use Illuminate\Console\Command;

use function Laravel\Prompts\warning;

class DataExportCommand extends Command
{
    use ParsesMarketDataArgs;
    use DebugMemory;

    protected $signature = 'alphaforge:data:export
        {exchange : The exchange identifier (e.g., binance, kraken)}
        {market : The trading pair symbol (e.g., BTC/USDT)}
        {timeframe : The timeframe (e.g., 1m, 5m, 1h, 1d)}
        {--debug : Show peak memory usage on exit}';

    protected $description = 'Export market data to an external format (not yet implemented)';

    public function handle(): int
    {
        $exchange = $this->parseExchange();
        $market = $this->parseMarket();
        $timeframe = $this->parseTimeframe();

        warning('Export is not yet implemented.');

        $this->debugMemory();
        return self::FAILURE;
    }
}
