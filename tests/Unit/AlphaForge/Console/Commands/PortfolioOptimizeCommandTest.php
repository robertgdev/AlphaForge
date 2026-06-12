<?php

use App\AlphaForge\Console\Commands\PortfolioOptimizeCommand;
use Illuminate\Console\Command;

describe('PortfolioOptimizeCommand validation', function () {
    it('has execution-timeframe in signature', function () {
        $ref = new ReflectionClass(PortfolioOptimizeCommand::class);
        $defaultProps = $ref->getDefaultProperties();

        expect($defaultProps['signature'])->toContain('--execution-timeframe=');
    });

    it('has all key options in signature', function () {
        $ref = new ReflectionClass(PortfolioOptimizeCommand::class);
        $defaultProps = $ref->getDefaultProperties();
        $signature = $defaultProps['signature'];

        expect($signature)->toContain('alphaforge:optimize:portfolio')
            ->and($signature)->toContain('{strategy')
            ->and($signature)->toContain('{symbols*')
            ->and($signature)->toContain('--method=')
            ->and($signature)->toContain('--timeframe=')
            ->and($signature)->toContain('--execution-timeframe=')
            ->and($signature)->toContain('--exchange=')
            ->and($signature)->toContain('--start-date=')
            ->and($signature)->toContain('--end-date=')
            ->and($signature)->toContain('--top-n=')
            ->and($signature)->toContain('--min-trades=');
    });

    it('has description', function () {
        $ref = new ReflectionClass(PortfolioOptimizeCommand::class);
        $defaultProps = $ref->getDefaultProperties();
        expect($defaultProps['description'])->not->toBeEmpty();
    });

    it('extends Command', function () {
        expect(is_a(PortfolioOptimizeCommand::class, Command::class, true))->toBeTrue();
    });

    it('has private buildConfig method', function () {
        $ref = new ReflectionClass(PortfolioOptimizeCommand::class);
        expect($ref->hasMethod('buildConfig'))->toBeTrue()
            ->and($ref->getMethod('buildConfig')->isPrivate())->toBeTrue();
    });

    it('has private displayResults method', function () {
        $ref = new ReflectionClass(PortfolioOptimizeCommand::class);
        expect($ref->hasMethod('displayResults'))->toBeTrue()
            ->and($ref->getMethod('displayResults')->isPrivate())->toBeTrue();
    });

    it('has handle method with correct dependencies', function () {
        $ref = new ReflectionClass(PortfolioOptimizeCommand::class);
        $method = $ref->getMethod('handle');
        $params = $method->getParameters();

        expect($params)->toHaveCount(1)
            ->and($params[0]->getName())->toBe('optimizer');
    });
});
