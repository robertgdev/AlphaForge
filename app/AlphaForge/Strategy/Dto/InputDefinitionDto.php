<?php

namespace App\AlphaForge\Strategy\Dto;

final readonly class InputDefinitionDto
{
    public function __construct(
        public string $name,
        public ?string $description,
        public string $type,
        public mixed $defaultValue = null,
        public ?float $min = null,
        public ?float $max = null,
        public ?array $choices = null,
        public ?int $minChoices = null,
        public ?int $maxChoices = null,
        public float|int|null $step = null,
    ) {}
}
