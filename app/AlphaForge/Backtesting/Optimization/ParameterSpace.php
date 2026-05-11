<?php

namespace App\AlphaForge\Backtesting\Optimization;

use App\AlphaForge\Strategy\Attribute\Input;
use App\AlphaForge\Strategy\Service\StrategyRegistryInterface;

class ParameterSpace
{
    /**
     * @param  array<string, ParameterDimension>  $dimensions
     */
    public function __construct(
        public readonly array $dimensions,
    ) {}

    public static function fromStrategy(string $strategyAlias, StrategyRegistryInterface $registry): self
    {
        $strategy = $registry->get($strategyAlias);
        $reflection = new \ReflectionClass($strategy);

        $dimensions = [];

        foreach ($reflection->getProperties() as $property) {
            $inputAttributes = $property->getAttributes(Input::class);

            if (empty($inputAttributes)) {
                continue;
            }

            $inputAttribute = $inputAttributes[0]->newInstance();

            if ($inputAttribute->min === null || $inputAttribute->max === null) {
                continue;
            }

            $propertyType = $property->getType();
            $typeName = ($propertyType instanceof \ReflectionNamedType) ? $propertyType->getName() : 'int';

            $dimensions[$property->getName()] = new ParameterDimension(
                name: $property->getName(),
                min: $inputAttribute->min,
                max: $inputAttribute->max,
                step: (float) ($inputAttribute->step ?? 1),
                type: $typeName === 'float' ? 'float' : 'int',
            );
        }

        return new self($dimensions);
    }

    public static function fromArray(array $ranges): self
    {
        $dimensions = [];

        foreach ($ranges as $name => $config) {
            $dimensions[$name] = new ParameterDimension(
                name: $name,
                min: (float) $config['min'],
                max: (float) $config['max'],
                step: (float) ($config['step'] ?? 1),
                type: ($config['type'] ?? 'int') === 'float' ? 'float' : 'int',
            );
        }

        return new self($dimensions);
    }

    public function gridSize(): int
    {
        if (empty($this->dimensions)) {
            return 0;
        }

        $size = 1;
        foreach ($this->dimensions as $dimension) {
            $size *= $dimension->count();
        }

        return $size;
    }

    public function toArray(): array
    {
        $result = [];
        foreach ($this->dimensions as $name => $dimension) {
            $result[$name] = [
                'min' => $dimension->min,
                'max' => $dimension->max,
                'step' => $dimension->step,
                'type' => $dimension->type,
            ];
        }

        return $result;
    }
}
