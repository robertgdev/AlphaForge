<?php

use App\Models\User;
use App\AlphaForge\Jobs\DownloadMarketDataJob;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

describe('DownloadController', function () {
    beforeEach(function () {
        $this->user = User::factory()->create();
        Bus::fake();
    });

    it('can queue a download job', function () {
        $response = $this->actingAs($this->user)
            ->postJson('/api/alphaforge/data/download', [
                'exchangeId' => 'binance',
                'symbol' => 'BTC/USDT',
                'timeframe' => '1h',
                'startDate' => '2023-01-01',
                'endDate' => '2024-01-01',
                'forceOverwrite' => false,
            ]);

        $response->assertStatus(202)
            ->assertJsonStructure([
                'status',
                'jobId',
            ])
            ->assertJson(['status' => 'queued']);

        Bus::assertDispatched(DownloadMarketDataJob::class, function ($job) {
            return $job->exchangeId === 'binance'
                && $job->symbol === 'BTC/USDT'
                && $job->timeframe === '1h'
                && $job->startDate->eq(Carbon::parse('2023-01-01'))
                && $job->endDate->eq(Carbon::parse('2024-01-01'))
                && $job->forceOverwrite === false;
        });
    });

    it('validates required fields', function () {
        $response = $this->actingAs($this->user)
            ->postJson('/api/alphaforge/data/download', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['exchangeId', 'symbol', 'timeframe', 'startDate']);
    });

    it('validates date format', function () {
        $response = $this->actingAs($this->user)
            ->postJson('/api/alphaforge/data/download', [
                'exchangeId' => 'binance',
                'symbol' => 'BTC/USDT',
                'timeframe' => '1h',
                'startDate' => 'invalid-date',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['startDate']);
    });

    it('validates end date is after start date', function () {
        $response = $this->actingAs($this->user)
            ->postJson('/api/alphaforge/data/download', [
                'exchangeId' => 'binance',
                'symbol' => 'BTC/USDT',
                'timeframe' => '1h',
                'startDate' => '2024-01-01',
                'endDate' => '2023-01-01',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['endDate']);
    });

    it('defaults end date to now when not provided', function () {
        Carbon::setTestNow('2024-06-15 12:00:00');

        $response = $this->actingAs($this->user)
            ->postJson('/api/alphaforge/data/download', [
                'exchangeId' => 'binance',
                'symbol' => 'BTC/USDT',
                'timeframe' => '1h',
                'startDate' => '2023-01-01',
            ]);

        $response->assertStatus(202);

        Bus::assertDispatched(DownloadMarketDataJob::class, function ($job) {
            return $job->endDate->format('Y-m-d') === '2024-06-15';
        });

        Carbon::setTestNow();
    });

    it('can cancel a download job', function () {
        $jobId = 'download_test123';

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/alphaforge/data/download/{$jobId}");

        $response->assertStatus(202)
            ->assertJson([
                'status' => 'cancellation_requested',
                'jobId' => $jobId,
            ]);

        $cacheKey = "alphaforge.download.cancel.{$jobId}";
        expect(Cache::has($cacheKey))->toBeTrue();
    });
});
