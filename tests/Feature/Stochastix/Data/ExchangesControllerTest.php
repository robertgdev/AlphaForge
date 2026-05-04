<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('ExchangesController', function () {
    beforeEach(function () {
        $this->user = User::factory()->create();
    });

    it('returns list of supported exchanges', function () {
        $response = $this->actingAs($this->user)
            ->getJson('/api/stochastix/data/exchanges');

        $response->assertStatus(200)
            ->assertJsonStructure(['*' => []]);

        // Verify some known exchanges are in the list
        $exchanges = $response->json();
        expect($exchanges)->toContain('binance');
    });
});
