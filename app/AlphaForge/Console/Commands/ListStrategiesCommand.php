<?php

namespace App\AlphaForge\Console\Commands;

use App\AlphaForge\Strategy\Dto\StrategyDefinitionDto;
use App\AlphaForge\Strategy\Service\StrategyRegistryInterface;
use Illuminate\Console\Command;

class ListStrategiesCommand extends Command
{
    protected $signature = 'alphaforge:strategies:list';

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
            $this->info('No strategies found.');

            return 0;
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

        return 0;
    }
}
