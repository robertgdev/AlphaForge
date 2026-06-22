<?php

namespace App\AlphaForge\Console\Commands;

use App\AlphaForge\Common\Enum\TimeframeEnum;
use App\AlphaForge\Models\TradeSignal;
use App\AlphaForge\Services\Dto\SignalEvaluationResult;
use App\AlphaForge\Services\TradeSignalEvaluator;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableStyle;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\warning;

class EvaluateTradeSignalCommand extends Command
{
    protected $signature = 'alphaforge:signal:evaluate
        {direction? : Trade direction (long|short)}
        {exchange? : Exchange identifier (e.g., binance)}
        {symbol? : Trading symbol (e.g., BTCUSDT)}
        {entry-price? : Entry price}
        {stop-loss? : Stop loss price}
        {take-profit? : Take profit price}
        {--entry-timestamp= : Unix timestamp of signal entry (defaults to now)}
        {--trailing-percent= : Trailing stop percentage (e.g., 5 for 5%)}
        {--timeframe=1h : OHLCV timeframe for evaluation (1m, 5m, 15m, 30m, 1h, 4h, 1d)}
        {--re-evaluate : Re-evaluate an existing open signal}
        {--signal-id= : ID of existing signal to re-evaluate}
        {--list-open : List all open trade signals}';

    protected $description = 'Evaluate a trade signal against OHLCV data';

    public function handle(TradeSignalEvaluator $evaluator): int
    {
        if ($this->option('list-open')) {
            return $this->listOpen();
        }

        if ($this->option('re-evaluate') || $this->option('signal-id')) {
            return $this->reEvaluate($evaluator);
        }

        return $this->createAndEvaluate($evaluator);
    }

    private function createAndEvaluate(TradeSignalEvaluator $evaluator): int
    {
        $direction = strtoupper((string) $this->argument('direction'));

        $required = ['direction', 'exchange', 'symbol', 'entry-price', 'stop-loss', 'take-profit'];
        foreach ($required as $arg) {
            if ($this->argument($arg) === null) {
                error("Missing required argument: {$arg}");

                return self::FAILURE;
            }
        }

        if (! in_array($direction, ['LONG', 'SHORT'], true)) {
            error("Invalid direction '{$direction}'. Must be 'long' or 'short'.");

            return self::FAILURE;
        }

        $exchange = $this->argument('exchange');
        $symbol = strtoupper($this->argument('symbol'));
        $entryPrice = $this->argument('entry-price');
        $stopLoss = $this->argument('stop-loss');
        $takeProfit = $this->argument('take-profit');
        $entryTimestamp = $this->option('entry-timestamp') ? (int) $this->option('entry-timestamp') : now()->timestamp;
        $trailingPercent = $this->option('trailing-percent');
        $timeframe = $this->option('timeframe');

        if (! is_numeric($entryPrice) || (float) $entryPrice <= 0) {
            error('Entry price must be a positive number.');

            return self::FAILURE;
        }

        if (! is_numeric($stopLoss) || (float) $stopLoss <= 0) {
            error('Stop loss must be a positive number.');

            return self::FAILURE;
        }

        if (! is_numeric($takeProfit) || (float) $takeProfit <= 0) {
            error('Take profit must be a positive number.');

            return self::FAILURE;
        }

        $tf = TimeframeEnum::tryFrom($timeframe);
        if ($tf === null) {
            error("Invalid timeframe '{$timeframe}'. Valid values: 1m, 5m, 15m, 30m, 1h, 4h, 1d, 1w, 1M");

            return self::FAILURE;
        }

        $trailingStopEnabled = $trailingPercent !== null;
        $trailingPercentValue = $trailingStopEnabled ? (float) $trailingPercent : null;

        if ($trailingStopEnabled && ($trailingPercentValue === null || $trailingPercentValue <= 0)) {
            error('Trailing stop percentage must be a positive number.');

            return self::FAILURE;
        }

        $signal = new TradeSignal([
            'exchange' => $exchange,
            'symbol' => $symbol,
            'direction' => $direction,
            'entry_price' => $entryPrice,
            'stop_loss' => $stopLoss,
            'take_profit' => $takeProfit,
            'trailing_stop_enabled' => $trailingStopEnabled,
            'trailing_stop_percent' => $trailingPercentValue,
            'entry_timestamp' => $entryTimestamp,
            'timeframe' => $timeframe,
            'status' => 'open',
        ]);

        $result = $evaluator->evaluate($signal);
        $this->applyResult($signal, $result);
        $signal->save();

        $this->displaySignalResult($signal, $result);

        return self::SUCCESS;
    }

    private function reEvaluate(TradeSignalEvaluator $evaluator): int
    {
        $signalId = $this->option('signal-id');

        if (! $signalId) {
            error('The --signal-id option is required when using --re-evaluate.');

            return self::FAILURE;
        }

        $signal = TradeSignal::find($signalId);

        if (! $signal) {
            error("Signal with ID '{$signalId}' not found.");

            return self::FAILURE;
        }

        if (! $signal->isOpen()) {
            warning("Signal {$signalId} is already closed ({$signal->status}).");

            $this->displayClosedSignal($signal);

            return self::SUCCESS;
        }

        $result = $evaluator->evaluate($signal);
        $this->applyResult($signal, $result);
        $signal->save();

        $this->displaySignalResult($signal, $result);

        return self::SUCCESS;
    }

