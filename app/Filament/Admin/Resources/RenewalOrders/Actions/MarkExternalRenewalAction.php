<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\RenewalOrders\Actions;

use App\Domain\GraveRegistry\Models\GraveRecord;
use App\Domain\Renewal\Actions\MarkExternalRenewal;
use App\Domain\Renewal\Exceptions\DuplicateRenewalPeriodException;
use App\Filament\Admin\Pages\PasswordReauthentication;
use App\Http\Middleware\RequireRecentAuthentication;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\Reauthentication\Exceptions\ReauthenticationRequiredException;
use App\Platform\IdentityAccess\Reauthentication\ReauthenticationGuard;
use App\Platform\IdentityAccess\Roles\ActorRole;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * The `ListRenewalOrders` header action that creates a NEW renewal record via
 * `App\Domain\Renewal\Actions\MarkExternalRenewal` — distinct in shape from
 * the sibling `RecordExternalRenewalPaymentAction`, which acts on an
 * EXISTING renewal. This one starts from a grave-record search, since no
 * renewal row exists yet for the external period being recorded.
 *
 * ---------------------------------------------------------------------------
 * Two enforcement layers, but the FIRST one is coarser than its sibling's
 * ---------------------------------------------------------------------------
 * `RecordExternalRenewalPaymentAction` and `ExpireRenewalAction` both act on
 * an already-bound `Renewal` (a per-row action), so their `->authorize()`
 * gate can ask `OrderTransitionAuthorizerContract` a question scoped to that
 * exact record. This action is a HEADER action with no bound record at
 * mount/visibility time — the grave is only chosen inside the modal form —
 * so `RenewalMarkingPolicy::allows(ActorContext $actor, GraveRecord $grave)`,
 * which needs a `GraveRecord` to check the cemetery-scope grant against,
 * cannot be evaluated at that point. `->authorize()` therefore only mirrors
 * the policy's FIRST two checks (authenticated + `ActorRole::ADMIN`, per
 * Ruling B, 12 Aug 2026) — the "does this actor hold any admin capability at
 * all" question, which is all a bare button-visibility gate can honestly
 * answer. It intentionally does NOT check the actor's cemetery-scope grant.
 *
 * The real, precise per-grave enforcement — including the cemetery-scope
 * grant `->authorize()` cannot see — happens for real inside
 * `MarkExternalRenewal::__invoke()`, which the `->action()` closure below
 * calls, and its `AuthorizationException` is caught there exactly like
 * `RecordExternalRenewalPaymentAction` catches its own domain failures. An
 * admin who holds the role but lacks the scope grant for the grave they
 * picked therefore still sees the button, submits the form, and is refused
 * at the real check — the same shape `RenewalMarkingPolicy`'s own doc block
 * describes for the three-check chain, just split across two layers because
 * this entry point is a header action rather than a per-row one.
 *
 * Re-authentication (money-adjacent action, same as
 * `RecordExternalRenewalPaymentAction`): `ReauthenticationGuard::assertFresh()`
 * runs before the domain call, and a stale actor is redirected to
 * `PasswordReauthentication` with the pending reason threaded through
 * `RequireRecentAuthentication::REASON_SESSION_KEY` rather than being
 * allowed to silently succeed.
 */
final class MarkExternalRenewalAction
{
    public static function make(): Action
    {
        return Action::make('mark_external_renewal')
            ->label('Tandai Perpanjangan Eksternal')
            ->color('warning')
            ->icon(Heroicon::OutlinedBanknotes)
            ->requiresConfirmation()
            ->modalHeading('Catat perpanjangan eksternal')
            ->modalDescription('Perpanjangan ditandai dibayar di luar platform dan dicatat di audit.')
            ->schema([
                Select::make('grave_record_id')
                    ->label('Makam')
                    ->searchable()
                    ->getSearchResultsUsing(
                        fn (string $search): array => GraveRecord::query()
                            ->where('deceased_name', 'ilike', "%{$search}%")
                            ->limit(20)
                            ->pluck('deceased_name', 'id')
                            ->all()
                    )
                    ->getOptionLabelUsing(
                        fn ($value): ?string => GraveRecord::find($value)?->deceased_name
                    )
                    ->required(),

                TextInput::make('target_due_period')
                    ->label('Periode (YYYY-MM-DD)')
                    ->required()
                    // A malformed value here would otherwise reach
                    // `MarkExternalRenewal::__invoke()` and blow up as an
                    // uncaught `Carbon\Exceptions\InvalidFormatException`
                    // when `Renewal::create()` casts it — real validation at
                    // the form layer, not just the \Throwable catch below,
                    // is what turns that into a clean refusal instead of a
                    // 500.
                    ->rules(['date_format:Y-m-d'])
                    ->validationMessages([
                        'date_format' => 'Periode harus dalam format YYYY-MM-DD.',
                    ]),

                Textarea::make('evidence')->label('Bukti')->rows(2)->required(),
                Textarea::make('reason')->label('Alasan')->rows(2)->required(),
            ])
            ->authorize(fn (): bool => self::authorized())
            ->action(function (array $data): void {
                $actor = app(ActorContext::class);

                try {
                    app(ReauthenticationGuard::class)->assertFresh($actor);
                } catch (ReauthenticationRequiredException) {
                    Notification::make()
                        ->warning()
                        ->title('Perlu verifikasi ulang')
                        ->body('Lakukan verifikasi ulang untuk tindakan ini.')
                        ->send();

                    session()->put(RequireRecentAuthentication::REASON_SESSION_KEY, 'money_action');
                    session()->put('url.intended', route('filament.admin.resources.pesanan-perpanjangan.index'));
                    redirect()->route(PasswordReauthentication::ROUTE_NAME);

                    return;
                }

                $grave = GraveRecord::findOrFail((string) $data['grave_record_id']);

                try {
                    app(MarkExternalRenewal::class)(
                        $grave,
                        (string) $data['target_due_period'],
                        (string) $data['evidence'],
                        (string) $data['reason'],
                    );
                    Notification::make()->success()->title('Perpanjangan eksternal dicatat.')->send();
                } catch (DuplicateRenewalPeriodException $exception) {
                    Notification::make()->danger()->title('Periode ini sudah tercatat')->body($exception->getMessage())->send();
                } catch (AuthorizationException|\Throwable $exception) {
                    Notification::make()->danger()->title('Gagal mencatat perpanjangan')->body($exception->getMessage())->send();
                }
            });
    }

    private static function authorized(): bool
    {
        $actor = app(ActorContext::class);

        return $actor->isAuthenticated() && $actor->hasRole(ActorRole::ADMIN);
    }
}
