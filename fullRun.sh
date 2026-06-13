#!/usr/bin/env bash
set -euo pipefail

FORCE_FLAG="--force"
UPDATE_FLAG="--force"
DEBUG_FLAG=""
while [[ $# -gt 0 ]]; do
    case "$1" in
        --no-force) FORCE_FLAG="" UPDATE_FLAG="--update"; shift ;;
        --force)    FORCE_FLAG="--force" UPDATE_FLAG="--force"; shift ;;
        --debug)    DEBUG_FLAG="--debug"; shift ;;
        --help|-h)
            echo "Usage: $0 [--force|--no-force] [--debug]"
            echo "  --force     Force overwrite of existing data (default)"
            echo "  --no-force  Update incrementally: skip if data already exists,"
            echo "              append only missing data for data:import step"
            echo "  --debug     Show peak memory usage after each command"
            exit 0
            ;;
        *) echo "Unknown: $1"; exit 1 ;;
    esac
done

EXCHANGE="binance"
MARKET="BTC/USDT"
SYMBOL="BTCUSDT"

START_DATE="2023-01-01"
END_DATE="2024-12-31"

echo "=== [1/5] Downloading m1 OHLCV data for ${MARKET} ==="
php artisan alphaforge:data:import "${EXCHANGE}" "${MARKET}" 1m "${START_DATE}" "${END_DATE}" ${FORCE_FLAG} ${DEBUG_FLAG}

echo ""
echo "=== [2/5] Aggregating m1 -> h1 OHLCV ==="
php artisan alphaforge:data:aggregate "${EXCHANGE}" "${MARKET}" 1m 1h ${UPDATE_FLAG} ${DEBUG_FLAG}

echo ""
echo "=== [3/5] Converting h1 OHLCV -> Heikin-Ashi ==="
php artisan alphaforge:heikenashi "${EXCHANGE}" "${MARKET}" 1h ${UPDATE_FLAG} ${DEBUG_FLAG}

echo ""
echo "=== [4/5] Walk-Forward Analysis (optimize IS 75% → validate OOS 25%) ==="
WF_OUTPUT=$(php artisan alphaforge:walk-forward sma_crossover "${MARKET}" \
    --exchange="${EXCHANGE}" \
    --timeframe=1h \
    --execution-timeframe=1m \
    --start-date="${START_DATE}" \
    --end-date="${END_DATE}" \
    --data-type=heikenashi \
    --use-strategy-ranges \
    --method=random \
    --iterations=500 \
    --objective=balanced \
    --top-n=50 \
    --split=0.75 \
    --runner=fork \
    --workers=auto \
    --format=table \
    --sizing-model=risk_based \
    --risk-per-trade=1.0 \
    --max-leverage=1.0 \
    ${DEBUG_FLAG} \
    2>&1)

echo "${WF_OUTPUT}"

WF_ID=$(echo "${WF_OUTPUT}" | grep -oP 'Walk-Forward Run ID:\s*\K[a-f0-9\-]+')
if [ -z "${WF_ID}" ]; then
    echo "ERROR: Could not extract Walk-Forward Run ID from output."
    exit 1
fi

echo ""
echo "============================================================"
echo " Walk-Forward Run ID: ${WF_ID}"
echo "============================================================"

echo ""
echo "=== [a] Sensitivity analysis ==="
OPT_ID=$(php artisan tinker --execute="echo App\AlphaForge\Backtesting\Model\WalkForwardRun::find('${WF_ID}')->optimization_run_id ?? '';" 2>/dev/null)
if [ -n "${OPT_ID}" ]; then
    php artisan alphaforge:optimizations:sensitivity "${OPT_ID}" --metric=sharpe_ratio ${DEBUG_FLAG} 2>&1 || true
else
    echo "Skipping sensitivity analysis — no optimization run linked to walk-forward run ${WF_ID}"
fi

echo ""
echo "=== [b] Export walk-forward results to CSV ==="
php artisan alphaforge:walk-forward:show "${WF_ID}" --format=csv --output="storage/app/results/wf_${WF_ID}.csv" ${DEBUG_FLAG}

