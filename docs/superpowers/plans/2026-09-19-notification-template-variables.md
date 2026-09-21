# Notification Template Variables Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make `{{ variable }}` placeholders in notification templates actually render, so a later plan can put an order's confirmation snapshot into the `Booking submitted` email.

**Architecture:** A `NotificationVariableResolver` builds the variable bag for a render from the outbox row (fresh event) or from the delivery's `event_id` (channel send), by consulting per-aggregate `NotificationVariableSource` implementations, then restricts the bag to the template version's `variable_allowlist`. The three call sites that today pass `[]` to `TemplateRenderer::render()` pass the resolved bag instead. The two version-2 template bodies that use single braces get version-3 rows with `{{ }}`.

**Tech Stack:** Laravel 13, PHP 8.5, PostgreSQL 18 (tests run on SQLite unless `DB_CONNECTION` is set — see `phpunit.xml`), PHPUnit.

**Spec:** `.kiro/specs/platform-notifications/requirements.md` AC1, AC8, AC11, AC15 and `design.md` §Template variables (added 19 Sep 2026). Decisions: `docs/product/prd-yiem-2026-09-18.md` §16 and the grill-spec record in `docs/superpowers/plans/2026-09-19-order-confirmation-snapshot.md` §Decisions (S11, S12).

## Global Constraints

- `docs/contracts/notification-matrix.md` is the single source of truth for event, recipient, channel, template; never restate it in code (AC1).
- No restricted data (KTP, KK, death-certificate content, bank details, full addresses) in a body, subject, or template variable (AC11). `TemplateRenderer`'s `restricted_fields` check stays the last line of defence; do not weaken it.
- `notification_template_versions` rows are immutable (trigger + model guard). New copy means a new version row inserted by a migration, and `down()` only re-points `active_version_id`.
- `app/Platform/**` never imports an `app/Domain/**` class (`app/Platform/README.md`). Feature modules implement the platform contract and register it.
- Never invent an outbox event name (finding N-12). Nothing here adds an event.
- Placeholders are `{{ name }}` (`TemplateRenderer::PLACEHOLDER_PATTERN`). Single braces are not a placeholder.
- One PR for this plan. Commit after every task. Run `vendor/bin/phpunit --filter <TestClass>` per task; the full suite runs in CI (`composer install` does not run on this host — `CLAUDE.md` §Scope note).
- Every existing test must keep passing. In particular `tests/Feature/Notification/NotificationDispatchPipelineTest.php` and `tests/Unit/Platform/Notification/TemplateRendererTest.php`.

---

## File structure

| File | Responsibility |
|---|---|
| `app/Platform/Notification/Contracts/NotificationVariableSource.php` (create) | Contract: given a matrix event name, aggregate type/id, and the outbox payload, return scalar variables. |
| `app/Platform/Notification/PayloadNotificationVariableSource.php` (create) | Platform-shipped source: passes through scalar keys of the outbox payload's `data`. Serves the two version-3 marketplace/vendor templates. |
| `app/Platform/Notification/NotificationVariableResolver.php` (create) | Merges every registered source's bag, restricts it to the version's allowlist. Entry points for an outbox row and for a delivery. |
| `app/Providers/NotificationServiceProvider.php` (modify, or whichever provider binds `Contracts\Channel` — Task 3 finds it) | Registers the resolver as a singleton with the payload source. |
| `app/Platform/Notification/Actions/DispatchNotification.php:268-269` (modify) | Fresh-event render uses the resolver. |
| `app/Platform/Notification/Channels/MailChannel.php:104` (modify) | Channel render uses the resolver. |
| `app/Platform/Notification/Channels/LogChannel.php:66` (modify) | Same. |
| `database/migrations/2026_09_20_100000_add_v3_notification_templates_with_double_brace_placeholders.php` (create) | Version 3 for `Vendor accepted/rejected` and `Marketplace order submitted`. |
| `tests/Unit/Platform/Notification/NotificationVariableResolverTest.php` (create) | Resolver unit tests. |
| `tests/Feature/Notification/NotificationTemplateVariablesRenderTest.php` (create) | End-to-end: outbox payload value appears in the in-app body and the mail body. |
| `.kiro/specs/platform-notifications/tasks.md`, `traceability-matrix.md` (modify) | Record what shipped. |

