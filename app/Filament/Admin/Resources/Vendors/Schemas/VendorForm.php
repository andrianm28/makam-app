<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Vendors\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

/**
 * Create/edit form for `VendorResource` — the model's only writable
 * fields, per the P2 design spec §4.3 ("Create/edit: name + is_active").
 *
 * ---------------------------------------------------------------------------
 * Write path: the model, not a Domain Action
 * ---------------------------------------------------------------------------
 * No `Vendor` write Action exists in `Marketplace` — the design doc's
 * "route through domain Actions WHERE THEY EXIST" rule has no Action to
 * route to here, so the default model save is the correct path. The audit
 * row is written from the pages' `afterCreate()`/`afterSave()` hooks,
 * which run inside Filament's own transaction (the `CemeteryResource`
 * precedent).
 */
final class VendorForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('name')
                    ->label('Nama vendor')
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull(),

                Toggle::make('is_active')
                    ->label('Aktif')
                    ->default(true),
            ]);
    }
}
