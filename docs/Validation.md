# Statistical Validation & Robustness Framework

## Intraday Open-Cross Probability Engine – Phase 2

---

## Overview

The Statistical Validation Framework extends the Open-Cross Probability Engine with comprehensive tools to ensure that the probability surface represents persistent market structure rather than historical noise.

### Key Validation Components

1. **Chronological Train/Test Evaluation** - Out-of-sample testing with strict temporal ordering
2. **Probability Calibration Testing** - Verify predicted probabilities match observed frequencies
3. **Rolling-Window Stability Testing** - Ensure surface stability across time
4. **Regime Sensitivity Analysis** - Test sensitivity to volatility regimes
5. **Sample Size & Uncertainty Estimation** - Statistical confidence for each bucket
6. **Randomized Baseline Comparison** - Detect structural deviation from randomness
7. **Cross-Period Surface Comparison** - Evaluate long-term structural persistence
8. **Strategy-Level Simulation Testing** - Test actionable signal quality

---

## Installation

The validation framework is automatically registered with Laravel. No additional configuration is required.

---

## Command Usage

### Basic Command

```bash
php artisan analysis:opencross-validate {exchange} {market} {timeframe}
```

### Example

```bash
php artisan analysis:opencross-validate binance BTC/USDT 1m \
    --train-start=2023-01-01 \
    --train-end=2023-06-30 \
    --test-start=2023-07-01 \
    --test-end=2023-12-31
```

---

## Command Options

### Required Arguments

| Argument | Description | Example |
|----------|-------------|---------|
| `exchange` | Exchange identifier | `binance`, `kraken` |
| `market` | Trading pair symbol | `BTC/USDT`, `ETH/USDT` |
| `timeframe` | Source timeframe | `1m` (must be 1m) |

### Train/Test Split Options

| Option | Description | Default |
|--------|-------------|---------|
| `--train-start` | Training period start date (Y-m-d) | None |
| `--train-end` | Training period end date (Y-m-d) | None |
| `--test-start` | Test period start date (Y-m-d) | None |
| `--test-end` | Test period end date (Y-m-d) | None |

### Analysis Configuration Options

| Option | Description | Default |
|--------|-------------|---------|
| `--block` | Block duration in minutes | `15` |
| `--bucket` | Distance bucket size | `0.001` |
| `--min-samples` | Minimum samples for high confidence | `100` |
| `--volatility-normalized` | Use volatility normalization | `false` |
| `--volatility-lookback` | Volatility lookback period | `20` |
| `--symmetric` | Merge symmetric buckets | `false` |

### Validation Test Options

| Option | Description | Default |
|--------|-------------|---------|
| `--rolling-window` | Rolling window size in months | `6` |
| `--rolling-step` | Rolling step size in months | `1` |
| `--calibration-bin` | Calibration bin width | `0.05` |
| `--regime-classifier` | Regime classification method | `volatility_percentile` |
| `--regime-threshold` | Regime classification threshold | `0.7` |
| `--random-iterations` | Randomization iterations | `10` |
| `--simulation-threshold` | Strategy simulation threshold | `0.7` |

### Output Options

| Option | Description | Default |
|--------|-------------|---------|
| `--tests` | Comma-separated list of tests to run | `all` |
| `--output` | Output format (`json`, `csv`, `markdown`, `all`) | `json` |
| `--save` | Path to save results | None |
| `--verbose` | Show detailed progress | `false` |

---

## Validation Tests

### 1. Chronological Train/Test Evaluation

Tests out-of-sample performance by building the probability surface on training data and evaluating on test data.

**Key Principle**: Random shuffling is strictly prohibited. All splits maintain chronological order.

**Output Metrics**:
- Mean predicted probability
- Mean realized frequency
- Absolute calibration error
- Brier score

**Example**:
```bash
php artisan analysis:opencross-validate binance BTC/USDT 1m \
    --train-start=2023-01-01 --train-end=2023-06-30 \
    --test-start=2023-07-01 --test-end=2023-12-31 \
    --tests=train_test
```

### 2. Calibration Testing

Evaluates whether predicted probabilities match observed frequencies.

