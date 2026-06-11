<?php

namespace App\Providers;

use App\AlphaForge\Analysis\Console\Commands\OpenCrossProbabilityCommand;
use App\AlphaForge\Analysis\Console\Commands\OpenCrossValidateCommand;
use App\AlphaForge\Analysis\Engine\OpenCrossProbabilityEngine;
use App\AlphaForge\Analysis\Renderer\ProbabilitySurfaceRenderer;
use App\AlphaForge\Backtesting\Optimization\MarketDataLoader;
use App\AlphaForge\Backtesting\Optimization\Optimizer;
use App\AlphaForge\Backtesting\Optimization\Runner\LightweightOptimizationRunner;
use App\AlphaForge\Backtesting\Optimization\Runner\OptimizationRunnerInterface;
use App\AlphaForge\Backtesting\Service\Backtester;
use App\AlphaForge\Backtesting\Service\BacktestRunService;
use App\AlphaForge\Backtesting\Service\MultiTimeframeDataService;
use App\AlphaForge\Backtesting\Service\MultiTimeframeDataServiceInterface;
use App\AlphaForge\Backtesting\Service\ParameterOptimizerService;
use App\AlphaForge\Backtesting\Service\SeriesMetricService;
use App\AlphaForge\Backtesting\Service\SeriesMetricServiceInterface;
use App\AlphaForge\Backtesting\Service\StatisticsService;
use App\AlphaForge\Backtesting\Service\StatisticsServiceInterface;
use App\AlphaForge\Backtesting\WalkForward\WalkForwardAnalyzer;
use App\AlphaForge\Backtesting\WalkForward\WalkForwardExporter;
use App\AlphaForge\Backtesting\WalkForward\WalkForwardService;
use App\AlphaForge\Console\Commands\ExportOptimizeCommand;
use App\AlphaForge\Console\Commands\ExportTradesCommand;
use App\AlphaForge\Console\Commands\ListOptimizationsCommand;
use App\AlphaForge\Console\Commands\ListStrategiesCommand;
use App\AlphaForge\Console\Commands\ListWalkForwardRunsCommand;
use App\AlphaForge\Console\Commands\MonteCarloCommand;
use App\AlphaForge\Console\Commands\OptimizationResultCommand;
use App\AlphaForge\Console\Commands\OptimizeStrategyCommand;
use App\AlphaForge\Console\Commands\PortfolioOptimizeCommand;
use App\AlphaForge\Console\Commands\SensitivityAnalysisCommand;
use App\AlphaForge\Console\Commands\ShowOptimizationCommand;
use App\AlphaForge\Console\Commands\ShowWalkForwardRunCommand;
use App\AlphaForge\Conversion\AtrRenkoConverter;
use App\AlphaForge\Conversion\HeikenAshiConverter;
use App\AlphaForge\Conversion\RenkoConverter;
use App\AlphaForge\Data\Service\BinaryStorage;
use App\AlphaForge\Data\Service\BinaryStorageInterface;
use App\AlphaForge\Data\Service\DataAvailabilityService;
use App\AlphaForge\Data\Service\DataInspectionService;
use App\AlphaForge\Data\Service\Exchange\CcxtAdapter;
use App\AlphaForge\Data\Service\Exchange\ExchangeAdapterInterface;
use App\AlphaForge\Data\Service\Exchange\ExchangeFactory;
use App\AlphaForge\Data\Service\MarketDataService;
use App\AlphaForge\Data\Service\OhlcvDownloader;
use App\AlphaForge\Services\MarketDataFileService;
use App\AlphaForge\Services\MarketDataPathBuilder;
use App\AlphaForge\Strategy\Service\StrategyRegistry;
use App\AlphaForge\Strategy\Service\StrategyRegistryInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\ServiceProvider;

use function Safe\mkdir;

class AlphaForgeServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Merge config
        $this->mergeConfigFrom(
            __DIR__.'/../../config/alphaforge.php',
            'alphaforge'
        );

        // Bind interfaces to implementations
        $this->app->singleton(BinaryStorageInterface::class, BinaryStorage::class);
        $this->app->singleton(StrategyRegistryInterface::class, StrategyRegistry::class);
        $this->app->singleton(StatisticsServiceInterface::class, StatisticsService::class);
        $this->app->singleton(SeriesMetricServiceInterface::class, SeriesMetricService::class);
        $this->app->singleton(MultiTimeframeDataServiceInterface::class, MultiTimeframeDataService::class);

        // Exchange and data acquisition services
        $this->app->singleton(ExchangeFactory::class);
        $this->app->singleton(ExchangeAdapterInterface::class, CcxtAdapter::class);
        $this->app->singleton(MarketDataService::class);
        $this->app->singleton(DataInspectionService::class);
        $this->app->singleton(DataAvailabilityService::class);

        // Bind MarketDataFileService with configuration
        $this->app->singleton(MarketDataFileService::class, function ($app) {
            return new MarketDataFileService(
                config('alphaforge.storage.market_data_path', storage_path('app/marketdata'))
            );
        });

        // Bind OhlcvDownloader with configuration
        $this->app->bind(OhlcvDownloader::class, function ($app) {
            return new OhlcvDownloader(
                $app->make(ExchangeAdapterInterface::class),
                $app->make(BinaryStorageInterface::class),
                $app->make(MarketDataFileService::class)
            );
        });

        // Bind DataInspectionService with configuration
        $this->app->bind(DataInspectionService::class, function ($app) {
            return new DataInspectionService(
                $app->make(BinaryStorageInterface::class),
                config('alphaforge.storage.market_data_path', storage_path('app/market'))
            );
        });

        // Bind DataAvailabilityService with configuration
        $this->app->bind(DataAvailabilityService::class, function ($app) {
            return new DataAvailabilityService(
                config('alphaforge.storage.market_data_path', storage_path('app/market')),
                $app->make(BinaryStorageInterface::class)
            );
        });

        // Bind MarketDataPathBuilder as singleton
        $this->app->singleton(MarketDataPathBuilder::class, function ($app) {
            return new MarketDataPathBuilder(
                config('alphaforge.storage.market_data_path', storage_path('app/marketdata'))
            );
        });

        // Bind Backtester with configuration
        $this->app->bind(Backtester::class, function ($app) {
            return new Backtester(
                $app->make(StrategyRegistryInterface::class),
                $app->make(BinaryStorageInterface::class),
                $app->make(StatisticsServiceInterface::class),
                $app->make(SeriesMetricServiceInterface::class),
                $app->make(MultiTimeframeDataServiceInterface::class),
                $app->make(MarketDataPathBuilder::class),
            );
        });

        // Bind BacktestRunService (uses Backtester which is already bound)
        $this->app->singleton(BacktestRunService::class);

        // Bind ParameterOptimizerService
        $this->app->singleton(ParameterOptimizerService::class);

        // Bind Optimization runner and Optimizer
        $this->app->singleton(OptimizationRunnerInterface::class, LightweightOptimizationRunner::class);
        $this->app->singleton(MarketDataLoader::class, function ($app) {
            return new MarketDataLoader(
                $app->make(BinaryStorageInterface::class),
                $app->make(MarketDataPathBuilder::class),
            );
        });
        $this->app->singleton(Optimizer::class);

        $this->app->singleton(WalkForwardService::class);
        $this->app->singleton(WalkForwardAnalyzer::class);
        $this->app->singleton(WalkForwardExporter::class);

        // Bind RenkoConverter with configuration
        $this->app->bind(RenkoConverter::class, function ($app) {
            return new RenkoConverter(
                $app->make(BinaryStorageInterface::class),
                $app->make(MarketDataFileService::class),
                $app->make(MarketDataPathBuilder::class),
            );
        });

        // Bind AtrRenkoConverter with configuration
        $this->app->bind(AtrRenkoConverter::class, function ($app) {
            return new AtrRenkoConverter(
                $app->make(BinaryStorageInterface::class),
                $app->make(MarketDataFileService::class),
                $app->make(MarketDataPathBuilder::class),
            );
        });

        // Bind HeikenAshiConverter with configuration
        $this->app->bind(HeikenAshiConverter::class, function ($app) {
            return new HeikenAshiConverter(
                $app->make(BinaryStorageInterface::class),
                $app->make(MarketDataFileService::class),
                $app->make(MarketDataPathBuilder::class),
            );
        });

        // Bind OpenCrossProbabilityEngine with configuration
        $this->app->bind(OpenCrossProbabilityEngine::class, function ($app) {
            return new OpenCrossProbabilityEngine(
                $app->make(BinaryStorageInterface::class),
                $app->make(MarketDataFileService::class),
                config('alphaforge.storage.market_data_path', storage_path('app/marketdata'))
            );
        });

        // Bind ProbabilitySurfaceRenderer
        $this->app->singleton(ProbabilitySurfaceRenderer::class);
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Set bcscale from config
        $scale = config('alphaforge.defaults.bc_scale', 12);
        bcscale($scale);

        // Publish config
        $this->publishes([
            __DIR__.'/../../config/alphaforge.php' => config_path('alphaforge.php'),
        ], 'alphaforge-config');

        // Load routes
        $this->loadRoutesFrom(__DIR__.'/../../routes/alphaforge.php');

        // Load migrations
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

        // Register commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                OpenCrossProbabilityCommand::class,
                OpenCrossValidateCommand::class,
                OptimizeStrategyCommand::class,
                ListOptimizationsCommand::class,
                ListStrategiesCommand::class,
                ShowOptimizationCommand::class,
                OptimizationResultCommand::class,
                ListWalkForwardRunsCommand::class,
                ShowWalkForwardRunCommand::class,
                SensitivityAnalysisCommand::class,
                MonteCarloCommand::class,
                PortfolioOptimizeCommand::class,
                ExportTradesCommand::class,
                ExportOptimizeCommand::class,
            ]);

            // Register user analysis commands — registered after built-in so
            // commands with the same signature from user paths take precedence.
            $this->registerUserAnalysisCommands();
        }

        // Ensure storage directories exist
        $this->ensureStorageDirectoriesExist();
    }

    /**
     * Discover and register user-defined analysis commands from configured paths.
     *
     * Each entry in config('alphaforge.analysis.user_paths') maps a namespace to
     * a directory. Files are scanned for classes extending Illuminate\Console\Command
     * with a $signature property. Registered after built-in commands so user commands
     * with the same signature take precedence.
     */
    private function registerUserAnalysisCommands(): void
    {
        $userPaths = config('alphaforge.analysis.user_paths', []);

        foreach ($userPaths as $namespace => $dir) {
            if (empty($dir) || ! File::isDirectory($dir)) {
                continue;
            }

            $files = File::allFiles($dir);
            $commands = [];

            foreach ($files as $file) {
                $relativePath = str_replace($dir.DIRECTORY_SEPARATOR, '', $file->getPathname());
                $className = str_replace(['/', '.php'], ['\\', ''], $relativePath);

                if (empty($className)) {
                    continue;
                }

                $fqcn = $namespace.'\\'.$className;

                if (! class_exists($fqcn)) {
                    continue;
                }

                $reflection = new \ReflectionClass($fqcn);

                if (! $reflection->isSubclassOf(Command::class) || $reflection->isAbstract()) {
                    continue;
                }

                if (! $reflection->hasProperty('signature')) {
                    continue;
                }

                $commands[] = $fqcn;
            }

            if (! empty($commands)) {
                $this->commands($commands);
            }
        }
    }

    /**
     * Ensure required storage directories exist.
     */
    private function ensureStorageDirectoriesExist(): void
    {
        $directories = [
            config('alphaforge.storage.market_data_path', storage_path('app/market')),
            config('alphaforge.storage.backtest_results_path', storage_path('app/backtests')),
            config('alphaforge.storage.cache_path', storage_path('app/cache/alphaforge')),
            storage_path('tmp'),
        ];

        foreach ($directories as $directory) {
            if (! is_dir($directory)) {
                mkdir($directory, 0775, true);
            }
        }
    }
}
