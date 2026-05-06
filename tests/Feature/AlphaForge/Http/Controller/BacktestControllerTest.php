<?php

use App\Models\User;
use App\AlphaForge\Backtesting\Model\BacktestRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

describe('BacktestController', function () {
    beforeEach(function () {
        $this->user = User::factory()->create();
    });

    describe('index', function () {
        it('returns paginated list of backtests', function () {
            BacktestRun::factory()->for($this->user)->count(3)->create();

            $response = $this->actingAs($this->user)
                ->getJson('/api/stochastix/backtests');

            $response->assertOk()
                ->assertJsonStructure([
                    'data',
                    'current_page',
                    'last_page',
                    'per_page',
                    'total',
                ]);
        });

        it('only returns backtests for authenticated user', function () {
            $otherUser = User::factory()->create();
            BacktestRun::factory()->for($otherUser)->count(2)->create();
            BacktestRun::factory()->for($this->user)->count(1)->create();

            $response = $this->actingAs($this->user)
                ->getJson('/api/stochastix/backtests');

            $response->assertOk();
            expect($response->json('data'))->toHaveCount(1);
        });
    });

    describe('store', function () {
        it('creates a new backtest run', function () {
            Bus::fake();

            $data = [
                'strategy' => 'SampleStrategy',
                'symbols' => ['BTC/USDT', 'ETH/USDT'],
                'timeframe' => '1h',
                'exchange' => 'binance',
                'initial_capital' => '10000',
                'start_date' => '2023-01-01',
                'end_date' => '2023-12-31',
            ];

            $response = $this->actingAs($this->user)
                ->postJson('/api/stochastix/backtests', $data);

            $response->assertAccepted()
                ->assertJson([
                    'message' => 'Backtest queued successfully',
                ]);

            $this->assertDatabaseHas('backtest_runs', [
                'user_id' => $this->user->id,
                'strategy_alias' => 'SampleStrategy',
                'status' => 'pending',
            ]);
        });

        it('validates required fields', function () {
            $response = $this->actingAs($this->user)
                ->postJson('/api/stochastix/backtests', []);

            $response->assertUnprocessable()
                ->assertJsonValidationErrors([
                    'strategy',
                    'symbols',
                    'timeframe',
                    'exchange',
                    'initial_capital',
                ]);
        });

        it('validates timeframe is valid enum value', function () {
            $data = [
                'strategy' => 'SampleStrategy',
                'symbols' => ['BTC/USDT'],
                'timeframe' => 'invalid',
                'exchange' => 'binance',
                'initial_capital' => '10000',
                'start_date' => '2023-01-01',
            ];

            $response = $this->actingAs($this->user)
                ->postJson('/api/stochastix/backtests', $data);

            $response->assertUnprocessable()
                ->assertJsonValidationErrors(['timeframe']);
        });
    });

    describe('show', function () {
        it('returns a specific backtest', function () {
            $backtest = BacktestRun::factory()->for($this->user)->create();

            $response = $this->actingAs($this->user)
                ->getJson("/api/stochastix/backtests/{$backtest->id}");

            $response->assertOk()
                ->assertJson([
                    'id' => $backtest->id,
                ]);
        });

        it('returns 404 for non-existent backtest', function () {
            $response = $this->actingAs($this->user)
                ->getJson('/api/stochastix/backtests/non-existent-id');

            $response->assertNotFound();
        });
    });

    describe('destroy', function () {
        it('cancels a pending backtest', function () {
            $backtest = BacktestRun::factory()->for($this->user)->create();

            $response = $this->actingAs($this->user)
                ->deleteJson("/api/stochastix/backtests/{$backtest->id}");

            $response->assertOk()
                ->assertJson([
                    'message' => 'Backtest cancelled',
                ]);

            $backtest->refresh();
            expect($backtest->status)->toBe('failed');
        });

        it('cannot cancel a running backtest', function () {
            $backtest = BacktestRun::factory()->for($this->user)->running()->create();

            $response = $this->actingAs($this->user)
                ->deleteJson("/api/stochastix/backtests/{$backtest->id}");

            $response->assertBadRequest();
        });
    });

    describe('statistics', function () {
        it('returns statistics for completed backtest', function () {
            $backtest = BacktestRun::factory()->for($this->user)->completed()->create();

            $response = $this->actingAs($this->user)
                ->getJson("/api/stochastix/backtests/{$backtest->id}/statistics");

            $response->assertOk()
                ->assertJsonStructure([
                    'statistics',
                ]);
        });

        it('returns error for non-completed backtest', function () {
            $backtest = BacktestRun::factory()->for($this->user)->create();

            $response = $this->actingAs($this->user)
                ->getJson("/api/stochastix/backtests/{$backtest->id}/statistics");

            $response->assertBadRequest();
        });
    });
});
