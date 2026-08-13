<?php

declare(strict_types=1);

namespace App\Filament\Vendor\Resources\VendorOrders\Schemas;

use App\Domain\Marketplace\VendorProcessingStatus;
use App\Filament\Vendor\Support\VendorOrderStatusPresenter;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rule;

/**
 * Edit form for `VendorOrderResource`. There is no create page, so this schema
 * is only ever rendered against an existing record.
 *
 * ---------------------------------------------------------------------------
 * Exactly two writable fields
 * ---------------------------------------------------------------------------
 * `status` and `notes` are the whole of what a vendor may change about an
 * order. Everything else on the screen — `uuid`, `customer_name`,
 * `customer_phone`, `customer_email`, and the ordered product — is context the
 * vendor needs in order to decide the status, not data they own.
 *
 * Those context fields are rendered `->disabled()`. Filament does not submit a
 * disabled field, and the decision is made server-side:
 * `Schemas\Components\Concerns\CanBeDisabled::disabled()` also calls
 * `saved(false)`, and `Schemas\Components\Concerns\HasState::isDehydrated()`
 * falls back to `isSaved()` — so a disabled field's key is simply absent from
 * the `$data` array the save ever sees. They are display-only, not a write path
 * that happens to be greyed out in the browser: a client that re-enables the
 * input still gets nothing through, because the schema, not the markup, decides
 * which keys are dehydrated. (Filament's own source comment on `disabled()`
 * makes the matching point that the greyed-out UI alone is not the control.)
 *
 * The ordered product is a `TextEntry`, not a disabled `TextInput`. Its value
 * lives across two relationships (`listing.product.name`); a form field fills
 * from the record's own attributes, so a disabled `TextInput` on that path
 * would render permanently blank. `TextEntry::getState()` resolves through the
 * relationship from the record instead. (`Forms\Components\Placeholder` would
 * also work but is deprecated in Filament 5 in favour of exactly this.) An
 * entry is not a field at all, so it likewise submits nothing.
 *
 * ---------------------------------------------------------------------------
 * No vendor picker, deliberately
 * ---------------------------------------------------------------------------
 * Unlike `VendorListingForm`, this schema has no `VendorPicker` and no
 * `vendor_id` field in any form. An order's vendor is fixed by the customer at
 * checkout; letting a vendor edit it would be a write path out of the actor's
 * own scope — the vendor could push an order onto someone else's queue, or pull
 * one in. The record itself is resolved through
 * `VendorOrderResource::getEloquentQuery()`, which is vendor-scoped, so the
 * only orders reachable here are already the actor's own.
 *
 * `status` offers every code in `VendorOrderStatusPresenter::options()`, which
 * walks `VendorProcessingStatus::KNOWN_STATUSES`. No transition graph is
 * enforced here because none exists: `VendorProcessingStatus` defines a closed
 * list of states and says nothing about legal moves between them, and inventing
 * an ordering in a form would be a business rule with no source behind it. The
 * model's `saving` hook still rejects any status outside the closed list.
 */
final class VendorOrderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('uuid')
                    ->label('ID Pesanan')
                    ->disabled(),

                TextEntry::make('listing.product.name')
                    ->label('Produk')
                    ->placeholder('—'),

                TextInput::make('customer_name')
                    ->label('Nama pelanggan')
                    ->disabled(),

                TextInput::make('customer_phone')
                    ->label('Telepon pelanggan')
                    ->disabled(),

                TextInput::make('customer_email')
                    ->label('Email pelanggan')
                    ->disabled(),

                Select::make('status')
                    ->label('Status pesanan')
                    ->options(VendorOrderStatusPresenter::options())
                    ->required()
                    ->native(false)
                    // `->options()` only constrains what the UI offers; a
                    // posted value outside the closed list must fail
                    // validation here (a readable form error) rather than
                    // reaching the Domain Action's `assertKnown()` throw
                    // (a 500). `UpdateVendorOrderStatus` re-asserts
                    // regardless, so this rule is a boundary convenience,
                    // not the authority.
                    ->rules([Rule::in(VendorProcessingStatus::KNOWN_STATUSES)]),

                Textarea::make('notes')
                    ->label('Catatan vendor')
                    ->helperText('Catatan internal untuk pesanan ini, misalnya alasan penolakan atau jadwal pengerjaan.')
                    ->nullable()
                    ->columnSpanFull(),
            ]);
    }
}
