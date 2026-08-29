<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CemeteryResource\Actions;

use App\Domain\CemeteryDirectory\Actions\SetCemeteryPlotTrackingMode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\CemeteryDirectory\PlotTrackingMode;
use App\Filament\Admin\Resources\CemeteryResource;
use App\Platform\Audit\AuditSource;
use App\Platform\IdentityAccess\ActorContext;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Throwable;

/**
 * The 'Aktifkan pelacakan granular' header action on `EditCemetery` — the
 * only Filament call-site for `SetCemeteryPlotTrackingMode`, which until
 * this action existed had no UI wiring anywhere in the panel (a deliberately
 * deferred gap, per that action's own doc block and
 * `docs/superpowers/plans/2026-08-26-cemetery-plot-tracking-mode.md`'s
 * Global Constraints).
 *
 * Deliberately one-directional in this UI: visible only while the cemetery
 * is still `aggregate`. `SetCemeteryPlotTrackingMode` itself refuses
 * `GRANULAR -> AGGREGATE` once any block exists and treats the tier as a
 * permanent business classification, not a toggle — so no reverse action is
 * offered here; an admin who granularized a cemetery by mistake before any
 * block exists still has console access as the correction path, the same
 * as every other one-way decision this codebase asks a human to get right
 * the first time.
 *
 * Same shape as `Agreements\Actions\SupersedeAgreementAction`: a static
 * `Action::make()` factory, a confirmation modal spelling out the
 * consequence in plain language, and the domain action's call wrapped in a
 * try/catch that turns a refusal into a danger notification instead of an
 * uncaught exception. No separate `->authorize()` call — `EditCemetery`
 * only mounts behind `CemeteryResource::getAuthorizationResponse()`
 * (`MasterDataAdminAuthorizerContract`), so every header action on that
 * page already sits behind the same gate.
 */
final class SwitchToGranularTrackingAction
{
    public static function make(Cemetery $cemetery): Action
    {
        return Action::make('switch_to_granular_tracking')
            ->label('Aktifkan pelacakan granular')
            ->icon(Heroicon::OutlinedSquares2x2)
            ->color('warning')
            ->visible(fn (): bool => $cemetery->plot_tracking_mode === PlotTrackingMode::AGGREGATE)
            ->requiresConfirmation()
            ->modalHeading('Aktifkan pelacakan granular untuk makam ini?')
            ->modalDescription(
                'Makam beralih dari kuota per paket ke inventaris petak individual '
                .'(blok dan plot). Ini adalah keputusan permanen — makam tidak dapat '
                .'dikembalikan ke pelacakan agregat setelah blok pertama dibuat. '
                .'Pemilihan petak spesifik akan muncul di wizard pemesanan publik '
                .'untuk makam ini setelah minimal satu blok dibuat.'
            )
            ->modalSubmitActionLabel('Aktifkan')
            ->action(fn () => self::run($cemetery));
    }

    private static function run(Cemetery $cemetery): void
    {
        $actor = app(ActorContext::class);

        try {
            app(SetCemeteryPlotTrackingMode::class)(
                $cemetery,
                PlotTrackingMode::GRANULAR,
                (string) $actor->identityReference,
                CemeteryResource::auditRoleFor($actor),
                AuditSource::Panel,
                'Diaktifkan melalui panel admin.',
            );

            Notification::make()
                ->success()
                ->title('Pelacakan granular diaktifkan.')
                ->body('Tambahkan blok pada tab "Blok Makam" di bawah untuk mulai membuat plot.')
                ->send();
        } catch (Throwable $exception) {
            Notification::make()
                ->danger()
                ->title('Gagal mengaktifkan pelacakan granular')
                ->body($exception->getMessage())
                ->send();
        }
    }
}
