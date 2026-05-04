<?php

namespace App\AlphaForge\Common\Enum;

enum AppliedPriceEnum: string
{
    case Open = 'open';
    case High = 'high';
    case Low = 'low';
    case Close = 'close';
}
