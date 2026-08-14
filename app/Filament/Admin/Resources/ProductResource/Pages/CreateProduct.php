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
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Create page for `ProductResource`. Submission overrides
 * `handleRecordCreation()` (verified against the installed
 * `Filament\Resources\Pages\CreateRecord` source, same as
 * `Pages\CreateFaqArticle`'s doc block) so the write goes through the
 * `Product` model's own save path — the `saving` hook asserts the code and
 * category are canonical closed-list values — and is wrapped in
 * `Audit::wrap()` so the row and its `PRODUCT_CREATED` audit record commit
 * in the same transaction.
 *
 * `price_version` is forced to `1` here: a brand-new row is the first cut
 * of its definition, matching the create-table migration's documented
 * semantics ("the first published cut of this catalogue entry"). The
 * backfill migration bumped the nine seeded rows to `2` when it wrote
 * their first real price — this row's own first price IS version 1.
 */
final class CreateProduct extends CreateRecord
{
    protected static string $resource = ProductResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $data['price_version'] = 1;

        return Audit::wrap(
            fn (): Product => Product::create($data),
            action: ProductAuditActions::CREATED,
            subject: fn (Product $saved): AuditSubject => new AuditSubject(
                type: 'product',
                id: $saved->id,
                version: $saved->price_version,
            ),
            outcome: AuditOutcome::Allowed,
            actorRef: Auth::id() ?? 0,
            actorRole: ProductResource::auditRoleFor(app(ActorContext::class)),
            source: AuditSource::Panel,
        );
    }
}
