<?php

namespace App\AlphaForge\Console\Commands;

use App\AlphaForge\Console\Concerns\HasJsonOutput;
use App\AlphaForge\Strategy\Dto\StrategyDefinitionDto;
use App\AlphaForge\Strategy\Service\StrategyRegistryInterface;
use Illuminate\Console\Command;

class ListStrategiesCommand extends Command
{
    use HasJsonOutput;

    protected $signature = 'alphaforge:strategies:list
        {--json : Output results as JSON}
        {--debug : Show peak memory usage on exit}';

    protected $description = 'List all available strategies';

    public function __construct(
        private readonly StrategyRegistryInterface $strategyRegistry,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $definitions = $this->strategyRegistry->getStrategyDefinitions();

        if (empty($definitions)) {
            if ($this->jsonEnabled()) {
                return $this->outputJson(true, ['strategies' => []]);
            }

            $this->info('No strategies found.');

            $this->debugMemory();

            return 0;
        }

        if ($this->jsonEnabled()) {
            return $this->outputJson(true, [
                'strategies' => array_map(fn (StrategyDefinitionDto $d) => [
                    'alias' => $d->alias,
                    'name' => $d->name,
                    'description' => $d->description ?? null,
                    'timeframe' => $d->timeframe ?? null,
                    'requiredData' => $d->requiredMarketData ?? [],
                    'inputs' => count($d->inputs),
                ], $definitions),
            ]);
        }

        $this->table(
            ['Alias', 'Name', 'Description', 'Timeframe', 'Required Data', 'Inputs'],
            array_map(fn (StrategyDefinitionDto $d) => [
                $d->alias,
                $d->name,
                $d->description ?? '-',
                $d->timeframe ?? '-',
                ! empty($d->requiredMarketData) ? implode(', ', $d->requiredMarketData) : '-',
                count($d->inputs),
            ], $definitions),
        );

        $this->debugMemory();

        return 0;
    }
}