**Procedure**:
1. Partition predictions into probability bins
2. Compute average predicted probability per bin
3. Compute actual crossing frequency per bin
4. Calculate calibration error

**Metrics**:
- **Brier Score**: `mean((predicted - actual)^2)`
- **Mean Absolute Calibration Error**
- **Maximum Calibration Deviation**

**Acceptance Criteria**:
- Observed frequencies within statistical confidence bounds
- No systematic over- or under-prediction pattern

**Example**:
```bash
php artisan analysis:opencross-validate binance BTC/USDT 1m \
    --calibration-bin=0.05 \
    --tests=calibration
```

### 3. Rolling Stability Testing

Ensures surface stability across time using rolling windows.

**Configuration**:
- Window size (default: 6 months)
- Step size (default: 1 month)

**Comparison Metrics**:
- Mean absolute surface difference
- Maximum absolute difference
- Correlation between consecutive surfaces

**Example**:
```bash
php artisan analysis:opencross-validate binance BTC/USDT 1m \
    --rolling-window=6 --rolling-step=1 \
    --tests=rolling_stability
```

### 4. Regime Sensitivity Analysis

Tests sensitivity to volatility regimes.

**Regime Classifiers**:
- `volatility_percentile` - Rolling volatility percentile
- `volatility_threshold` - Fixed volatility threshold
- `atr_based` - ATR-based classification

**Procedure**:
1. Split data into low/high volatility regimes
2. Build surface independently for each regime
3. Compare surfaces

**Example**:
```bash
php artisan analysis:opencross-validate binance BTC/USDT 1m \
    --regime-classifier=volatility_percentile \
    --regime-threshold=0.7 \
    --tests=regime
```

### 5. Sample Size & Uncertainty Estimation

Reports statistical uncertainty for each bucket.

**Standard Error**:
```
SE = sqrt(p(1 - p) / n)
```

**95% Confidence Interval**:
```
p ± 1.96 * SE
```

**Minimum Sample Threshold**:
Buckets below `--min-samples` are flagged or excluded.

**Example**:
```bash
php artisan analysis:opencross-validate binance BTC/USDT 1m \
    --min-samples=100 \
    --tests=uncertainty
```

### 6. Randomized Baseline Testing

Detects whether observed structure exceeds randomness.

**Procedure**:
1. Randomly permute minute order within blocks
2. Recompute probability surface
3. Compare to original surface

**Metrics**:
- Mean absolute surface difference
- Structural deviation score
- Calibration degradation

**Acceptance Criteria**:
Original surface must significantly differ from randomized baseline.

**Example**:
```bash
php artisan analysis:opencross-validate binance BTC/USDT 1m \
    --random-iterations=10 \
    --tests=randomization
```

### 7. Cross-Period Surface Comparison

Evaluates long-term structural persistence.

**Procedure**:
1. Split data by calendar year (or major market regime)
2. Build independent surfaces for each period
3. Compare surfaces

**Comparison Metrics**:
- Surface correlation
- Mean absolute bucket difference
- Shape consistency (monotonicity preservation)

**Example**:
```bash
php artisan analysis:opencross-validate binance BTC/USDT 1m \
    --tests=cross_period
```

### 8. Strategy-Level Simulation Testing

Tests whether probability estimates provide actionable signal.

**Example Rule**:
```
Enter trade if P(cross) > threshold
```

**Evaluation Metrics**:
- Win rate
- Expected value
- Sharpe ratio
- Maximum drawdown
- Stability across time splits

**Requirement**: Strategy performance evaluated strictly out-of-sample.

**Example**:
```bash
php artisan analysis:opencross-validate binance BTC/USDT 1m \
    --simulation-threshold=0.7 \
    --train-start=2023-01-01 --train-end=2023-06-30 \
    --test-start=2023-07-01 --test-end=2023-12-31 \
    --tests=simulation
```

---

## Running Multiple Tests

Run specific tests by providing a comma-separated list:

```bash
php artisan analysis:opencross-validate binance BTC/USDT 1m \
    --tests=train_test,calibration,rolling_stability
```

