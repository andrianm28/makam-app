<?php

declare(strict_types=1);

namespace App\Platform\IdentityAccess\Contracts;

use App\Platform\IdentityAccess\ActorContext;

/**
 * The AC4 mechanism: "THE SYSTEM SHALL require each panel (`/admin`,
 * `/vendor`, operator) to declare explicit access checks. THE SYSTEM SHALL
 * NOT grant record access on panel membership alone."
 *
 * One implementation per panel. `allows()` is deliberately given an
 * `ActorContext`, not a raw Eloquent `User`/`Authenticatable` — the point of
 * AC4 is that panel entry is an explicit authorization decision read from
 * the single resolved actor context (AC8), never "this row exists in the
 * `users` table" (panel membership alone) and never re-derived ad hoc
 * inside a Filament class.
 *
 * This batch (S3-T1) ships the mechanism and one real implementation,
 * `Panel\AdminPanelAccessPolicy`, for the one panel that exists today
 * (`/admin`, from Batch 2.4). It does NOT invent access rules for `/vendor`
 * or an operator panel — neither exists yet in this codebase, and the
 * batch brief is explicit that inventing rules for panels/resources that
 * don't exist is out of scope.
 */
interface PanelAccessPolicy
{
    public function allows(ActorContext $actor): bool;
}
