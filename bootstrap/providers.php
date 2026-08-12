<?php

use App\Domain\Faq\Providers\FaqServiceProvider;
use App\Platform\Correlation\Providers\CorrelationServiceProvider;
use App\Platform\DocumentVault\Providers\DocumentVaultServiceProvider;
use App\Platform\FeatureGate\Providers\FeatureGateServiceProvider;
use App\Platform\FinancialLedger\Providers\FinancialLedgerServiceProvider;
use App\Platform\IdentityAccess\Providers\IdentityAccessServiceProvider;
use App\Platform\Notification\Providers\NotificationServiceProvider;
use App\Platform\Payment\Providers\PaymentServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;

return [
    AppServiceProvider::class,
    // AdminPanelProvider's own class-level comment documents exactly which
    // parts of its Filament 5 API usage are unverified (composer install
    // cannot run on this host — CI is the first real boot of this class
    // against the actual pinned filament/filament v5.7.3 package).
    AdminPanelProvider::class,
    // FeatureGateServiceProvider's own class-level comment documented this
    // exact missing line since the batch that wrote it (Sprint 3):
    // GateRegistrySource/FeatureGateResolver/ModeResolver bindings existed
    // but were unreachable dead code until now. Every FeatureGate test
    // before S4-T3 used an in-memory GateRegistrySource stub, bypassing the
    // container entirely — S4-T3's homepage was the first real HTTP
    // request in this codebase to call app(ModeResolver::class), which is
    // what actually surfaced this as a live 500
    // (BindingResolutionException: GateRegistrySource is not instantiable).
    FeatureGateServiceProvider::class,
    // Batch 3.1 (S3-T1) — binds IdentityAccessAdapter/ActorContextResolver
    // and registers the actor_sessions login/logout listeners. See that
    // provider's own class-level comment for why this registration line is
    // here despite bootstrap/providers.php not being in that batch's
    // literal owned-files list (same precedent as AdminPanelProvider above).
    IdentityAccessServiceProvider::class,
    // Batch 3.3 (S3-T10) — binds CorrelationContext (scoped()). Same
    // precedent as IdentityAccessServiceProvider above: this file is not
    // in that batch's literal owned-files list either, but the brief
    // explicitly authorizes this one additive line.
    CorrelationServiceProvider::class,
    // platform-payment-adapter Task 3 — registers the `payment-webhook` rate
    // limiter the webhook route's `throttle:` middleware names, and binds
    // SumoPodWebhookSignature to its configured credentials. Same precedent as
    // the three lines above: this file is not in the task's literal owned-files
    // list, but a named rate limiter has to be registered somewhere a service
    // provider runs, and the route would 500 on an unregistered limiter name.
    PaymentServiceProvider::class,
    // Task 4 (platform-document-vault lane, task-4-brief.md ambiguity
    // ruling 2) — binds ObjectStorage/MalwareScanner/StoragePathResolver.
    // Same precedent as IdentityAccessServiceProvider/
    // CorrelationServiceProvider above: not in Task 4's literal
    // owned-files list, but explicitly authorized by the coordinator so
    // UploadDocument's container-resolved dependencies are reachable —
    // see DocumentVaultServiceProvider's own class-level comment.
    DocumentVaultServiceProvider::class,
    // Task 7 (platform-financial-ledger lane) — binds Contracts\Journal plus
    // this module's three authorizer seams. Same precedent as
    // IdentityAccessServiceProvider/CorrelationServiceProvider/
    // DocumentVaultServiceProvider above: bootstrap/providers.php is not in
    // Task 7's literal owned-files list, but its brief explicitly authorizes
    // this one additive line. Without it lane/l3-payment-adapter's
    // app(Contracts\Journal::class) does not resolve at all — see
    // FinancialLedgerServiceProvider's own class-level comment.
    FinancialLedgerServiceProvider::class,
    // Task 3 of the L2 `platform-notifications` lane (task-3-brief.md D7)
    // — binds RecipientRoleSource/NotificationSubjectSource and registers
    // the OutboxEventPublished -> ConsumeOutboxNotificationJob listener.
    // Same precedent as every provider above: this file is not in that
    // task's literal owned-files list, but the brief explicitly authorizes
    // this one additive line, and without it the whole outbox-fed
    // dispatch path is dead code no consumer can reach.
    NotificationServiceProvider::class,
    // Task 1 of the L9 `admin-operations` lane — binds
    // `App\Domain\Faq\Contracts\FaqAuthorizer`, the seam the FAQ admin
    // surface's authorization runs through. Same precedent as every provider
    // above. Without it `FaqArticleResource`/`FaqArticlesTable` raise
    // `BindingResolutionException` on the first admin request, which is the
    // correct fail-closed behaviour for a missing authorization seam — see
    // `FaqServiceProvider`'s own class-level comment.
    FaqServiceProvider::class,
];
