<?php

use App\AlphaForge\Jobs\RunBacktestJob;

describe('RunBacktestJob', function () {
    describe('job configuration', function () {
        it('has correct number of tries', function () {
            $ref = new \ReflectionClass(RunBacktestJob::class);
            $prop = $ref->getProperty('tries');

            expect($prop->getDefaultValue())->toBe(1);
        });

        it('has correct timeout', function () {
            $ref = new \ReflectionClass(RunBacktestJob::class);
            $prop = $ref->getProperty('timeout');

            expect($prop->getDefaultValue())->toBe(3600);
        });

        it('implements ShouldQueue', function () {
            expect(is_a(RunBacktestJob::class, \Illuminate\Contracts\Queue\ShouldQueue::class, true))->toBeTrue();
        });

        it('uses correct traits', function () {
            $traits = class_uses(RunBacktestJob::class);

            expect($traits)->toHaveKey(\Illuminate\Bus\Queueable::class)
                ->and($traits)->toHaveKey(\Illuminate\Queue\InteractsWithQueue::class)
                ->and($traits)->toHaveKey(\Illuminate\Foundation\Bus\Dispatchable::class)
                ->and($traits)->toHaveKey(\Illuminate\Queue\SerializesModels::class);
        });
    });

    describe('handle method', function () {
        it('has handle method that accepts BacktestRunService', function () {
            $ref = new \ReflectionClass(RunBacktestJob::class);
            $method = $ref->getMethod('handle');

            expect($method->isPublic())->toBeTrue();

            $params = $method->getParameters();
            expect($params)->toHaveCount(1);

            $type = $params[0]->getType();
            expect($type)->not->toBeNull();
            expect($type->getName())->toBe(\App\AlphaForge\Backtesting\Service\BacktestRunService::class);
        });
    });

    describe('failed method', function () {
        it('has failed method that accepts Throwable', function () {
            $ref = new \ReflectionClass(RunBacktestJob::class);
            $method = $ref->getMethod('failed');

            expect($method->isPublic())->toBeTrue();

            $params = $method->getParameters();
            expect($params)->toHaveCount(1);

            $type = $params[0]->getType();
            expect($type)->not->toBeNull();
            expect($type->getName())->toBe(\Throwable::class);
        });
    });

    describe('constructor', function () {
        it('accepts BacktestRun as parameter', function () {
            $ref = new \ReflectionClass(RunBacktestJob::class);
            $constructor = $ref->getConstructor();

            $params = $constructor->getParameters();
            expect($params)->toHaveCount(1);

            $type = $params[0]->getType();
            expect($type)->not->toBeNull();
            expect($type->getName())->toBe(\App\AlphaForge\Backtesting\Model\BacktestRun::class);
        });
    });
});
