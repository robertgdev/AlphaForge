<?php

namespace App\AlphaForge\Strategy\Service;

use App\AlphaForge\Strategy\Attribute\AsStrategy;
use App\AlphaForge\Strategy\Attribute\Input;
use App\AlphaForge\Strategy\Dto\InputDefinitionDto;
use App\AlphaForge\Strategy\Dto\StrategyDefinitionDto;
use App\AlphaForge\Strategy\StrategyInterface;
use Illuminate\Support\Facades\File;

class StrategyRegistry implements StrategyRegistryInterface
{
/** @var array<string, StrategyDefinitionDto> */
    private array $definitions;

    /** @var array<string, AsStrategy> */
    private array $metadata;

    /** @var array<string, class-string<StrategyInterface>> */
    private array $classMap;

    /** @var array<string, StrategyInterface> */
    private array $instances;

    public function __construct()
    {
        /** @var array<string, StrategyDefinitionDto> $definitions */
        $this->definitions = [];
        /** @var array<string, AsStrategy> $metadata */
        $this->metadata = [];
        /** @var array<string, class-string<StrategyInterface>> $classMap */
        $this->classMap = [];
        /** @var array<string, StrategyInterface> $instances */
        $this->instances = [];

        $this->discoverStrategies();
    }

    /**
     * Discover all strategies in the configured path.
     */
    private function discoverStrategies(): void
    {
        $strategyPath = config('alphaforge.strategies.path', app_path('AlphaForge/Strategy/Concretes'));
        $baseNamespace = config('alphaforge.strategies.namespace', 'App\\AlphaForge\\Strategy\\Concretes');

        if (! File::isDirectory($strategyPath)) {
            return;
        }

        $files = File::allFiles($strategyPath);

        foreach ($files as $file) {
            $className = $this->getClassNameFromFile($file->getPathname(), $strategyPath);

            if ($className === null) {
                continue;
            }

            $fullClassName = $baseNamespace.'\\'.$className;

            if (! class_exists($fullClassName)) {
                continue;
            }

            $reflectionClass = new \ReflectionClass($fullClassName);

            if (! $reflectionClass->implementsInterface(StrategyInterface::class)) {
                continue;
            }

            $asStrategyAttributes = $reflectionClass->getAttributes(AsStrategy::class);

            if (empty($asStrategyAttributes)) {
                continue;
            }

            /** @var AsStrategy $asStrategy */
            $asStrategy = $asStrategyAttributes[0]->newInstance();
            $alias = $asStrategy->alias;

            $this->metadata[$alias] = $asStrategy;
            $this->classMap[$alias] = $fullClassName;

            // Build inputs
            $inputs = $this->extractInputs($reflectionClass);

            $this->definitions[$alias] = new StrategyDefinitionDto(
                alias: $alias,
                name: $asStrategy->name,
                description: $asStrategy->description,
                inputs: $inputs,
                timeframe: $asStrategy->timeframe?->value,
                requiredMarketData: array_map(fn ($tf) => $tf->value, $asStrategy->requiredMarketData)
            );
        }
    }

