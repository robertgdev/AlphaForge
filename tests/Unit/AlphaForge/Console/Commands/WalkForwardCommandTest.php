<?php

use App\AlphaForge\Console\Commands\WalkForwardCommand;
use Illuminate\Console\Command;

describe('WalkForwardCommand validation', function () {
    it('has all new options in signature', function () {
        $ref = new ReflectionClass(WalkForwardCommand::class);
        $defaultProps = $ref->getDefaultProperties();
        $signature = $defaultProps['signature'];

        expect($signature)->toContain('--execution-timeframe=')
            ->and($signature)->toContain('--min-trades=')
            ->and($signature)->toContain('--min-oos-days=')
            ->and($signature)->toContain('--force')
            ->and($signature)->toContain('--format=')
            ->and($signature)->toContain('--output=');
    });

    it('has all required arguments in signature', function () {
        $ref = new ReflectionClass(WalkForwardCommand::class);
        $defaultProps = $ref->getDefaultProperties();
        $signature = $defaultProps['signature'];

        expect($signature)->toContain('{strategy')
            ->and($signature)->toContain('{symbol')
            ->and($signature)->toContain('--split=')
            ->and($signature)->toContain('--oos-start=')
            ->and($signature)->toContain('--method=')
            ->and($signature)->toContain('--objective=')
            ->and($signature)->toContain('--top-n=')
            ->and($signature)->toContain('--use-strategy-ranges')
            ->and($signature)->toContain('--params=');
    });

    it('has description', function () {
        $ref = new ReflectionClass(WalkForwardCommand::class);
        $defaultProps = $ref->getDefaultProperties();
        expect($defaultProps['description'])->not->toBeEmpty();
    });

    it('extends Command', function () {
        expect(is_a(WalkForwardCommand::class, Command::class, true))->toBeTrue();
    });

    it('has handle method with correct dependencies', function () {
        $ref = new ReflectionClass(WalkForwardCommand::class);
        $method = $ref->getMethod('handle');
        $params = $method->getParameters();

        expect($params)->toHaveCount(5)
            ->and($params[0]->getName())->toBe('service')
            ->and($params[1]->getName())->toBe('analyzer')
            ->and($params[2]->getName())->toBe('exporter')
            ->and($params[3]->getName())->toBe('inputParser')
            ->and($params[4]->getName())->toBe('dataAutoGenerator');
    });

    it('has private displayResultsTable method', function () {
        $ref = new ReflectionClass(WalkForwardCommand::class);
        expect($ref->hasMethod('displayResultsTable'))->toBeTrue()
            ->and($ref->getMethod('displayResultsTable')->isPrivate())->toBeTrue();
    });

    it('has private displaySummary method', function () {
        $ref = new ReflectionClass(WalkForwardCommand::class);
        expect($ref->hasMethod('displaySummary'))->toBeTrue()
            ->and($ref->getMethod('displaySummary')->isPrivate())->toBeTrue();
    });

    it('has private outputResult method', function () {
        $ref = new ReflectionClass(WalkForwardCommand::class);
        expect($ref->hasMethod('outputResult'))->toBeTrue()
            ->and($ref->getMethod('outputResult')->isPrivate())->toBeTrue();
    });
});
