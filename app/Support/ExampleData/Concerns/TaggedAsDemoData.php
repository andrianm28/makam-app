<?php

declare(strict_types=1);

namespace App\Support\ExampleData\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * The single place every demo generator marks a row as this subsystem's
 * own, so `demo-data:purge` (Task 11) can find and remove it. Deliberately
 * a plain static helper, not a trait mixed into every touched model — the
 * models this subsystem writes to (Order, Renewal, CarePlan, Certificate,
 * VisitationBooking, ...) belong to many different domains this subsystem does
 * not own; adding a trait to each would mean touching files outside this
 * subsystem's boundary for no real benefit over a one-line call at the
 * generator's own call site.
 */
final class TaggedAsDemoData
{
    public static function tag(Model $model, string $batchId): void
    {
        $model->forceFill(['demo_batch_id' => $batchId])->save();
    }
}
