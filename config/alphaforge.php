<?php

return [
    'defaults' => [
        'bc_scale' => env('STOCHASTIX_BC_SCALE', 12),
        'commission' => [
            'type' => env('STOCHASTIX_COMMISSION_TYPE', 'percentage'), // percentage, fixed_per_trade, fixed_per_unit
            'rate' => env('STOCHASTIX_COMMISSION_RATE', 0.001),
            'amount' => env('STOCHASTIX_COMMISSION_AMOUNT', 0),
        ],
    ],

    'storage' => [
        'market_data_path' => env('STOCHASTIX_MARKET_DATA_PATH', storage_path('app/marketdata')),
        'backtest_results_path' => env('STOCHASTIX_BACKTEST_RESULTS_PATH', storage_path('app/backtests')),
        'cache_path' => env('STOCHASTIX_CACHE_PATH', storage_path('app/cache/stochastix')),
    ],

    'backtesting' => [
        'default_initial_capital' => env('STOCHASTIX_DEFAULT_CAPITAL', 10000),
        'default_stake_currency' => env('STOCHASTIX_STAKE_CURRENCY', 'USDT'),
        'max_concurrent_backtests' => env('STOCHASTIX_MAX_CONCURRENT_BACKTESTS', 5),
    ],

    'data' => [
        'default_exchange' => env('STOCHASTIX_DEFAULT_EXCHANGE', 'binance'),
        'default_timeframe' => env('STOCHASTIX_DEFAULT_TIMEFRAME', '1h'),
        'rate_limit_requests_per_second' => env('STOCHASTIX_RATE_LIMIT_RPS', 10),
        'download_chunk_size' => env('STOCHASTIX_DOWNLOAD_CHUNK_SIZE', 1000),
    ],

    'strategies' => [
        'namespace' => 'App\\AlphaForge\\Strategy',
        'path' => app_path('AlphaForge/Strategy'),
        'auto_discover' => env('STOCHASTIX_AUTO_DISCOVER_STRATEGIES', true),
        'cache_discovery' => ! env('APP_DEBUG', false),
    ],

    'queues' => [
        'backtests' => env('STOCHASTIX_BACKTESTS_QUEUE', 'backtests'),
        'downloads' => env('STOCHASTIX_DOWNLOADS_QUEUE', 'downloads'),
    ],

    'broadcasting' => [
        'enabled' => env('STOCHASTIX_BROADCASTING_ENABLED', true),
        'channel_prefix' => env('STOCHASTIX_CHANNEL_PREFIX', 'stochastix'),
    ],
];
