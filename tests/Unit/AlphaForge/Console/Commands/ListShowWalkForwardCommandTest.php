<?php

use App\AlphaForge\Console\Commands\ListWalkForwardRunsCommand;
use App\AlphaForge\Console\Commands\ShowWalkForwardRunCommand;
use Illuminate\Console\Command;

describe('ListWalkForwardRunsCommand', function () {
    it('has correct command signature', function () {
        $ref = new ReflectionClass(ListWalkForwardRunsCommand::class);
        $defaultProps = $ref->getDefaultProperties();

        expect($defaultProps['signature'])->toContain('alphaforge:walk-forward:list')
            ->and($defaultProps['signature'])->toContain('--strategy=')
            ->and($defaultProps['signature'])->toContain('--status=')
            ->and($defaultProps['signature'])->toContain('--limit=');
    });

    it('has description', function () {
        $ref = new ReflectionClass(ListWalkForwardRunsCommand::class);
        $defaultProps = $ref->getDefaultProperties();

        expect($defaultProps['description'])->not->toBeEmpty();
    });

    it('extends Command', function () {
        expect(is_a(ListWalkForwardRunsCommand::class, Command::class, true))->toBeTrue();
    });
});

describe('ShowWalkForwardRunCommand', function () {
    it('has correct command signature', function () {
        $ref = new ReflectionClass(ShowWalkForwardRunCommand::class);
        $defaultProps = $ref->getDefaultProperties();

        expect($defaultProps['signature'])->toContain('alphaforge:walk-forward:show')
            ->and($defaultProps['signature'])->toContain('{run_id')
            ->and($defaultProps['signature'])->toContain('--top=')
            ->and($defaultProps['signature'])->toContain('--format=')
            ->and($defaultProps['signature'])->toContain('--output=');
    });

    it('has description', function () {
        $ref = new ReflectionClass(ShowWalkForwardRunCommand::class);
        $defaultProps = $ref->getDefaultProperties();

        expect($defaultProps['description'])->not->toBeEmpty();
    });

    it('extends Command', function () {
        expect(is_a(ShowWalkForwardRunCommand::class, Command::class, true))->toBeTrue();
    });
});
