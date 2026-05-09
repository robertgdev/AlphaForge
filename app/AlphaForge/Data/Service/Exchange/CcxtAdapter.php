<?php

namespace App\AlphaForge\Data\Service\Exchange;

use App\AlphaForge\Data\Exception\DownloadCancelledException;
use App\AlphaForge\Data\Exception\EmptyHistoryException;
use App\AlphaForge\Data\Exception\ExchangeException;
use App\AlphaForge\Events\DownloadProgress;
use Carbon\Carbon;
use ccxt\Exchange;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

readonly class CcxtAdapter implements ExchangeAdapterInterface
{
    public function __construct(
        private Dispatcher $eventDispatcher,
        private ExchangeFactory $exchangeFactory
    ) {}

    public function supportsExchange(string $exchangeId): bool
    {
        /** @psalm-suppress PossiblyInvalidArgument */
        return in_array($exchangeId, Exchange::$exchanges, true);
    }

    public function fetchOhlcv(
        string $exchangeId,
        string $symbol,
        string $timeframe,
        Carbon $startTime,
        Carbon $endTime,
        ?string $jobId = null
    ): \Generator {
        $exchange = $this->exchangeFactory->create($exchangeId);
        /** @psalm-suppress PossiblyInvalidMethodCall */
        $exchange->loadMarkets();

        /** @psalm-suppress PossiblyInvalidOperand, MixedArgument */
        if (! $exchange->has['fetchOHLCV']) {
            throw new ExchangeException(sprintf('Exchange "%s" does not support fetching OHLCV data.', $exchangeId));
        }

        /** @psalm-suppress MixedAssignment, MixedArgument */
        $timeframes = $exchange->timeframes ?? [];
        if (! is_array($timeframes) || ! array_key_exists($timeframe, $timeframes)) {
            throw new ExchangeException(sprintf(
                'Exchange "%s" does not support the "%s" timeframe.',
                $exchangeId,
                $timeframe
            ));
        }

        /** @psalm-suppress MixedAssignment, MixedArgument, MixedArgumentTypeCoercion */
        $since = $startTime->timestamp * 1000;
        /** @psalm-suppress MixedAssignment */
        $endTimestamp = $endTime->timestamp * 1000;
        /** @psalm-suppress MixedAssignment, MixedArgument */
        $limit = (int) ($exchange->limits['OHLCV']['limit'] ?? 1000);
        /** @psalm-suppress MixedAssignment, MixedArgument */
        $durationMs = (int) Exchange::parse_timeframe($timeframe) * 1000;
        /** @psalm-suppress MixedArgument */
        $totalDuration = max(1, (int) $endTimestamp - (int) $since);
        $isFirstFetch = true;
        $cancellationCacheKey = 'alphaforge.download.cancel.'.$jobId;

        while ($since <= $endTimestamp) {
            if ($jobId) {
                if (Cache::has($cancellationCacheKey)) {
                    Cache::forget($cancellationCacheKey);
                    throw new DownloadCancelledException("Download job {$jobId} was cancelled by user request.");
                }
            }

            try {
                /** @var list<list<int|float>>|null $ohlcvs */
                $ohlcvs = $exchange->fetch_ohlcv($symbol, $timeframe, $since, $limit);
            } catch (\Throwable $e) {
                throw new ExchangeException(sprintf(
                    'Failed to fetch OHLCV for %s on %s: %s',
                    $symbol,
                    $exchangeId,
                    $e->getMessage()
                ), 0, $e);
            }

            if (empty($ohlcvs)) {
                if ($isFirstFetch) {
                    throw new EmptyHistoryException(sprintf(
                        'Exchange returned no data for %s starting from %s. Data may not be available for this period.',
                        $symbol,
                        $startTime->format('Y-m-d H:i:s')
                    ));
                }
                Log::info("No more OHLCV data returned for {$symbol} starting from ".((int) ($since / 1000)));
                break;
            }

            $isFirstFetch = false;

            $lastTimestamp = 0;
            $batchRecordCount = 0;

            foreach ($ohlcvs as $ohlcv) {
                /** @psalm-suppress MixedAssignment, MixedObjectFetch, MixedOffsetAccess */
                [$timestamp, $open, $high, $low, $close, $volume] = $ohlcv;

                /** @psalm-suppress MixedArgument */
                if ((int) $timestamp > $endTimestamp) {
                    break 2;
                }
                /** @psalm-suppress MixedArgument, MixedArgumentTypeCoercion */
                if ((int) $timestamp < (int) $since) {
                    continue;
                }

                yield [
                    'timestamp' => (int) ($timestamp / 1000),
                    'open' => (float) $open,
                    'high' => (float) $high,
                    'low' => (float) $low,
                    'close' => (float) $close,
                    'volume' => (float) $volume,
                ];

                /** @psalm-suppress MixedArgument */
                $lastTimestamp = (int) $timestamp;
                $batchRecordCount++;
            }

            if ($lastTimestamp > 0) {
                // Dispatch progress event
                $this->eventDispatcher->dispatch(new DownloadProgress(
                    $jobId,
                    $symbol,
                    (int) ($lastTimestamp / 1000),
                    $batchRecordCount,
                    $totalDuration,
                    max(0, (int) $lastTimestamp - (int) $startTime->timestamp * 1000)
                ));
            }

            if ($lastTimestamp === 0) {
                Log::info("No valid records found in the fetched batch for {$symbol}. Stopping.");
                break;
            }

            $since = $lastTimestamp + $durationMs;
            usleep(200000); // 200ms delay between requests
        }
    }

    public function fetchFirstAvailableTimestamp(
        string $exchangeId,
        string $symbol,
        string $timeframe
    ): ?Carbon {
        $exchange = $this->exchangeFactory->create($exchangeId);

        /** @psalm-suppress PossiblyInvalidOperand, MixedArgument */
        if (! $exchange->has['fetchOHLCV']) {
            return null;
        }

        try {
            /** @var list<list<int|float>>|null $ohlcvs */
            $ohlcvs = $exchange->fetch_ohlcv($symbol, $timeframe, null, 1);

            if (! empty($ohlcvs) && isset($ohlcvs[0][0])) {
                return Carbon::createFromTimestamp((int) ($ohlcvs[0][0] / 1000));
            }
        } catch (\Throwable $e) {
            Log::warning('Could not determine first available timestamp for {symbol} on {exchange}.', [
                'symbol' => $symbol,
                'exchange' => $exchangeId,
                'reason' => $e->getMessage(),
            ]);

            return null;
        }

        return null;
    }
}
