<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\RefundObligations\Tables;

use App\Domain\RefundObligation\Models\RefundObligation;
use App\Domain\RefundObligation\RefundObligationStatus;
use App\Filament\Admin\Resources\RefundObligations\Actions\ConfirmRefundReceiptAction;
use App\Filament\Admin\Resources\RefundObligations\Actions\RecordRefundExecutionAction;
use App\Platform\FinancialLedger\Money;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * The outstanding-debt queue for `RefundObligationsResource`.
 *
 * ---------------------------------------------------------------------------
 * Sorted by deadline ASCENDING — the plan's own instruction
 * ---------------------------------------------------------------------------
 * Stage R2: *"daftar kewajiban terutang diurut tenggat"*. Every other
 * financial table in this panel defaults to newest-first, because they answer
 * "what happened". This one answers "what is most overdue", and the family
 * who has waited longest must be at the top — not pushed to page two by
 * debts opened this morning. `RefundObligationsResource::getEloquentQuery()`
 * applies the same ordering at the query level so it survives a caller that
 * does not come through this table.
 *
 * ---------------------------------------------------------------------------
 * Default filter: outstanding only, and it is removable
 * ---------------------------------------------------------------------------
 * The default view is the bill — what is still owed. Confirmed obligations are
 * one filter change away rather than deleted from view, because "show me the
 * refunds we have completed" is a question an auditor asks and a queue that
 * silently hides its own history answers badly.
 *
 * ---------------------------------------------------------------------------
 * What is NOT shown
 * ---------------------------------------------------------------------------
 * `execution_evidence_path` has no column. It is a pointer to restricted
 * material on a private disk, and a table cell is exactly the place it would
 * leak into a screenshot, an export, or a support chat (`AGENTS.md`
 * §Observability). Whether a row HAS evidence is visible — that is what the
 * status means — but where it lives is not. `execution_reference` is shown
 * because it is the opaque tracking string an operator needs to answer "which
 * transfer was this", and carries no account data by the field's own stated
 * contract.
 */
final class RefundObligationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('order.reference')
                    ->label('Pesanan')
                    ->searchable()
                    ->placeholder('—'),

                TextColumn::make('amount_minor')
                    ->label('Jumlah terutang')
                    ->formatStateUsing(fn (int $state): string => (new Money($state))->format()),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (RefundObligationStatus $state): string => self::statusLabel($state))
                    ->color(fn (RefundObligationStatus $state): string => self::statusColor($state)),

                TextColumn::make('due_at')
                    ->label('Tenggat')
                    ->dateTime()
                    ->sortable()
                    // The whole point of the screen: a debt past its deadline
                    // reads as late at a glance, not after arithmetic. Only
                    // OUTSTANDING debts can be late — the deadline stops
                    // applying once the operator has executed, which is what
                    // `RefundObligation::isOverdue()` encodes.
                    ->color(fn (RefundObligation $record): string => $record->isOverdue() ? 'danger' : 'gray')
                    ->description(fn (RefundObligation $record): ?string => $record->isOverdue()
                        ? 'Terlambat'
                        : null),

                TextColumn::make('opened_at')
                    ->label('Dibuka')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('executed_at')
                    ->label('Dieksekusi')
                    ->dateTime()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('execution_reference')
                    ->label('Rujukan transfer')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('confirmed_at')
                    ->label('Dikonfirmasi')
                    ->dateTime()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(self::statusOptions())
                    ->default(RefundObligationStatus::TERUTANG->value),
            ])
            ->recordActions([
                RecordRefundExecutionAction::make(),
                ConfirmRefundReceiptAction::make(),
            ])
            // No bulk actions, deliberately. Discharging a debt is a per-debt
            // decision with its own amount, its own transfer reference, and
            // its own evidence; there is no honest way to do several at once.
            ->toolbarActions([])
            ->defaultSort('due_at', 'asc');
    }

    /**
     * @return array<string, string>
     */
    public static function statusOptions(): array
    {
        $options = [];

        foreach (RefundObligationStatus::cases() as $case) {
            $options[$case->value] = self::statusLabel($case);
        }

        return $options;
    }

    public static function statusLabel(RefundObligationStatus $status): string
    {
        return match ($status) {
            RefundObligationStatus::TERUTANG => 'Terutang',
            RefundObligationStatus::DIEKSEKUSI => 'Dieksekusi',
            RefundObligationStatus::TERKONFIRMASI => 'Terkonfirmasi',
        };
    }

    /**
     * `DIEKSEKUSI` is warning, not success. The operator says the money was
     * sent; nobody has confirmed it arrived, and a transfer can still bounce.
     * Only `TERKONFIRMASI` means a family actually has their money back.
     */
    public static function statusColor(RefundObligationStatus $status): string
    {
        return match ($status) {
            RefundObligationStatus::TERUTANG => 'danger',
            RefundObligationStatus::DIEKSEKUSI => 'warning',
            RefundObligationStatus::TERKONFIRMASI => 'success',
        };
    }
}
