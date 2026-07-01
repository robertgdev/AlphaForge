<?php

namespace App\AlphaForge\Console\Commands;

use App\AlphaForge\Backtesting\Optimization\OptimizationMethod;
use App\AlphaForge\Backtesting\Optimization\ParallelRunnerMode;
use App\AlphaForge\Common\Enum\TimeframeEnum;
use App\AlphaForge\Console\Concerns\HasJsonOutput;
use App\AlphaForge\Strategy\Service\StrategyRegistryInterface;
use Illuminate\Console\Command;
use Symfony\Component\Console\Exception\CommandNotFoundException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

class SchemaCommand extends Command
{
    use HasJsonOutput;

    protected $signature = 'alphaforge:schema
        {name? : The command name to show schema for. If omitted, lists all schemas.}
        {--json : Output results as JSON (always JSON for this command)}';

    protected $description = 'Output JSON schema describing command parameters';

    /**
     * @var array<string, array<string, string>>
     */
    private const PARAM_METADATA = [
        'exchange' => [
            'type' => 'string',
            'examples' => 'binance,kraken,bybit',
        ],
        'market' => [
            'type' => 'string',
            'pattern' => 'BASE/QUOTE',
            'examples' => 'BTC/USDT,ETH/USDT',
        ],
        'symbol' => [
            'type' => 'string',
            'pattern' => 'BASEQUOTE',
            'examples' => 'BTCUSDT,ETHUSDT',
        ],
        'symbols' => [
            'type' => 'array',
            'pattern' => 'BASEQUOTE',
            'examples' => 'BTCUSDT,ETHUSDT,SOLUSDT',
        ],
        'timeframe' => [
            'type' => 'string',
            'allowed_values' => '',
        ],
        'source_timeframe' => [
            'type' => 'string',
            'allowed_values' => '',
        ],
        'target_timeframe' => [
            'type' => 'string',
            'allowed_values' => '',
        ],
        'execution-timeframe' => [
            'type' => 'string',
            'allowed_values' => '',
        ],
        'strategy' => [
            'type' => 'string',
            'examples' => '',
        ],
        'start-date' => [
            'type' => 'date',
            'format' => 'YYYY-MM-DD',
            'examples' => '2024-01-01',
        ],
        'startdate' => [
            'type' => 'date',
            'format' => 'YYYY-MM-DD',
            'examples' => '2024-01-01',
        ],
        'end-date' => [
            'type' => 'date',
            'format' => 'YYYY-MM-DD',
            'examples' => '2024-12-31',
        ],
        'enddate' => [
            'type' => 'date',
            'format' => 'YYYY-MM-DD',
            'examples' => '2024-12-31',
        ],
        'capital' => [
            'type' => 'number',
            'examples' => '1000,10000,50000',
        ],
        'initial-capital' => [
            'type' => 'number',
            'examples' => '1000,10000,50000',
        ],
        'method' => [
            'type' => 'string',
            'allowed_values' => '',
        ],
        'runner' => [
            'type' => 'string',
            'allowed_values' => '',
        ],
        'data-type' => [
            'type' => 'string',
            'allowed_values' => 'ohlcv,heikenashi,renko,atr_renko',
        ],
        'brick-size' => [
            'type' => 'number',
            'examples' => '0.001,10,100',
        ],
        'atr-period' => [
            'type' => 'integer',
            'examples' => '7,14,21',
        ],
        'atr_period' => [
            'type' => 'integer',
            'examples' => '7,14,21',
        ],
        'iterations' => [
            'type' => 'integer',
            'examples' => '100,500,1000',
        ],
        'population' => [
            'type' => 'integer',
            'examples' => '20,50,100',
        ],
        'generations' => [
            'type' => 'integer',
            'examples' => '10,20,50',
        ],
        'top-n' => [
            'type' => 'integer',
            'examples' => '10,50,100',
        ],
        'top' => [
            'type' => 'integer',
            'examples' => '10,20,50',
        ],
        'limit' => [
            'type' => 'integer',
            'examples' => '10,20,50',
        ],
        'split' => [
            'type' => 'float',
            'minimum' => 0,
            'maximum' => 1,
            'examples' => '0.6,0.75,0.8',
        ],
        'risk-per-trade' => [
            'type' => 'float',
            'minimum' => 0,
            'maximum' => 100,
            'examples' => '0.5,1.0,2.0',
        ],
        'max-leverage' => [
            'type' => 'float',
            'minimum' => 1,
            'examples' => '1.0,2.0,5.0',
        ],
        'brick_size' => [
            'type' => 'number',
            'examples' => '0.001,10,100',
        ],
        'stop-loss' => [
            'type' => 'number',
            'examples' => '49000,45000',
        ],
        'take-profit' => [
            'type' => 'number',
            'examples' => '52000,55000',
        ],
        'entry-price' => [
            'type' => 'number',
            'examples' => '50000,48000',
        ],
        'direction' => [
            'type' => 'string',
            'allowed_values' => 'long,short',
        ],
        'signal-id' => [
            'type' => 'string',
            'examples' => '',
        ],
        'backtest_id' => [
            'type' => 'string',
            'examples' => '',
        ],
        'optimization_id' => [
            'type' => 'string',
            'examples' => '',
        ],
        'run_id' => [
            'type' => 'string',
            'examples' => '',
        ],
        'seed' => [
            'type' => 'integer',
            'examples' => '42,1337',
        ],
        'trailing-percent' => [
            'type' => 'float',
            'minimum' => 0,
            'maximum' => 100,
            'examples' => '2,5,10',
        ],
        'sizing-model' => [
            'type' => 'string',
            'allowed_values' => 'percent_of_equity,risk_based,fixed_dollar,kelly,atr_volatility',
        ],
        'format' => [
            'type' => 'string',
            'allowed_values' => 'table,csv,json',
        ],
        'objective' => [
            'type' => 'string',
            'examples' => 'sharpe_ratio,balanced,conservative,sharpe_focused,aggressive',
        ],
        'metric' => [
            'type' => 'string',
            'examples' => 'sharpe_ratio,total_return_percent,profit_factor,sortino_ratio,max_drawdown_percent',
        ],
        'output' => [
            'type' => 'string',
            'examples' => 'results.csv',
        ],
        'params' => [
            'type' => 'string',
            'examples' => '{"fastPeriod":{"min":5,"max":20,"step":5}}',
        ],
        'inputs' => [
            'type' => 'string',
            'examples' => '{"fastPeriod":10,"slowPeriod":50}',
        ],
        'stake-currency' => [
            'type' => 'string',
            'examples' => 'USDT,BTC,ETH',
        ],
        'workers' => [
            'type' => 'string',
            'examples' => 'auto,2,4,8',
        ],
    ];

