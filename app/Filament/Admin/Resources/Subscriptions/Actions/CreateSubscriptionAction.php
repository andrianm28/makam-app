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

            $subscription = app(CreateSubscriptionDomainAction::class)(
                $carePlan,
                (string) $grave->getKey(),
                (string) $actor->identityReference,
                CarePlanFrequency::from($carePlan->frequency),
                (string) $actor->identityReference,
                SubscriptionsResource::auditRoleFor($actor),
            );

            Notification::make()->success()->title('Langganan perawatan dibuat.')->send();
            redirect()->route('filament.admin.resources.subscriptions.view', ['record' => $subscription->getKey()]);
        } catch (\Throwable $exception) {
            Notification::make()
                ->danger()
                ->title('Gagal membuat langganan')
                ->body($exception->getMessage())
                ->send();
        }
    }
}
