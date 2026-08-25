<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Abandoned draft retention
    |--------------------------------------------------------------------------
    |
    | How many days an untouched booking draft is kept before
    | `booking:purge-stale-drafts` deletes it. Measured against `updated_at`,
    | so a draft still being worked on is never caught by the sweep.
    |
    | From Step 6 a draft holds customer and deceased personal data, so this
    | window is a privacy control, not a housekeeping convenience: it bounds
    | how long that data survives an abandoned booking. Shorten it freely;
    | lengthening it needs a reason.
    |
    */

    'draft_retention_days' => (int) env('BOOKING_DRAFT_RETENTION_DAYS', 30),

];
