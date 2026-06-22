<?php

use App\AlphaForge\ExitRule\DefaultExitRules;
use App\AlphaForge\ExitRule\ExitRuleSet;
use App\AlphaForge\Strategy\Dto\BarData;
use App\AlphaForge\Strategy\Dto\InitializeData;
use App\AlphaForge\Strategy\StrategyInterface;

describe('DefaultExitRules trait', function () {
    it('returns null from getExitRules', function () {
        $strategy = new class implements StrategyInterface
        {
            use DefaultExitRules;

            public function configure(array $runtimeParameters): void {}

            public function initialize(InitializeData $data): void {}

            public function onBar(BarData $data): array
            {
                return [];
            }
        };

        expect($strategy->getExitRules())->toBeNull();
    });

    it('can be overridden by the strategy', function () {
        $strategy = new class implements StrategyInterface
        {
            use DefaultExitRules;

            public function configure(array $runtimeParameters): void {}

            public function initialize(InitializeData $data): void {}

            public function onBar(BarData $data): array
            {
                return [];
            }

            public function getExitRules(): ?ExitRuleSet
            {
                return new ExitRuleSet;
            }
        };

        expect($strategy->getExitRules())->toBeInstanceOf(ExitRuleSet::class);
    });
});
