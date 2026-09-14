<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Domain\RefundObligation\Models\RefundObligation;
use App\Domain\RefundObligation\RefundObligationDeadlineQuery;
use App\Domain\RefundObligation\RefundObligationStatus;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\Payment\Contracts\PaymentActionAuthorizer;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * The dashboard half of the refund plan's R4.
 *
 * ---------------------------------------------------------------------------
 * Why a dashboard widget when a resource list already exists
 * ---------------------------------------------------------------------------
 * Stage R2 built `RefundObligationsResource`, whose list is already ordered
 * by deadline. That is the place to WORK a debt. It is not the place that
 * tells you a debt needs working, because it only shows what it knows to
 * someone who already decided to open it.
 *
 * R4's sentence is exactly about that gap: late obligations must appear
 * *"di tempat yang dilihat orang, bukan hanya di tabel yang harus dibuka"*.
 * The console watchdog (`spine:watchdog` signals 5-6) covers the operator
 * who reads alerts; this covers the one who opens the admin panel.
 *
 * ---------------------------------------------------------------------------
 * Shows debts that are LATE OR NEARLY LATE, not all outstanding debts
 * ---------------------------------------------------------------------------
 * A widget listing every open obligation would be a second copy of the
 * resource list, and would go back to being wallpaper the moment there were
 * more than a handful. The filter is the same two-tier question the watchdog
 * asks, for the same reason recorded there: the provider supports
 * withdraw-to-main-account only, so execution is two manual bank movements
 * and the first settles on the provider's clock. A debt that first appears
 * here on its deadline appears too late to be saved.
 *
 * It disappears when there is nothing late — an empty widget every day is how
 * an operator learns to stop reading it.
 *
 * ---------------------------------------------------------------------------
 * Authorization
 * ---------------------------------------------------------------------------
 * `PaymentActionAuthorizer`, the same contract `RefundObligationsResource`
 * and the manual-payment verification actions gate on. A widget must never be
 * the soft way into data its resource guards.
 */
final class OverdueRefundObligationQueueWidget extends TableWidget
{
    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = -10;

    /**
     * Hidden for an actor who may not act on payments, AND hidden when there
     * is nothing late.
     *
     * The second condition is the one that keeps this widget worth reading.
     * A query failure hides it rather than breaking the dashboard — the
     * watchdog covers the same facts on a different surface, so a dashboard
     * that renders without this widget is degraded, not blind.
     */
    public static function canView(): bool
    {
        try {
            app(PaymentActionAuthorizer::class)->authorize(app(ActorContext::class));
        } catch (Throwable) {
            return false;
        }

        try {
            $deadlines = app(RefundObligationDeadlineQuery::class);

            return $deadlines->overdueCount() > 0 || $deadlines->dueSoonCount() > 0;
        } catch (Throwable) {
            return false;
        }
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Kewajiban Refund yang Mendesak')
            ->description(
                'Uang sudah diterima dari pemesan dan belum dikembalikan. Eksekusi berjalan dua '
                .'langkah — withdraw ke rekening utama, lalu transfer ke pemesan — jadi mulailah '
                .'sebelum tenggatnya, bukan pada tenggatnya.'
            )
            ->query($this->urgentObligationsQuery())
            ->columns([
                // `due_at` is NOT NULL in R0's migration and cast to
                // CarbonImmutable, so there is no null branch to guard here.
                // PHPStan said so, and it was right: a guard for an
                // impossible state is noise that makes the real branch
                // harder to read.
                TextColumn::make('due_at')
                    ->label('Tenggat')
                    ->dateTime()
                    ->sortable()
                    ->badge()
                    ->color(fn (RefundObligation $record): string => $record->due_at->isPast()
                        ? 'danger'
                        : 'warning'),

                // `longAbsoluteDiffForHumans()`, not `diffForHumans(null, true)`:
                // that second argument is Carbon's `$syntax`, not an
                // "absolute" flag, so the original read as a correct-looking
                // call that would have rendered the wrong thing. The word
                // before it already carries the direction ("Terlambat" /
                // "Tersisa"), so the duration itself must be unsigned.
                TextColumn::make('due_at')
                    ->label('Sisa waktu')
                    ->formatStateUsing(fn (RefundObligation $record): string => $record->due_at->isPast()
                        ? 'Terlambat '.$record->due_at->longAbsoluteDiffForHumans()
                        : 'Tersisa '.$record->due_at->longAbsoluteDiffForHumans()),

                TextColumn::make('order.reference')
                    ->label('Pesanan'),

                TextColumn::make('amount_minor')
                    ->label('Jumlah terutang')
                    ->money('IDR', divideBy: 100),

                TextColumn::make('opened_at')
                    ->label('Dibuka')
                    ->dateTime(),
            ])
            ->defaultSort('due_at', 'asc')
            ->emptyStateHeading('Tidak ada kewajiban refund yang mendesak')
            ->emptyStateDescription(
                'Setiap kewajiban yang masih terutang berada jauh dari tenggatnya. '
                .'Daftar lengkapnya ada di halaman Kewajiban Refund.'
            )
            ->paginated([5, 10, 25]);
    }

    /**
     * Outstanding only, and only those already late or close to it.
     *
     * `TERUTANG` is the whole of "outstanding": an obligation that reached
     * `DIEKSEKUSI` has had its money sent and its evidence recorded, and is
     * not late whatever its deadline says.
     */
    private function urgentObligationsQuery(): Builder
    {
        $cutoff = now()->addHours(RefundObligationDeadlineQuery::DUE_SOON_HOURS);

        return RefundObligation::query()
            ->with('order')
            ->where('status', RefundObligationStatus::TERUTANG->value)
            ->where('due_at', '<', $cutoff);
    }
}
