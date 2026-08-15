<?php

declare(strict_types=1);

namespace App\Domain\OrderWorkflow\Providers;

use App\Domain\OrderWorkflow\Authorization\Contracts\OrderTransitionAuthorizerContract;
use App\Domain\OrderWorkflow\Authorization\OrderTransitionAuthorizer;
use Illuminate\Support\ServiceProvider;

final class OrderWorkflowServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(OrderTransitionAuthorizerContract::class, OrderTransitionAuthorizer::class);
    }
}
