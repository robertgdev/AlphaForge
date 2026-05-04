# Intraday Open-Cross Probability Analysis

## Overview

The Open-Cross Probability Engine is a statistical tool that computes the probability that price will cross back over the opening price of a fixed time block before the block closes. This analysis produces a **probability surface** that can be used for:

- Understanding mean-reversion tendencies in intraday price movements
- Quantifying the probability of price returning to the opening level
- Identifying optimal entry/exit points based on distance from open and time remaining
- Building trading strategies that exploit first-passage probability

## What It Calculates

### The Core Question

> "Given that price is currently X% away from the block open with Y minutes remaining, what is the probability that price will cross back over the open before the block closes?"

### Probability Surface

The engine produces a two-dimensional probability surface:

```
P(cross | distance_bucket, minutes_remaining)
```

Where:
- **distance_bucket**: The current price distance from the block open, bucketed into fixed-width intervals
- **minutes_remaining**: The number of minutes left in the current time block
- **P(cross)**: The empirical probability (based on historical data) that price will cross the open

### Example Interpretation

| Distance from Open | Minutes Remaining | Cross Probability | Sample Size |
|-------------------|-------------------|-------------------|-------------|
| -0.5% (below)     | 14                | 95%               | 1,234       |
| -0.3% (below)     | 10                | 72%               | 1,890       |
| +0.2% (above)     | 10                | 48%               | 1,654       |
| +0.5% (above)     | 2                 | 14%               | 892         |

**Interpretation**: When price is 0.5% below the block open with 14 minutes remaining, there's a 95% historical probability that price will cross back above the open before the block ends.

## How It Works

### 1. Block Partitioning

The engine partitions 1-minute OHLCV data into non-overlapping time blocks:

```
For 15-minute blocks:
[00:00-00:14] [00:15-00:29] [00:30-00:44] [00:45-00:59] ...
```

Each block is processed independently:
- **Block Open**: The open price of the first minute in the block
- **Block Close**: The end of the block (not a price)

### 2. Distance Calculation

For each minute within a block, the distance from the block open is calculated:

```
distance_pct = (current_price - block_open) / block_open
```

When **volatility normalization** is enabled:

```
distance_sigma = (current_price - block_open) / volatility
```

Where `volatility` is the rolling Average True Range (ATR) normalized by price.

### 3. Crossing Detection

A **cross** occurs when price moves from one side of the open to the other:

```
If price is ABOVE open (distance > 0):
  Cross = future_low < block_open

If price is BELOW open (distance < 0):
  Cross = future_high > block_open
```

The crossing must occur **strictly after** the current minute and **before** the block ends.

### 4. O(n) Algorithm - Backward Scan

The key performance optimization is the backward scan for computing future ranges:

```php
// Traverse backwards through the block
$futureMinLow = PHP_FLOAT_MAX;
$futureMaxHigh = PHP_FLOAT_MIN;

for ($i = $blockLength - 1; $i >= 0; $i--) {
    // Store future extremes BEFORE including current candle
    $records[$i]['future_min_low'] = $futureMinLow;
    $records[$i]['future_max_high'] = $futureMaxHigh;
    
    // Update running extremes
    $futureMinLow = min($futureMinLow, $currentLow);
    $futureMaxHigh = max($futureMaxHigh, $currentHigh);
}
```

This gives us O(n) complexity per block instead of O(n²) from nested forward scanning.

### 5. Statistics Accumulation

For each minute in each block:

1. Calculate distance from block open
2. Bucket the distance into fixed-width intervals
3. Calculate minutes remaining in block
4. Determine if a cross occurred in the future
5. Record the observation in the statistics accumulator

The accumulator maintains counts:

```
stats[distance_bucket][minutes_remaining] = {
    total: N,
    crosses: M
}
```

### 6. Probability Calculation

Final probability for each bucket:

```
cross_probability = crosses / total
```

