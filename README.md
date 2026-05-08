# LaraStochastix - Laravel Trading Backtesting System

A comprehensive trading backtesting system ported from Symfony to Laravel 12. This system provides high-performance backtesting capabilities for algorithmic trading strategies with support for multi-timeframe analysis, comprehensive statistics, real-time progress broadcasting, and market data acquisition from 100+ cryptocurrency exchanges.

## Overview

LaraStochastix is a Laravel port of the Stochastix trading backtesting system. It maintains all the core functionality of the original Symfony application while leveraging Laravel's ecosystem for queue management, broadcasting, and database operations while adding significant new capabilities.

## Features

- **High-Performance Backtesting**: Uses the `ds` extension's `Ds\Vector` and `Ds\Map` for memory-efficient time series data
- **BCMath Precision**: All financial calculations use arbitrary precision arithmetic
- **Dual-Timeframe Execution**: Generate signals on a higher timeframe (e.g., H1) while processing orders and SL/TP at a lower timeframe (e.g., M1) for improved accuracy
- **Multi-Timeframe Support**: Strategies can access multiple timeframes simultaneously
- **Comprehensive Statistics**: 30+ metrics including Sharpe Ratio, Sortino Ratio, CAGR, Max Drawdown, etc.
- **Real-time Progress**: WebSocket broadcasting of backtest progress via Laravel Reverb
- **Queue-Based Execution**: Background job processing with dedicated queues
- **Strategy Auto-Discovery**: Automatic discovery of strategies via PHP 8 attributes
- **Market Data Acquisition**: Download OHLCV data from 100+ exchanges via CCXT library
- **Gap Detection**: Automatically detects and fills gaps in historical data
- **Binary Storage**: Efficient binary format (.stchx) for market data storage
- **Data Conversion**: Convert OHLCV data to Heiken-Ashi, fixed-brick Renko, and ATR-based Renko formats
- **Parameter Optimization**: Grid search optimization across strategy parameter ranges

## Directory Structure