---

### Task 1: The contract and the payload source

**Files:**
- Create: `app/Platform/Notification/Contracts/NotificationVariableSource.php`
- Create: `app/Platform/Notification/PayloadNotificationVariableSource.php`
- Test: `tests/Unit/Platform/Notification/PayloadNotificationVariableSourceTest.php`

**Interfaces:**
- Produces: `NotificationVariableSource::variablesFor(string $matrixEventName, string $aggregateType, string $aggregateId, array $payload): array<string, scalar|Stringable|null>`
- Produces: `NotificationVariableSource::handles(string $aggregateType): bool`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Notification;

use App\Platform\Notification\PayloadNotificationVariableSource;
use PHPUnit\Framework\TestCase;

final class PayloadNotificationVariableSourceTest extends TestCase
{
    public function test_it_handles_every_aggregate_type(): void
    {
        $source = new PayloadNotificationVariableSource;

        self::assertTrue($source->handles('order'));
        self::assertTrue($source->handles('vendor_order'));
    }

    public function test_it_passes_through_scalar_data_keys_only(): void
    {
        $source = new PayloadNotificationVariableSource;

        $bag = $source->variablesFor('Marketplace order submitted', 'marketplace_order', 'abc', [
            'data' => [
                'order_id' => 'MO-1',
                'outcome' => 'diterima',
                'nested' => ['not' => 'scalar'],
                'nothing' => null,
            ],
        ]);

        self::assertSame(['order_id' => 'MO-1', 'outcome' => 'diterima', 'nothing' => null], $bag);
    }