Confidence levels are assigned based on sample size:
- **High**: n ≥ 100 samples
- **Medium**: n ≥ 30 samples
- **Low**: n < 30 samples

## Configuration Options

### Block Duration (`--block`)

The length of each time block in minutes. Common values:
- **5 minutes**: High-frequency analysis
- **15 minutes**: Standard intraday blocks
- **60 minutes**: Hourly analysis

### Bucket Size (`--bucket`)

The width of distance buckets as a decimal:
- **0.001** (0.1%): Fine-grained analysis
- **0.005** (0.5%): Medium granularity
- **0.01** (1%): Coarse analysis

### Volatility Normalization (`--volatility-normalized`)

When enabled, distance is measured in standard deviations (sigma) rather than percentage:

```
distance_sigma = (price - open) / ATR
```

This makes the analysis comparable across different volatility regimes.

#### Why Use Volatility Normalization?

**The Problem with Raw Percentage Distance:**

Consider two scenarios:
- **Scenario A**: BTC/USDT moves 0.5% away from open during a low-volatility period (ATR = 0.3%)
- **Scenario B**: BTC/USDT moves 0.5% away from open during a high-volatility period (ATR = 1.2%)

Both show the same 0.5% distance, but they represent very different market conditions:
- In Scenario A, 0.5% is a **significant move** (1.67× the ATR) - less likely to cross back
- In Scenario B, 0.5% is a **minor fluctuation** (0.42× the ATR) - more likely to cross back

**The Solution - Sigma-Based Distance:**

With volatility normalization:
- Scenario A: distance_sigma = 0.5% / 0.3% = **1.67 sigma**
- Scenario B: distance_sigma = 0.5% / 1.2% = **0.42 sigma**

Now the probability surface reflects the true statistical significance of the price move, not just its raw magnitude.

#### How It Changes the Results

**Without Volatility Normalization:**
```
Distance Bucket    Cross Probability
-0.5%              65%
-0.3%              78%
-0.1%              92%
```

**With Volatility Normalization (bucket = 0.5 sigma):**
```
Distance Bucket    Cross Probability
-2.0 sigma         35%    (extreme move, unlikely to revert)
-1.0 sigma         58%    (moderate move)
-0.5 sigma         82%    (minor move, likely to revert)
```

The sigma-based buckets group observations by their **statistical significance** rather than raw price change.

#### When to Use It

| Use Volatility Normalization When... | Use Raw Percentage When... |
|-------------------------------------|---------------------------|
| Analyzing multiple assets with different volatilities | Analyzing a single asset |
| Comparing results across different time periods | Building simple rules |
| Building volatility-aware strategies | Quick analysis |
| You want statistically meaningful distances | You care about actual P&L amounts |

### Volatility Lookback (`--volatility-lookback`)

The number of periods used to calculate the rolling Average True Range (ATR). Default: 20.

#### How ATR is Calculated

For each candle, the **True Range (TR)** is calculated as:

```
TR = max(
    high - low,
    |high - previous_close|,
    |low - previous_close|
)
```

The **ATR** is then a rolling average of True Range values:

```
ATR = (Previous_ATR × (n-1) + Current_TR) / n
```

Where `n` is the lookback period.

#### Impact of Lookback Period

| Lookback | Characteristics | Best For |
|----------|----------------|----------|
| **5-10** | Fast-reacting, noisy | High-frequency trading, detecting sudden volatility changes |
| **14-20** | Balanced, standard | General-purpose analysis (default: 20) |
| **30-50** | Slow, stable | Long-term volatility regime detection |
| **50+** | Very smooth, lagging | Position trading, ignoring short-term noise |

#### Example Impact on Results

**Short Lookback (10 periods):**
- Quickly adapts to volatility spikes
- May overreact to single large candles
- Distance sigma can be unstable

**Long Lookback (50 periods):**
- Stable volatility estimate
- Ignores short-term volatility bursts
- May be too slow to detect regime changes

