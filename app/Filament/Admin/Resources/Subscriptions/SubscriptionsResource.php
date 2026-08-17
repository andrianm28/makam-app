<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Subscriptions;

use App\Domain\CareSubscription\Models\Subscription;
use App\Filament\Admin\Resources\Subscriptions\Actions\CancelSubscriptionAction;
use App\Filament\Admin\Resources\Subscriptions\Actions\CreateSubscriptionAction;
use App\Filament\Admin\Resources\Subscriptions\Actions\PauseSubscriptionAction;
use App\Filament\Admin\Resources\Subscriptions\Pages\ListSubscriptions;
use App\Filament\Admin\Resources\Subscriptions\Pages\ViewSubscription;
use App\Filament\Admin\Resources\Subscriptions\Schemas\SubscriptionInfolist;
use App\Filament\Admin\Resources\Subscriptions\Tables\SubscriptionsTable;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\MasterData\Contracts\MasterDataAdminAuthorizerContract;
use App\Platform\IdentityAccess\MasterData\Exceptions\MasterDataNotAuthorisedException;
use App\Platform\IdentityAccess\Roles\ActorRole;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Admin surface for `subscriptions` — subscription management with
 * header actions for create, pause, and cancel.
 */
final class SubscriptionsResource extends Resource
{
    protected static ?string $model = Subscription::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPathRoundedSquare;

    protected static ?string $recordTitleAttribute = 'reference';

    protected static ?string $navigationGroup = 'Master Data';

    protected static ?int $navigationSort = 61;

    public static function canAccess(): bool
    {
        try {
            app(MasterDataAdminAuthorizerContract::class)->authorize(app(ActorContext::class));
        } catch (MasterDataNotAuthorisedException) {
            return false;
        }

        return true;
    }

    public static function getAuthorizationResponse(string|UnitEnum $action, ?Model $record = null): Response
    {
        try {
            app(MasterDataAdminAuthorizerContract::class)->authorize(app(ActorContext::class));

            return Response::allow();
        } catch (MasterDataNotAuthorisedException) {
            return Response::deny('Anda tidak berwenang mengelola langganan perawatan.');
        }
    }

    public static function auditRoleFor(ActorContext $actor): string
    {
        foreach ([ActorRole::ADMIN, ActorRole::RESTRICTED_ADMIN, ActorRole::OPERATOR, ActorRole::FINANCE] as $role) {
            if ($actor->hasRole($role)) {
                return $role;
            }
        }

        return $actor->isAuthenticated() ? 'authenticated_actor' : 'guest';
    }

    public static function table(Table $table): Table
    {
        return SubscriptionsTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return SubscriptionInfolist::configure($schema);
    }

    public static function getEloquentQuery(): Builder
    {
        return Subscription::query()->latest('created_at');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSubscriptions::route('/'),
            'view' => ViewSubscription::route('/{record}'),
        ];
    }

    public static function getModelLabel(): string
    {
        return 'langganan';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Langganan Perawatan';
    }

    public static function getNavigationLabel(): string
    {
        return 'Langganan Perawatan';
    }
}
