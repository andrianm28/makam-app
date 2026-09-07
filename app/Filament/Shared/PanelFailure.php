<?php

declare(strict_types=1);

namespace App\Filament\Shared;

use App\Domain\PlotReservation\Exceptions\PlotReservationTransitionException;
use App\Domain\PreNeed\Exceptions\IllegalPreNeedCaseTransitionException;
use Filament\Notifications\Notification;
use Throwable;

/**
 * ARCH-07's one shared seam for a Filament action's `catch (\Throwable)`
 * block: every real bug reaches error tracking via `report()` (previously
 * silently swallowed everywhere this replaces), and the operator only ever
 * sees a raw internal exception message when the thrown class is on the
 * ALLOWLIST below.
 *
 * The allowlist is built from real evidence, not guessed: only domain
 * exception classes whose OWN doc comment says their message is meant for
 * an admin/operator surface are listed. Every other `\Throwable` — including
 * ones from third-party code, infrastructure failures, or a domain
 * exception nobody has reviewed for operator-safe wording — gets the fixed
 * generic Indonesian message instead, so an internal message never leaks
 * implementation details to an operator by accident.
 *
 * Adding a class here is a judgment call about what is safe to show a
 * human operator, so it should be done deliberately, one class at a time,
 * with the same doc-comment evidence this file's own two entries have —
 * never as a blanket "seems fine" addition.
 */
final class PanelFailure
{
    /**
     * @var list<class-string<Throwable>>
     */
    private const array OPERATOR_SAFE_EXCEPTIONS = [
        IllegalPreNeedCaseTransitionException::class,
        PlotReservationTransitionException::class,
    ];

    private const string GENERIC_MESSAGE = 'Terjadi kesalahan pada sistem. Tim teknis telah diberi tahu.';

    public static function notify(Throwable $e, string $title): void
    {
        report($e);

        Notification::make()
            ->danger()
            ->title($title)
            ->body(self::messageFor($e))
            ->send();
    }

    private static function messageFor(Throwable $e): string
    {
        foreach (self::OPERATOR_SAFE_EXCEPTIONS as $safeClass) {
            if ($e instanceof $safeClass) {
                return $e->getMessage();
            }
        }

        return self::GENERIC_MESSAGE;
    }
}
