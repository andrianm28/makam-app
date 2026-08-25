<?php

declare(strict_types=1);

namespace App\Domain\VendorFulfillment;

/**
 * Before/after evidence types for work order photographs.
 */
enum EvidenceType: string
{
    case Before = 'before';

    case After = 'after';

    /**
     * @var list<string>
     */
    public const array KNOWN_TYPES = [
        self::Before->value,
        self::After->value,
    ];
}
