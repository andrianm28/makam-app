<?php

declare(strict_types=1);

namespace App\Livewire\Public\CareSubscription;

use App\Domain\CareSubscription\Models\CarePlan;
use App\Domain\CareSubscription\Models\Subscription;
use App\Domain\CareSubscription\Models\SubscriptionCycle;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * `/langganan/{subscriptionReference}` — customer-facing subscription status
 * view (P5b Lane 4). Shows subscription status, care plan name, frequency,
 * price, and cycle history with SEPARATE billing + work indicators (AC6).
 *
 * ---------------------------------------------------------------------------
 * State-only, no vault references
 * ---------------------------------------------------------------------------
 * The page renders only the subscription read model and its cycles. No vault
 * document reference, no payment session internals, and no subject internals
 * leave the server through this route.
 *
 * ---------------------------------------------------------------------------
 * PAID ≠ COMPLETED (AC6)
 * ---------------------------------------------------------------------------
 * Every cycle row renders billing status and work order status as TWO
 * SEPARATE badge indicators — never collapsed into one "done" badge.
 *
 * ---------------------------------------------------------------------------
 * Server-confirmed state only (AC4)
 * ---------------------------------------------------------------------------
 * All state is read from the database, never from a browser return URL or
 * client-supplied parameter.
 */
final class SubscriptionStatusPage extends Component
{
    public ?string $subscriptionReference = null;

    public function mount(string $subscriptionReference): void
    {
        $this->subscriptionReference = $subscriptionReference;

        if ($this->findSubscription() === null) {
            abort(404);
        }
    }

    public function render(): View
    {
        $subscription = $this->findSubscription();

        if ($subscription === null) {
            abort(404);
        }

        $cycles = SubscriptionCycle::query()
            ->where('subscription_id', $subscription->getKey())
            ->orderByDesc('cycle_start')
            ->get();

        $carePlan = CarePlan::query()->find($subscription->care_plan_id);

        return view('livewire.public.care-subscription.subscription-status-page', [
            'subscription' => $subscription,
            'cycles' => $cycles,
            'carePlan' => $carePlan,
        ])->layout('layouts.app', [
            'title' => 'Status Langganan - Makam.co.id',
            'active' => null,
        ]);
    }

    private function findSubscription(): ?Subscription
    {
        if ($this->subscriptionReference === null) {
            return null;
        }

        return Subscription::query()
            ->where('reference', $this->subscriptionReference)
            ->first();
    }
}
