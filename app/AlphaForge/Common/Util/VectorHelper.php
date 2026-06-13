<?php

namespace App\AlphaForge\Common\Util;

use Ds\Vector;

final class VectorHelper
{
    /**
     * Convert any iterable (array, generator, Traversable) to a Ds\Vector.
     *
     * Works with generators that use yield — PHP's foreach iterates
     * generators natively since they implement Iterator (a subtype of
     * iterable). For very large generators, be aware that the entire
     * result set is materialized into memory.
     *
     * @template T
     * @param  iterable<T>  $iterable
     * @return Vector<T>
     */
    public static function fromIterable(iterable $iterable): Vector
    {
        $vector = new Vector;
        foreach ($iterable as $value) {
            $vector->push($value);
        }

        return $vector;
    }
}
