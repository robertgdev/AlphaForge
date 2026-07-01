<?php

namespace App\AlphaForge\Console\Commands;

use App\AlphaForge\Common\Enum\TimeframeEnum;
use App\AlphaForge\Console\Concerns\HasJsonOutput;
use App\AlphaForge\Models\TradeSignal;
use App\AlphaForge\Services\TradeSignalEvaluator;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableStyle;

class EvaluateAllTradeSignalsCommand extends Command
{
    use HasJsonOutput;

    protected $signature = 'alphaforge:signal:evaluate-all
        {--timeframe= : Optional timeframe filter (1m, 5m, 15m, 30m, 1h, 4h, 1d)}
        {--symbol= : Optional symbol filter}
        {--limit= : Max number of signals to evaluate}
        {--json : Output results as JSON}
        {--schema : Display command parameter schema as JSON}';

    protected $description = 'Re-evaluate all open trade signals';

    public function handle(TradeSignalEvaluator $evaluator): int
    {
        if (($code = $this->handleSchemaFlag()) !== null) {
            return $code;
        }

        $query = TradeSignal::where('status', 'open')->orderBy('created_at', 'asc');

        if ($this->option('timeframe')) {
            $tf = TimeframeEnum::tryFrom($this->option('timeframe'));
            if ($tf === null) {
                if ($this->jsonEnabled()) {
                    return $this->outputJsonError("Invalid timeframe '{$this->option('timeframe')}'. Valid values: 1m, 5m, 15m, 30m, 1h, 4h, 1d, 1w, 1M.");
                }
                $this->warn("Invalid timeframe '{$this->option('timeframe')}'. Valid values: 1m, 5m, 15m, 30m, 1h, 4h, 1d, 1w, 1M.");
                $this->warn('Proceeding without timeframe filter...');
            } else {
                $query->where('timeframe', $this->option('timeframe'));
            }
        }

        if ($this->option('symbol')) {
            $query->where('symbol', strtoupper($this->option('symbol')));
        }

        if ($this->option('limit')) {
            $query->limit((int) $this->option('limit'));
        }

        $signals = $query->get();

        if ($signals->isEmpty()) {
            if ($this->jsonEnabled()) {
                return $this->outputJson(true, ['evaluated' => 0, 'closed' => 0, 'remainingOpen' => 0, 'errors' => 0, 'signals' => []]);
            }

            $this->info('No open trade signals to evaluate.');

            return self::SUCCESS;
        }

        if (! $this->jsonEnabled()) {
            $this->line('<fg=yellow>Evaluating '.$signals->count().' open trade signal(s)...</>');
            $this->newLine();
        }

        $closed = 0;
        $remainingOpen = 0;
        $errors = 0;

        $rows = [];
        $jsonSignals = [];

        foreach ($signals as $signal) {
            $result = $evaluator->evaluate($signal);

            $pnlDisplay = '-';
            $statusDisplay = '<fg=yellow>open</>';

            if ($result->errorMessage) {
                $statusDisplay = '<fg=yellow>open</>';
                $errors++;
            } elseif ($result->status === 'open') {
                $remainingOpen++;
            } else {
                $closed++;

                if ($result->status === 'winner') {
                    $statusDisplay = '<fg=green>closed</>';
                    $pnlDisplay = '<fg=green>+'.number_format($result->profitLossPct, 2).'%</>';
                    $signal->markAsWinner(
                        $result->exitPrice,
                        $result->exitTimestamp,
                        $result->exitReason,
                        $result->profitLossPct,
                        $result->profitLossAbs,
                    );
                } else {
                    $statusDisplay = '<fg=red>closed</>';
                    $pnlDisplay = '<fg=red>'.number_format($result->profitLossPct, 2).'%</>';
                    $signal->markAsLoser(
                        $result->exitPrice,
                        $result->exitTimestamp,
                        $result->exitReason,
                        $result->profitLossPct,
                        $result->profitLossAbs,
                    );
                }
            }

            $rows[] = [
                Str::limit($signal->id, 12, ''),
                $signal->symbol,
                $signal->direction,
                number_format((float) $signal->entry_price, 2),
                $statusDisplay,
                $pnlDisplay,
                $result->exitReason ?? '-',
            ];

            $jsonSignals[] = [
                'id' => $signal->id,
                'symbol' => $signal->symbol,
                'dir' => $signal->direction,
                'entry' => (float) $signal->entry_price,
                'status' => $result->status === 'open' ? 'open' : ($result->status === 'winner' ? 'closed' : 'closed'),
                'pnlPct' => $result->profitLossPct ?? null,
                'exit' => $result->exitReason ?? null,
            ];
        }

        if ($this->jsonEnabled()) {
            return $this->outputJson(true, [
                'evaluated' => $signals->count(),
                'closed' => $closed,
                'remainingOpen' => $remainingOpen,
                'errors' => $errors,
                'signals' => $jsonSignals,
            ]);
        }

        $table = new Table($this->output);
        $table->setHeaders(['ID', 'Symbol', 'Dir', 'Entry', 'Status', 'PnL %', 'Exit']);

        foreach ($rows as $row) {
            $table->addRow($row);
        }

        $rightAlign = new TableStyle;
        $rightAlign->setPadType(STR_PAD_LEFT);
        $table->setColumnStyle(3, $rightAlign);
        $table->setColumnStyle(5, $rightAlign);

        $table->render();
        $this->newLine();

        $this->info("Totals: {$signals->count()} evaluated, {$closed} closed, {$remainingOpen} remaining open".($errors > 0 ? ", {$errors} errors" : ''));

        return self::SUCCESS;
    }
}