```
app/AlphaForge/
|-- Backtesting/
|   |-- Model/
|   |   |-- BacktestCursor.php      # Tracks current bar index during backtest
|   |   |-- BacktestRun.php         # Eloquent model for backtest records
|   |   `-- OptimizationRun.php      # Eloquent model for optimization runs
|   |-- Service/
|   |   |-- Backtester.php          # Main backtesting engine (single & dual-timeframe)
|   |   |-- ParameterOptimizerService.php  # Parameter optimization engine
|   |-- Dto/
|   |   |-- BacktestConfiguration.php  # Backtest config DTO (includes execution_timeframe)
|   |   `-- OptimizationResult.php  # Optimization result DTO
|-- Common/
|   |-- Enum/
|   |   |-- AppliedPriceEnum.php    # Price types for indicator calculation
|   |   |-- DirectionEnum.php       # Long/Short direction
|   |   |-- OhlcvEnum.php           # OHLCV field identifiers
|   |   |-- TALibFunctionEnum.php    # TA-Lib function mappings
|   |   `-- TimeframeEnum.php       # Trading timeframes (1m to 1M)
|   |-- Model/
|   |   |-- MutableSeries.php       # Mutable time series
|   |   |-- MultiTimeframeOhlcvSeries.php  # Multi-timeframe container
|   |   |-- OhlcvSeries.php         # OHLCV data container
|   |   `-- Series.php              # Immutable time series
|   `-- Util/
|       `-- Math.php                 # BCMath statistical functions
|-- Conversion/
|   |-- RenkoConverter.php          # Fixed-brick Renko conversion
|   `-- AtrRenkoConverter.php       # ATR-based Renko conversion
|-- Data/
|   |-- Dto/
|   |   `-- DownloadRequestDto.php   # Download request data transfer
|   |-- Exception/
|   |   |-- DataFileNotFoundException.php
|   |   |-- DownloadCancelledException.php
|   |   |-- DownloaderException.php
|   |   |-- EmptyHistoryException.php
|   |   `-- ExchangeException.php
|   |-- Model/
|   |   `-- MarketDataDownload.php  # Eloquent model for downloads
|   `-- Service/
|       |-- BinaryStorage.php       # Binary .stchx file I/O
|       |-- BinaryStorageInterface.php
|       |-- DataAvailabilityService.php  # Manifest of available data
|       |-- DataInspectionService.php    # Data file inspection
|       |-- MarketDataService.php        # Exchange/symbol listing
|       |-- OhlcvDownloader.php          # Main download orchestration
|       `-- Exchange/
|           |-- CcxtAdapter.php          # CCXT exchange adapter
|           |-- ExchangeAdapterInterface.php
|           `-- ExchangeFactory.php        # CCXT instance factory
|-- Events/
|   |-- BacktestProgress.php        # Backtest broadcasting event
|   `-- DownloadProgress.php        # Download broadcasting event
|-- Http/
|   |-- Controllers/
|   |   `-- Api/
|   |       |-- BacktestController.php
|   |       `-- StrategyController.php
|   |   `-- AlphaForge/Data/
|   |       |-- DataAvailabilityController.php
|   |       |-- DownloadController.php
|   |       |-- ExchangesController.php
|   |       |-- InspectController.php
|   |       `-- SymbolsController.php
|-- Jobs/
|   |-- RunBacktestJob.php          # Queue job for backtests
|   `-- DownloadMarketDataJob.php   # Queue job for downloads
|-- Order/
|   |-- Dto/
|   |   |-- ExecutionResult.php      # Order execution result
|   |   |-- OrderSignal.php         # Trading signal from strategy
|   |   |-- PendingOrder.php         # Order awaiting execution
|   |   `-- PositionDto.php          # Position data
|   |-- Enum/
|   |   `-- OrderTypeEnum.php       # Market/Limit/Stop/StopLimit
|   `-- Model/
|       |-- OrderManager.php        # Order queue management
|       `-- PortfolioManager.php      # Position and cash management
|-- Plot/
|   `-- PlotDefinition.php           # Chart plot definitions
|-- Strategy/
|   |-- Attribute/
|   |   |-- AsStrategy.php          # Strategy metadata attribute
|   |   `-- Input.php               # Strategy input definition with optimization ranges
|   |-- Dto/
|   |   |-- InputDefinitionDto.php
|   |   `-- StrategyDefinitionDto.php
|   |-- Model/
|   |   `-- StrategyContextInterface.php
|   |-- Service/
|   |   |-- StrategyRegistry.php     # Auto-discovery and registration
|   |   `-- StrategyRegistryInterface.php
|   `-- StrategyInterface.php        # Strategy contract
```

## Artisan Commands

AlphaForge provides comprehensive CLI commands for all operations. All commands are under the `alphaforge:` namespace.

### Data Management

#### `alphaforge:data` - Market Data Operations

Import, export, delete, update, or inspect market data from exchanges.

```bash
php artisan alphaforge:data <action> [arguments] [options]
```

**Arguments:**

| Argument | Description                                                                        | Required For |
|---------|------------------------------------------------------------------------------------|--------------|
| `action` | Action to perform: `import`, `export`, `update`, `delete`, `info`, `list` | All actions |
| `exchange` | Exchange identifier (e.g., `binance`, `kraken`)                                    | `import`, `delete`, `info`, `update` |
| `market` | Trading pair symbol (e.g., `BTC/USDT`)                                             | `import`, `delete`, `info`, `update` |
| `timeframe` | Timeframe (e.g., `1m`, `5m`, `1h`, `1d`)                                           | `import`, `delete`, `info`, `update` |
| `startdate` | Start date for import (Y-m-d or Y-m-d H:i:s)                                       | `import` only |
| `enddate` | End date for import/update (Y-m-d or Y-m-d H:i:s, defaults to now)                 | `import`, `update` |

**Usage by Action:**

- `import`: `alphaforge:data import <exchange> <market> <timeframe> <startdate> [enddate]`
- `update`: `alphaforge:data update <exchange> <market> <timeframe> [enddate] [--with-dependencies]`
- `delete`: `alphaforge:data delete <exchange> <market> <timeframe>`
- `info`: `alphaforge:data info <exchange> <market> <timeframe>`
- `list`: `alphaforge:data list [options]`
- `export`: `alphaforge:data export` (not yet implemented)

**Options:**

| Option | Description |
|--------|-------------|
| `--force` | Force overwrite existing data (for import) or skip confirmation (for delete) |
| `--with-dependencies` | When updating, also update all derived data files (Renko, Heiken-Ashi, etc.) |
| `--exchange-filter=` | Filter by exchange for list action |
| `--symbol-filter=` | Filter by symbol for list action |

**Examples:**

```bash
# Download market data
php artisan alphaforge:data import binance BTC/USDT 1h 2023-01-01 2024-01-01

# Update existing data to latest
php artisan alphaforge:data update binance BTC/USDT 1h

# Update existing data up to a specific date
php artisan alphaforge:data update binance BTC/USDT 1h 2024-06-01

# List all stored data
php artisan alphaforge:data list

# List data for specific exchange
php artisan alphaforge:data list --exchange-filter=binance

# Inspect stored data
php artisan alphaforge:data info binance BTC/USDT 1h

# Delete market data
php artisan alphaforge:data delete binance BTC/USDT 1h --force
```

**Cascading Updates with `--with-dependencies`:**

When OHLCV data is updated, any derived data files (Renko, ATR-Renko, Heiken-Ashi) that were generated from it become stale. The `--with-dependencies` flag automatically detects and incrementally updates all derived files after the OHLCV update completes.

```bash
# Update OHLCV data and cascade the update to all derived files
php artisan alphaforge:data update binance BTC/USDT 1h --with-dependencies
```

Each dependent file gets its own progress bar during the incremental conversion. After all dependencies are processed, a summary table shows the result for each (Updated / Up to Date / Full Conversion / Failed).

#### `alphaforge:data:repair` - Repair Corrupted Data Files

Scans and repairs corrupted market data files by fixing header record counts.

```bash
php artisan alphaforge:data:repair [options]
```

**Options:**

| Option | Description |
|--------|-------------|
| `--dry-run` | Show what would be fixed without making changes |
| `--exchange-filter=` | Filter by exchange (e.g., `binance`) |
| `--symbol-filter=` | Filter by symbol (e.g., `BTCUSDT`) |

**Examples:**

```bash
# Scan all files and preview changes
php artisan alphaforge:data:repair --dry-run

# Repair specific symbol
php artisan alphaforge:data:repair --symbol-filter=BTCUSDT

