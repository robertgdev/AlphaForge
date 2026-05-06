<?php

use App\AlphaForge\Common\Model\Series;
use Ds\Vector;

describe('Series', function () {
    beforeEach(function () {
        $this->values = ['10.5', '20.3', '15.7', '25.1', '18.9'];
        $this->series = new Series($this->values);
    });

    it('can be created with values', function () {
        expect($this->series->count())->toBe(5);
    });

    it('implements ArrayAccess for values', function () {
        expect($this->series[0])->toBe('18.9');  // Last value (index 0 = current)
        expect($this->series[4])->toBe('10.5');  // First value (index 4 = oldest)
    });

    it('can check if offset exists', function () {
        expect(isset($this->series[0]))->toBeTrue();
        expect(isset($this->series[10]))->toBeFalse();
    });

    it('throws exception when trying to set value', function () {
        $this->series[0] = 'new_value';
    })->throws(LogicException::class);

    it('throws exception when trying to unset value', function () {
        unset($this->series[0]);
    })->throws(LogicException::class);

    it('returns current and previous values', function () {
        expect($this->series->current())->toBe('18.9');
        expect($this->series->previous())->toBe('25.1');
    });

    it('can convert to array', function () {
        expect($this->series->toArray())->toBe($this->values);
    });

    it('can get the underlying vector', function () {
        expect($this->series->getVector())->toBeInstanceOf(Vector::class);
    });

    it('can check crosses over another series', function () {
        $series1 = new Series([10, 12]);  // Previous=10, Current=12
        $series2 = new Series([15, 11]);  // Previous=15, Current=11

        // 10 <= 15 and 12 > 11 = true (crosses over)
        expect($series1->crossesOver($series2))->toBeTrue();
    });

    it('can check crosses under another series', function () {
        $series1 = new Series([15, 11]);  // Previous=15, Current=11
        $series2 = new Series([10, 12]);  // Previous=10, Current=12

        // 15 >= 10 and 11 < 12 = true (crosses under)
        expect($series1->crossesUnder($series2))->toBeTrue();
    });
});
