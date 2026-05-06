<?php

use App\AlphaForge\Strategy\Service\StrategyInputParser;

describe('StrategyInputParser', function () {
    beforeEach(function () {
        $this->parser = new StrategyInputParser;
    });

    it('parses valid JSON object', function () {
        $result = $this->parser->parseInputs('{"fastPeriod":10,"slowPeriod":20}');

        expect($result)->toBe(['fastPeriod' => 10, 'slowPeriod' => 20]);
    });

    it('parses nested JSON', function () {
        $result = $this->parser->parseInputs('{"params":{"fast":10},"enabled":true}');

        expect($result)->toBe(['params' => ['fast' => 10], 'enabled' => true]);
    });

    it('returns empty array for null input', function () {
        $result = $this->parser->parseInputs(null);

        expect($result)->toBe([]);
    });

    it('returns empty array for empty string', function () {
        $result = $this->parser->parseInputs('');

        expect($result)->toBe([]);
    });

    it('returns false for invalid JSON', function () {
        $result = $this->parser->parseInputs('{invalid json}');

        expect($result)->toBeFalse();
    });

    it('returns empty array for JSON scalar (not object)', function () {
        $result = $this->parser->parseInputs('"hello"');

        expect($result)->toBe([]);
    });

    it('returns indexed array for JSON array', function () {
        $result = $this->parser->parseInputs('[1,2,3]');

        expect($result)->toBe([1, 2, 3]);
    });

    it('parses JSON with string values', function () {
        $result = $this->parser->parseInputs('{"stakeAmount":"1000"}');

        expect($result)->toBe(['stakeAmount' => '1000']);
    });

    it('parses JSON with numeric values', function () {
        $result = $this->parser->parseInputs('{"period":14,"rate":0.5}');

        expect($result)->toBe(['period' => 14, 'rate' => 0.5]);
    });

    it('parses JSON with boolean values', function () {
        $result = $this->parser->parseInputs('{"enabled":true,"disabled":false}');

        expect($result)->toBe(['enabled' => true, 'disabled' => false]);
    });
});
