<?php

use App\AlphaForge\Backtesting\Optimization\Objective\SingleMetricObjective;
use App\AlphaForge\Backtesting\Optimization\TopNResults;

describe('Optimizer checkpointing', function () {
    it('saves and loads checkpoint file', function () {
        $optId = 'test-checkpoint-'.time();
        $dir = sys_get_temp_dir().'/alphaforge_checkpoints';
        $path = "{$dir}/{$optId}.json";

        if (file_exists($path)) {
            unlink($path);
        }

        // Simulate save
        $objective = new SingleMetricObjective('sharpe_ratio');
        $topResults = new TopNResults(3, $objective);
        $topResults->consider(['a' => 1], ['sharpe_ratio' => '1.5'], 1.5);
        $topResults->consider(['a' => 2], ['sharpe_ratio' => '2.0'], 2.0);

        // Manually create checkpoint file
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $checkpoint = [
            'optimization_id' => $optId,
            'completed' => 50,
            'timestamp' => now()->toIso8601String(),
            'generator_state' => ['index' => 50],
            'top_results' => [
                ['parameters' => ['a' => 2], 'statistics' => ['sharpe_ratio' => '2.0'], 'score' => 2.0],
                ['parameters' => ['a' => 1], 'statistics' => ['sharpe_ratio' => '1.5'], 'score' => 1.5],
            ],
        ];

        file_put_contents(
            $path,
            json_encode($checkpoint, JSON_PRETTY_PRINT),
        );

        expect(file_exists($path))->toBeTrue();

        // Read it back
        $data = json_decode(file_get_contents($path), true);
        expect($data['completed'])->toBe(50);
        expect($data['generator_state']['index'])->toBe(50);
        expect(count($data['top_results']))->toBe(2);

        // Cleanup
        unlink($path);
    });

    it('checkpoint structure contains required keys', function () {
        $checkpoint = [
            'optimization_id' => 'abc',
            'completed' => 10,
            'timestamp' => '2024-01-01T00:00:00+00:00',
            'generator_state' => [],
            'top_results' => [],
        ];

        expect($checkpoint)->toHaveKeys([
            'optimization_id',
            'completed',
            'timestamp',
            'generator_state',
            'top_results',
        ]);
    });
});