# Repair specific exchange
php artisan alphaforge:data:repair --exchange-filter=binance
```

#### `alphaforge:data:aggregate` - Timeframe Aggregation

Aggregate OHLCV data from a lower timeframe to a higher timeframe (e.g., 1m → 1h).

```bash
php artisan alphaforge:data:aggregate <exchange> <symbol> <source_timeframe> <target_timeframe> [options]
```

**Arguments:**

| Argument | Description | Example |
|---------|-------------|---------|
| `exchange` | Exchange identifier | `binance` |
| `symbol` | Trading pair symbol | `BTC/USDT` |
| `source_timeframe` | Source timeframe to aggregate from | `1m`, `5m`, `15m` |
| `target_timeframe` | Target timeframe to aggregate to | `1h`, `4h`, `1d` |

**Options:**

| Option | Description |
|--------|-------------|
| `--force` | Overwrite target file if it exists |
| `--update` | Incrementally update the target file by appending new aggregated data |

**Examples:**

```bash
# Aggregate 1-minute data to 1-hour
php artisan alphaforge:data:aggregate binance BTC/USDT 1m 1h

# Aggregate 5-minute data to 4-hour
php artisan alphaforge:data:aggregate binance ETH/USDT 5m 4h --force

# Incrementally update an existing aggregated file with new source data
php artisan alphaforge:data:aggregate binance BTC/USDT 1m 1h --update
```

---

### Backtesting Commands

#### `alphaforge:backtest:run` - Run a Backtest

Run a strategy backtest from the command line.

```bash
php artisan alphaforge:backtest:run <strategy> <symbols*> [options]
```

**Arguments:**

| Argument | Description | Example |
|---------|-------------|---------|
| `strategy` | Strategy alias | `sma_crossover`, `rsi_strategy` |
| `symbols*` | Trading symbols to backtest (multiple allowed) | `BTCUSDT ETHUSDT` |

**Options:**

| Option | Description | Default |
|--------|-------------|---------|
| `--exchange=` | Exchange identifier | `binance` |
| `--timeframe=` | Signal timeframe | `1h` |
| `--execution-timeframe=` | Lower timeframe for order/position execution (e.g., `1m`, `5m`) | - |
| `--capital=` | Initial capital in quote currency | `10000` |
| `--stake-currency=` | Stake currency | `USDT` |
| `--start-date=` | Start date (Y-m-d or Y-m-d H:i:s) | - |
| `--end-date=` | End date (Y-m-d or Y-m-d H:i:s) | - |
| `--inputs=` | Strategy inputs as JSON string | `'{"fastPeriod":10}'` |
| `--async` | Queue the backtest instead of running synchronously | - |

**Examples:**

```bash
# Basic backtest with default parameters
php artisan alphaforge:backtest:run sma_crossover BTCUSDT

# Backtest with custom parameters
php artisan alphaforge:backtest:run sma_crossover BTCUSDT --timeframe=4h --capital=50000

# Backtest with strategy inputs
php artisan alphaforge:backtest:run sma_crossover BTCUSDT --inputs='{"fastPeriod":5,"slowPeriod":20}'

# Backtest with date range
php artisan alphaforge:backtest:run sma_crossover BTCUSDT --start-date="2023-01-01" --end-date="2024-01-01"

# Queue backtest for async execution
php artisan alphaforge:backtest:run sma_crossover BTCUSDT --async

# Backtest multiple symbols at once
php artisan alphaforge:backtest:run sma_crossover BTCUSDT ETHUSDT SOLUSDT --timeframe=1h
```

##### Dual-Timeframe Execution (Signal TF + Execution TF)

By default, backtests run entirely on a single timeframe: signals, order execution, and SL/TP checks all use the same bar data. This can lead to unrealistic results — for example, when both a stop-loss and take-profit are hit within the same hourly bar, the backtester cannot know which was hit first.

The `--execution-timeframe` option solves this by separating the **signal timeframe** from the **execution timeframe**:

- **Signal timeframe** (`--timeframe`): The strategy's `onBar()` is called once per signal bar (e.g., every H1 candle)
- **Execution timeframe** (`--execution-timeframe`): Pending orders and SL/TP exits are processed on every execution bar (e.g., every M1 candle) within each signal bar's time window

This provides significantly more accurate fill prices and SL/TP resolution, especially on higher timeframes where a single bar covers a large price range.

**How it works:**

1. The backtester iterates over signal timeframe bars (e.g., H1)
2. For each signal bar, it processes all execution bars (e.g., M1) that fall within that signal bar's time window — evaluating pending orders and checking SL/TP at minute-level granularity
3. After processing the execution bars, the strategy's `onBar()` is called on the signal bar
4. Any resulting signals create pending orders that are evaluated against the next signal bar's execution bars

**Requirements:**

- Execution timeframe data must be lower (finer) than the signal timeframe (e.g., `1m` for `1h`, `5m` for `4h`)
- The execution timeframe data must already be downloaded and must cover the full date range of the signal timeframe data

**Examples:**

```bash
# Generate signals on H1, but process orders/SL-TP on M1 for accuracy
php artisan alphaforge:backtest:run sma_crossover BTCUSDT --timeframe=1h --execution-timeframe=1m

# D1 signals with M5 execution
php artisan alphaforge:backtest:run sma_crossover BTCUSDT --timeframe=1d --execution-timeframe=5m

# H4 signals with M1 execution, with date range
php artisan alphaforge:backtest:run sma_crossover BTCUSDT --timeframe=4h --execution-timeframe=1m \
    --start-date="2023-01-01" --end-date="2024-01-01"
