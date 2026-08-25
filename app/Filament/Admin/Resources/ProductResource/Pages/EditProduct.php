<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ProductResource\Pages;

use App\Domain\Marketplace\Models\Product;
use App\Domain\Marketplace\ProductAuditActions;
use App\Filament\Admin\Resources\ProductResource\ProductResource;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\IdentityAccess\ActorContext;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Edit page for `ProductResource`. Overrides `handleRecordUpdate()`
 * (verified against the installed `Filament\Resources\Pages\EditRecord`
 * source, same as `Pages\EditFaqArticle`'s doc block) so:
 *
 *  - a base-price change bumps `price_version` by exactly 1 — the column's
 *    documented "a new cut of the definition" semantics (the backfill
 *    migration bumped the nine seeded rows to `2` when it wrote their first
 *    real price; an admin price edit is the next cut). A save that does not
 *    touch the price leaves the version untouched, so unrelated edits do
 *    not inflate it;
 *  - the save runs through the `Product` model (`saving` hook: closed-list
 *    assertions), never a raw bypass;
 *  - the save and its `PRODUCT_UPDATED` audit record commit together via
 *    `Audit::wrap()` (AC4). `PRODUCT_UPDATED` is on
 *    `SensitiveActions::ACTIONS`, so a non-blank `reason` is mandatory —
 *    the form enforces it (`Schemas\ProductForm`'s edit-only reason field);
 *    this page removes it from the model payload after reading it for the
 *    audit row.
 */
final class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Product $record */
        $nextBasePrice = $data['base_price_idr'] ?? null;

        $priceChanged = match (true) {
            $nextBasePrice === null && $record->base_price_idr === null => false,
            $nextBasePrice === null || $record->base_price_idr === null => true,
            default => (int) $nextBasePrice !== $record->base_price_idr,
        };

        if ($priceChanged) {
            $data['price_version'] = $record->price_version + 1;
        }

        $reason = filled($data['reason'] ?? null) ? (string) $data['reason'] : null;
        unset($data['reason']);

        return Audit::wrap(
            function () use ($record, $data): Product {
                $record->update($data);

                return $record;
            },
            action: ProductAuditActions::UPDATED,
            subject: fn (Product $saved): AuditSubject => new AuditSubject(
                type: 'product',
                id: $saved->id,
                version: $saved->price_version,
            ),
            outcome: AuditOutcome::Allowed,
            actorRef: Auth::id() ?? 0,
            actorRole: ProductResource::auditRoleFor(app(ActorContext::class)),
            source: AuditSource::Panel,
            reason: $reason,
        );
    }
}