    private function listOpen(): int
    {
        $signals = TradeSignal::where('status', 'open')->orderBy('created_at', 'desc')->get();

        if ($signals->isEmpty()) {
            info('No open trade signals.');

            return self::SUCCESS;
        }

        $this->line('<fg=yellow>Open Trade Signals ('.$signals->count().'):</>');
        $this->newLine();

        $table = new Table($this->output);
        $table->setHeaders(['ID', 'Symbol', 'Exchange', 'Direction', 'Entry', 'SL', 'TP', 'Trail %', 'Created']);

        foreach ($signals as $s) {
            $table->addRow([
                Str::limit($s->id, 12, ''),
                $s->symbol,
                $s->exchange,
                $s->direction,
                number_format((float) $s->entry_price, 2),
                number_format((float) $s->stop_loss, 2),
                number_format((float) $s->take_profit, 2),
                $s->trailing_stop_enabled ? number_format((float) $s->trailing_stop_percent, 2).'%' : '-',
                $s->created_at->format('Y-m-d H:i'),
            ]);
        }

        $rightAlign = new TableStyle;
        $rightAlign->setPadType(STR_PAD_LEFT);
        foreach ([4, 5, 6, 7] as $col) {
            $table->setColumnStyle($col, $rightAlign);
        }

        $table->render();
        $this->newLine();
        note('Re-evaluate with: alphaforge:signal:evaluate --re-evaluate --signal-id=<id>');

        return self::SUCCESS;
    }

    private function displaySignalResult(TradeSignal $signal, SignalEvaluationResult $result): void
    {
        $this->newLine();
        info('Trade Signal Evaluation');
        $this->newLine();

        $this->components->twoColumnDetail('Signal ID', $signal->id);
        $this->components->twoColumnDetail('Exchange', $signal->exchange);
        $this->components->twoColumnDetail('Symbol', $signal->symbol);
        $this->components->twoColumnDetail('Direction', $signal->direction);
        $this->components->twoColumnDetail('Entry Price', number_format((float) $signal->entry_price, 2));
        $this->components->twoColumnDetail('Stop Loss', number_format((float) $signal->stop_loss, 2));
        $this->components->twoColumnDetail('Take Profit', number_format((float) $signal->take_profit, 2));

        if ($signal->trailing_stop_enabled) {
            $this->components->twoColumnDetail('Trailing Stop', number_format((float) $signal->trailing_stop_percent, 2).'%');
        }

        $this->components->twoColumnDetail('Timeframe', $signal->timeframe);
        $this->components->twoColumnDetail('Entry Timestamp', (string) $signal->entry_timestamp.' ('.date('Y-m-d H:i:s', $signal->entry_timestamp).')');

        $this->newLine();

        $statusLabel = match ($result->status) {
            'open' => '<fg=yellow>OPEN</>',
            'winner' => '<fg=green>WINNER</>',
            'loser' => '<fg=red>LOSER</>',
            default => $result->status,
        };

        $this->components->twoColumnDetail('Status', $statusLabel);

        if ($result->errorMessage) {
            $this->components->twoColumnDetail('Error', '<fg=red>'.$result->errorMessage.'</>');
        }

        if ($result->exitReason) {
            $this->components->twoColumnDetail('Exit Reason', $result->exitReason);
            $this->components->twoColumnDetail('Exit Price', number_format($result->exitPrice, 2));
            $this->components->twoColumnDetail('Exit Timestamp', (string) $result->exitTimestamp.' ('.date('Y-m-d H:i:s', $result->exitTimestamp).')');

            $pnlColor = $result->profitLossPct > 0 ? 'green' : 'red';
            $this->components->twoColumnDetail('PnL %', "<fg={$pnlColor}>".number_format($result->profitLossPct, 4).'%</>');
            $this->components->twoColumnDetail('PnL Absolute', "<fg={$pnlColor}>".number_format($result->profitLossAbs, 8).'</>');
        }

        $this->newLine();

        if ($result->status === 'open') {
            note('Trade is still open. Re-evaluate later with: alphaforge:signal:evaluate --re-evaluate --signal-id='.$signal->id);
        }
    }

    private function displayClosedSignal(TradeSignal $signal): void
    {
        $this->newLine();
        $this->components->twoColumnDetail('Signal ID', $signal->id);
        $this->components->twoColumnDetail('Status', $signal->status);
        $this->components->twoColumnDetail('Exit Reason', $signal->exit_reason ?? 'N/A');
        $this->components->twoColumnDetail('Exit Price', $signal->exit_price !== null ? number_format((float) $signal->exit_price, 2) : 'N/A');
        $this->components->twoColumnDetail('PnL %', $signal->profit_loss_pct !== null ? number_format((float) $signal->profit_loss_pct, 4).'%' : 'N/A');
        $this->newLine();
    }

    private function applyResult(TradeSignal $signal, SignalEvaluationResult $result): void
    {
        if ($result->status === 'open') {
            return;
        }

        $signal->status = $result->status;
        $signal->exit_price = $result->exitPrice;
        $signal->exit_timestamp = $result->exitTimestamp;
        $signal->exit_reason = $result->exitReason;
        $signal->profit_loss_pct = $result->profitLossPct;
        $signal->profit_loss_abs = $result->profitLossAbs;
    }
}
