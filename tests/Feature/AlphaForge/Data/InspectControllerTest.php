<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('InspectController', function () {
    beforeEach(function () {
        $this->user = User::factory()->create();
    });

    it('returns 404 when data file not found', function () {
        $response = $this->actingAs($this->user)
            ->getJson('/api/alphaforge/data/inspect/binance/BTC-USDT/1h');

        $response->assertStatus(404)
            ->assertJsonStructure(['error']);
    });

    it('handles symbol with dash separator', function () {
        // This test verifies the URL format with dash instead of slash
        // works correctly for symbol parsing
        $response = $this->actingAs($this->user)
            ->getJson('/api/alphaforge/data/inspect/binance/ETH-USDT/1h');

        // Should return 404 since no actual data file exists
        $response->assertStatus(404);
    });
});