Available test names:
- `train_test`
- `calibration`
- `rolling_stability`
- `regime`
- `uncertainty`
- `randomization`
- `cross_period`
- `simulation`
- `all` (default)

---

## Output Formats

### JSON Output

```bash
php artisan analysis:opencross-validate binance BTC/USDT 1m --output=json
```

Returns structured JSON with all validation results.

### CSV Output

```bash
php artisan analysis:opencross-validate binance BTC/USDT 1m --output=csv
```

Returns tabular CSV format suitable for spreadsheet analysis.

### Markdown Output

```bash
php artisan analysis:opencross-validate binance BTC/USDT 1m --output=markdown
```

Returns formatted Markdown report.

### All Formats (Summary)

```bash
php artisan analysis:opencross-validate binance BTC/USDT 1m --output=all
```

Displays a comprehensive summary in the console.

---

## Saving Results

Save results to a file:

```bash
php artisan analysis:opencross-validate binance BTC/USDT 1m \
    --output=json \
    --save=storage/validation/btc_usdt_2023.json
```

The file extension determines the format:
- `.json` - JSON format
- `.csv` - CSV format
- `.md` - Markdown format

---

## Acceptance Criteria

The model is considered **statistically robust** if:

| Criterion | Threshold |
|-----------|-----------|
| Out-of-sample calibration error | Low (configurable) |
| Rolling window stability | High correlation (> 0.8) |
| Regime differences | Explainable or normalized |
| Randomized baseline deviation | Material difference |
| Strategy simulation | Retains performance out-of-sample |

---

## Overfitting Safeguards

The system implements several safeguards:

- **Limited conditioning variables** - Restricts dimensional expansion
- **Bucket sparsity tracking** - Monitors sample distribution
- **Dimensional expansion penalty** - Warns on excessive fragmentation
- **Sample fragmentation threshold** - Alerts when below minimum

---

## Architecture

### Service Classes

| Class | Purpose |
|-------|---------|
| `ValidationOrchestrator` | Coordinates all validation tests |
| `TrainTestSplitter` | Chronological data splitting |
| `CalibrationTester` | Brier score and calibration bins |
| `RollingStabilityTester` | Rolling window surface comparison |
| `RegimeSensitivityAnalyzer` | Volatility regime classification |
| `UncertaintyEstimator` | Standard error and confidence intervals |
| `RandomizedBaselineGenerator` | Block permutation testing |
| `CrossPeriodComparator` | Period-based surface correlation |
| `StrategySimulator` | Trading strategy simulation |

### DTOs

| Class | Purpose |
|-------|---------|
| `ValidationResult` | Main result container |
| `CalibrationReport` | Calibration test results |
| `StabilityReport` | Rolling stability results |
| `RegimeReport` | Regime sensitivity results |
| `UncertaintyReport` | Uncertainty estimation results |
| `RandomizationReport` | Randomization test results |
| `CrossPeriodReport` | Cross-period comparison results |
| `SimulationReport` | Strategy simulation results |

---

## Mathematical Context

This framework validates whether the empirical estimator of:

```
P(cross | distance, time_remaining)
```

represents a **first-passage probability under finite time horizon** that exhibits structural persistence consistent with a stable stochastic process.

The validation ensures that the probability surface captures genuine market microstructure rather than historical noise or overfit patterns.

---

## Non-Goals

The following are explicitly out of scope:

- Execution cost modeling
- Market impact modeling
- Real-time adaptation
- Portfolio-level optimization

---

## Troubleshooting

### Common Issues

**"Insufficient data for train/test split"**
- Ensure the date range covers enough data points
- Reduce `--min-samples` threshold if appropriate

**"No data found for exchange/market"**
- Verify the exchange and market identifiers match downloaded data
- Run `php artisan stochastix:data availability` to check available data

**"Calibration error too high"**
- Consider increasing `--min-samples` to exclude noisy buckets
- Review regime sensitivity for potential market structure changes

---

## See Also

- [Analysis Documentation](Analysis.md) - Open-Cross Probability Engine Phase 1
- [Data Command Documentation](DataCommand.md) - Market data management