```

#### `alphaforge:backtest:debug` - Debug Backtest Data

Check if market data exists and is valid for a strategy backtest.

```bash
php artisan alphaforge:backtest:debug <strategy> <symbol> [options]
```

**Arguments:**

| Argument | Description | Example |
|---------|-------------|---------|
| `strategy` | Strategy alias | `sma_crossover` |
| `symbol` | Trading symbol | `BTCUSDT` |

**Options:**

| Option | Description | Default |
|--------|-------------|---------|
| `--exchange=` | Exchange identifier | `binance` |
| `--timeframe=` | Timeframe | `1h` |

**Example:**

```bash
php artisan alphaforge:backtest:debug sma_crossover BTCUSDT --timeframe=1h
```

---

### Parameter Optimization Commands

#### `alphaforge:optimize` - Run Parameter Optimization

Run strategy parameter optimization using grid search.

```bash
php artisan alphaforge:optimize <strategy> <symbol> [options]
```

**Arguments:**

| Argument | Description | Example |
|---------|-------------|---------|
| `strategy` | Strategy alias | `sma_crossover` |
| `symbol` | Trading symbol | `BTCUSDT` |

**Options:**

| Option | Description | Default |
|--------|-------------|---------|
| `--exchange=` | Exchange identifier | `binance` |
| `--timeframe=` | Timeframe | `1h` |
| `--capital=` | Initial capital | `10000` |
| `--stake-currency=` | Stake currency | `USDT` |
| `--start-date=` | Start date (Y-m-d) | - |
| `--end-date=` | End date (Y-m-d) | - |
| `--params=` | Parameter ranges as JSON | See below |
| `--use-strategy-ranges` | Use strategy's defined min/max/step ranges | - |
| `--metric=` | Metric to optimize | `sharpe_ratio` |

**Parameter JSON Format:**

```json
{
  "fastPeriod": {"min": 5, "max": 20, "step": 5},
  "slowPeriod": {"min": 30, "max": 60, "step": 10}
}
```
**Note:** The `step` field is required for each parameter. When using `--use-strategy-ranges`, ensure your strategy's `#[Input]` attributes define `step` values.

**Optimization Metrics:**

- `sharpe_ratio` (default)
- `total_return` / `total_return_percent`
- `win_rate`
- `profit_factor`
- `max_drawdown` / `max_drawdown_percent` (lower is better)
- `sortino_ratio`
- `calmar_ratio`

**Examples:**

```bash
# Optimize using strategy's defined ranges
php artisan alphaforge:optimize sma_crossover BTCUSDT --use-strategy-ranges

# Optimize with explicit parameter ranges
php artisan alphaforge:optimize sma_crossover BTCUSDT \
    --params='{"fastPeriod":{"min":5,"max":20,"step":5},"slowPeriod":{"min":30,"max":60,"step":10}}'

# Optimize for different metric
php artisan alphaforge:optimize sma_crossover BTCUSDT --use-strategy-ranges --metric=total_return

# Optimize with date range
php artisan alphaforge:optimize sma_crossover BTCUSDT --use-strategy-ranges --start-date="2024-01-01" --end-date="2024-06-01"
```

#### `alphaforge:optimizations:list` - List Past Optimizations

List all past optimization runs.

```bash
php artisan alphaforge:optimizations:list [options]
```

**Arguments:**

This command has no required arguments.

**Options:**

| Option | Description | Default |
|--------|-------------|---------|
| `--strategy=` | Filter by strategy alias (e.g., `--strategy=sma_crossover`) | - |
| `--status=` | Filter by status: `pending`, `running`, `completed`, or `failed` | - |
| `--limit=` | Number of results to show | `20` |

**Example:**

```bash
# List all optimizations
php artisan alphaforge:optimizations:list

# List completed optimizations for a specific strategy
php artisan alphaforge:optimizations:list --strategy=sma_crossover --status=completed

# List most recent 5 optimizations
php artisan alphaforge:optimizations:list --limit=5
```

#### `alphaforge:optimizations:show` - Show Optimization Details

Show detailed results of an optimization run.

```bash
php artisan alphaforge:optimizations:show <optimization_id> [options]
```

**Arguments:**

| Argument | Description | Example |
|---------|-------------|---------|
| `optimization_id` | The optimization run ID (UUID) | `019d5725-3226-732b-9941-4e47a3350f93` |

**Options:**

| Option | Description | Default |
|--------|-------------|---------|
| `--top=` | Number of top results to display | `10` |

**Example:**

```bash
# Show optimization details
php artisan alphaforge:optimizations:show 019d5725-3226-732b-9941-4e47a3350f93

# Show top 20 results
php artisan alphaforge:optimizations:show 019d5725-3226-732b-9941-4e47a3350f93 --top=20
```

#### `alphaforge:optimizations:result` - Show Specific Optimization Result

Show a specific backtest result from within an optimization.

```bash
php artisan alphaforge:optimizations:result <backtest_id> [options]
```

**Arguments:**

| Argument | Description | Example |
|---------|-------------|---------|
| `backtest_id` | The backtest run ID (UUID) | `019d5725-3226-732b-9941-4e47a3350f93` |

**Options:**
| Option | Description |
|--------|-------------|
| `--show-positions` | Include positions in output |

**Example:**