    /**
     * Extract input definitions from a strategy class.
     */
    private function extractInputs(\ReflectionClass $reflectionClass): array
    {
        /** @var list<array{name: string, description: string|null, type: string, default: mixed, min: float|int|null, max: float|int|null, choices: list<string>|null, minChoices: int|null, maxChoices: int|null}> $inputs */
        $inputs = [];

        foreach ($reflectionClass->getProperties(\ReflectionProperty::IS_PUBLIC | \ReflectionProperty::IS_PROTECTED | \ReflectionProperty::IS_PRIVATE) as $property) {
            $inputAttributes = $property->getAttributes(Input::class);

            if (empty($inputAttributes)) {
                continue;
            }

            /** @var Input $inputAttribute */
            $inputAttribute = $inputAttributes[0]->newInstance();
            $propertyName = $property->getName();
            $propertyType = $property->getType();

            $jsonType = 'string';
            $choices = $inputAttribute->choices;
            $defaultValue = $property->hasDefaultValue() ? $property->getDefaultValue() : null;

            if ($propertyType instanceof \ReflectionNamedType) {
                $typeName = $propertyType->getName();

                if (is_subclass_of($typeName, \BackedEnum::class)) {
                    $jsonType = 'string';
                    if ($choices === null) {
                        $choices = array_column($typeName::cases(), 'value');
                    }
                    if ($defaultValue instanceof \BackedEnum) {
                        $defaultValue = $defaultValue->value;
                    }
                } elseif ($typeName === 'array' && $inputAttribute->arrayType && is_subclass_of($inputAttribute->arrayType, \BackedEnum::class)) {
                    $jsonType = 'array';
                    $choices = array_column($inputAttribute->arrayType::cases(), 'value');
                    if (is_array($defaultValue)) {
                        $defaultValue = array_map(fn ($enum) => $enum->value, $defaultValue);
                    }
                } else {
                    $jsonType = match ($typeName) {
                        'int' => 'integer',
                        'float' => 'number',
                        'bool' => 'boolean',
                        'string' => 'string',
                        'array' => 'array',
                        default => 'string',
                    };
                }
            }

            $inputs[] = new InputDefinitionDto(
                name: $propertyName,
                description: $inputAttribute->description,
                type: $jsonType,
                defaultValue: $defaultValue,
                min: $inputAttribute->min,
                max: $inputAttribute->max,
                choices: $choices,
                minChoices: $inputAttribute->minChoices,
                maxChoices: $inputAttribute->maxChoices,
                step: $inputAttribute->step,
            );
        }

        return $inputs;
    }

    /**
     * Get class name from file path.
     */
    private function getClassNameFromFile(string $filePath, string $basePath): ?string
    {
        $relativePath = str_replace($basePath.DIRECTORY_SEPARATOR, '', $filePath);
        $className = str_replace(
            ['/', '.php'],
            ['\\', ''],
            $relativePath
        );

        return $className ?: null;
    }

    /**
     * Check if a strategy exists.
     */
    public function has(string $alias): bool
    {
        return isset($this->metadata[$alias]);
    }

    /**
     * Get a strategy instance by alias.
     */
    public function get(string $alias): StrategyInterface
    {
        if (! isset($this->metadata[$alias])) {
            throw new \InvalidArgumentException("Strategy '{$alias}' not found");
        }

        if (! isset($this->instances[$alias])) {
            $className = $this->classMap[$alias];
            $this->instances[$alias] = new $className;
        }

        return $this->instances[$alias];
    }

    /**
     * Get all registered strategies.
     */
    public function all(): array
    {
        return $this->metadata;
    }

    /**
     * Get strategy definition by alias.
     *
     * @return array{name: string, description: string|null, inputs: list<array{name: string, description: string|null, type: string, default: mixed, min: float|int|null, max: float|int|null, choices: list<string>|null, minChoices: int|null, maxChoices: int|null}>, timeframes: list<string>}
     */
    public function getDefinition(string $alias): array
    {
        if (! isset($this->definitions[$alias])) {
            throw new \InvalidArgumentException("Strategy '{$alias}' not found");
        }

        $definition = $this->definitions[$alias];

        return [
            'name' => $definition->name,
            'description' => $definition->description,
            'inputs' => array_map(fn ($input) => [
                'name' => $input->name,
                'description' => $input->description,
                'type' => $input->type,
                'default' => $input->defaultValue,
                'min' => $input->min,
                'max' => $input->max,
                'step' => $input->step,
                'choices' => $input->choices,
            ], $definition->inputs),
            'timeframes' => $definition->requiredMarketData,
        ];
    }

    /**
     * Get all strategy definitions.
     *
     * @return list<StrategyDefinitionDto>
     */
    public function getStrategyDefinitions(): array
    {
        return array_values($this->definitions);
    }

    /**
     * Get strategy metadata by alias.
     */
    public function getMetadata(string $alias): ?AsStrategy
    {
        return $this->metadata[$alias] ?? null;
    }
}
