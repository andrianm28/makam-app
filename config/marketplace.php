<?php

declare(strict_types=1);

return [
    /*
     * The `badan_usaha` (legal business entity) marketplace money settles
     * under. No registry or closed list for this value exists anywhere in the
     * platform — `vendor_payables.entity_ref` and `journal_batches.entity_ref`
     * are open strings validated only as non-blank — so it is configured, never
     * derived and never defaulted. A blank value fails checkout closed rather
     * than misattributing money to a placeholder entity (requirement 10).
     */
    'badan_usaha_ref' => env('MARKETPLACE_BADAN_USAHA_REF'),
];
