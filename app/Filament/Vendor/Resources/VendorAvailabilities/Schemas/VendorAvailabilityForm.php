<?php

declare(strict_types=1);

namespace App\Filament\Vendor\Resources\VendorAvailabilities\Schemas;

use App\Filament\Vendor\Schemas\VendorPicker;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

/**
 * Create/edit form for `VendorAvailabilityResource`.
 *
 * ---------------------------------------------------------------------------
 * Why `capacity` is forced to 0 when `is_blocked` is on
 * ---------------------------------------------------------------------------
 * This is not a UI preference. `database/migrations/
 * 2026_08_12_100040_create_vendor_availability_table.php` states it as a schema
 * invariant — "A blocked day can never also advertise capacity ... since a
 * 'blocked but capacity > 0' row would be a self-contradiction, not a data
 * variation" — and enforces it in the database:
 *
 *     ALTER TABLE vendor_availability ADD CONSTRAINT
 *       vendor_availability_capacity_zero_when_blocked
 *       CHECK ((is_blocked = false) OR (capacity = 0))
 *
 * That constraint is added on `pgsql` only (production), so on a non-pgsql
 * connection nothing below the form would catch a contradictory row. The form
 * therefore enforces the same rule itself rather than relying on the CHECK:
 *
 *  - `->disabled(...)` and the toggle's `afterStateUpdated()` are the visible
 *    half — the field greys out and zeroes as soon as the day is blocked.
 *  - `->dehydrateStateUsing(...)` is the half that actually decides what is
 *    written. It re-reads `is_blocked` at dehydration time and coerces the
 *    value server-side, so a client that keeps a stale or hand-edited capacity
 *    in Livewire state still saves 0. `disabled()`'s own source comment says
 *    the same thing: the disabled state can be bypassed on the client.
 *
 * `->dehydrated()` is explicit because `disabled()` calls `saved(false)` for
 * the disabled case, and an un-dehydrated field on EDIT would leave a
 * pre-existing non-zero `capacity` in place next to `is_blocked = true` —
 * exactly the contradictory row the constraint forbids.
 *
 * `vendor_id` is NOT a plain input. It comes from `VendorPicker`, which
 * renders only for multi-grant actors, and the value is decided server-side by
 * `Concerns\StampsCurrentVendor` on create. On edit the field still renders
 * for a multi-grant actor but its options stay limited to granted vendors, and
 * Filament's Select derives an `in:` validation rule from those options — so
 * a forged `vendor_id` for a vendor the actor does NOT hold fails validation
 * and no write occurs. There is no write path out of the actor's own scope;
 * see `Pages\EditVendorAvailability`'s doc block.
 */
final class VendorAvailabilityForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                VendorPicker::make(),

                DatePicker::make('available_date')
                    ->label('Tanggal')
                    ->required()
                    ->native(false),

                TextInput::make('capacity')
                    ->label('Kapasitas')
                    ->helperText('Jumlah pesanan yang dapat dilayani pada tanggal ini. Hari yang diblokir selalu bernilai 0.')
                    ->numeric()
                    ->required()
                    ->minValue(0)
                    ->default(0)
                    ->disabled(fn (Get $get): bool => (bool) $get('is_blocked'))
                    ->dehydrated()
                    ->dehydrateStateUsing(
                        fn (Get $get, mixed $state): int => (bool) $get('is_blocked') ? 0 : (int) $state,
                    ),

                Toggle::make('is_blocked')
                    ->label('Diblokir')
                    ->helperText('Tandai bila vendor tidak menerima pesanan sama sekali pada tanggal ini.')
                    ->default(false)
                    ->live()
                    ->afterStateUpdated(function (Set $set, mixed $state): void {
                        if ((bool) $state) {
                            $set('capacity', 0);
                        }
                    }),
            ]);
    }
}
