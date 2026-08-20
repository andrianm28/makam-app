<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\AuditEvents\Schemas;

use App\Filament\Admin\Resources\AuditEvents\Tables\AuditEventsTable;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * View-page read-only schema for `AuditEventsResource` — every column
 * `AuditEvent` carries, per this batch's brief: actor, role, action,
 * subject, outcome, reason, correlation id, metadata, occurred_at, source.
 *
 * `metadata` renders as a plain `KeyValueEntry` with no redaction step of
 * its own. That is deliberate, not an oversight: `Audit::record()` rejects
 * any key outside `MetadataAllowlist::ALLOWED_KEYS` at WRITE time (throws
 * `AuditMetadataKeyNotAllowedException`), and every key currently on that
 * allowlist is documented, at the point it was added, as non-secret,
 * non-identifying — `reference_number`/`previous_state`/`new_state`/`note`
 * (the original "what changed, on which reference" shape), `method`/
 * `recovery_codes_remaining` (explicitly re-checked against "no credential,
 * TOTP secret, or recovery code" before being added), and `purpose`
 * (explicitly re-checked against "no KTP, KK, death-certificate content,
 * bank detail, credential, or full address" before being added). So by the
 * time a row reaches this view, its `metadata` has already passed the one
 * control this codebase has for restricted-data-in-audit-payloads — there is
 * no second, weaker value that could still be sitting in a column this
 * allowlist did not check. Displaying it as-written is safe BECAUSE the
 * allowlist is enforced upstream, not despite it; this schema does not
 * re-implement that check; see `Contracts\AuditReadAuthorizer`'s own doc
 * block for why this resource does not invent a second redaction layer here.
 */
final class AuditEventInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Section::make('Peristiwa')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('action')->label('Aksi'),

                        TextEntry::make('outcome')
                            ->label('Hasil')
                            ->badge()
                            ->formatStateUsing(fn (string $state): string => AuditEventsTable::outcomeLabel($state))
                            ->color(fn (string $state): string => AuditEventsTable::outcomeColor($state)),

                        TextEntry::make('occurred_at')
                            ->label('Waktu')
                            ->dateTime('d M Y H:i:s'),

                        TextEntry::make('source')->label('Sumber'),

                        TextEntry::make('reason')
                            ->label('Alasan')
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ]),

                Section::make('Aktor')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('actor_ref')->label('Referensi aktor')->placeholder('—'),

                        TextEntry::make('actor_role')->label('Peran aktor'),
                    ]),

                Section::make('Subjek')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('subject_type')
                            ->label('Tipe subjek')
                            ->formatStateUsing(fn (string $state): string => class_basename($state)),

                        TextEntry::make('subject_id')->label('ID subjek'),

                        TextEntry::make('subject_version')->label('Versi subjek')->placeholder('—'),

                        TextEntry::make('correlation_id')->label('ID korelasi')->placeholder('—'),
                    ]),

                Section::make('Metadata')
                    ->schema([
                        KeyValueEntry::make('metadata')
                            ->label('Metadata')
                            ->keyLabel('Kunci')
                            ->valueLabel('Nilai')
                            ->placeholder('Tidak ada metadata'),
                    ]),
            ]);
    }
}