echo ""
echo "=== [5/5] Backtest best OOS params on full OOS period ==="
OOS_START=$(php artisan tinker --execute="echo App\AlphaForge\Backtesting\Model\WalkForwardRun::find('${WF_ID}')->oos_start_date->toDateString();")
OOS_END=$(php artisan tinker --execute="echo App\AlphaForge\Backtesting\Model\WalkForwardRun::find('${WF_ID}')->oos_end_date->toDateString();")
BEST_PARAMS_JSON=$(php artisan tinker --execute="echo json_encode(App\AlphaForge\Backtesting\Model\WalkForwardRun::find('${WF_ID}')->best_parameters);")

echo "  OOS Period: ${OOS_START} to ${OOS_END}"
echo "  Best OOS params: ${BEST_PARAMS_JSON}"

BT_ID=""
if [ -n "${FORCE_FLAG}" ]; then
    BT_OUTPUT=$(php artisan alphaforge:backtest:run sma_crossover "${MARKET}" \
        --exchange="${EXCHANGE}" \
        --timeframe=1h \
        --execution-timeframe=1m \
        --start-date="${OOS_START}" \
        --end-date="${OOS_END}" \
        --data-type=heikenashi \
        --inputs="${BEST_PARAMS_JSON}" \
        --capital=10000 \
        --sizing-model=risk_based \
        --risk-per-trade=1.0 \
        --max-leverage=1.0 \
        --force \
        ${DEBUG_FLAG} \
        2>&1)
    BT_ID=$(echo "${BT_OUTPUT}" | grep -oP 'backtest run ID:\s*\K[a-f0-9\-]+' || echo "")
else
    BT_OUTPUT=$(php artisan alphaforge:backtest:run sma_crossover "${MARKET}" \
        --exchange="${EXCHANGE}" \
        --timeframe=1h \
        --execution-timeframe=1m \
        --start-date="${OOS_START}" \
        --end-date="${OOS_END}" \
        --data-type=heikenashi \
        --inputs="${BEST_PARAMS_JSON}" \
        --capital=10000 \
        --sizing-model=risk_based \
        --risk-per-trade=1.0 \
        --max-leverage=1.0 \
        ${DEBUG_FLAG} \
        2>&1 || true)
    BT_ID=$(echo "${BT_OUTPUT}" | grep -oP 'backtest run ID:\s*\K[a-f0-9\-]+' || echo "")
    if [ -z "${BT_ID}" ]; then
        BT_ID=$(echo "${BT_OUTPUT}" | grep -oP '\(ID:\s*\K[a-f0-9\-]+' || echo "")
        if [ -n "${BT_ID}" ]; then
            echo "Backtest with these params already exists, using ID: ${BT_ID}"
        fi
    fi
fi

echo "${BT_OUTPUT}"

if [ -n "${BT_ID}" ]; then
    echo ""
    echo "=== [c] Monte Carlo simulation (2000 iterations) ==="
    php artisan alphaforge:monte-carlo "${BT_ID}" --iterations=2000 --seed=42 ${DEBUG_FLAG}

    echo ""
    echo "=== [d] Export OOS backtest trades to CSV ==="
    php artisan alphaforge:export:backtest "${BT_ID}" --format=csv --output="storage/app/results/bt_oos_${BT_ID}.csv" ${DEBUG_FLAG}
fi

echo ""
echo "===== DONE ====="
echo "Walk-Forward Run ID: ${WF_ID}"
[ -n "${BT_ID}" ] && echo "OOS Backtest ID:      ${BT_ID}"
echo "CSV exports in storage/app/results/"
echo "For further analysis run:"
echo "  php artisan alphaforge:walk-forward:show ${WF_ID}"
echo "  php artisan alphaforge:optimizations:show <optimization_id>"
echo "  php artisan alphaforge:optimizations:sensitivity <optimization_id> --surface=fastPeriod,slowPeriod"