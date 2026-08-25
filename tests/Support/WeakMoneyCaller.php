<?php

namespace Tests\Support;

use App\Platform\FinancialLedger\Money;

final class WeakMoneyCaller
{
    public static function construct(float $minorUnits): Money
    {
        return new Money($minorUnits);
    }
}