    public function test_a_payload_without_a_data_envelope_is_read_at_the_top_level(): void
    {
        $source = new PayloadNotificationVariableSource;

        self::assertSame(['order_id' => 'MO-2'], $source->variablesFor('x', 'y', 'z', ['order_id' => 'MO-2']));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Platform/Notification/PayloadNotificationVariableSourceTest.php`
Expected: FAIL with `Class "App\Platform\Notification\PayloadNotificationVariableSource" not found`

- [ ] **Step 3: Write the contract and the source**

```php
<?php

declare(strict_types=1);

namespace App\Platform\Notification\Contracts;

/**
 * Supplies template variables for one render. Implementations live in the
 * platform (payload pass-through) or in a feature module (e.g. OrderWorkflow
 * for the `order` aggregate) and are registered on
 * `App\Platform\Notification\NotificationVariableResolver`; the platform
 * never imports a Domain model to get at a value (app/Platform/README.md).
 *
 * Values must be scalar, Stringable, or null — `TemplateRenderer` rejects
 * anything else. Never return restricted data (AC11); the renderer's
 * `restricted_fields` check is the last line of defence, not the first.
 */
interface NotificationVariableSource
{
    public function handles(string $aggregateType): bool;

    /**
     * @param  array<string, mixed>  $payload  The outbox row's payload (envelope or bare data)
     * @return array<string, scalar|\Stringable|null>
     */
    public function variablesFor(string $matrixEventName, string $aggregateType, string $aggregateId, array $payload): array;
}
```

```php
<?php

declare(strict_types=1);

namespace App\Platform\Notification;

use App\Platform\Notification\Contracts\NotificationVariableSource;

/**
 * Pass-through of the outbox payload's scalar `data` keys. This is what the
 * `Vendor accepted/rejected` (`vendor_order_id`, `outcome`) and `Marketplace
 * order submitted` (`order_id`) templates reference — their producers put
 * exactly those non-restricted references in `Outbox::record(data: ...)`.
 * Nested arrays are dropped, never flattened.
 */
final class PayloadNotificationVariableSource implements NotificationVariableSource
{
    public function handles(string $aggregateType): bool
    {
        return true;
    }

    public function variablesFor(string $matrixEventName, string $aggregateType, string $aggregateId, array $payload): array
    {
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;

        $bag = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && ($value === null || is_scalar($value))) {
                $bag[$key] = $value;
            }
        }

        return $bag;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Platform/Notification/PayloadNotificationVariableSourceTest.php`
Expected: PASS (3 tests)

- [ ] **Step 5: Commit**

```bash
git add app/Platform/Notification/Contracts/NotificationVariableSource.php app/Platform/Notification/PayloadNotificationVariableSource.php tests/Unit/Platform/Notification/PayloadNotificationVariableSourceTest.php
git commit -m "feat(notification): variable source contract and payload pass-through source"
```

---

### Task 2: The resolver

**Files:**
- Create: `app/Platform/Notification/NotificationVariableResolver.php`
- Test: `tests/Unit/Platform/Notification/NotificationVariableResolverTest.php`

**Interfaces:**
- Consumes: `NotificationVariableSource` (Task 1).
- Produces: `NotificationVariableResolver::__construct(NotificationVariableSource ...$sources)`, `forOutboxRow(OutboxEvent $row, string $matrixEventName, NotificationTemplateVersion $version): array`, `forDelivery(NotificationDelivery $delivery, NotificationTemplateVersion $version): array`, `restrictToAllowlist(array $bag, NotificationTemplateVersion $version): array`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Notification;

use App\Platform\Notification\Contracts\NotificationVariableSource;
use App\Platform\Notification\Models\NotificationTemplateVersion;
use App\Platform\Notification\NotificationVariableResolver;
use App\Platform\Outbox\Models\OutboxEvent;
use PHPUnit\Framework\TestCase;

final class NotificationVariableResolverTest extends TestCase
{
    public function test_the_bag_is_restricted_to_the_version_allowlist(): void
    {
        $resolver = new NotificationVariableResolver(new class implements NotificationVariableSource
        {
            public function handles(string $aggregateType): bool
            {
                return true;
            }

            public function variablesFor(string $matrixEventName, string $aggregateType, string $aggregateId, array $payload): array
            {
                return ['order_id' => 'MO-1', 'secret' => 'never'];
            }
        });

        $version = new NotificationTemplateVersion;
        $version->setRawAttributes(['variable_allowlist' => json_encode(['order_id'])]);

        $row = new OutboxEvent;
        $row->setRawAttributes([
            'aggregate_type' => 'marketplace_order',
            'aggregate_id' => 'abc',
            'payload' => json_encode(['data' => ['order_id' => 'MO-1']]),
        ]);

        self::assertSame(['order_id' => 'MO-1'], $resolver->forOutboxRow($row, 'Marketplace order submitted', $version));
    }

    public function test_a_version_with_an_empty_allowlist_gets_an_empty_bag(): void
    {
        $resolver = new NotificationVariableResolver(new class implements NotificationVariableSource
        {
            public function handles(string $aggregateType): bool
            {
                return true;
            }

            public function variablesFor(string $matrixEventName, string $aggregateType, string $aggregateId, array $payload): array
            {
                return ['order_id' => 'MO-1'];
            }
        });

        $version = new NotificationTemplateVersion;
        $version->setRawAttributes(['variable_allowlist' => json_encode([])]);

        self::assertSame([], $resolver->restrictToAllowlist(['order_id' => 'MO-1'], $version));
    }

    public function test_a_source_that_does_not_handle_the_aggregate_is_skipped(): void
    {
        $resolver = new NotificationVariableResolver(new class implements NotificationVariableSource
        {
            public function handles(string $aggregateType): bool
            {
                return $aggregateType === 'order';
            }

            public function variablesFor(string $matrixEventName, string $aggregateType, string $aggregateId, array $payload): array
            {
                return ['order_reference' => 'MK-1'];
            }
        });

        $version = new NotificationTemplateVersion;
        $version->setRawAttributes(['variable_allowlist' => json_encode(['order_reference'])]);

        $row = new OutboxEvent;
        $row->setRawAttributes(['aggregate_type' => 'vendor_order', 'aggregate_id' => 'v1', 'payload' => json_encode([])]);

        self::assertSame([], $resolver->forOutboxRow($row, 'Vendor accepted/rejected', $version));
    }

    public function test_a_later_source_overrides_an_earlier_one_for_the_same_key(): void
    {
        $first = new class implements NotificationVariableSource
        {
            public function handles(string $aggregateType): bool
            {
                return true;
            }

            public function variablesFor(string $matrixEventName, string $aggregateType, string $aggregateId, array $payload): array
            {
                return ['order_id' => 'from-payload'];
            }
        };
        $second = new class implements NotificationVariableSource
        {
            public function handles(string $aggregateType): bool
            {
                return true;
            }

            public function variablesFor(string $matrixEventName, string $aggregateType, string $aggregateId, array $payload): array
            {
                return ['order_id' => 'from-module'];
            }
        };

        $version = new NotificationTemplateVersion;
        $version->setRawAttributes(['variable_allowlist' => json_encode(['order_id'])]);
        $row = new OutboxEvent;
        $row->setRawAttributes(['aggregate_type' => 'order', 'aggregate_id' => 'o1', 'payload' => json_encode([])]);

        self::assertSame(['order_id' => 'from-module'], (new NotificationVariableResolver($first, $second))->forOutboxRow($row, 'x', $version));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Platform/Notification/NotificationVariableResolverTest.php`
Expected: FAIL with `Class "App\Platform\Notification\NotificationVariableResolver" not found`

- [ ] **Step 3: Write the resolver**

```php
<?php

declare(strict_types=1);

namespace App\Platform\Notification;

use App\Platform\Notification\Contracts\NotificationVariableSource;
use App\Platform\Notification\Models\NotificationDelivery;
use App\Platform\Notification\Models\NotificationTemplate;
use App\Platform\Notification\Models\NotificationTemplateVersion;
use App\Platform\Outbox\Models\OutboxEvent;

/**
 * Builds the variable bag for one `TemplateRenderer::render()` call.
 *
 * Two entry points, one per call-site shape: `DispatchNotification` holds
 * the fresh `OutboxEvent` row; a channel holds only the
 * `NotificationDelivery`, whose `event_id` IS the outbox row id
 * (`notification_events.event_id` references it, and
 * `notification_deliveries.event_id` references that). Both funnel into the
 * same merge-then-restrict path, so a channel re-render produces the same
 * bag the fresh-event render produced.
 *
 * Restricting to the version's `variable_allowlist` is what keeps every
 * version-1 template (empty allowlist, no placeholders) rendering: the
 * renderer rejects a SUPPLIED name that is not allowlisted, so an
 * unrestricted bag would have broken every seeded template at once.
 *
 * Sources are consulted in registration order and later keys win, so a
 * feature-module source (registered after the platform payload source)
 * can override a payload key with an authoritative value.
 */
final class NotificationVariableResolver
{
    /** @var list<NotificationVariableSource> */
    private readonly array $sources;

    public function __construct(NotificationVariableSource ...$sources)
    {
        $this->sources = array_values($sources);
    }

    /**
     * @return array<string, scalar|\Stringable|null>
     */
    public function forOutboxRow(OutboxEvent $row, string $matrixEventName, NotificationTemplateVersion $version): array
    {
        $payload = $row->payload;

        if (is_string($payload)) {
            $decoded = json_decode($payload, true);
            $payload = is_array($decoded) ? $decoded : [];
        }

        $bag = [];

        foreach ($this->sources as $source) {
            if (! $source->handles((string) $row->aggregate_type)) {
                continue;
            }

            $bag = array_merge($bag, $source->variablesFor(
                $matrixEventName,
                (string) $row->aggregate_type,
                (string) $row->aggregate_id,
                is_array($payload) ? $payload : [],
            ));
        }

        return $this->restrictToAllowlist($bag, $version);
    }

    /**
     * @return array<string, scalar|\Stringable|null>
     */
    public function forDelivery(NotificationDelivery $delivery, NotificationTemplateVersion $version): array
    {
        $row = OutboxEvent::query()->find((string) $delivery->event_id);
        $template = NotificationTemplate::query()->find($version->template_id);

        if ($row === null || $template === null) {
            return [];
        }

        return $this->forOutboxRow($row, (string) $template->event_name, $version);
    }

    /**
     * @param  array<string, mixed>  $bag
     * @return array<string, scalar|\Stringable|null>
     */
    public function restrictToAllowlist(array $bag, NotificationTemplateVersion $version): array
    {
        $allowlist = $version->variable_allowlist ?? [];

        if ($allowlist === []) {
            return [];
        }

        return array_intersect_key($bag, array_flip(array_map('strval', $allowlist)));
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Platform/Notification/NotificationVariableResolverTest.php`
Expected: PASS (4 tests). If `OutboxEvent` casts `payload` to array, the `is_string` branch is simply never taken; both shapes are handled.

- [ ] **Step 5: Commit**

```bash
git add app/Platform/Notification/NotificationVariableResolver.php tests/Unit/Platform/Notification/NotificationVariableResolverTest.php
git commit -m "feat(notification): variable resolver merges sources and restricts to the allowlist"
```

---

### Task 3: Bind the resolver and wire the three call sites

**Files:**
- Modify: the service provider that binds `App\Platform\Notification\Contracts\Channel` — find it with `grep -rn "Contracts\\\\Channel::class" app/Providers/` and register the resolver there.
- Modify: `app/Platform/Notification/Actions/DispatchNotification.php:107-114` (constructor) and `:268-269` (render).
- Modify: `app/Platform/Notification/Channels/MailChannel.php:74-77` (constructor) and `:104`.
- Modify: `app/Platform/Notification/Channels/LogChannel.php:58` (constructor) and `:66`.
- Test: `tests/Feature/Notification/NotificationTemplateVariablesRenderTest.php`

**Interfaces:**
- Consumes: `NotificationVariableResolver` (Task 2).

- [ ] **Step 1: Write the failing feature test**

This test mirrors `NotificationDispatchPipelineTest`'s fixture style: it records a real outbox row and consumes it synchronously. It uses the `Marketplace order submitted` row, whose version-3 template (Task 4) references `{{ order_id }}`. Until Task 4 lands, the active version is version 2 with single braces, so the assertion fails for the right reason.

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Notification;

use App\Platform\Notification\Jobs\ConsumeOutboxNotificationJob;
use App\Platform\Notification\Models\InAppNotification;
use App\Platform\Outbox\Outbox;
use App\Platform\Outbox\OutboxClassification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AC15 (platform-notifications): a value carried by the outbox payload
 * reaches the rendered body through the version's allowlist. Before this
 * plan every render call passed `[]`, so no template could carry a value
 * — this test is the one that would have failed then.
 */
final class NotificationTemplateVariablesRenderTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_payload_value_renders_into_the_in_app_body_through_the_allowlist(): void
    {
        // A platform admin exists with a business-entity scope so the
        // `Marketplace order submitted` row (Admin platform: IN_APP) has an
        // unconditional in-app recipient. Reuse the helper the pipeline test
        // uses if one exists; otherwise seed the minimum below.
        $this->seedPlatformAdminRecipient();

        $outboxEventId = Outbox::record(
            eventName: 'marketplace_order.submitted.v1',
            eventVersion: 1,
            aggregateType: 'marketplace_order',
            aggregateId: (string) \Illuminate\Support\Str::uuid(),
            data: ['order_id' => 'MO-TEST-42'],
            classification: OutboxClassification::Internal,
        )->getKey();

        ConsumeOutboxNotificationJob::dispatchSync($outboxEventId, matrixEventName: 'Marketplace order submitted');

        $body = InAppNotification::query()->latest('id')->value('body');

        self::assertIsString($body);
        self::assertStringContainsString('MO-TEST-42', $body);
        self::assertStringNotContainsString('{order_id}', $body, 'single-brace placeholders must not ship as literal text');
        self::assertStringNotContainsString('{{', $body);
    }

    private function seedPlatformAdminRecipient(): void
    {
        // Copy the exact fixture `NotificationDispatchPipelineTest` uses for
        // its platform-admin scenario (`test_ac7_platform_admin_recipient_gets_an_in_app_record`):
        // a User with ActorRole PLATFORM_ADMIN and a ScopeAssignment on the
        // platform `business_entity`. Read that method and replicate it here
        // verbatim; do not invent a different scope shape.
        $this->markTestIncomplete('Replace with the pipeline test fixture before running.');
    }
}
```

Replace the `markTestIncomplete` line with the copied fixture before Step 2 — the pipeline test's method name above is the one to read.

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/Notification/NotificationTemplateVariablesRenderTest.php`
Expected: FAIL — body contains the literal `{order_id}` (version 2 single braces), or, if the resolver is not yet wired, the renderer throws `Notification variable [order_id] is not allowlisted` / the body lacks `MO-TEST-42`.

- [ ] **Step 3: Register the resolver**

In the provider that binds `Contracts\Channel` (found by the grep above), add:

```php
use App\Platform\Notification\NotificationVariableResolver;
use App\Platform\Notification\PayloadNotificationVariableSource;

// inside register():
$this->app->singleton(NotificationVariableResolver::class, static fn (): NotificationVariableResolver => new NotificationVariableResolver(
    new PayloadNotificationVariableSource,
));
```

A feature module that needs its own source (the order snapshot plan) will `extend` this singleton and append its source; keep this binding a closure so `extend()` works.

- [ ] **Step 4: Wire `DispatchNotification`**

Constructor (`:107-114`): add `private readonly NotificationVariableResolver $variables,` as the last parameter and the `use App\Platform\Notification\NotificationVariableResolver;` import.

Render (`:268-269`): replace

```php
$rendered = $version !== null ? $this->renderer->render($version, []) : null;
```

with

```php
$rendered = $version !== null
    ? $this->renderer->render($version, $this->variables->forOutboxRow($outboxRow, (string) $template->event_name, $version))
    : null;
```

and update the D6 comment above it to say the bag is now resolved and allowlist-restricted, so an empty-allowlist version still renders with `[]`.

- [ ] **Step 5: Wire `MailChannel` and `LogChannel`**

`MailChannel` constructor (`:74-77`): add `private readonly NotificationVariableResolver $variables,`. Line `:104`:

```php
$rendered = $this->renderer->render($version, $this->variables->forDelivery($delivery, $version));
```

`LogChannel` constructor (`:58`): `public function __construct(private readonly TemplateRenderer $renderer, private readonly NotificationVariableResolver $variables) {}`. Line `:66`:

```php
$rendered = $this->renderer->render($version, $this->variables->forDelivery($delivery, $version));
```

Then fix every direct construction in tests:

```bash
grep -rn "new MailChannel(\|new LogChannel(\|new DispatchNotification(" tests/ app/
```

Each hit gets the extra argument `app(NotificationVariableResolver::class)` (tests) or container resolution (app code).

- [ ] **Step 6: Run the existing suites to prove nothing regressed**

Run: `vendor/bin/phpunit tests/Feature/Notification tests/Unit/Platform/Notification`
Expected: PASS. Every seeded version-1 template has an empty allowlist, so `restrictToAllowlist` returns `[]` and behaviour is byte-identical to before for them.

- [ ] **Step 7: Commit**

```bash
git add app/Providers app/Platform/Notification/Actions/DispatchNotification.php app/Platform/Notification/Channels/MailChannel.php app/Platform/Notification/Channels/LogChannel.php tests/
git commit -m "feat(notification): resolve template variables at all three render sites"
```

---

### Task 4: Version-3 templates with `{{ }}` placeholders

**Files:**
- Create: `database/migrations/2026_09_20_100000_add_v3_notification_templates_with_double_brace_placeholders.php`
- Test: `tests/Feature/Notification/NotificationTemplateVariablesRenderTest.php` (Task 3; now passes)

- [ ] **Step 1: Write the migration**

Copy `database/migrations/2026_09_06_130000_add_v2_notification_templates_for_zero_recipient_events.php` and change: the class doc block (why: single braces never matched `TemplateRenderer::PLACEHOLDER_PATTERN`, so the version-2 bodies shipped their placeholders as literal text — recorded in `.kiro/specs/platform-notifications/design.md` §Template variables), `CREATED_BY = 'seed:v3-double-brace-placeholders'`, the version number `2` → `3` in both the existence check and the insert, the `down()` re-point target `1` → `2`, and the two bodies:

```php
'body' => 'Kabar baik! Pesanan Anda dengan nomor referensi vendor {{ vendor_order_id }} telah {{ outcome }} oleh vendor. '
    .'Silakan cek halaman pesanan Anda di Makam.co.id untuk detail lebih lanjut. '
    .'Jika ada pertanyaan, hubungi layanan pelanggan kami.',
```

```php
'body' => 'Terima kasih, pesanan Anda dengan nomor {{ order_id }} telah berhasil kami terima dan sedang diproses oleh vendor. '
    .'Kami akan mengabari Anda begitu vendor memberikan keputusan atas pesanan ini.',
```

`variable_allowlist` and `restricted_fields` stay exactly as in version 2.

- [ ] **Step 2: Run the feature test**

Run: `vendor/bin/phpunit tests/Feature/Notification/NotificationTemplateVariablesRenderTest.php`
Expected: PASS — the body contains `MO-TEST-42` and no braces.

- [ ] **Step 3: Run the seed-coverage test**

Run: `vendor/bin/phpunit --filter test_the_matrix_seed_covers_every_matrix_event_with_one_active_version`
Expected: PASS (one active version per event still holds; it is now version 3 for two events).

- [ ] **Step 4: Commit**

```bash
git add database/migrations/2026_09_20_100000_add_v3_notification_templates_with_double_brace_placeholders.php
git commit -m "fix(notification): version-3 templates use the renderer's double-brace placeholders"
```

---

### Task 5: Record what shipped

**Files:**
- Modify: `.kiro/specs/platform-notifications/tasks.md` — the AC15 task line: tick it, `— done <date> (PR #<n>)`, name the two test files.
- Modify: `.kiro/specs/platform-notifications/traceability-matrix.md` — AC15 row: Test evidence `tests/Feature/Notification/NotificationTemplateVariablesRenderTest.php`, `tests/Unit/Platform/Notification/NotificationVariableResolverTest.php`; Status `Closed (local evidence)` only after those tests are green in CI, else leave `Open` with the PR number.
- Modify: `docs/remediation/findings.yml` — no entry exists for the single-brace defect; add `NOTIF-<next>` with `status: in_review` and this PR in `status_evidence`, statement: "Version-2 bodies for Vendor accepted/rejected and Marketplace order submitted used single-brace placeholders the renderer never matched; customers received literal `{order_id}`." Use the id numbering the file's existing NOTIF entries follow (`grep -n "^- id: NOTIF-" docs/remediation/findings.yml | tail -1`).

- [ ] **Step 1: Make the edits, run the docs gate**

Run: `bash ci/verify-docs.sh`
Expected: `RESULT: ALL DOC GATES PASS`

- [ ] **Step 2: Commit and open the PR**

```bash
git add .kiro/specs/platform-notifications docs/remediation/findings.yml
git commit -m "docs(notification): record template-variable pipeline in spec tasks, traceability, and findings"
```

PR title: `feat(notification): template variables reach the renderer; v3 templates fix single-brace bodies`. Base: `docs/design-system-and-planning`. Body cites AC15 and the S11/S12 decisions.

---

## Self-review

- **Spec coverage.** AC15's "expose the snapshot's operator contact and required-document list as allowlisted template variables" needs a source for the `order` aggregate; that source, and the `Booking submitted` version-2 template that references it, are Plan B Task 8 by design (S11: pipeline first). AC1 (no restating the matrix): nothing here reads matrix cells. AC8: `window_key` untouched. AC11: values pass through the renderer's restricted check unchanged.
- **Placeholders.** None of "TBD/TODO/similar to Task N". The one `markTestIncomplete` in Task 3 Step 1 is an explicit instruction to copy a named fixture before running, with the method to copy named.
- **Type consistency.** `forOutboxRow(OutboxEvent, string, NotificationTemplateVersion)` and `forDelivery(NotificationDelivery, NotificationTemplateVersion)` are used with those exact signatures in Task 3; `handles()`/`variablesFor()` match between Tasks 1 and 2.
