<?php

use App\AlphaForge\Common\Model\MutableSeries;

describe('MutableSeries', function () {
    it('can be created with initial values', function () {
        $series = new MutableSeries(['10', '20', '30']);

        expect($series->count())->toBe(3);
    });

    it('can be created with empty values', function () {
        $series = new MutableSeries([]);

        expect($series->count())->toBe(0);
    });

    it('can append values', function () {
        $series = new MutableSeries(['10']);
        $series->append('20');

        expect($series->count())->toBe(2);
        expect($series->toArray())->toBe(['10', '20']);
    });

    it('appends null when value is false', function () {
        $series = new MutableSeries(['10']);
        $series->append(false);

        expect($series->count())->toBe(2);
        expect($series->toArray()[1])->toBeNull();
    });

    it('resolves offsets relative to current index', function () {
        $series = new MutableSeries(['10', '20', '30', '40', '50']);
        $series->setCurrentIndex(3);

        expect($series[0])->toBe('40')
            ->and($series[1])->toBe('30')
            ->and($series[2])->toBe('20');
    });

    it('returns null for out-of-bounds offset', function () {
        $series = new MutableSeries(['10', '20']);
        $series->setCurrentIndex(0);

        expect($series[5])->toBeNull();
    });

    it('offsetExists returns false for out-of-bounds', function () {
        $series = new MutableSeries(['10', '20']);
        $series->setCurrentIndex(0);

        expect(isset($series[5]))->toBeFalse();
    });

    it('offsetExists returns false for negative offset', function () {
        $series = new MutableSeries(['10', '20']);

        expect(isset($series[-1]))->toBeFalse();
    });

    it('offsetGet returns null for negative offset', function () {
        $series = new MutableSeries(['10', '20']);

        expect($series[-1])->toBeNull();
    });

    it('setCurrentIndex throws for out-of-bounds index', function () {
        $series = new MutableSeries(['10', '20']);

        $series->setCurrentIndex(999);
    })->throws(OutOfBoundsException::class);

    it('setCurrentIndex throws for negative index', function () {
        $series = new MutableSeries(['10', '20']);

        $series->setCurrentIndex(-1);
    })->throws(OutOfBoundsException::class);

    it('can set current index to valid position', function () {
        $series = new MutableSeries(['10', '20', '30']);
        $series->setCurrentIndex(1);

        expect($series[0])->toBe('20')
            ->and($series[1])->toBe('10');
    });

    it('defaults current index to last element on construction', function () {
        $series = new MutableSeries(['10', '20', '30']);

        expect($series[0])->toBe('30')
            ->and($series[1])->toBe('20');
    });
});
