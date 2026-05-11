<?php

namespace App\Providers;

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
use App\AlphaForge\Backtesting\WalkForward\WalkForwardService;
use App\AlphaForge\Console\Commands\ListOptimizationsCommand;
use App\AlphaForge\Console\Commands\OptimizationResultCommand;
use App\AlphaForge\Console\Commands\OptimizeStrategyCommand;
use App\AlphaForge\Console\Commands\ShowOptimizationCommand;
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
use App\AlphaForge\Strategy\Service\StrategyRegistry;
use App\AlphaForge\Strategy\Service\StrategyRegistryInterface;
use App\Analysis\Engine\OpenCrossProbabilityEngine;
use App\Analysis\Renderer\ProbabilitySurfaceRenderer;
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

        // Bind Backtester with configuration
        $this->app->bind(Backtester::class, function ($app) {
            return new Backtester(
                $app->make(StrategyRegistryInterface::class),
                $app->make(BinaryStorageInterface::class),
                $app->make(StatisticsServiceInterface::class),
                $app->make(SeriesMetricServiceInterface::class),
                $app->make(MultiTimeframeDataServiceInterface::class),
                config('alphaforge.storage.market_data_path', storage_path('app/market'))
            );
        });

        // Bind BacktestRunService (uses Backtester which is already bound)
        $this->app->singleton(BacktestRunService::class);

        // Bind ParameterOptimizerService
        $this->app->singleton(ParameterOptimizerService::class);

        // Bind Optimization runner and Optimizer
        $this->app->singleton(OptimizationRunnerInterface::class, LightweightOptimizationRunner::class);
        $this->app->singleton(Optimizer::class);

        $this->app->singleton(WalkForwardService::class);
        $this->app->singleton(WalkForwardAnalyzer::class);

        // Bind RenkoConverter with configuration
        $this->app->bind(RenkoConverter::class, function ($app) {
            return new RenkoConverter(
                $app->make(BinaryStorageInterface::class),
                $app->make(MarketDataFileService::class),
                config('alphaforge.storage.market_data_path', storage_path('app/marketdata'))
            );
        });

        // Bind AtrRenkoConverter with configuration
        $this->app->bind(AtrRenkoConverter::class, function ($app) {
            return new AtrRenkoConverter(
                $app->make(BinaryStorageInterface::class),
                $app->make(MarketDataFileService::class),
                config('alphaforge.storage.market_data_path', storage_path('app/marketdata'))
            );
        });

        // Bind HeikenAshiConverter with configuration
        $this->app->bind(HeikenAshiConverter::class, function ($app) {
            return new HeikenAshiConverter(
                $app->make(BinaryStorageInterface::class),
                $app->make(MarketDataFileService::class),
                config('alphaforge.storage.market_data_path', storage_path('app/marketdata'))
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
        /*
        if ($this->app->runningInConsole()) {
            $this->commands([
                OptimizeStrategyCommand::class,
                ListOptimizationsCommand::class,
                ShowOptimizationCommand::class,
                OptimizationResultCommand::class,
            ]);
        }
        */

        // Ensure storage directories exist
        $this->ensureStorageDirectoriesExist();
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
        ];

        foreach ($directories as $directory) {
            if (! is_dir($directory)) {
                mkdir($directory, 0775, true);
            }
        }
    }
}