```bash
# Show backtest result summary
php artisan alphaforge:optimizations:result 019d5725-3226-732b-9941-4e47a3350f93

# Show with positions
php artisan alphaforge:optimizations:result 019d5725-3226-732b-9941-4e47a3350f93 --show-positions
```

---

### Data Conversion Commands

#### `alphaforge:renko` - Convert to Renko Bricks (Fixed Brick Size)

Convert OHLC market data to Renko brick format with a fixed brick size.

```bash
php artisan alphaforge:renko <exchange> <market> <timeframe> <brick_size> [options]
```

**Arguments:**

| Argument | Description | Example |
|---------|-------------|---------|
| `exchange` | Exchange identifier | `binance` |
| `market` | Trading pair symbol | `BTC/USDT` |
| `timeframe` | Source timeframe | `1m`, `5m`, `1h`, `1d` |
| `brick_size` | Fixed brick size for Renko conversion | `10`, `100`, `0.001` |

**Options:**

| Option | Description |
|--------|-------------|
| `--force` | Force overwrite existing Renko file |
| `--update` | Incrementally update the Renko file by appending new converted data |

**Examples:**

```bash
# Create Renko chart with $100 brick size
php artisan alphaforge:renko binance BTC/USDT 1h 100

# Force overwrite
php artisan alphaforge:renko binance ETH/USDT 1h 50 --force

# Incrementally update an existing Renko file with new source data
php artisan alphaforge:renko binance BTC/USDT 1h 100 --update
```

#### `alphaforge:renkoAtr` - Convert to ATR-Based Renko Bricks

Convert OHLC market data to Renko brick format using a **dynamic brick size** derived from the Average True Range (ATR) indicator. Unlike fixed-brick Renko, the ATR-based approach automatically adapts brick sizes to market volatility.

```bash
php artisan alphaforge:renkoAtr <exchange> <market> <timeframe> <atr_period> [options]
```

**Arguments:**

| Argument | Description | Example |
|---------|-------------|---------|
| `exchange` | Exchange identifier | `binance` |
| `market` | Trading pair symbol | `BTC/USDT` |
| `timeframe` | Source timeframe | `1m`, `5m`, `1h`, `1d` |
| `atr_period` | ATR period for dynamic brick sizing (minimum 2) | `14`, `20` |

**Options:**

| Option | Description |
|--------|-------------|
| `--force` | Force overwrite existing ATR-Renko file |
| `--update` | Incrementally update the ATR-Renko file by appending new converted data |

**How it works:**

1. Reads the OHLC data and computes the ATR series using the PHP Trader extension (`trader_atr`)
2. The ATR value at each bar becomes the dynamic brick size for that bar
3. During the ATR warmup period (first `atr_period` bars), the first valid ATR value is used
4. Applies the same high-low Renko logic as fixed-brick Renko, but with varying brick sizes
5. Output is stored as a `.stchx` file with `dataType=4` (ATR-Renko) and the ATR period stored in the `brickSize` header field

**Requirements:**

- The PHP Trader extension must be installed (`pecl install trader`)
- Source OHLC data must contain at least `atr_period` records

**Examples:**

```bash
# Create ATR-Renko chart with 14-period ATR
php artisan alphaforge:renkoAtr binance BTC/USDT 1h 14

# Create ATR-Renko with 20-period ATR, force overwrite
php artisan alphaforge:renkoAtr binance ETH/USDT 1h 20 --force

# Incrementally update an existing ATR-Renko file with new source data
php artisan alphaforge:renkoAtr binance BTC/USDT 1h 14 --update

# Use 1m source data for finer granularity
php artisan alphaforge:renkoAtr binance BTC/USDT 1m 14
```

**Fixed-brick vs. ATR-based Renko:**

| Feature | `alphaforge:renko` (Fixed) | `alphaforge:renkoAtr` (ATR) |
|---------|----------------------|------------------------|
| Brick size | Constant (user-specified) | Dynamic (ATR-derived) |
| Volatility adaptation | None — same size in all conditions | Automatic — larger bricks in volatile markets, smaller in quiet markets |
| Input parameter | Brick size (e.g., `100`) | ATR period (e.g., `14`) |
| PHP Trader extension | Not required | Required |
| Header `dataType` | `3` (Renko) | `4` (ATR-Renko) |
| Header `brickSize` field | The fixed brick size | The ATR period |

#### `alphaforge:heikenashi` - Convert to Heiken-Ashi

Convert OHLC market data to Heiken-Ashi candle format.

```bash
php artisan alphaforge:heikenashi <exchange> <market> <timeframe> [options]
```

**Arguments:**

| Argument | Description | Example |
|---------|-------------|---------|
| `exchange` | Exchange identifier | `binance` |
| `market` | Trading pair symbol | `BTC/USDT` |
| `timeframe` | Source timeframe | `1m`, `5m`, `1h`, `1d` |

**Options:**

| Option | Description |
|--------|-------------|
| `--force` | Force overwrite existing Heiken-Ashi file |
| `--update` | Incrementally update the Heiken-Ashi file by appending new converted data |

**Example:**

```bash
# Convert to Heiken-Ashi
php artisan alphaforge:heikenashi binance BTC/USDT 1h

# Force overwrite
php artisan alphaforge:heikenashi binance BTC/USDT 1h --force

# Incrementally update an existing Heiken-Ashi file with new source data
php artisan alphaforge:heikenashi binance BTC/USDT 1h --update
```

---

### Analysis Commands

