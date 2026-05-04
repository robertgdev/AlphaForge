<?php

namespace App\AlphaForge\Strategy\Service;

class StrategyInputParser
{
    /**
     * Parse a JSON string of strategy inputs into an array.
     *
     * @return array<string, mixed>|false  Returns false on invalid JSON, empty array on null/empty input.
     */
    public function parseInputs(?string $json): array|false
    {
        if (empty($json)) {
            return [];
        }

        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

            if (! is_array($decoded)) {
                return [];
            }

            /** @var array<string, mixed> $decoded */
            return $decoded;
        } catch (\JsonException) {
            return false;
        }
    }
}
