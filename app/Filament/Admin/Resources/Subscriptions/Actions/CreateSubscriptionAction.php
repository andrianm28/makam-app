<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Subscriptions\Actions;

use App\Domain\CareSubscription\Actions\CreateSubscription as CreateSubscriptionDomainAction;
use App\Domain\CareSubscription\CarePlanFrequency;
use App\Domain\CareSubscription\Models\CarePlan;
use App\Domain\CareSubscription\Models\Subscription;
use App\Domain\PlotInventory\Models\GravePlot;
use App\Filament\Admin\Resources\Subscriptions\SubscriptionsResource;
use App\Platform\IdentityAccess\ActorContext;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * Header action factory for creating a subscription via modal form.
 * Selects grave, care plan; calls CreateSubscription domain action.
 *
 * ---------------------------------------------------------------------------
 * `customer_id` is a known, pre-existing gap this class does not close
 * ---------------------------------------------------------------------------
 * The form below has no customer-selection field, so `run()` passes the
 * ADMIN'S OWN identity as `customer_id` -- not a real customer. This
 * predates the `2026_08_22_100000_fix_customer_and_uploader_identity_
 * columns` migration (which only fixed the column's TYPE, uuid to a real
 * bigint FK on `users`) and is unrelated to that fix: even with the correct
 * column type, this form still has no way to say which real customer a
 * subscription belongs to. A real fix needs a customer-selection field
 * (likely a searchable `Select` over `users`), a separate, real product
 * decision this class flags rather than invents unilaterally.
 */
final class CreateSubscriptionAction
{
    public static function make(): Action
    {
        return Action::make('buatLangganan')
            ->label('Buat Langganan')
            ->icon(Heroicon::OutlinedPlus)
            ->color('primary')
            ->form([
                Select::make('grave_id')
                    ->label('Makam')
                    ->options(fn () => GravePlot::query()->pluck('slot', 'id'))
                    ->required()
                    ->searchable()
                    ->native(false),

                Select::make('care_plan_id')
                    ->label('Rencana perawatan')
                    ->options(fn () => CarePlan::query()->where('status', 'active')->pluck('name', 'id'))
                    ->required()
                    ->searchable()
                    ->native(false),
            ])
            ->action(fn (array $data) => self::run($data));
    }

    private static function run(array $data): void
    {
        $actor = app(ActorContext::class);

        try {
            $grave = GravePlot::query()->findOrFail($data['grave_id']);
            $carePlan = CarePlan::query()->findOrFail($data['care_plan_id']);

            // customer_id is the ADMIN's own identity, not a real customer
            // selection -- a pre-existing gap this fix's own migration does
            // not resolve (this form has no customer-selection field at
            // all). Flagged here, not silently fixed: a real "which
            // customer is this subscription for" UI is a separate,
            // real product decision. See this class's own doc block.
            $subscription = app(CreateSubscriptionDomainAction::class)(
                $carePlan,
                (string) $grave->getKey(),
                (int) $actor->identityReference,
                CarePlanFrequency::from($carePlan->frequency),
                (string) $actor->identityReference,
                SubscriptionsResource::auditRoleFor($actor),
            );

            Notification::make()->success()->title('Langganan perawatan dibuat.')->send();
            redirect()->route('filament.admin.resources.langganan.view', ['record' => $subscription->getKey()]);
        } catch (\Throwable $exception) {
            Notification::make()
                ->danger()
                ->title('Gagal membuat langganan')
                ->body($exception->getMessage())
                ->send();
        }
    }
}