#### Choosing the Right Lookback

```
Rule of thumb: lookback ≈ block_minutes × 2

For 15-minute blocks:  lookback = 20-30
For 5-minute blocks:   lookback = 10-15
For 60-minute blocks:  lookback = 50-100
```

This ensures the volatility estimate captures approximately 2-3 blocks of history.

### Symmetric Merge (`--symmetric`)

When enabled, positive and negative distance buckets are merged by absolute value:

```
Original:  -0.3%, -0.2%, -0.1%, +0.1%, +0.2%, +0.3%
Merged:    0.1%, 0.2%, 0.3% (absolute values)
```

This doubles the sample size per bucket but loses directional information.

### Trim Zeros (`--trim-zeros`)

Automatically trims trailing zero-probability buckets from terminal display. This is useful because:

1. **Large distances have near-zero cross probability**: When price moves significantly far from the block open, the probability of crossing back before the block ends becomes essentially zero.

2. **Output readability**: Without trimming, the heatmap may show dozens of zero-probability buckets that aren't useful for analysis.

**How it works:**
- Scans the probability surface to find the furthest bucket from zero that has any non-zero probability (>0.1%)
- Keeps a 5-bucket margin beyond that point
- Only affects terminal display (heatmap, summary, table) - JSON/CSV exports contain the full dataset

**Example:**

Without `--trim-zeros`:
```
Distance ↓     14    13    12    11    10 ...
 +5.00%         ░     ░     ░     ░     ░  ← All zeros
 +4.00%         ░     ░     ░     ░     ░  ← All zeros
 +3.00%         ▒     ▒     ▒     ▒     ▒  ← First non-zero
 +2.00%         ▓     ▓     ▓     ▓     ▓
 ...
```

With `--trim-zeros`:
```
Distance ↓     14    13    12    11    10 ...
 +3.00%         ▒     ▒     ▒     ▒     ▒  ← Trimmed to meaningful range
 +2.00%         ▓     ▓     ▓     ▓     ▓
 +1.00%         █     █     █     █     █
 ...
```

### Max Distance (`--max-distance=N`)

Explicitly limits the display to ±N buckets from zero. This gives you direct control over the distance range shown.

**Example:**
```bash
# Show only ±5 buckets from zero
php artisan analysis:opencross binance BTC/USDT 1m --max-distance=5
```

With `--bucket=0.001` (0.1% buckets), `--max-distance=5` would show:
```
Distance range: -0.5%, -0.4%, -0.3%, -0.2%, -0.1%, 0%, +0.1%, +0.2%, +0.3%, +0.4%, +0.5%
```

**Combining with `--trim-zeros`:**

Both options can be used together:
- `--max-distance` is applied first (hard limit)
- `--trim-zeros` then refines within that range (adaptive limit)

```bash
# Limit to ±10 buckets, then trim any trailing zeros within that range
php artisan analysis:opencross binance BTC/USDT 1m --max-distance=10 --trim-zeros
```

### Date Range Filtering (`--startdate` and `--enddate`)

Limit the analysis to a specific date range. Both options accept dates in `Y-m-d` or `Y-m-d H:i:s` format.

**Examples:**

```bash
# Analyze only data from 2024
php artisan analysis:opencross binance BTC/USDT 1m --startdate=2024-01-01 --enddate=2024-12-31

# Analyze from a specific date onwards
php artisan analysis:opencross binance BTC/USDT 1m --startdate=2024-06-01

# Analyze up to a specific date
php artisan analysis:opencross binance BTC/USDT 1m --enddate=2024-06-30

# Analyze a specific month with datetime precision
php artisan analysis:opencross binance BTC/USDT 1m \
    --startdate=2024-06-01 \
    --enddate=2024-06-30
```

