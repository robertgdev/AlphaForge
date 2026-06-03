<?php

return [
    'defaults' => [
        'bc_scale' => env('ALPHAFORGE_BC_SCALE', 12),
        'commission' => [
            'type' => env('ALPHAFORGE_COMMISSION_TYPE', 'percentage'), // percentage, fixed_per_trade, fixed_per_unit
            'rate' => env('ALPHAFORGE_COMMISSION_RATE', 0.001),
            'amount' => env('ALPHAFORGE_COMMISSION_AMOUNT', 0),
        ],
    ],

    'storage' => [
        'market_data_path' => env('ALPHAFORGE_MARKET_DATA_PATH', storage_path('app/marketdata')),
        'backtest_results_path' => env('ALPHAFORGE_BACKTEST_RESULTS_PATH', storage_path('app/backtests')),
        'cache_path' => env('ALPHAFORGE_CACHE_PATH', storage_path('app/cache/alphaforge')),
    ],

    'backtesting' => [
        'default_initial_capital' => env('ALPHAFORGE_DEFAULT_CAPITAL', 10000),
        'default_stake_currency' => env('ALPHAFORGE_STAKE_CURRENCY', 'USDT'),
        'max_concurrent_backtests' => env('ALPHAFORGE_MAX_CONCURRENT_BACKTESTS', 5),
        'risk_free_rate' => env('ALPHAFORGE_RISK_FREE_RATE', 0.02),
    ],

    'data' => [
        'default_exchange' => env('ALPHAFORGE_DEFAULT_EXCHANGE', 'binance'),
        'default_timeframe' => env('ALPHAFORGE_DEFAULT_TIMEFRAME', '1h'),
        'rate_limit_requests_per_second' => env('ALPHAFORGE_RATE_LIMIT_RPS', 10),
        'download_chunk_size' => env('ALPHAFORGE_DOWNLOAD_CHUNK_SIZE', 1000),
    ],

    'strategies' => [
        'namespace' => 'App\\AlphaForge\\Strategy',
        'path' => app_path('AlphaForge/Strategy'),
        'auto_discover' => env('ALPHAFORGE_AUTO_DISCOVER_STRATEGIES', true),
        'cache_discovery' => ! env('APP_DEBUG', false),
    ],

    'optimization' => [
        'cpu_ratio' => env('ALPHAFORGE_OPT_CPU_RATIO', 0.8),
        'random_max_retries' => env('ALPHAFORGE_RANDOM_MAX_RETRIES', 10),
    ],

    'queues' => [
        'backtests' => env('ALPHAFORGE_BACKTESTS_QUEUE', 'backtests'),
        'downloads' => env('ALPHAFORGE_DOWNLOADS_QUEUE', 'downloads'),
    ],

    'broadcasting' => [
        'enabled' => env('ALPHAFORGE_BROADCASTING_ENABLED', true),
        'channel_prefix' => env('ALPHAFORGE_CHANNEL_PREFIX', 'alphaforge'),
    ],
];
