<?php

namespace App\AlphaForge\ExitRule;

class ExitRuleSet
{
    /** @var list<PriceBasedExitRule> */
    private array $priceRules = [];

    /** @var list<ExitRuleInterface> */
    private array $signalRules = [];

    public function __construct(array $rules = [])
    {
        foreach ($rules as $rule) {
            $this->add($rule);
        }
    }

    public function add(ExitRuleInterface $rule): self
    {
        if ($rule instanceof PriceBasedExitRule) {
            $this->priceRules[] = $rule;
        } else {
            $this->signalRules[] = $rule;
        }

        return $this;
    }

    public function evaluate(ExitContext $context): ?ExitTrigger
    {
        foreach ($this->priceRules as $rule) {
            $trigger = $rule->evaluate($context);
            if ($trigger !== null) {
                return $trigger;
            }
        }

        foreach ($this->signalRules as $rule) {
            $trigger = $rule->evaluate($context);
            if ($trigger !== null) {
                return $trigger;
            }
        }

        return null;
    }
}