**Behavior:**
- `--startdate`: Includes records from 00:00:00 of the specified date
- `--enddate`: Includes records up to 23:59:59 of the specified date
- If only date is provided (no time), the time defaults to start of day for `--startdate` and end of day for `--enddate`
- If both are specified, `--startdate` must be before or equal to `--enddate`
- Date filtering is applied before block partitioning, so partial blocks at the boundaries are handled correctly

## Output Formats

### JSON Output

```json
{
  "metadata": {
    "exchange": "binance",
    "market": "BTC/USDT",
    "block_minutes": 15,
    "bucket_size": 0.001,
    "volatility_normalized": false
  },
  "summary": {
    "total_blocks_analyzed": 4320,
    "total_observations": 64800
  },
  "probability_surface": [
    {
      "distance_bucket": -0.005,
      "minutes_remaining": 14,
      "samples": 1523,
      "cross_probability": 0.847,
      "confidence": "high"
    }
  ]
}
```

### ASCII Heatmap

```
Open-Cross Probability Heatmap
Block: 15 min | Bucket: 0.10% | Samples: 64,800

Distance ↓   14    12    10     8     6     4     2     0
─────────────────────────────────────────────────────────────
 -0.5%    [████████████████████] 95%  (n=1,234)
 -0.4%    [██████████████████  ] 87%  (n=1,456)
 -0.3%    [████████████████    ] 76%  (n=1,678)
 -0.2%    [██████████████      ] 65%  (n=1,890)
 -0.1%    [████████████        ] 52%  (n=2,012)
  0.0%    [                    ] N/A  (at open)
 +0.1%    [████████████        ] 48%  (n=1,987)
 +0.2%    [██████████          ] 38%  (n=1,876)
 +0.3%    [████████            ] 29%  (n=1,654)
 +0.4%    [██████              ] 21%  (n=1,432)
 +0.5%    [████                ] 14%  (n=1,210)

Legend: ░ = 0-20%  ▒ = 20-40%  ▓ = 40-60%  █ = 60-100%
```

### Cross-Section Chart

```
Cross-Section at 10 Minutes Remaining
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

Probability
  100% │
       │
   80% │     ╭──────╮
       │    ╱        ╲
   60% │   ╱          ╲
       │  ╱            ╲
   40% │ ╱              ╲
       │╱                ╲
   20% │                  ╲___
       │                      ╲____
    0% └─────────────────────────────── Distance from Open
       -0.5%  -0.3%  -0.1%  +0.1%  +0.3%  +0.5%

       ● = high confidence (n≥100)  ○ = low confidence (n<100)
```

## Interpreting the Results

### Key Patterns to Look For

1. **Asymmetric Cross Probabilities**
   - Price below open often has higher cross probability than price above open
   - This reflects the "gravity" effect - prices tend to revert to mean

2. **Time Decay**
   - Cross probability decreases as minutes remaining decreases
   - Less time = less opportunity for price to cross back

3. **Distance Effect**
   - Larger distances from open typically have lower cross probabilities
   - But this depends on the asset's typical volatility

4. **Volatility Regime**
   - When volatility-normalized, the surface should be more stable across different market conditions

### Practical Applications

1. **Mean-Reversion Strategies**
   - Enter when cross probability is high (e.g., >70%)
   - Exit when probability drops below threshold

2. **Stop-Loss Placement**
   - If cross probability is low, price may not return to open
   - Consider tighter stops when probability is low

3. **Position Sizing**
   - Size positions based on cross probability confidence
   - Higher probability = larger position (within risk limits)

4. **Time-Based Decisions**
   - Avoid entering late in a block when cross probability is low
   - Close positions before block end if probability drops significantly

## Mathematical Foundation

This engine estimates an **empirical approximation of first-passage probability** for a stochastic intraday price process with finite time horizon.

### First Passage Time

In continuous-time finance, the first passage time (or hitting time) is the time at which a stochastic process first reaches a given boundary. For a price process P(t):

```
τ = inf{t > 0 : P(t) = barrier}
```

### Empirical Estimation

