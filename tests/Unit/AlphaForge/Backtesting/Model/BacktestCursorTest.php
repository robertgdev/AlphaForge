<?php

use App\AlphaForge\Backtesting\Model\BacktestCursor;

describe('BacktestCursor', function () {
    it('initializes currentIndex to zero', function () {
        $cursor = new BacktestCursor;

        expect($cursor->currentIndex)->toBe(0);
    });

    it('initializes executionIndex to zero', function () {
        $cursor = new BacktestCursor;

        expect($cursor->executionIndex)->toBe(0);
    });

    it('allows setting currentIndex', function () {
        $cursor = new BacktestCursor;
        $cursor->currentIndex = 42;

        expect($cursor->currentIndex)->toBe(42);
    });

    it('allows setting executionIndex', function () {
        $cursor = new BacktestCursor;
        $cursor->executionIndex = 10;

        expect($cursor->executionIndex)->toBe(10);
    });

    it('allows independent modification of both indices', function () {
        $cursor = new BacktestCursor;
        $cursor->currentIndex = 100;
        $cursor->executionIndex = 50;

        expect($cursor->currentIndex)->toBe(100)
            ->and($cursor->executionIndex)->toBe(50);
    });
});
