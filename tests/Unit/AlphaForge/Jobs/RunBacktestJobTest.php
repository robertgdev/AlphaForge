<?php

use App\AlphaForge\Backtesting\Model\BacktestRun;
use App\AlphaForge\Backtesting\Service\BacktestRunService;
use App\AlphaForge\Jobs\RunBacktestJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

describe('RunBacktestJob', function () {
    describe('job configuration', function () {
        it('has correct number of tries', function () {
            $ref = new ReflectionClass(RunBacktestJob::class);
            $prop = $ref->getProperty('tries');

            expect($prop->getDefaultValue())->toBe(1);
        });

        it('has correct timeout', function () {
            $ref = new ReflectionClass(RunBacktestJob::class);
            $prop = $ref->getProperty('timeout');

            expect($prop->getDefaultValue())->toBe(3600);
        });

        it('implements ShouldQueue', function () {
            expect(is_a(RunBacktestJob::class, ShouldQueue::class, true))->toBeTrue();
        });

        it('uses correct traits', function () {
            $traits = class_uses(RunBacktestJob::class);

            expect($traits)->toHaveKey(Queueable::class)
                ->and($traits)->toHaveKey(InteractsWithQueue::class)
                ->and($traits)->toHaveKey(Dispatchable::class)
                ->and($traits)->toHaveKey(SerializesModels::class);
        });
    });

    describe('handle method', function () {
        it('has handle method that accepts BacktestRunService', function () {
            $ref = new ReflectionClass(RunBacktestJob::class);
            $method = $ref->getMethod('handle');

            expect($method->isPublic())->toBeTrue();

            $params = $method->getParameters();
            expect($params)->toHaveCount(1);

            $type = $params[0]->getType();
            expect($type)->not->toBeNull();
            expect($type->getName())->toBe(BacktestRunService::class);
        });
    });

    describe('failed method', function () {
        it('has failed method that accepts Throwable', function () {
            $ref = new ReflectionClass(RunBacktestJob::class);
            $method = $ref->getMethod('failed');

            expect($method->isPublic())->toBeTrue();

            $params = $method->getParameters();
            expect($params)->toHaveCount(1);

            $type = $params[0]->getType();
            expect($type)->not->toBeNull();
            expect($type->getName())->toBe(Throwable::class);
        });
    });

    describe('constructor', function () {
        it('accepts BacktestRun as parameter', function () {
            $ref = new ReflectionClass(RunBacktestJob::class);
            $constructor = $ref->getConstructor();

            $params = $constructor->getParameters();
            expect($params)->toHaveCount(1);

            $type = $params[0]->getType();
            expect($type)->not->toBeNull();
            expect($type->getName())->toBe(BacktestRun::class);
        });
    });
});
