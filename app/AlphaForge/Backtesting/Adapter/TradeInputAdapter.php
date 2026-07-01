<?php

namespace App\AlphaForge\Backtesting\Adapter;

use RobertGDev\AlphaforgeStatistics\Trade\TradeInput;
use App\AlphaForge\Order\Dto\PositionDto;
use Ds\Vector;

class TradeInputAdapter
{
    /**
     * Convert a Vector of PositionDto to a Vector of TradeInput.
     *
     * @param  Vector<PositionDto>  $positions
     * @return Vector<TradeInput>
     */
    public static function fromPositions(Vector $positions): Vector
    {
        $result = new Vector;

        foreach ($positions as $position) {
            $result->push(new TradeInput(
                id: $position->id,
                direction: $position->direction,
                entryPrice: $position->entryPrice,
                exitPrice: $position->exitPrice,
                entryTimestamp: (int) $position->entryTime->timestamp,
                exitTimestamp: $position->exitTime ? (int) $position->exitTime->timestamp : null,
                realizedPnl: $position->realizedPnl,
                exitTag: $position->exitTag,
            ));
        }

        return $result;
    }
}