    public function handle(): int
    {
        $commandName = $this->argument('name');

        if ($commandName) {
            $schema = $this->buildSchema($commandName);
            if ($schema === null) {
                $this->line(json_encode([
                    'command' => $this->getName(),
                    'success' => false,
                    'data' => null,
                    'error' => "Command not found: {$commandName}",
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                return 1;
            }

            $this->line(json_encode([
                'command' => $this->getName(),
                'success' => true,
                'data' => $schema,
                'error' => null,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return 0;
        }

        $allSchemas = [];
        foreach (array_keys($this->getApplication()->all()) as $name) {
            if (! str_starts_with($name, 'alphaforge:') || $name === 'alphaforge:schema') {
                continue;
            }
            $schema = $this->buildSchema($name);
            if ($schema !== null) {
                $allSchemas[$name] = $schema;
            }
        }

        $this->line(json_encode([
            'command' => $this->getName(),
            'success' => true,
            'data' => $allSchemas,
            'error' => null,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return 0;
    }

    /**
     * @return array{command: string, description: string, arguments: list<array>, options: list<array>}|null
     */
    private function buildSchema(string $commandName): ?array
    {
        try {
            $cmd = $this->getApplication()->find($commandName);
        } catch (CommandNotFoundException) {
            return null;
        }

        $def = $cmd->getDefinition();

        $arguments = [];
        foreach ($def->getArguments() as $arg) {
            if ($arg->getName() === 'command') {
                continue;
            }
            $arguments[] = $this->buildArgumentSchema($arg);
        }

        $options = [];
        foreach ($def->getOptions() as $opt) {
            if (in_array($opt->getName(), ['help', 'silent', 'quiet', 'version', 'ansi', 'no-interaction', 'env', 'verbose'])) {
                continue;
            }
            $options[] = $this->buildOptionSchema($opt);
        }

        return [
            'schema' => 'alphaforge.help.v1',
            'command' => $commandName,
            'description' => $cmd->getDescription() ?: null,
            'arguments' => $arguments,
            'options' => $options,
        ];
    }

    /**
     * @return array{name: string, type: string, required: bool, description: string|null, examples?: list<string>, allowed_values?: list<string>, pattern?: string, format?: string, minimum?: int|float, maximum?: int|float, default?: mixed, is_array?: bool}
     */
    private function buildArgumentSchema(InputArgument $arg): array
    {
        $name = $arg->getName();
        $meta = self::PARAM_METADATA[$name] ?? [];
        $type = $this->resolveType($name, $meta);

        $schema = [
            'name' => $name,
            'type' => $type,
            'required' => $arg->isRequired(),
            'description' => $arg->getDescription() ?: null,
        ];

        if ($arg->isArray()) {
            $schema['is_array'] = true;
        }

        if ($arg->getDefault() !== null && ! $arg->isRequired()) {
            $schema['default'] = $arg->getDefault();
        }

        $this->enrichMetadata($schema, $meta);

        return $schema;
    }

    /**
     * @param  array<string, string>  $meta
     * @return array{name: string, type: string, required: bool, description: string|null, examples?: list<string>, allowed_values?: list<string>, pattern?: string, format?: string, minimum?: int|float, maximum?: int|float, default?: mixed, is_array?: bool, accept_value?: bool}
     */
    private function buildOptionSchema(InputOption $opt): array
    {
        $name = $opt->getName();
        $meta = self::PARAM_METADATA[$name] ?? [];
        $type = $this->resolveType($name, $meta);

        $schema = [
            'name' => $name,
            'type' => $type,
            'required' => $opt->isValueRequired() && ! $opt->acceptValue(),
            'description' => $opt->getDescription() ?: null,
        ];

        if ($opt->isArray()) {
            $schema['is_array'] = true;
        }

        if ($opt->acceptValue()) {
            $schema['accept_value'] = true;
        }

        if ($opt->getDefault() !== null && $opt->getDefault() !== false) {
            $schema['default'] = $opt->getDefault();
        }

        $this->enrichMetadata($schema, $meta);

        return $schema;
    }

    /**
     * @param  array<string, string>  $meta
     */
    private function resolveType(string $name, array $meta): string
    {
        if (isset($meta['type'])) {
            return $meta['type'];
        }

        if (in_array($name, ['json', 'force', 'update', 'debug', 'use-strategy-ranges', 'auto-generate', 'no-color', 'async', 're-evaluate', 'list-open', 'show-positions', 'interactions', 'with-dependencies', 'dry-run'])) {
            return 'boolean';
        }

        return 'string';
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  array<string, string>  $meta
     */
    private function enrichMetadata(array &$schema, array $meta): void
    {
        if (isset($meta['allowed_values']) && $meta['allowed_values'] !== '') {
            $schema['allowed_values'] = explode(',', $meta['allowed_values']);
        }

        if (isset($meta['examples']) && $meta['examples'] !== '') {
            $schema['examples'] = explode(',', $meta['examples']);
        }

        if (isset($meta['pattern'])) {
            $schema['pattern'] = $meta['pattern'];
        }

        if (isset($meta['format'])) {
            $schema['format'] = $meta['format'];
        }

        if (isset($meta['minimum'])) {
            $schema['minimum'] = $meta['minimum'];
        }

        if (isset($meta['maximum'])) {
            $schema['maximum'] = $meta['maximum'];
        }

        $name = $schema['name'];

        // Dynamic enrichment for enums
        if ($name === 'timeframe' || $name === 'source_timeframe' || $name === 'target_timeframe' || $name === 'execution-timeframe') {
            $schema['allowed_values'] = array_map(fn (TimeframeEnum $t) => $t->value, TimeframeEnum::cases());
        }

        if ($name === 'method') {
            $schema['allowed_values'] = array_map(fn (OptimizationMethod $m) => $m->value, OptimizationMethod::cases());
        }

        if ($name === 'runner') {
            $schema['allowed_values'] = array_map(fn (ParallelRunnerMode $m) => $m->value, ParallelRunnerMode::cases());
        }

        if ($name === 'strategy' && (empty($schema['examples']) || $schema['examples'] === '')) {
            $schema['examples'] = $this->getStrategyExamples();
        }

        if ($name === 'stop-loss' || $name === 'take-profit' || $name === 'entry-price') {
            if (! isset($schema['examples'])) {
                $schema['examples'] = [];
            }
        }
    }

    /**
     * @return list<string>
     */
    private function getStrategyExamples(): array
    {
        try {
            $registry = app()->make(StrategyRegistryInterface::class);
        } catch (\Throwable) {
            return ['sma_crossover', 'ema_crossover'];
        }

        $definitions = $registry->getStrategyDefinitions();

        return array_slice(
            array_map(fn ($d) => $d->alias, $definitions),
            0,
            8,
        );
    }
}