Rather than making parametric assumptions (e.g., Brownian motion), this engine:

1. Partitions historical data into independent blocks
2. Observes whether the barrier (block open) was crossed
3. Estimates probability directly from observed frequencies

This non-parametric approach:
- Captures real market microstructure effects
- Avoids model misspecification
- Adapts to any asset or timeframe

### Limitations

1. **Stationarity Assumption**: Assumes the probability distribution is stable over time
2. **Independence**: Treats blocks as independent (may not hold in trending markets)
3. **Sample Size**: Rare events (extreme distances) may have few samples
4. **Regime Changes**: Market regime shifts may invalidate historical patterns

## Performance Characteristics

- **Time Complexity**: O(n) where n is the number of records
- **Space Complexity**: O(n) for storing records and volatility calculations
- **Target Performance**: 500,000 records in < 3 seconds

## Command Reference

```bash
# Basic usage
php artisan analysis:opencross binance BTC/USDT 1m --block=15 --bucket=0.001

# With volatility normalization
php artisan analysis:opencross binance BTC/USDT 1m \
    --block=15 \
    --volatility-normalized \
    --volatility-lookback=20

# Trim zero-probability buckets for cleaner output
php artisan analysis:opencross binance BTC/USDT 1m --trim-zeros

# Limit to ±5 buckets from zero
php artisan analysis:opencross binance BTC/USDT 1m --max-distance=5

# Combine both options
php artisan analysis:opencross binance BTC/USDT 1m --trim-zeros --max-distance=10

# Analyze a specific date range
php artisan analysis:opencross binance BTC/USDT 1m \
    --startdate=2024-01-01 \
    --enddate=2024-12-31

# Combine date range with other options
php artisan analysis:opencross binance BTC/USDT 1m \
    --startdate=2024-06-01 \
    --enddate=2024-06-30 \
    --volatility-normalized \
    --trim-zeros

# Export results
php artisan analysis:opencross binance BTC/USDT 1m \
    --output=json \
    --save=analysis.json

# Different output formats
php artisan analysis:opencross binance BTC/USDT 1m --output=heatmap
php artisan analysis:opencross binance BTC/USDT 1m --output=summary
php artisan analysis:opencross binance BTC/USDT 1m --output=csv
```

### All Command Options

| Option | Default | Description |
|--------|---------|-------------|
| `exchange` | (required) | Exchange identifier (e.g., binance, kraken) |
| `market` | (required) | Trading pair symbol (e.g., BTC/USDT) |
| `timeframe` | (required) | Source timeframe (must be 1m) |
| `--block` | 15 | Block duration in minutes |
| `--bucket` | 0.001 | Distance bucket size as decimal |
| `--min-samples` | 100 | Minimum samples for high confidence |
| `--use-close` | false | Use close price for distance calculation |
| `--symmetric` | false | Merge positive/negative buckets by absolute value |
| `--volatility-normalized` | false | Normalize distance by rolling volatility |
| `--volatility-lookback` | 20 | Lookback period for ATR calculation |
| `--startdate` | (none) | Start date for analysis (Y-m-d or Y-m-d H:i:s) |
| `--enddate` | (none) | End date for analysis (Y-m-d or Y-m-d H:i:s) |
| `--trim-zeros` | false | Trim trailing zero-probability buckets from display |
| `--max-distance` | (none) | Limit display to ±N buckets from zero |
| `--output` | table | Output format (table, json, csv, heatmap, summary) |
| `--save` | (none) | Path to save results |
| `--width` | 80 | Width for ASCII graph output |

## Related Concepts

- **Mean Reversion**: The tendency of prices to return to their average
- **First Passage Time**: Time until a stochastic process hits a boundary
- **Barrier Options**: Options whose payoff depends on whether price crosses a barrier
- **ATR (Average True Range)**: A measure of market volatility
- **O(n) Algorithm**: Linear time complexity - processing time scales linearly with data size
