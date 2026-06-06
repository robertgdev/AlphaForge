<?php

use App\AlphaForge\Backtesting\Optimization\Sensitivity\ParameterSensitivityService;

describe('ParameterSensitivityService', function () {
    describe('importance()', function () {
        it('identifies the param that drives score variance', function () {
            $runs = [];
            foreach ([5, 10, 15] as $fast) {
                foreach ([20, 30, 40] as $slow) {
                    $score = (float) $fast * 0.5 + 1.0; // only fastPeriod matters
                    $runs[] = [
                        'params' => ['fastPeriod' => $fast, 'slowPeriod' => $slow],
                        'stats' => ['optimization_score' => (string) $score],
                    ];
                }
            }

            $service = new ParameterSensitivityService($runs);
            $result = $service->importance('optimization_score');

            expect($result)->toHaveCount(2);
            $fastKey = array_search('fastPeriod', array_column($result, 'param'));
            $slowKey = array_search('slowPeriod', array_column($result, 'param'));
            expect($result[$fastKey]['importance_pct'])->toBeGreaterThan(80.0);
            expect($result[$slowKey]['importance_pct'])->toBeLessThan(20.0);
        });

        it('distributes importance across multiple impactful params', function () {
            $runs = [];
            foreach ([10, 20] as $a) {
                foreach ([5, 15] as $b) {
                    $score = (float) $a * 0.3 + (float) $b * 0.7;
                    $runs[] = [
                        'params' => ['paramA' => $a, 'paramB' => $b],
                        'stats' => ['optimization_score' => (string) $score],
                    ];
                }
            }

            $service = new ParameterSensitivityService($runs);
            $result = $service->importance('optimization_score');

            expect($result)->toHaveCount(2);
            $bKey = array_search('paramB', array_column($result, 'param'));
            $aKey = array_search('paramA', array_column($result, 'param'));
            // paramB (coefficient 0.7) should be more important than paramA (0.3)
            expect($result[$bKey]['importance_pct'])->toBeGreaterThan($result[$aKey]['importance_pct']);
        });

        it('assigns equal importance when params have equal impact', function () {
            $runs = [];
            foreach ([1, 2] as $a) {
                foreach ([3, 4] as $b) {
                    $score = (float) $a + (float) $b;
                    $runs[] = [
                        'params' => ['a' => $a, 'b' => $b],
                        'stats' => ['optimization_score' => (string) $score],
                    ];
                }
            }

            $service = new ParameterSensitivityService($runs);
            $result = $service->importance('optimization_score');

            expect($result)->toHaveCount(2);
            expect(abs($result[0]['importance_pct'] - $result[1]['importance_pct']))->toBeLessThan(10.0);
        });

        it('returns zero importance when all params have zero variance', function () {
            $runs = [];
            foreach ([10, 20] as $a) {
                foreach ([30, 40] as $b) {
                    $runs[] = [
                        'params' => ['a' => $a, 'b' => $b],
                        'stats' => ['optimization_score' => '5.0'],
                    ];
                }
            }

            $service = new ParameterSensitivityService($runs);
            $result = $service->importance('optimization_score');

            expect($result[0]['importance_pct'])->toBe(0.0);
            expect($result[1]['importance_pct'])->toBe(0.0);
        });

        it('works with a custom metric', function () {
            $runs = [];
            foreach ([5, 10] as $p) {
                $runs[] = [
                    'params' => ['p' => $p],
                    'stats' => ['sharpe_ratio' => (string) ($p * 0.1), 'optimization_score' => '1.0'],
                ];
            }

            $service = new ParameterSensitivityService($runs);
            $default = $service->importance('optimization_score');
            $custom = $service->importance('sharpe_ratio');

            expect($default[0]['importance_pct'])->toBe(0.0);
            expect($custom[0]['importance_pct'])->toBe(100.0);
        });

        it('handles empty data gracefully', function () {
            $service = new ParameterSensitivityService([]);
            $result = $service->importance();

            expect($result)->toBe([]);
        });

        it('sorts results descending by importance', function () {
            $runs = [];
            foreach ([1, 2, 3] as $strong) {
                foreach ([10, 20] as $weak) {
                    $score = (float) $strong * 10.0 + (float) $weak * 0.1;
                    $runs[] = [
                        'params' => ['strong' => $strong, 'weak' => $weak],
                        'stats' => ['optimization_score' => (string) $score],
                    ];
                }
            }

            $service = new ParameterSensitivityService($runs);
            $result = $service->importance('optimization_score');

            expect($result[0]['param'])->toBe('strong');
            expect($result[1]['param'])->toBe('weak');
        });
    });

    describe('surface()', function () {
        it('builds a full grid with mean scores per cell', function () {
            $runs = [];
            foreach ([10, 20] as $fast) {
                foreach ([50, 100] as $slow) {
                    $score = $fast + $slow;
                    $runs[] = [
                        'params' => ['fastPeriod' => $fast, 'slowPeriod' => $slow],
                        'stats' => ['score' => (string) $score],
                    ];
                }
            }

            $service = new ParameterSensitivityService($runs);
            $result = $service->surface('fastPeriod', 'slowPeriod', 'score');

            expect($result['rows'])->toBe([10, 20]);
            expect($result['cols'])->toBe([50, 100]);
            expect($result['grid'][0][0])->not->toBeNull(); // fast=10, slow=50
        });

        it('averages multiple entries in the same cell', function () {
            $runs = [
                ['params' => ['a' => 10, 'b' => 20], 'stats' => ['score' => '3.0']],
                ['params' => ['a' => 10, 'b' => 20], 'stats' => ['score' => '7.0']],
            ];

            $service = new ParameterSensitivityService($runs);
            $result = $service->surface('a', 'b', 'score');

            expect($result['grid'][0][0])->toBe(5.0); // average of 3.0 and 7.0
        });

        it('returns null for empty cells in a sparse grid', function () {
            // a=10 paired with b=20, a=20 paired with both b=20 and b=30
            // a=10,b=30 never occurs → null cell in the grid
            $runs = [
                ['params' => ['a' => 10, 'b' => 20], 'stats' => ['score' => '5.0']],
                ['params' => ['a' => 20, 'b' => 20], 'stats' => ['score' => '6.0']],
                ['params' => ['a' => 20, 'b' => 30], 'stats' => ['score' => '7.0']],
            ];

            $service = new ParameterSensitivityService($runs);
            $result = $service->surface('a', 'b', 'score');

            // rows: [10, 20], cols: [20, 30]
            // grid[0][0] = a=10, b=20 → 5.0
            // grid[0][1] = a=10, b=30 → null (never observed)
            // grid[1][0] = a=20, b=20 → 6.0
            // grid[1][1] = a=20, b=30 → 7.0
            expect($result['cols'])->toBe([20, 30]);
            expect($result['rows'])->toBe([10, 20]);
            expect($result['grid'][0][0])->toBe(5.0);
            expect($result['grid'][0][1])->toBeNull();
            expect($result['grid'][1][0])->toBe(6.0);
            expect($result['grid'][1][1])->toBe(7.0);
        });

        it('detects a robust flat optimum (high stability)', function () {
            // All cells have similar high scores — flat plateau
            $runs = [];
            foreach ([10, 20, 30] as $a) {
                foreach ([50, 100, 150] as $b) {
                    $runs[] = [
                        'params' => ['a' => $a, 'b' => $b],
                        'stats' => ['score' => '9.0'],
                    ];
                }
            }

            $service = new ParameterSensitivityService($runs);
            $result = $service->surface('a', 'b', 'score');

            expect($result['stability_score'])->toBeGreaterThan(90.0);
        });

        it('detects a fragile sharp optimum (low stability)', function () {
            // One peak cell much higher than neighbors — sharp spike
            $runs = [];
            foreach ([10, 20, 30] as $a) {
                foreach ([50, 100, 150] as $b) {
                    $score = ($a === 20 && $b === 100) ? 10.0 : 1.0;
                    $runs[] = [
                        'params' => ['a' => $a, 'b' => $b],
                        'stats' => ['score' => (string) $score],
                    ];
                }
            }

            $service = new ParameterSensitivityService($runs);
            $result = $service->surface('a', 'b', 'score');

            expect($result['stability_score'])->toBeLessThan(30.0);
        });

        it('reports best and worst scores correctly', function () {
            $runs = [
                ['params' => ['a' => 10, 'b' => 20], 'stats' => ['score' => '1.0']],
                ['params' => ['a' => 20, 'b' => 30], 'stats' => ['score' => '9.0']],
            ];

            $service = new ParameterSensitivityService($runs);
            $result = $service->surface('a', 'b', 'score');

            expect($result['best_score'])->toBe(9.0);
            expect($result['worst_score'])->toBe(1.0);
        });

        it('sorts rows and cols in ascending order', function () {
            $runs = [];
            foreach ([30, 10, 20] as $a) {
                foreach ([100, 50, 75] as $b) {
                    $runs[] = [
                        'params' => ['a' => $a, 'b' => $b],
                        'stats' => ['score' => '5.0'],
                    ];
                }
            }

            $service = new ParameterSensitivityService($runs);
            $result = $service->surface('a', 'b', 'score');

            expect($result['rows'])->toBe([10, 20, 30]);
            expect($result['cols'])->toBe([50, 75, 100]);
        });
    });

    describe('interactions()', function () {
        it('detects strong interaction when score depends on combination', function () {
            // Score depends on product, not individual params
            $runs = [];
            foreach ([10, 20, 30] as $a) {
                foreach ([5, 15, 25] as $b) {
                    $score = $a * $b; // interaction: neither alone explains this
                    $runs[] = [
                        'params' => ['a' => $a, 'b' => $b],
                        'stats' => ['score' => (string) $score],
                    ];
                }
            }

            $service = new ParameterSensitivityService($runs);
            $result = $service->interactions('score');

            expect($result)->toHaveCount(1);
            expect($result[0]['param_a'])->toBe('a');
            expect($result[0]['param_b'])->toBe('b');
            expect($result[0]['interaction'])->toBeGreaterThan(0);
        });

        it('returns near-zero interaction for independent params', function () {
            $runs = [];
            foreach ([10, 20] as $a) {
                foreach ([30, 40] as $b) {
                    $score = $a + $b; // additive: fully separable
                    $runs[] = [
                        'params' => ['a' => $a, 'b' => $b],
                        'stats' => ['score' => (string) $score],
                    ];
                }
            }

            $service = new ParameterSensitivityService($runs);
            $result = $service->interactions('score');

            expect($result[0]['interaction'])->toBeLessThan(0.001);
        });

        it('returns empty for single parameter', function () {
            $runs = [
                ['params' => ['p' => 10], 'stats' => ['score' => '5.0']],
                ['params' => ['p' => 20], 'stats' => ['score' => '8.0']],
            ];

            $service = new ParameterSensitivityService($runs);
            $result = $service->interactions('score');

            expect($result)->toBe([]);
        });

        it('returns pair for two params', function () {
            $runs = [
                ['params' => ['a' => 1, 'b' => 1], 'stats' => ['score' => '1.0']],
                ['params' => ['a' => 1, 'b' => 2], 'stats' => ['score' => '2.0']],
                ['params' => ['a' => 2, 'b' => 1], 'stats' => ['score' => '3.0']],
                ['params' => ['a' => 2, 'b' => 2], 'stats' => ['score' => '4.0']],
            ];

            $service = new ParameterSensitivityService($runs);
            $result = $service->interactions('score');

            expect($result)->toHaveCount(1);
            expect($result[0]['param_a'])->toBe('a');
            expect($result[0]['param_b'])->toBe('b');
        });

        it('returns all pairs for three params', function () {
            $runs = [
                ['params' => ['a' => 1, 'b' => 1, 'c' => 1], 'stats' => ['score' => '1.0']],
                ['params' => ['a' => 1, 'b' => 2, 'c' => 1], 'stats' => ['score' => '2.0']],
                ['params' => ['a' => 2, 'b' => 1, 'c' => 2], 'stats' => ['score' => '3.0']],
                ['params' => ['a' => 2, 'b' => 2, 'c' => 2], 'stats' => ['score' => '4.0']],
            ];

            $service = new ParameterSensitivityService($runs);
            $result = $service->interactions('score');

            expect($result)->toHaveCount(3); // a×b, a×c, b×c
        });
    });
});