#### `alphaforge:analysis:opencross` - Open-Cross Probability Analysis

Analyze Open-Cross probability for intraday price movements.

```bash
php artisan alphaforge:analysis:opencross <exchange> <market> <timeframe> [options]
```

**Arguments:**

| Argument | Description | Example |
|---------|-------------|---------|
| `exchange` | Exchange identifier | `binance` |
| `market` | Trading pair symbol | `BTC/USDT` |
| `timeframe` | Source timeframe (must be 1m) | `1m` |

**Options:**

| Option | Description | Default |
|--------|-------------|---------|
| `--block=` | Block duration in minutes | `15` |
| `--bucket=` | Distance bucket size as decimal | `0.001` |
| `--min-samples=` | Minimum samples for high confidence | `100` |
| `--use-close` | Use close price instead of current price | - |
| `--symmetric` | Merge positive/negative buckets by absolute value | - |
| `--volatility-normalized` | Normalize distance by rolling volatility | - |
| `--volatility-lookback=` | Lookback period for volatility | `60` |
| `--startdate=` | Start date (Y-m-d or Y-m-d H:i:s) | - |
| `--enddate=` | End date (Y-m-d or Y-m-d H:i:s) | - |
| `--trim-zeros` | Trim trailing zero-probability buckets | - |
| `--max-distance=` | Limit display to ±N buckets | - |
| `--output=` | Output format (`table`, `json`, `csv`, `html`, `heatmap`, `summary`) | `table` |
| `--save=` | Optional path to save results | - |
| `--width=` | Width for ASCII graph output | `80` |

**Example:**

```bash
# Basic analysis
php artisan alphaforge:analysis:opencross binance BTC/USDT 1m

# With volatility normalization
php artisan alphaforge:analysis:opencross binance BTC/USDT 1m --volatility-normalized --volatility-lookback=120

# Output as JSON
php artisan alphaforge:analysis:opencross binance BTC/USDT 1m --output=json --save=results.json

# Symmetric mode with larger block
php artisan alphaforge:analysis:opencross binance BTC/USDT 1m --block=60 --symmetric
```

#### `alphaforge:analysis:opencross-validate` - Statistical Validation

Run statistical validation tests on Open-Cross Probability analysis.

```bash
php artisan alphaforge:analysis:opencross-validate <exchange> <market> <timeframe> [options]
```

**Arguments:**

| Argument | Description | Example |
|---------|-------------|---------|
| `exchange` | Exchange identifier | `binance` |
| `market` | Trading pair symbol | `BTC/USDT` |
| `timeframe` | Source timeframe (must be 1m) | `1m` |

**Options:**

| Option | Description | Default |
|--------|-------------|---------|
| `--train-start=` | Training period start date (Y-m-d) | - |
| `--train-end=` | Training period end date (Y-m-d) | - |
| `--test-start=` | Test period start date (Y-m-d) | - |
| `--test-end=` | Test period end date (Y-m-d) | - |
| `--block=` | Block duration in minutes | `15` |
| `--bucket=` | Distance bucket size | `0.001` |
| `--min-samples=` | Minimum samples | `100` |
| `--volatility-normalized` | Use volatility normalization | - |
| `--volatility-lookback=` | Volatility lookback period | `20` |
| `--symmetric` | Merge symmetric buckets | - |
| `--rolling-window=` | Rolling window in months | `6` |
| `--rolling-step=` | Rolling step in months | `1` |
| `--calibration-bin=` | Calibration bin width | `0.05` |
| `--regime-classifier=` | Regime classification method | `volatility_percentile` |
| `--regime-threshold=` | Regime classification threshold | `0.7` |
| `--random-iterations=` | Randomization iterations | `10` |
| `--simulation-threshold=` | Strategy simulation threshold | `0.7` |
| `--tests=` | Comma-separated tests to run | `all` |
| `--output=` | Output format (`json`, `csv`, `markdown`, `all`) | `json` |
| `--save=` | Path to save results | - |
| `--verbose` | Show detailed progress | - |

**Example:**

```bash
# Basic validation
php artisan alphaforge:analysis:opencross-validate binance BTC/USDT 1m

# With train/test split
php artisan alphaforge:analysis:opencross-validate binance BTC/USDT 1m \
    --train-start="2023-01-01" --train-end="2023-06-01" \
    --test-start="2023-06-01" --test-end="2024-01-01"

# Save results
php artisan alphaforge:analysis:opencross-validate binance BTC/USDT 1m --save=validation_results.json
```

---

## Configuration

### Configuration File

The main configuration is in `config/alphaforge.php`:

```php
return [
    'defaults' => [
        'bc_scale' => 12,           // BCMath decimal precision
        'trading_days_per_year' => 252,
        'risk_free_rate' => '0.02',  // 2% annual risk-free rate
    ],
    
    'storage' => [
        'market_data_path' => storage_path('app/market'),
        'backtest_results_path' => storage_path('app/backtests'),
        'cache_path' => storage_path('app/cache/stochastix'),
    ],
    
    'queues' => [
        'backtest' => 'backtests',
        'download' => 'downloads',
    ],
    
    'strategies' => [
        'path' => app_path('AlphaForge/Strategy/Concretes'),
        'namespace' => 'App\\AlphaForge\\Strategy\\Concretes',
    ],
];
```

### Environment Variables

Add to your `.env`:

```env
# Queue connection (redis recommended)
QUEUE_CONNECTION=redis

# Broadcasting (reverb for WebSockets)
BROADCAST_CONNECTION=reverb

# Database
DB_CONNECTION=mysql
```

## Database Migrations

Run migrations to create the required tables:

```bash
php artisan migrate
```

### Tables Created

1. **backtest_runs**: Stores backtest configurations and results
   - `id` (UUID)
   - `user_id` (nullable foreign key)
   - `optimization_id` (nullable foreign key to optimization_runs)
   - `strategy_alias`
   - `symbols` (JSON array)
   - `timeframe`
   - `execution_timeframe` (nullable — lower timeframe for dual-TF execution)
   - `exchange`
   - `initial_capital`
   - `final_capital`
   - `strategy_inputs` (JSON - parameters used)
   - `statistics` (JSON - computed metrics)
   - `status` (pending/running/completed/failed)

2. **optimization_runs**: Stores optimization run metadata
   - `id` (UUID)
   - `user_id` (nullable foreign key)
   - `strategy_alias`
   - `symbols` (JSON array)
   - `timeframe`
   - `exchange`
   - `parameter_ranges` (JSON - what was scanned)
   - `optimization_metric` (what was optimized)
   - `total_combinations` / `completed_combinations`
   - `best_parameters` (JSON)
   - `best_statistics` (JSON)
   - `status`

3. **market_data_downloads**: Tracks market data download jobs
   - `id` (UUID)
   - `user_id`
   - `symbol`
   - `timeframe`
   - `exchange`
   - `status`
   - `file_path`
   - `bars_count`

## API Endpoints

### Strategies

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/stochastix/strategies` | List all available strategies |
| GET | `/api/stochastix/strategies/{alias}` | Get strategy details |

### Backtests

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/stochastix/backtests` | List backtest runs |
| POST | `/api/stochastix/backtests` | Queue a new backtest |
| GET | `/api/stochastix/backtests/{id}` | Get backtest details |
| DELETE | `/api/stochastix/backtests/{id}` | Cancel pending backtest |
| GET | `/api/stochastix/backtests/{id}/statistics` | Get backtest statistics |

### Data Acquisition

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/stochastix/data/exchanges` | List all supported exchanges |
| GET | `/api/stochastix/data/symbols/{exchangeId}` | Get futures symbols for an exchange |
| POST | `/api/stochastix/data/download` | Queue a market data download |
| DELETE | `/api/stochastix/data/download/{jobId}` | Cancel a running download |
| GET | `/api/stochastix/data/inspect/{exchange}/{symbol}/{timeframe}` | Inspect stored data |
| GET | `/api/stochastix/data-availability` | Get manifest of all stored data |

### Example: Running a Backtest

```bash
curl -X POST http://localhost:8000/api/stochastix/backtests \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{
    "strategy": "sma_crossover",
    "symbols": ["BTCUSDT"],
    "timeframe": "1h",
    "exchange": "binance",
    "initial_capital": 10000,
    "stake_currency": "USDT",
    "strategy_inputs": {
      "fastPeriod": 10,
      "slowPeriod": 50
    },
    "commission_config": {
      "type": "percentage",
      "rate": 0.1
    },
    "start_date": "2023-01-01",
    "end_date": "2024-01-01"
  }'
```

### Example: Running a Dual-Timeframe Backtest

```bash
curl -X POST http://localhost:8000/api/stochastix/backtests \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{
    "strategy": "sma_crossover",
    "symbols": ["BTCUSDT"],
    "timeframe": "1h",
    "execution_timeframe": "1m",
    "exchange": "binance",
    "initial_capital": 10000,
    "stake_currency": "USDT",
    "start_date": "2023-01-01",
    "end_date": "2024-01-01"
  }'
```

## Creating Strategies

### Strategy Interface

All strategies must implement `StrategyInterface`:

```php
interface StrategyInterface
{
    public function configure(array $inputs): void;
    public function onBar(array $data): array;
}
```

### Strategy Attribute

Use the `AsStrategy` attribute to define metadata:

```php
use App\Stochastix\Strategy\Attribute\AsStrategy;
use App\Stochastix\Strategy\Attribute\Input;
use App\Stochastix\Strategy\StrategyInterface;
use App\Stochastix\Common\Enum\TimeframeEnum;

#[AsStrategy(
    alias: 'sma_crossover',
    name: 'SMA Crossover',
    description: 'Simple Moving Average crossover strategy',
    timeframe: TimeframeEnum::H1,
    requiredMarketData: [TimeframeEnum::H1, TimeframeEnum::H4]
)]
class SmaCrossover implements StrategyInterface
{
    #[Input(
        description: 'Fast SMA period',
        min: 5,
        max: 50,
        step: 5
    )]
    private int $fastPeriod = 10;
    
    #[Input(
        description: 'Slow SMA period',
        min: 20,
        max: 200,
        step: 10
    )]
    private int $slowPeriod = 50;
    
    public function configure(array $inputs): void
    {
        if (isset($inputs['fastPeriod'])) {
            $this->fastPeriod = (int) $inputs['fastPeriod'];
        }
        if (isset($inputs['slowPeriod'])) {
            $this->slowPeriod = (int) $inputs['slowPeriod'];
        }
    }
    
    public function onBar(array $data): array
    {
        $signals = [];
        $ohlcv = $data['ohlcv'];
        $cursor = $data['cursor'];
        $portfolio = $data['portfolio'];
        
        // Calculate SMAs and generate signals
        // ...
        
        return $signals;
    }
}
```

### Input Attribute Properties

The `#[Input]` attribute supports the following properties for parameter optimization:

| Property | Type | Description |
|----------|------|-------------|
| `description` | `?string` | Human-readable description |
| `min` | `?float` | Minimum value for optimization |
| `max` | `?float` | Maximum value for optimization |
| `step` | `?mixed` | Step size for grid search (e.g., 5 for testing 5, 10, 15...) |
| `choices` | `?array` | Specific values to test |
| `arrayType` | `?string` | Class name for array item type |
| `minChoices` | `?int` | Minimum array items |
| `maxChoices` | `?int` | Maximum array items |

## Broadcasting Events

### Backtest Progress

Progress is broadcast on two channels:

1. `PresenceChannel: backtest.{id}` - For the specific backtest
2. `Channel: user.{userId}.backtests` - For user's backtest list

Event payload:
```json
{
    "backtest_id": "uuid",
    "percent": 50,
    "message": "Processing bar 500 of 1000",
    "timestamp": "2024-01-15T10:30:00Z"
}
```

### Frontend Integration

Using Laravel Echo:

```javascript
import Echo from 'laravel-echo';

const echo = new Echo({
    broadcaster: 'reverb',
    // ... configuration
});

// Subscribe to backtest progress
echo.private(`backtest.${backtestId}`)
    .listen('BacktestProgress', (e) => {
        console.log(`${e.percent}%: ${e.message}`);
    });

// Subscribe to download progress
echo.private(`download.${jobId}`)
    .listen('DownloadProgress', (e) => {
        console.log(`${e.percent_complete}%: ${e.records_in_batch} records`);
    });
```

## Queue Configuration

### Dedicated Queues

The system uses dedicated queues for different job types:

- `backtests` queue: For `RunBacktestJob`
- `downloads` queue: For market data downloads

### Queue Workers

Start queue workers:

```bash
# Process backtest jobs
php artisan queue:work --queue=backtests

# Process download jobs
php artisan queue:work --queue=downloads

# Process all queues
php artisan queue:work
```

## Statistics Calculated

The `StatisticsService` calculates the following metrics:

### Capital Metrics
- Initial Capital
- Final Capital
- Total Return
- Total Return %

### Time Metrics
- Trading Days
- CAGR (Compound Annual Growth Rate)

### Trade Metrics
- Total Trades
- Winning Trades
- Losing Trades
- Win Rate
- Gross Profit
- Gross Loss
- Net Profit
- Profit Factor
- Average Win
- Average Loss
- Largest Win
- Largest Loss

### Risk Metrics
- Max Drawdown
- Max Drawdown %
- Average Drawdown
- Max Drawdown Duration

### Risk-Adjusted Metrics
- Sharpe Ratio
- Sortino Ratio
- Calmar Ratio
- Volatility (Annualized)

### Trade Analysis
- Average Trade Duration
- Max Consecutive Wins
- Max Consecutive Losses
- Expectancy
- Long/Short Trade Counts
- Long/Short Win Rates

## Binary Market Data Format

Market data is stored in `.stchx` binary files:

```
Header (64 bytes):
- Magic bytes: "STCHX" (5 bytes)
- Version: uint8
- Symbol: string[32]
- Timeframe: string[8]
- Exchange: string[16]
- Reserved: 2 bytes
- numRecords: uint32

Data (per bar, 48 bytes):
- Timestamp: int64
- Open: float64
- High: float64
- Low: float64
- Close: float64
- Volume: float64
```

### Data Types

The `dataType` field in the header distinguishes file formats:

| dataType | Format | Description |
|----------|--------|-------------|
| 1 | OHLCV | Standard candlestick data |
| 2 | Heiken-Ashi | Heiken-Ashi smoothed candles |
| 3 | Renko | Fixed-brick Renko data (`brickSize` = fixed size) |
| 4 | ATR-Renko | ATR-based Renko data (`brickSize` = ATR period) |

## Testing

Run the test suite:

```bash
# Run all tests
php artisan test

# Run with Pest
./vendor/bin/pest

# Run specific test file
./vendor/bin/pest tests/Unit/AlphaForge/Common/Enum/TimeframeEnumTest.php
```

### Test Categories

- **Unit Tests**: Enum tests, Math utility tests, Series model tests
- **Feature Tests**: Controller tests, Job tests, Service tests

## Dependencies

### Required PHP Extensions

- `bcmath` - Arbitrary precision math
- `ds` - Data structures (Vector, Map)
- `json` - JSON handling
- `trader` - TA-Lib functions (required for ATR-based Renko conversion)

### Composer Packages

- `laravel/framework` ^12.0
- `laravel/reverb` - WebSocket broadcasting
- `nesbot/carbon` - Date handling
- `ccxt/ccxt` - Exchange integration (optional, for data acquisition)

## Service Provider

The `StochastixServiceProvider` handles:

1. **Configuration Merging**: Loads default configuration
2. **Service Binding**: Binds interfaces to implementations
3. **Route Loading**: Loads API routes
4. **Migration Loading**: Loads database migrations
5. **Command Registration**: Registers all artisan commands
6. **Storage Initialization**: Creates required directories

## License

MIT License

## Credits

Ported from Symfony Stochastix to Laravel 12 by the development team.
