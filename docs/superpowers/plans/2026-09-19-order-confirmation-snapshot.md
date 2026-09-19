# Order Confirmation Snapshot Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** When a booking is submitted, freeze the cemetery's operator contact and the list of documents the family must bring onto the order, and show both on the Step 4 confirmation screen and in the `Booking submitted` email.

**Architecture:** A write-once `order_confirmation_snapshots` row is captured inside `SubmitBookingDraft`'s transaction from three new nullable `cemeteries` columns (admin-edited) with a platform customer-service fallback. `OrderReadModel` exposes it as `handover`; the wizard renders it; an `OrderWorkflow`-owned `NotificationVariableSource` feeds it to a new `Booking submitted` template version. Document codes come from a closed PHP list kept in parity with `docs/product/required-document-catalog.md` by a drift test.

**Tech Stack:** Laravel 13, Livewire 4, Filament 5, PostgreSQL 18 (tests default to SQLite per `phpunit.xml`), PHPUnit.

**Spec:** `.kiro/specs/booking-and-order-orchestration/requirements.md` AC13, AC15 + `design.md` §Confirmation snapshot; `.kiro/specs/cemetery-directory-and-availability/requirements.md` AC15; `.kiro/specs/platform-notifications/requirements.md` AC15; `.kiro/specs/admin-operations/requirements.md` AC13; `docs/product/required-document-catalog.md`. Decisions: `docs/product/prd-yiem-2026-09-18.md` §16 (Q15, Q22, Q23, Q35) and §Decisions below.

**Depends on:** `docs/superpowers/plans/2026-09-19-notification-template-variables.md` merged first (Task 8 registers a `NotificationVariableSource` and needs the resolver binding).

## Decisions (grill-spec, 19 Sep 2026, all accepted)

| # | Decision |
|---|---|
| S1 | Separate `order_confirmation_snapshots` table, `order_id` unique, write-once. |
| S2 | Platform fallback records `contact_is_platform_fallback = true`; `contact_phone` is NULL when only `ContactInfo`'s placeholder default exists. The screen then links `/bantuan` without a number. |
| S3 | Operating hours = free text ≤ 120 chars. |
| S4 | A cemetery override replaces the whole list; the admin form is pre-filled from the platform default. |
| S5 | Snapshot stores codes only; labels resolve at render; catalog codes are never deleted. |
| S6 | Closed PHP list + drift test against the markdown catalog (precedent: `ServiceCode` + `ServiceCodeDriftTest`). |
| S7 | Booking only. Renewal confirmation is out of scope here. |
| S8 | No backfill; orders without a row render only the help-centre line. |
| S9 | Operator panel gets a read-only widget showing the three fields per granted cemetery. |
| S10 | "Confirmed" = submission (`MASUK`). |
| S11 | Two PRs: variable pipeline first (other plan), then this. |
| S12 | Document list reaches the template as one pre-joined string variable. |
| S13 | Snapshot written in the same transaction as the `MASUK` status event. |
| S14 | Wizard reads the snapshot through `OrderReadModel`, not the inline array. |
| S15 | `OrderConfirmation` schema added to `openapi.yaml` with a parity test. |
| S16 | Admin form also gains `operator_name` (existing column, never on the form). |
| S17 | `orders_status_check` drift recorded as `SCHEMA-01`, fixed separately. |

## Global Constraints

- Domain logic stays in Actions/Query classes under `app/Domain/`; Livewire and Filament call them (`AGENTS.md` §Architecture).
- `app/Platform/**` never imports `app/Domain/**`; the order variable source lives in `app/Domain/OrderWorkflow/` and is registered onto the platform resolver from a Domain-side provider.
- No hardcoded design values in Blade; use `x-mk.*` components and tokens (`ci/verify-docs.sh` GATE 2/3 fail otherwise).
- Every transactional screen keeps its loading/empty/error/pending/success/support states; the new confirmation block only adds content inside the existing success branch.
- Never print a placeholder phone number to a customer (S2).
- Migrations are expand-only; `down()` non-destructive for data.
- Copy is Bahasa Indonesia. Labels for documents come from the catalog file verbatim.
- `orders.status` is written only by `RecordOrderStatusChange`; this plan never touches it.
- One PR for this plan; commit per task; run the named test class per task; CI runs the full suite.

---

## File structure

| File | Responsibility |
|---|---|
| `app/Domain/CemeteryDirectory/RequiredDocumentCode.php` (create) | Closed list of document codes, labels, applies-to, platform default for booking. |
| `tests/Feature/Domain/CemeteryDirectory/RequiredDocumentCodeDriftTest.php` (create) | Parity with `docs/product/required-document-catalog.md`. |
| `database/migrations/2026_09_20_110000_add_operator_contact_and_document_override_to_cemeteries.php` (create) | Three nullable columns. |
| `app/Domain/CemeteryDirectory/Models/Cemetery.php` (modify) | Fillable, casts, override validation in `saving`. |
| `database/migrations/2026_09_20_110010_create_order_confirmation_snapshots_table.php` (create) | The snapshot table. |
| `app/Domain/OrderWorkflow/Models/OrderConfirmationSnapshot.php` (create) | Write-once model. |
| `app/Domain/OrderWorkflow/Models/Order.php` (modify) | `confirmationSnapshot()` relation. |
| `app/Support/ContactInfo.php` (modify) | `phoneIsConfigured()`. |
| `app/Domain/OrderWorkflow/Actions/CaptureOrderConfirmationSnapshot.php` (create) | Builds and inserts the row. |
| `app/Domain/OrderWorkflow/Actions/SubmitBookingDraft.php` (modify) | Calls the capture inside the transaction. |
| `app/Domain/OrderWorkflow/OrderConfirmationHandover.php` (create) | Read-side value object. |
| `app/Domain/OrderWorkflow/OrderReadModel.php` (modify) | `handover` field. |
| `app/Livewire/Public/Booking/BookingWizard.php` + `resources/views/livewire/public/booking/wizard.blade.php` (modify) | Step 4 block. |
| `app/Domain/OrderWorkflow/Notifications/OrderNotificationVariableSource.php` (create) + provider registration | `order_reference`, `contact_line`, `required_documents` variables. |
| `database/migrations/2026_09_20_110020_add_v2_booking_submitted_template_with_handover.php` (create) | Real copy for `Booking submitted`. |
| `app/Filament/Admin/Resources/CemeteryResource/Schemas/CemeteryForm.php` (modify) | Four fields. |
| `app/Filament/Operator/Widgets/CemeteryHandoverWidget.php` + view (create), `OperatorPanelProvider` (modify) | Read-only display. |
| `docs/contracts/openapi.yaml` (modify) + test | `OrderConfirmation` schema. |
| Spec `tasks.md`, `docs/domain/traceability-matrix.md`, `docs/product/screen-inventory.md` (modify) | Record what shipped. |

---

### Task 1: Closed document-code list with a drift test

**Files:**
- Create: `app/Domain/CemeteryDirectory/RequiredDocumentCode.php`
- Test: `tests/Feature/Domain/CemeteryDirectory/RequiredDocumentCodeDriftTest.php`

**Interfaces:**
- Produces: `RequiredDocumentCode::KNOWN_CODES` (list<string>), `::LABELS` (array<string,string>), `::APPLIES_TO` (array<string, list<string>> with values `booking`/`renewal`), `::defaultForBooking(): list<string>`, `::label(string $code): string`, `::assertKnown(string $code): void`, `::isKnown(string $code): bool`.

- [ ] **Step 1: Write the failing drift test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\CemeteryDirectory;

use App\Domain\CemeteryDirectory\RequiredDocumentCode;
use Tests\TestCase;

/**
 * Drift detection between `docs/product/required-document-catalog.md` and
 * `RequiredDocumentCode`, in the shape `ServiceCodeDriftTest` uses for
 * `service-catalog.md`. The markdown is canonical (AGENTS.md §Documentation);
 * the PHP list exists so form validation and rendering do not parse
 * markdown at runtime. Change one, and this test tells you to change the
 * other.
 */
final class RequiredDocumentCodeDriftTest extends TestCase
{
    public function test_known_codes_match_the_catalogue_in_order(): void
    {
        $rows = $this->catalogueRows();

        self::assertSame(array_keys($rows), RequiredDocumentCode::KNOWN_CODES);
    }

    public function test_labels_match_the_catalogue_verbatim(): void
    {
        foreach ($this->catalogueRows() as $code => [$label]) {
            self::assertSame($label, RequiredDocumentCode::label($code), "[{$code}] label mismatch.");
        }
    }

    public function test_the_platform_default_is_exactly_the_default_table_rows_that_apply_to_booking(): void
    {
        $expected = [];

        foreach ($this->catalogueRows() as $code => [, $appliesTo, $section]) {
            if ($section === 'default' && in_array('booking', $appliesTo, true)) {
                $expected[] = $code;
            }
        }

        self::assertSame($expected, RequiredDocumentCode::defaultForBooking());
    }

    /**
     * @return array<string, array{0: string, 1: list<string>, 2: string}>  code => [label, appliesTo, section]
     */
    private function catalogueRows(): array
    {
        $markdown = file_get_contents(base_path('docs/product/required-document-catalog.md'));
        self::assertIsString($markdown);

        $rows = [];
        $section = null;

        foreach (preg_split('/\R/', $markdown) as $line) {
            if (str_starts_with($line, '## Platform default')) {
                $section = 'default';

                continue;
            }

            if (str_starts_with($line, '## Optional codes')) {
                $section = 'optional';

                continue;
            }

            if (str_starts_with($line, '## ')) {
                $section = null;

                continue;
            }

            if ($section === null || preg_match('/^\| ([A-Z_]+) \| (.+?) \|(?: (.+?) \|)?$/', $line, $m) !== 1) {
                continue;
            }

            $appliesTo = isset($m[3]) ? array_map('trim', explode(',', $m[3])) : ['booking', 'renewal'];
            $rows[$m[1]] = [$m[2], $appliesTo, $section];
        }

        self::assertNotEmpty($rows, 'No catalogue rows parsed.');

        return $rows;
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/Domain/CemeteryDirectory/RequiredDocumentCodeDriftTest.php`
Expected: FAIL with `Class "App\Domain\CemeteryDirectory\RequiredDocumentCode" not found`

- [ ] **Step 3: Write the closed list**

```php
<?php

declare(strict_types=1);

namespace App\Domain\CemeteryDirectory;

use InvalidArgumentException;

/**
 * Closed list mirroring `docs/product/required-document-catalog.md`
 * (canonical). `RequiredDocumentCodeDriftTest` fails when the two diverge.
 * Codes are never removed — an order snapshot stores codes only and must
 * still resolve a label years later (grill-spec S5); retire a document by
 * dropping it from `defaultForBooking()` and the catalogue's default table,
 * never by deleting the constant.
 */
final class RequiredDocumentCode
{
    public const string KTP_PEMESAN = 'KTP_PEMESAN';

    public const string KK = 'KK';

    public const string SURAT_KETERANGAN_KEMATIAN = 'SURAT_KETERANGAN_KEMATIAN';

    public const string KTP_ALMARHUM = 'KTP_ALMARHUM';

    public const string BUKTI_HAK_MAKAM = 'BUKTI_HAK_MAKAM';

    public const string SURAT_PENGANTAR_RT_RW = 'SURAT_PENGANTAR_RT_RW';

    public const string SURAT_KUASA = 'SURAT_KUASA';

    public const string BUKTI_BAYAR_MANUAL = 'BUKTI_BAYAR_MANUAL';

    /** @var list<string> Catalogue order: default table, then optional table. */
    public const array KNOWN_CODES = [
        self::KTP_PEMESAN,
        self::KK,
        self::SURAT_KETERANGAN_KEMATIAN,
        self::KTP_ALMARHUM,
        self::BUKTI_HAK_MAKAM,
        self::SURAT_PENGANTAR_RT_RW,
        self::SURAT_KUASA,
        self::BUKTI_BAYAR_MANUAL,
    ];

    /** @var array<string, string> Verbatim from the catalogue. */
    public const array LABELS = [
        self::KTP_PEMESAN => 'KTP pemesan',
        self::KK => 'Kartu Keluarga',
        self::SURAT_KETERANGAN_KEMATIAN => 'Surat keterangan kematian (RS, puskesmas, atau kelurahan)',
        self::KTP_ALMARHUM => 'KTP almarhum, bila ada',
        self::BUKTI_HAK_MAKAM => 'Bukti hak/izin penggunaan makam terakhir (IPTM atau setara)',
        self::SURAT_PENGANTAR_RT_RW => 'Surat pengantar RT/RW',
        self::SURAT_KUASA => 'Surat kuasa ahli waris',
        self::BUKTI_BAYAR_MANUAL => 'Bukti transfer, bila membayar lewat jalur manual',
    ];

    /** @var array<string, list<string>> */
    public const array APPLIES_TO = [
        self::KTP_PEMESAN => ['booking', 'renewal'],
        self::KK => ['booking', 'renewal'],
        self::SURAT_KETERANGAN_KEMATIAN => ['booking'],
        self::KTP_ALMARHUM => ['booking'],
        self::BUKTI_HAK_MAKAM => ['renewal'],
        self::SURAT_PENGANTAR_RT_RW => ['booking', 'renewal'],
        self::SURAT_KUASA => ['booking', 'renewal'],
        self::BUKTI_BAYAR_MANUAL => ['booking', 'renewal'],
    ];

    /** @var list<string> The catalogue's "Platform default" table, booking rows. */
    private const array DEFAULT_FOR_BOOKING = [
        self::KTP_PEMESAN,
        self::KK,
        self::SURAT_KETERANGAN_KEMATIAN,
        self::KTP_ALMARHUM,
    ];

    /**
     * @return list<string>
     */
    public static function defaultForBooking(): array
    {
        return self::DEFAULT_FOR_BOOKING;
    }

    public static function isKnown(string $code): bool
    {
        return in_array($code, self::KNOWN_CODES, true);
    }

    public static function assertKnown(string $code): void
    {
        if (! self::isKnown($code)) {
            throw new InvalidArgumentException("Unknown required-document code [{$code}].");
        }
    }

    public static function label(string $code): string
    {
        self::assertKnown($code);

        return self::LABELS[$code];
    }

    /**
     * @return array<string, string>  code => label, for form options
     */
    public static function options(): array
    {
        return self::LABELS;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/Domain/CemeteryDirectory/RequiredDocumentCodeDriftTest.php`
Expected: PASS (3 tests). If the applies-to parse disagrees, the catalogue's third column is the authority — fix the constant, not the test.

- [ ] **Step 5: Commit**

```bash
git add app/Domain/CemeteryDirectory/RequiredDocumentCode.php tests/Feature/Domain/CemeteryDirectory/RequiredDocumentCodeDriftTest.php
git commit -m "feat(cemetery): closed required-document code list with catalogue drift test"
```

---

### Task 2: Cemetery columns for operator contact and document override

**Files:**
- Create: `database/migrations/2026_09_20_110000_add_operator_contact_and_document_override_to_cemeteries.php`
- Modify: `app/Domain/CemeteryDirectory/Models/Cemetery.php:92-114` (fillable), `:127-137` (casts), `:140-152` (saving hook)
- Test: `tests/Feature/Domain/CemeteryDirectory/CemeteryOperatorContactTest.php`

**Interfaces:**
- Produces: `cemeteries.operator_contact_phone` (string 32, null), `cemeteries.operator_hours_text` (string 120, null), `cemeteries.required_documents_override` (json list<string>|null). Model casts `required_documents_override` to `array`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\CemeteryDirectory;

use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\CemeteryDirectory\RequiredDocumentCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

final class CemeteryOperatorContactTest extends TestCase
{
    use RefreshDatabase;

    public function test_contact_and_override_persist_and_round_trip(): void
    {
        $cemetery = Cemetery::factory()->create([
            'operator_name' => 'UPTD TPU Contoh',
            'operator_contact_phone' => '+62 21 000 0000',
            'operator_hours_text' => 'Setiap hari 07.00–16.00',
            'required_documents_override' => [RequiredDocumentCode::KTP_PEMESAN, RequiredDocumentCode::SURAT_KUASA],
        ]);

        $fresh = Cemetery::query()->findOrFail($cemetery->id);

        self::assertSame('+62 21 000 0000', $fresh->operator_contact_phone);
        self::assertSame('Setiap hari 07.00–16.00', $fresh->operator_hours_text);
        self::assertSame(['KTP_PEMESAN', 'SURAT_KUASA'], $fresh->required_documents_override);
    }

    public function test_an_unknown_override_code_is_rejected_on_save(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Cemetery::factory()->create(['required_documents_override' => ['NOT_A_CODE']]);
    }

    public function test_the_three_columns_default_to_null(): void
    {
        $cemetery = Cemetery::factory()->create();

        self::assertNull($cemetery->fresh()->operator_contact_phone);
        self::assertNull($cemetery->fresh()->operator_hours_text);
        self::assertNull($cemetery->fresh()->required_documents_override);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/Domain/CemeteryDirectory/CemeteryOperatorContactTest.php`
Expected: FAIL — `table cemeteries has no column named operator_contact_phone`

- [ ] **Step 3: Write the migration**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * cemetery-directory-and-availability AC15 (19 Sep 2026): per-cemetery
 * operator contact and an optional override of the required-document list
 * (`docs/product/required-document-catalog.md`). All three are nullable —
 * absence means "use the platform fallback / platform default", which
 * `CaptureOrderConfirmationSnapshot` resolves at submission. Expand-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cemeteries', function (Blueprint $table): void {
            $table->string('operator_contact_phone', 32)->nullable()->after('operator_name');
            $table->string('operator_hours_text', 120)->nullable()->after('operator_contact_phone');
            $table->json('required_documents_override')->nullable()->after('operator_hours_text');
        });
    }

    public function down(): void
    {
        Schema::table('cemeteries', function (Blueprint $table): void {
            $table->dropColumn(['operator_contact_phone', 'operator_hours_text', 'required_documents_override']);
        });
    }
};
```

- [ ] **Step 4: Update the model**

In `$fillable` add after `'operator_name',`:

```php
        'operator_contact_phone',
        'operator_hours_text',
        'required_documents_override',
```

In `casts()` add:

```php
            'required_documents_override' => 'array',
```

In `booted()`'s `saving` closure, after the launch-city check, add:

```php
            foreach ($cemetery->required_documents_override ?? [] as $code) {
                RequiredDocumentCode::assertKnown((string) $code);
            }
```

with `use App\Domain\CemeteryDirectory\RequiredDocumentCode;` imported (same namespace: no import needed if the model is in `App\Domain\CemeteryDirectory\Models` — it is, so add the `use`).

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/Domain/CemeteryDirectory/CemeteryOperatorContactTest.php`
Expected: PASS (3 tests)

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_09_20_110000_add_operator_contact_and_document_override_to_cemeteries.php app/Domain/CemeteryDirectory/Models/Cemetery.php tests/Feature/Domain/CemeteryDirectory/CemeteryOperatorContactTest.php
git commit -m "feat(cemetery): operator contact phone, hours text, and required-document override columns"
```

---

### Task 3: The snapshot table and write-once model

**Files:**
- Create: `database/migrations/2026_09_20_110010_create_order_confirmation_snapshots_table.php`
- Create: `app/Domain/OrderWorkflow/Models/OrderConfirmationSnapshot.php`
- Modify: `app/Domain/OrderWorkflow/Models/Order.php` — add relation after `status()` (`:104-107`)
- Test: `tests/Feature/Domain/OrderWorkflow/OrderConfirmationSnapshotModelTest.php`

**Interfaces:**
- Produces: `OrderConfirmationSnapshot` with attributes `order_id`, `contact_name`, `contact_phone`, `contact_hours_text`, `contact_is_platform_fallback` (bool), `required_document_codes` (array), `captured_at`; `Order::confirmationSnapshot(): HasOne`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\OrderWorkflow;

use App\Domain\OrderWorkflow\Exceptions\OrderConfirmationSnapshotIsImmutableException;
use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\Models\OrderConfirmationSnapshot;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\OrderWorkflow\ProductType;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class OrderConfirmationSnapshotModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_snapshot_is_written_once_and_read_back_through_the_order(): void
    {
        $order = $this->makeOrder();

        OrderConfirmationSnapshot::query()->create([
            'order_id' => $order->id,
            'contact_name' => 'UPTD TPU Contoh',
            'contact_phone' => '+62 21 000 0000',
            'contact_hours_text' => 'Setiap hari 07.00–16.00',
            'contact_is_platform_fallback' => false,
            'required_document_codes' => ['KTP_PEMESAN', 'KK'],
            'captured_at' => now(),
        ]);

        $snapshot = $order->fresh()->confirmationSnapshot;

        self::assertNotNull($snapshot);
        self::assertSame(['KTP_PEMESAN', 'KK'], $snapshot->required_document_codes);
        self::assertFalse($snapshot->contact_is_platform_fallback);
    }

    public function test_a_second_snapshot_for_the_same_order_is_rejected_by_the_database(): void
    {
        $order = $this->makeOrder();
        $row = ['order_id' => $order->id, 'contact_is_platform_fallback' => true, 'required_document_codes' => [], 'captured_at' => now()];

        OrderConfirmationSnapshot::query()->create($row);

        $this->expectException(QueryException::class);
        OrderConfirmationSnapshot::query()->create($row);
    }

    public function test_a_persisted_snapshot_cannot_be_updated_or_deleted(): void
    {
        $order = $this->makeOrder();
        $snapshot = OrderConfirmationSnapshot::query()->create(['order_id' => $order->id, 'contact_is_platform_fallback' => true, 'required_document_codes' => [], 'captured_at' => now()]);

        try {
            $snapshot->update(['contact_name' => 'changed']);
            self::fail('update() must throw');
        } catch (OrderConfirmationSnapshotIsImmutableException) {
        }

        try {
            $snapshot->delete();
            self::fail('delete() must throw');
        } catch (OrderConfirmationSnapshotIsImmutableException) {
        }

        self::assertNull($snapshot->fresh()->contact_name);
    }

    private function makeOrder(): Order
    {
        return Order::query()->create([
            'reference' => 'MK-2026-'.strtoupper(bin2hex(random_bytes(4))),
            'product_type' => ProductType::AT_NEED_SERVICE_ORDER->value,
            'status' => OrderStatus::MASUK->value,
        ]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/Domain/OrderWorkflow/OrderConfirmationSnapshotModelTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Write the migration, exception, model, and relation**

Migration:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * booking-and-order-orchestration AC15 (19 Sep 2026): what the family was
 * told at submission — operator contact and the documents to bring —
 * frozen per order. One row per order (`order_id` UNIQUE), written once by
 * `CaptureOrderConfirmationSnapshot` inside `SubmitBookingDraft`'s
 * transaction, never updated (model guard). No backfill for orders that
 * predate this table (grill-spec S8). Precedent for a frozen child table
 * over a JSON column on the parent: `quote_lines`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_confirmation_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('order_id')->unique()->constrained('orders')->restrictOnDelete();
            $table->string('contact_name', 255)->nullable();
            $table->string('contact_phone', 32)->nullable();
            $table->string('contact_hours_text', 120)->nullable();
            $table->boolean('contact_is_platform_fallback');
            $table->json('required_document_codes');
            $table->timestamp('captured_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_confirmation_snapshots');
    }
};
```

Exception `app/Domain/OrderWorkflow/Exceptions/OrderConfirmationSnapshotIsImmutableException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\OrderWorkflow\Exceptions;

use LogicException;

final class OrderConfirmationSnapshotIsImmutableException extends LogicException
{
    public static function forOperation(string $operation): self
    {
        return new self("order_confirmation_snapshots rows are write-once; [{$operation}] is not allowed.");
    }
}
```

Model (same guard shape as `NotificationTemplateVersion`):

```php
<?php

declare(strict_types=1);

namespace App\Domain\OrderWorkflow\Models;

use App\Domain\OrderWorkflow\Exceptions\OrderConfirmationSnapshotIsImmutableException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Write-once. See the migration's doc block for why this exists and the
 * design section `booking-and-order-orchestration/design.md` §Confirmation
 * snapshot. `create()` is the only open path; the guards below stop
 * Eloquent-level rewrites. Bulk builder updates and raw SQL are NOT
 * stopped — stated, as `Order`'s own doc block states the same gap.
 */
final class OrderConfirmationSnapshot extends Model
{
    protected $table = 'order_confirmation_snapshots';

    public $timestamps = false;

    protected $fillable = [
        'order_id',
        'contact_name',
        'contact_phone',
        'contact_hours_text',
        'contact_is_platform_fallback',
        'required_document_codes',
        'captured_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'contact_is_platform_fallback' => 'boolean',
            'required_document_codes' => 'array',
            'captured_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::saving(function (self $snapshot): void {
            if ($snapshot->exists) {
                throw OrderConfirmationSnapshotIsImmutableException::forOperation('save');
            }
        });

        self::deleting(function (): void {
            throw OrderConfirmationSnapshotIsImmutableException::forOperation('delete');
        });
    }

    public function update(array $attributes = [], array $options = []): bool
    {
        throw OrderConfirmationSnapshotIsImmutableException::forOperation('update');
    }

    protected function performUpdate(Builder $query): bool
    {
        throw OrderConfirmationSnapshotIsImmutableException::forOperation('performUpdate');
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }
}
```

`Order.php`, after `status()`:

```php
    /**
     * AC15 — what the family was told at submission. Null for orders that
     * predate the table (no backfill, grill-spec S8).
     *
     * @return HasOne<OrderConfirmationSnapshot, $this>
     */
    public function confirmationSnapshot(): HasOne
    {
        return $this->hasOne(OrderConfirmationSnapshot::class, 'order_id');
    }
```

with `use Illuminate\Database\Eloquent\Relations\HasOne;` added to the imports.

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/Domain/OrderWorkflow/OrderConfirmationSnapshotModelTest.php`
Expected: PASS (3 tests)

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_09_20_110010_create_order_confirmation_snapshots_table.php app/Domain/OrderWorkflow/Models/OrderConfirmationSnapshot.php app/Domain/OrderWorkflow/Exceptions/OrderConfirmationSnapshotIsImmutableException.php app/Domain/OrderWorkflow/Models/Order.php tests/Feature/Domain/OrderWorkflow/OrderConfirmationSnapshotModelTest.php
git commit -m "feat(order): write-once order_confirmation_snapshots table and model"
```

---

### Task 4: `ContactInfo::phoneIsConfigured()` and the capture action

**Files:**
- Modify: `app/Support/ContactInfo.php:69-72`
- Create: `app/Domain/OrderWorkflow/Actions/CaptureOrderConfirmationSnapshot.php`
- Test: `tests/Feature/Domain/OrderWorkflow/CaptureOrderConfirmationSnapshotTest.php`

**Interfaces:**
- Produces: `ContactInfo::phoneIsConfigured(): bool` — true only when a non-empty value comes from config, env, or `site_settings`, never from the placeholder constant.
- Produces: `CaptureOrderConfirmationSnapshot::__invoke(Order $order, Cemetery $cemetery): OrderConfirmationSnapshot`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\OrderWorkflow;

use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\CemeteryDirectory\RequiredDocumentCode;
use App\Domain\OrderWorkflow\Actions\CaptureOrderConfirmationSnapshot;
use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\OrderWorkflow\ProductType;
use App\Platform\SiteSettings\Models\SiteSetting;
use App\Support\ContactInfo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CaptureOrderConfirmationSnapshotTest extends TestCase
{
    use RefreshDatabase;

    public function test_operator_contact_and_override_are_frozen_verbatim(): void
    {
        $cemetery = Cemetery::factory()->create([
            'operator_name' => 'UPTD TPU Contoh',
            'operator_contact_phone' => '+62 21 000 0000',
            'operator_hours_text' => 'Setiap hari 07.00–16.00',
            'required_documents_override' => [RequiredDocumentCode::KTP_PEMESAN, RequiredDocumentCode::SURAT_KUASA],
        ]);
        $order = $this->makeOrder();

        $snapshot = app(CaptureOrderConfirmationSnapshot::class)($order, $cemetery);

        self::assertSame('UPTD TPU Contoh', $snapshot->contact_name);
        self::assertSame('+62 21 000 0000', $snapshot->contact_phone);
        self::assertSame('Setiap hari 07.00–16.00', $snapshot->contact_hours_text);
        self::assertFalse($snapshot->contact_is_platform_fallback);
        self::assertSame(['KTP_PEMESAN', 'SURAT_KUASA'], $snapshot->required_document_codes);
    }

    public function test_without_an_override_the_platform_default_for_booking_is_frozen(): void
    {
        $cemetery = Cemetery::factory()->create(['operator_contact_phone' => '+62 21 000 0000']);

        $snapshot = app(CaptureOrderConfirmationSnapshot::class)($this->makeOrder(), $cemetery);

        self::assertSame(RequiredDocumentCode::defaultForBooking(), $snapshot->required_document_codes);
    }

    public function test_without_an_operator_phone_the_configured_platform_phone_is_used_and_flagged(): void
    {
        SiteSetting::query()->create(['key' => SiteSetting::KEY_SUPPORT_PHONE, 'value' => '+62 800 1234 5678']);
        $this->app->forgetInstance(\App\Platform\SiteSettings\SettingsService::class);
        $cemetery = Cemetery::factory()->create(['operator_name' => 'TPU Tanpa Telepon']);

        $snapshot = app(CaptureOrderConfirmationSnapshot::class)($this->makeOrder(), $cemetery);

        self::assertTrue($snapshot->contact_is_platform_fallback);
        self::assertSame('+62 800 1234 5678', $snapshot->contact_phone);
        self::assertSame(ContactInfo::businessHours(), $snapshot->contact_hours_text);
        self::assertNull($snapshot->contact_name);
    }

    public function test_a_placeholder_platform_phone_is_never_frozen(): void
    {
        config(['site.support_phone' => null]);
        putenv('SUPPORT_PHONE');
        $this->app->forgetInstance(\App\Platform\SiteSettings\SettingsService::class);
        self::assertFalse(ContactInfo::phoneIsConfigured(), 'precondition: no real phone configured');

        $cemetery = Cemetery::factory()->create();

        $snapshot = app(CaptureOrderConfirmationSnapshot::class)($this->makeOrder(), $cemetery);

        self::assertTrue($snapshot->contact_is_platform_fallback);
        self::assertNull($snapshot->contact_phone);
    }

    private function makeOrder(): Order
    {
        return Order::query()->create([
            'reference' => 'MK-2026-'.strtoupper(bin2hex(random_bytes(4))),
            'product_type' => ProductType::AT_NEED_SERVICE_ORDER->value,
            'status' => OrderStatus::MASUK->value,
        ]);
    }
}
```

If `SiteSetting` has a factory or different required columns, read `app/Platform/SiteSettings/Models/SiteSetting.php` and its migration and adjust the `create()` call — do not weaken the assertion.

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/Domain/OrderWorkflow/CaptureOrderConfirmationSnapshotTest.php`
Expected: FAIL — `CaptureOrderConfirmationSnapshot` not found.

- [ ] **Step 3: Add `phoneIsConfigured()` to `ContactInfo`**

After `phone()`:

```php
    /**
     * True only when a real value exists in config, env, or `site_settings`.
     * `PHONE` is a deliberate placeholder, so anything that PRINTS a number
     * to a customer as a promise (an order confirmation's contact line)
     * must check this first and print no number at all when it is false —
     * grill-spec S2, 19 Sep 2026.
     */
    public static function phoneIsConfigured(): bool
    {
        $value = app(SettingsService::class)->setting(SiteSetting::KEY_SUPPORT_PHONE, null);

        return is_string($value) && trim($value) !== '';
    }
```

- [ ] **Step 4: Write the action**

```php
<?php

declare(strict_types=1);

namespace App\Domain\OrderWorkflow\Actions;

use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\CemeteryDirectory\RequiredDocumentCode;
use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\Models\OrderConfirmationSnapshot;
use App\Support\ContactInfo;
use Illuminate\Support\Carbon;

/**
 * AC15 (booking-and-order-orchestration). Called by `SubmitBookingDraft`
 * inside its transaction, right after the `OrderParty` row, so an order
 * never exists without the handover it was submitted with.
 *
 * Contact resolution (grill-spec S2, S23/Q35):
 *   operator phone present  -> operator name/phone/hours, fallback=false
 *   absent, platform phone configured -> platform phone + business hours, fallback=true
 *   absent, only the placeholder      -> phone NULL, hours NULL, fallback=true
 * Documents (S4, S5): the cemetery override replaces the whole list;
 * otherwise the platform default for booking. Codes only.
 */
final readonly class CaptureOrderConfirmationSnapshot
{
    public function __invoke(Order $order, Cemetery $cemetery): OrderConfirmationSnapshot
    {
        $operatorPhone = self::blankToNull($cemetery->operator_contact_phone);

        if ($operatorPhone !== null) {
            $contact = [
                'contact_name' => self::blankToNull($cemetery->operator_name),
                'contact_phone' => $operatorPhone,
                'contact_hours_text' => self::blankToNull($cemetery->operator_hours_text),
                'contact_is_platform_fallback' => false,
            ];
        } elseif (ContactInfo::phoneIsConfigured()) {
            $contact = [
                'contact_name' => null,
                'contact_phone' => ContactInfo::phone(),
                'contact_hours_text' => ContactInfo::businessHours(),
                'contact_is_platform_fallback' => true,
            ];
        } else {
            $contact = [
                'contact_name' => null,
                'contact_phone' => null,
                'contact_hours_text' => null,
                'contact_is_platform_fallback' => true,
            ];
        }

        $override = $cemetery->required_documents_override;
        $codes = is_array($override) && $override !== []
            ? array_values(array_map('strval', $override))
            : RequiredDocumentCode::defaultForBooking();

        foreach ($codes as $code) {
            RequiredDocumentCode::assertKnown($code);
        }

        return OrderConfirmationSnapshot::query()->create([
            'order_id' => $order->getKey(),
            ...$contact,
            'required_document_codes' => $codes,
            'captured_at' => Carbon::now(),
        ]);
    }

    private static function blankToNull(?string $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/Domain/OrderWorkflow/CaptureOrderConfirmationSnapshotTest.php`
Expected: PASS (4 tests)

- [ ] **Step 6: Commit**

```bash
git add app/Support/ContactInfo.php app/Domain/OrderWorkflow/Actions/CaptureOrderConfirmationSnapshot.php tests/Feature/Domain/OrderWorkflow/CaptureOrderConfirmationSnapshotTest.php
git commit -m "feat(order): capture the confirmation snapshot with a placeholder-safe platform fallback"
```

---

### Task 5: Capture inside `SubmitBookingDraft`'s transaction

**Files:**
- Modify: `app/Domain/OrderWorkflow/Actions/SubmitBookingDraft.php:64-69` (constructor), the `OrderParty::query()->create([...])` block (ends around `:222`)
- Test: `tests/Feature/Domain/OrderWorkflow/SubmitBookingDraftCapturesSnapshotTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\OrderWorkflow;

use App\Domain\Booking\Actions\SaveBookingDraftStep;
use App\Domain\Booking\Actions\StartBookingDraft;
use App\Domain\Booking\BookingServiceType;
use App\Domain\Booking\BookingWizardStep;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\OrderWorkflow\Actions\SubmitBookingDraft;
use App\Domain\ServiceCatalog\ServiceCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SubmitBookingDraftCapturesSnapshotTest extends TestCase
{
    use RefreshDatabase;

    public function test_submission_writes_exactly_one_snapshot_from_the_drafts_cemetery(): void
    {
        $cemetery = Cemetery::query()
            ->where('city', LaunchCityCode::JAKARTA)
            ->where('publication_status', 'published')
            ->whereDoesntHave('packages')
            ->firstOrFail();
        $cemetery->forceFill(['operator_contact_phone' => '+62 21 555 0000', 'operator_name' => 'Pengelola Uji'])->save();

        $draft = (new StartBookingDraft)();
        $draft = (new SaveBookingDraftStep)($draft, BookingWizardStep::DISCOVERY, [
            'city_code' => LaunchCityCode::JAKARTA,
            'cemetery_id' => $cemetery->id,
            'cemetery_package_id' => null,
            'service_type' => BookingServiceType::NEW_GRAVE,
            'selected_services' => [['code' => ServiceCode::DOCUMENT_PROCESSING, 'quantity' => 1]],
        ], 'idem-discovery-'.$draft->id);

        $order = app(SubmitBookingDraft::class)($draft, 'idem-submit-'.$draft->id);
        $again = app(SubmitBookingDraft::class)($draft, 'idem-submit-'.$draft->id);

        self::assertSame($order->id, $again->id, 'idempotent resubmission returns the incumbent order');
        self::assertSame(1, $order->confirmationSnapshot()->count());
        self::assertSame('+62 21 555 0000', $order->confirmationSnapshot->contact_phone);
        self::assertSame('Pengelola Uji', $order->confirmationSnapshot->contact_name);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/Domain/OrderWorkflow/SubmitBookingDraftCapturesSnapshotTest.php`
Expected: FAIL — `assertSame(1, 0)` on the snapshot count.

- [ ] **Step 3: Wire the action**

Constructor: add `private CaptureOrderConfirmationSnapshot $captureConfirmationSnapshot,` as the last parameter (the class is `final readonly`, so no `readonly` keyword on the property).

Right after the `OrderParty::query()->create([...]);` statement and before the `$draftHold = PlotReservation::activeForDraft($draft);` line, insert:

```php
        // AC15: freeze what the confirmation screen and email will tell the
        // family — inside this transaction, so no order exists without it
        // (grill-spec S13). A draft that reached submission always has a
        // cemetery (`SaveBookingDraftStep` requires it at DISCOVERY).
        $cemetery = Cemetery::query()->findOrFail($draft->cemetery_id);
        ($this->captureConfirmationSnapshot)($order, $cemetery);
```

Add `use App\Domain\CemeteryDirectory\Models\Cemetery;` to the imports.

- [ ] **Step 4: Run test to verify it passes, then the existing submission tests**

Run: `vendor/bin/phpunit tests/Feature/Domain/OrderWorkflow/SubmitBookingDraftCapturesSnapshotTest.php`
Expected: PASS

Run: `vendor/bin/phpunit --filter SubmitBookingDraft`
Expected: PASS. If any existing test submits a draft with `cemetery_id = null`, that test's fixture is wrong by the spec (DISCOVERY requires a cemetery) — fix the fixture, not the action.

- [ ] **Step 5: Commit**

```bash
git add app/Domain/OrderWorkflow/Actions/SubmitBookingDraft.php tests/Feature/Domain/OrderWorkflow/SubmitBookingDraftCapturesSnapshotTest.php
git commit -m "feat(order): submission captures the confirmation snapshot in the same transaction"
```

---

### Task 6: `OrderConfirmationHandover` on `OrderReadModel`

**Files:**
- Create: `app/Domain/OrderWorkflow/OrderConfirmationHandover.php`
- Modify: `app/Domain/OrderWorkflow/OrderReadModel.php:47-73`
- Test: `tests/Feature/OrderWorkflow/OrderReadModelTest.php` (add two tests)

**Interfaces:**
- Produces: `OrderConfirmationHandover` readonly with `?string $contactName`, `?string $contactPhone`, `?string $contactHoursText`, `bool $contactIsPlatformFallback`, `list<array{code: string, label: string}> $requiredDocuments`, `bool $hasContactPhone`; `OrderReadModel::$handover` (nullable, last constructor param).

- [ ] **Step 1: Write the failing tests** (append to `OrderReadModelTest`)

```php
    public function test_handover_is_null_for_an_order_without_a_snapshot(): void
    {
        $order = $this->makeOrder(OrderStatus::MASUK);

        self::assertNull(OrderReadModel::forOrder($order)->handover);
    }

    public function test_handover_resolves_document_labels_from_the_catalogue(): void
    {
        $order = $this->makeOrder(OrderStatus::MASUK);
        \App\Domain\OrderWorkflow\Models\OrderConfirmationSnapshot::query()->create([
            'order_id' => $order->id,
            'contact_name' => null,
            'contact_phone' => null,
            'contact_hours_text' => null,
            'contact_is_platform_fallback' => true,
            'required_document_codes' => ['KK'],
            'captured_at' => now(),
        ]);

        $handover = OrderReadModel::forOrder($order->fresh())->handover;

        self::assertNotNull($handover);
        self::assertFalse($handover->hasContactPhone);
        self::assertTrue($handover->contactIsPlatformFallback);
        self::assertSame([['code' => 'KK', 'label' => 'Kartu Keluarga']], $handover->requiredDocuments);
    }
```

- [ ] **Step 2: Run to verify they fail**

Run: `vendor/bin/phpunit tests/Feature/OrderWorkflow/OrderReadModelTest.php`
Expected: FAIL — `Undefined property: OrderReadModel::$handover`.

- [ ] **Step 3: Write the value object and extend the read model**

```php
<?php

declare(strict_types=1);

namespace App\Domain\OrderWorkflow;

use App\Domain\CemeteryDirectory\RequiredDocumentCode;
use App\Domain\OrderWorkflow\Models\OrderConfirmationSnapshot;

/**
 * Read-side projection of `order_confirmation_snapshots` (AC15). Labels are
 * resolved here, at render time, from the closed catalogue — the snapshot
 * holds codes only (grill-spec S5).
 */
final readonly class OrderConfirmationHandover
{
    /**
     * @param  list<array{code: string, label: string}>  $requiredDocuments
     */
    public function __construct(
        public ?string $contactName,
        public ?string $contactPhone,
        public ?string $contactHoursText,
        public bool $contactIsPlatformFallback,
        public array $requiredDocuments,
    ) {}

    public static function fromSnapshot(OrderConfirmationSnapshot $snapshot): self
    {
        $documents = [];

        foreach ($snapshot->required_document_codes ?? [] as $code) {
            $code = (string) $code;
            $documents[] = ['code' => $code, 'label' => RequiredDocumentCode::label($code)];
        }

        return new self(
            contactName: $snapshot->contact_name,
            contactPhone: $snapshot->contact_phone,
            contactHoursText: $snapshot->contact_hours_text,
            contactIsPlatformFallback: $snapshot->contact_is_platform_fallback,
            requiredDocuments: $documents,
        );
    }

    public function __get(string $name): mixed
    {
        return match ($name) {
            'hasContactPhone' => $this->contactPhone !== null && $this->contactPhone !== '',
            default => throw new \LogicException("Undefined property [{$name}]."),
        };
    }
}
```

Prefer a real method if the project's PHPStan level rejects `__get` on a readonly class: replace `__get` with `public function hasContactPhone(): bool` and update the tests and Blade to call it. Either is acceptable; pick one and use it everywhere.

`OrderReadModel`: add `public ?OrderConfirmationHandover $handover = null,` as the last constructor parameter, and in `forOrder()` add the named argument:

```php
            handover: $order->confirmationSnapshot !== null
                ? OrderConfirmationHandover::fromSnapshot($order->confirmationSnapshot)
                : null,
```

- [ ] **Step 4: Run the read-model tests**

Run: `vendor/bin/phpunit tests/Feature/OrderWorkflow/OrderReadModelTest.php`
Expected: PASS, including the `cases()`-driven tests already there.

- [ ] **Step 5: Commit**

```bash
git add app/Domain/OrderWorkflow/OrderConfirmationHandover.php app/Domain/OrderWorkflow/OrderReadModel.php tests/Feature/OrderWorkflow/OrderReadModelTest.php
git commit -m "feat(order): expose the confirmation handover on the Step 9 read model"
```

---

### Task 7: Step 4 confirmation block in the wizard

**Files:**
- Modify: `app/Livewire/Public/Booking/BookingWizard.php:1985-2004` (`$confirmationData` array) and imports
- Modify: `resources/views/livewire/public/booking/wizard.blade.php` — after the "Ada yang ingin ditanyakan atau diubah?" paragraph's closing `</x-mk.card>` (around `:1831`)
- Test: `tests/Feature/Livewire/Public/Booking/BookingWizardConfirmationHandoverTest.php`

- [ ] **Step 1: Write the failing test**

Copy `submitManualOrder()` from `tests/Feature/Livewire/Public/Booking/BookingWizardConfirmationDeliveryStateTest.php:98-133` verbatim into this class (it drives a real draft through Step 1–3 with manual payment and lands on Step 4).

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public\Booking;

use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class BookingWizardConfirmationHandoverTest extends TestCase
{
    use RefreshDatabase;

    public function test_step_4_shows_the_operator_contact_and_the_documents_to_bring(): void
    {
        $cemetery = $this->publishedJakartaCemeteryWithoutPackages();
        $cemetery->forceFill([
            'operator_name' => 'UPTD TPU Uji',
            'operator_contact_phone' => '+62 21 777 0000',
            'operator_hours_text' => 'Setiap hari 07.00–16.00',
        ])->save();

        $this->submitManualOrder()
            ->assertSee('Yang perlu Anda bawa')
            ->assertSee('KTP pemesan')
            ->assertSee('Kartu Keluarga')
            ->assertSee('Surat keterangan kematian')
            ->assertSee('UPTD TPU Uji')
            ->assertSee('+62 21 777 0000')
            ->assertSee('Setiap hari 07.00–16.00');
    }

    public function test_step_4_with_only_a_placeholder_platform_phone_links_the_help_centre_and_prints_no_number(): void
    {
        config(['site.support_phone' => null]);
        putenv('SUPPORT_PHONE');
        $this->app->forgetInstance(\App\Platform\SiteSettings\SettingsService::class);

        $this->submitManualOrder()
            ->assertSee('Yang perlu Anda bawa')
            ->assertSee('Kontak pengelola belum tersedia')
            ->assertSee('/bantuan')
            ->assertDontSee('0000-1234');
    }

    private function publishedJakartaCemeteryWithoutPackages(): Cemetery
    {
        return Cemetery::query()
            ->where('city', LaunchCityCode::JAKARTA)
            ->where('publication_status', 'published')
            ->whereDoesntHave('packages')
            ->firstOrFail();
    }

    // submitManualOrder(): copied verbatim from BookingWizardConfirmationDeliveryStateTest.
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/Livewire/Public/Booking/BookingWizardConfirmationHandoverTest.php`
Expected: FAIL — `Yang perlu Anda bawa` not found.

- [ ] **Step 3: Feed the handover into the view**

In `BookingWizard::render()`, inside the `$confirmationData = [...]` array add one key:

```php
                        'handover' => $order !== null ? OrderReadModel::forOrder($order)->handover : null,
```

and add `use App\Domain\OrderWorkflow\OrderReadModel;` to the imports. (S14: the block reads the documented projection; the rest of the inline array is untouched.)

- [ ] **Step 4: Render the block**

Insert after the closing `</x-mk.card>` of the card that contains "Ada yang ingin ditanyakan atau diubah?" and before the `</div>` that closes the `@if ($confirmationData !== null)` content:

```blade
                        @if ($confirmationData['handover'] !== null)
                            @php($handover = $confirmationData['handover'])
                            <x-mk.card aria-labelledby="booking-handover-heading">
                                <h3 id="booking-handover-heading" class="text-base font-semibold text-neutral-900">
                                    Yang perlu Anda bawa
                                </h3>
                                <p class="mt-1 text-sm text-neutral-600">
                                    Siapkan dokumen berikut saat menemui pengelola makam. Daftar ini
                                    dikunci pada saat pemesanan dan tidak berubah.
                                </p>
                                <ul class="mt-3 list-disc space-y-1 pl-5 text-sm text-neutral-800">
                                    @foreach ($handover->requiredDocuments as $document)
                                        <li>{{ $document['label'] }}</li>
                                    @endforeach
                                </ul>

                                <h3 class="mt-5 text-base font-semibold text-neutral-900">Kontak pengelola</h3>
                                @if ($handover->hasContactPhone)
                                    <p class="mt-1 text-sm text-neutral-800">
                                        @if ($handover->contactIsPlatformFallback)
                                            Pengelola makam ini belum mencantumkan nomor; hubungi pusat bantuan Makam.co.id:
                                        @elseif ($handover->contactName !== null)
                                            {{ $handover->contactName }}:
                                        @endif
                                        <a href="tel:{{ preg_replace('/\s+/', '', $handover->contactPhone) }}" class="font-medium underline underline-offset-2">{{ $handover->contactPhone }}</a>
                                        @if ($handover->contactHoursText !== null)
                                            <span class="text-neutral-600">&middot; {{ $handover->contactHoursText }}</span>
                                        @endif
                                    </p>
                                @else
                                    <p class="mt-1 text-sm text-neutral-800">
                                        Kontak pengelola belum tersedia. Hubungi
                                        <a href="/bantuan" class="font-medium underline underline-offset-2">pusat bantuan</a>
                                        dengan menyebutkan nomor pesanan Anda.
                                    </p>
                                @endif
                            </x-mk.card>
                        @endif
```

Class names above are the same utilities the surrounding Step 4 markup already uses (`text-sm text-neutral-600`, `font-medium underline underline-offset-2`); no new colour, spacing, or arbitrary value is introduced, so GATE 2/3 stay green. If `hasContactPhone` was implemented as a method in Task 6, write `$handover->hasContactPhone()`.

- [ ] **Step 5: Run the test, then the confirmation-state and accessibility suites**

Run: `vendor/bin/phpunit tests/Feature/Livewire/Public/Booking/BookingWizardConfirmationHandoverTest.php tests/Feature/Livewire/Public/Booking/BookingWizardConfirmationDeliveryStateTest.php tests/Feature/Livewire/Public/Booking/BookingWizardAccessibilityTest.php`
Expected: PASS

Run: `bash ci/verify-docs.sh`
Expected: `RESULT: ALL DOC GATES PASS` (scans `resources/` for hardcoded values).

- [ ] **Step 6: Commit**

```bash
git add app/Livewire/Public/Booking/BookingWizard.php resources/views/livewire/public/booking/wizard.blade.php tests/Feature/Livewire/Public/Booking/BookingWizardConfirmationHandoverTest.php
git commit -m "feat(booking): Step 4 shows the documents to bring and the operator contact"
```

---

### Task 8: `Booking submitted` email carries the handover

**Files:**
- Create: `app/Domain/OrderWorkflow/Notifications/OrderNotificationVariableSource.php`
- Modify: the Domain-side service provider that already registers `OrderWorkflow` listeners (find with `grep -rn "DispatchOrderNotifications::class" app/Providers/`) — extend the resolver singleton.
- Create: `database/migrations/2026_09_20_110020_add_v2_booking_submitted_template_with_handover.php`
- Test: `tests/Feature/Notification/BookingSubmittedEmailHandoverTest.php`

**Interfaces:**
- Consumes: `App\Platform\Notification\Contracts\NotificationVariableSource`, `NotificationVariableResolver` (other plan).
- Produces: variables `order_reference`, `contact_line`, `required_documents` for aggregate `order`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Notification;

use App\Domain\Booking\Actions\SaveBookingDraftStep;
use App\Domain\Booking\Actions\StartBookingDraft;
use App\Domain\Booking\BookingServiceType;
use App\Domain\Booking\BookingWizardStep;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\OrderWorkflow\Actions\SubmitBookingDraft;
use App\Domain\ServiceCatalog\ServiceCode;
use App\Platform\Notification\Jobs\ConsumeOutboxNotificationJob;
use App\Platform\Notification\Models\InAppNotification;
use App\Platform\Outbox\Outbox;
use App\Platform\Outbox\OutboxClassification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * platform-notifications AC15 + booking AC15: the `Booking submitted`
 * body carries the frozen handover. Asserted on the cemetery operator's
 * in-app record (that row has `IN_APP/EMAIL for selected location`), which
 * renders through the same version the mail channel renders.
 */
final class BookingSubmittedEmailHandoverTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_booking_submitted_body_lists_documents_and_the_operator_contact(): void
    {
        $cemetery = Cemetery::query()->where('city', LaunchCityCode::JAKARTA)->where('publication_status', 'published')->whereDoesntHave('packages')->firstOrFail();
        $cemetery->forceFill(['operator_name' => 'UPTD TPU Uji', 'operator_contact_phone' => '+62 21 777 0000', 'operator_hours_text' => 'Setiap hari 07.00–16.00'])->save();
        $this->grantOperatorOn($cemetery); // copy NotificationDispatchPipelineTest's operator ScopeAssignment fixture

        $draft = (new StartBookingDraft)();
        $draft = (new SaveBookingDraftStep)($draft, BookingWizardStep::DISCOVERY, [
            'city_code' => LaunchCityCode::JAKARTA, 'cemetery_id' => $cemetery->id, 'cemetery_package_id' => null,
            'service_type' => BookingServiceType::NEW_GRAVE,
            'selected_services' => [['code' => ServiceCode::DOCUMENT_PROCESSING, 'quantity' => 1]],
        ], 'idem-'.$draft->id);
        $order = app(SubmitBookingDraft::class)($draft, 'idem-submit-'.$draft->id);

        $outboxEventId = Outbox::record(
            eventName: 'order.status_changed.v1', eventVersion: 1, aggregateType: 'order', aggregateId: (string) $order->id,
            data: ['order_id' => (string) $order->id, 'to_status' => 'MASUK', 'from_status' => null],
            classification: OutboxClassification::Internal,
        )->getKey();

        ConsumeOutboxNotificationJob::dispatchSync($outboxEventId, matrixEventName: 'Booking submitted');

        $body = (string) InAppNotification::query()->latest('id')->value('body');

        self::assertStringContainsString($order->reference, $body);
        self::assertStringContainsString('KTP pemesan', $body);
        self::assertStringContainsString('Kartu Keluarga', $body);
        self::assertStringContainsString('UPTD TPU Uji', $body);
        self::assertStringContainsString('+62 21 777 0000', $body);
        self::assertStringNotContainsString('{{', $body);
    }
}
```

`grantOperatorOn()` is copied from the pipeline test's operator-scope fixture (the `ScopeAssignment` on `ScopeEntityType::CEMETERY` for a `CEMETERY_OPERATOR` user); name it and paste it — do not invent a different scope shape.

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/Notification/BookingSubmittedEmailHandoverTest.php`
Expected: FAIL — body is the version-1 "Matrix snapshot" placeholder.

- [ ] **Step 3: Write the Domain-side source**

```php
<?php

declare(strict_types=1);

namespace App\Domain\OrderWorkflow\Notifications;

use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\OrderConfirmationHandover;
use App\Platform\Notification\Contracts\NotificationVariableSource;

/**
 * Variables for the `order` aggregate (platform-notifications AC15).
 * Registered onto `NotificationVariableResolver` from the OrderWorkflow
 * provider — the platform contract, implemented in the feature module, so
 * the platform never imports `Order`.
 *
 *   order_reference    — `orders.reference`
 *   contact_line       — one sentence; never a placeholder number (S2)
 *   required_documents — labels joined by newline (S12)
 *
 * None of these is restricted data (AC11): a document NAME is not a
 * document's content.
 */
final class OrderNotificationVariableSource implements NotificationVariableSource
{
    public function handles(string $aggregateType): bool
    {
        return $aggregateType === 'order';
    }

    public function variablesFor(string $matrixEventName, string $aggregateType, string $aggregateId, array $payload): array
    {
        $order = Order::query()->with('confirmationSnapshot')->find($aggregateId);

        if ($order === null) {
            return [];
        }

        $bag = ['order_reference' => $order->reference];
        $snapshot = $order->confirmationSnapshot;

        if ($snapshot === null) {
            return $bag + [
                'contact_line' => 'Hubungi pusat bantuan Makam.co.id di makam.co.id/bantuan dengan menyebutkan nomor pesanan Anda.',
                'required_documents' => '',
            ];
        }

        $handover = OrderConfirmationHandover::fromSnapshot($snapshot);

        $contactLine = match (true) {
            ! $handover->hasContactPhone => 'Kontak pengelola belum tersedia. Hubungi pusat bantuan Makam.co.id di makam.co.id/bantuan dengan menyebutkan nomor pesanan Anda.',
            $handover->contactIsPlatformFallback => 'Pengelola makam ini belum mencantumkan nomor; hubungi pusat bantuan Makam.co.id: '.$handover->contactPhone
                .($handover->contactHoursText !== null ? ' ('.$handover->contactHoursText.')' : '').'.',
            default => 'Kontak pengelola: '.($handover->contactName !== null ? $handover->contactName.', ' : '').$handover->contactPhone
                .($handover->contactHoursText !== null ? ' ('.$handover->contactHoursText.')' : '').'.',
        };

        return $bag + [
            'contact_line' => $contactLine,
            'required_documents' => implode("\n", array_map(
                static fn (array $document): string => '- '.$document['label'],
                $handover->requiredDocuments,
            )),
        ];
    }
}
```

- [ ] **Step 4: Register it**

In the provider found by the grep, inside `register()`:

```php
use App\Domain\OrderWorkflow\Notifications\OrderNotificationVariableSource;
use App\Platform\Notification\NotificationVariableResolver;

$this->app->extend(
    NotificationVariableResolver::class,
    static fn (NotificationVariableResolver $resolver): NotificationVariableResolver => $resolver->with(new OrderNotificationVariableSource),
);
```

`with()` does not exist yet on the resolver from the other plan; add it there in this PR as a small, tested addition:

```php
    public function with(NotificationVariableSource $source): self
    {
        return new self(...[...$this->sources, $source]);
    }
```

with a unit test `test_with_appends_a_source_that_wins_on_key_collision` in `NotificationVariableResolverTest` (same shape as the existing override test).

- [ ] **Step 5: Write the template migration**

Copy the version-3 migration from the other plan, with `TEMPLATES` holding one entry, version `2`, `CREATED_BY = 'seed:booking-submitted-handover'`, `down()` re-pointing to version 1:

```php
        [
            'event' => 'Booking submitted',
            'subject' => 'Pemesanan Anda telah kami terima — {{ order_reference }}',
            'body' => 'Terima kasih. Pemesanan Anda dengan nomor {{ order_reference }} telah kami terima dan sedang ditinjau tim kami. '
                ."\n\nYang perlu Anda bawa saat menemui pengelola makam:\n{{ required_documents }}"
                ."\n\n{{ contact_line }}"
                ."\n\nAda yang ingin ditanyakan atau diubah? Hubungi pusat bantuan di makam.co.id/bantuan dan sebutkan nomor pesanan Anda.",
            'variable_allowlist' => ['order_reference', 'contact_line', 'required_documents'],
        ],
```

`restricted_fields` unchanged from version 1.

- [ ] **Step 6: Run the test and the notification suites**

Run: `vendor/bin/phpunit tests/Feature/Notification/BookingSubmittedEmailHandoverTest.php tests/Feature/Notification tests/Unit/Platform/Notification`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add app/Domain/OrderWorkflow/Notifications/OrderNotificationVariableSource.php app/Providers app/Platform/Notification/NotificationVariableResolver.php database/migrations/2026_09_20_110020_add_v2_booking_submitted_template_with_handover.php tests/
git commit -m "feat(notification): Booking submitted email carries the order's handover"
```

---

### Task 9: Admin form fields

**Files:**
- Modify: `app/Filament/Admin/Resources/CemeteryResource/Schemas/CemeteryForm.php` — after the `facilities` Repeater (`:130`)
- Test: `tests/Feature/Filament/Admin/CemeteryResourceCrudTest.php` (add two tests)

- [ ] **Step 1: Write the failing tests** (append; reuse the class's existing admin-login helper and `validCreateData()`)

```php
    public function test_operator_contact_fields_and_document_override_save_from_the_form(): void
    {
        $this->actingAsAdmin(); // the class's existing helper name — read the file and use it

        Livewire::test(CreateCemetery::class)
            ->fillForm([...$this->validCreateData(),
                'operator_name' => 'UPTD TPU Uji',
                'operator_contact_phone' => '+62 21 777 0000',
                'operator_hours_text' => 'Setiap hari 07.00–16.00',
                'required_documents_override' => ['KTP_PEMESAN', 'SURAT_KUASA'],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $cemetery = Cemetery::query()->where('operator_name', 'UPTD TPU Uji')->firstOrFail();
        self::assertSame('+62 21 777 0000', $cemetery->operator_contact_phone);
        self::assertSame(['KTP_PEMESAN', 'SURAT_KUASA'], $cemetery->required_documents_override);
    }

    public function test_leaving_the_document_list_at_the_platform_default_stores_null_not_a_copy(): void
    {
        $this->actingAsAdmin();

        Livewire::test(CreateCemetery::class)
            ->fillForm([...$this->validCreateData(), 'required_documents_override' => RequiredDocumentCode::defaultForBooking()])
            ->call('create')
            ->assertHasNoFormErrors();

        self::assertNull(Cemetery::query()->latest('id')->firstOrFail()->required_documents_override);
    }
```

- [ ] **Step 2: Run to verify they fail**

Run: `vendor/bin/phpunit tests/Feature/Filament/Admin/CemeteryResourceCrudTest.php`
Expected: FAIL — form has no such fields (values ignored, override stays null / phone null).

- [ ] **Step 3: Add the fields**

After the `facilities` Repeater component:

```php
                TextInput::make('operator_name')
                    ->label('Nama pengelola')
                    ->maxLength(255)
                    ->nullable(),

                TextInput::make('operator_contact_phone')
                    ->label('Telepon pengelola')
                    ->tel()
                    ->maxLength(32)
                    ->nullable()
                    ->helperText('Dicetak pada bukti pemesanan. Kosongkan bila belum ada; sistem memakai kontak pusat bantuan.'),

                TextInput::make('operator_hours_text')
                    ->label('Jam layanan pengelola')
                    ->maxLength(120)
                    ->nullable()
                    ->placeholder('Setiap hari 07.00–16.00'),

                CheckboxList::make('required_documents_override')
                    ->label('Dokumen yang perlu dibawa')
                    ->options(RequiredDocumentCode::options())
                    ->columns(2)
                    // S4: the form shows the platform default when no override
                    // exists, and stores NULL (not a copy) when the admin leaves
                    // it at the default — so a later default change still applies.
                    ->afterStateHydrated(static function (CheckboxList $component, mixed $state): void {
                        if ($state === null || $state === []) {
                            $component->state(RequiredDocumentCode::defaultForBooking());
                        }
                    })
                    ->dehydrateStateUsing(static function (mixed $state): ?array {
                        $codes = array_values(array_map('strval', is_array($state) ? $state : []));
                        $default = RequiredDocumentCode::defaultForBooking();
                        sort($codes);
                        $sortedDefault = $default;
                        sort($sortedDefault);

                        return $codes === $sortedDefault || $codes === [] ? null : $codes;
                    })
                    ->helperText('Daftar bawaan platform ditampilkan bila TPU ini belum punya daftar sendiri. Mengubahnya mengganti seluruh daftar untuk TPU ini.')
                    ->columnSpanFull(),
```

Imports: `use Filament\Forms\Components\CheckboxList;` and `use App\Domain\CemeteryDirectory\RequiredDocumentCode;`.

- [ ] **Step 4: Run the resource tests**

Run: `vendor/bin/phpunit tests/Feature/Filament/Admin/CemeteryResourceCrudTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Filament/Admin/Resources/CemeteryResource/Schemas/CemeteryForm.php tests/Feature/Filament/Admin/CemeteryResourceCrudTest.php
git commit -m "feat(admin): cemetery form edits operator contact and the required-document override"
```

---

### Task 10: Operator panel read-only widget

**Files:**
- Create: `app/Filament/Operator/Widgets/CemeteryHandoverWidget.php`
- Create: `resources/views/filament/operator/widgets/cemetery-handover.blade.php`
- Modify: `app/Providers/Filament/OperatorPanelProvider.php:74` (widgets list)
- Test: `tests/Feature/Filament/Operator/CemeteryHandoverWidgetTest.php`

- [ ] **Step 1: Write the failing test**

Read `tests/Feature/Filament/` for the existing operator-panel test that logs in a `CEMETERY_OPERATOR` with a `CurrentCemeteryScope` grant (grep `OperatorPanelProvider\|CemeteryOrderResource` under `tests/`) and reuse its login fixture.

```php
    public function test_the_widget_lists_contact_and_documents_for_each_granted_cemetery(): void
    {
        $cemetery = $this->grantedCemetery(); // from the reused fixture
        $cemetery->forceFill(['operator_name' => 'UPTD TPU Uji', 'operator_contact_phone' => '+62 21 777 0000'])->save();

        Livewire::test(\App\Filament\Operator\Widgets\CemeteryHandoverWidget::class)
            ->assertSee('UPTD TPU Uji')
            ->assertSee('+62 21 777 0000')
            ->assertSee('KTP pemesan')
            ->assertDontSee('<input');
    }
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/Filament/Operator/CemeteryHandoverWidgetTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Write the widget**

```php
<?php

declare(strict_types=1);

namespace App\Filament\Operator\Widgets;

use App\Domain\CemeteryDirectory\Access\CurrentCemeteryScope;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\CemeteryDirectory\RequiredDocumentCode;
use Filament\Widgets\Widget;

/**
 * admin-operations AC13: the operator panel READS the contact and
 * document fields in release 1 and cannot edit them (ADR-0008). An
 * operator needs to see which number is printed on families' booking
 * confirmations in their cemetery's name.
 */
final class CemeteryHandoverWidget extends Widget
{
    protected string $view = 'filament.operator.widgets.cemetery-handover';

    protected int|string|array $columnSpan = 'full';

    /**
     * @return list<array{name: string, operator_name: ?string, phone: ?string, hours: ?string, documents: list<string>, is_override: bool}>
     */
    public function getRows(): array
    {
        $ids = app(CurrentCemeteryScope::class)->grantedCemeteryIds();
        $rows = [];

        foreach (Cemetery::query()->whereIn('id', $ids)->orderBy('name')->get() as $cemetery) {
            $override = $cemetery->required_documents_override;
            $codes = is_array($override) && $override !== [] ? $override : RequiredDocumentCode::defaultForBooking();

            $rows[] = [
                'name' => (string) $cemetery->name,
                'operator_name' => $cemetery->operator_name,
                'phone' => $cemetery->operator_contact_phone,
                'hours' => $cemetery->operator_hours_text,
                'documents' => array_map(static fn (string $code): string => RequiredDocumentCode::label($code), array_map('strval', $codes)),
                'is_override' => is_array($override) && $override !== [],
            ];
        }

        return $rows;
    }
}
```

View:

```blade
<x-filament-widgets::widget>
    <x-filament::section heading="Kontak dan dokumen pada bukti pemesanan">
        <p class="text-sm">Data ini dicetak pada bukti pemesanan keluarga. Perubahan dilakukan oleh admin platform.</p>
        @forelse ($this->getRows() as $row)
            <div class="mt-4">
                <h3 class="font-semibold">{{ $row['name'] }}</h3>
                <dl class="mt-1 text-sm">
                    <dt class="font-medium">Kontak</dt>
                    <dd>
                        @if ($row['phone'])
                            {{ $row['operator_name'] ? $row['operator_name'].', ' : '' }}{{ $row['phone'] }}{{ $row['hours'] ? ' · '.$row['hours'] : '' }}
                        @else
                            Belum ada nomor; bukti pemesanan memakai kontak pusat bantuan.
                        @endif
                    </dd>
                    <dt class="mt-2 font-medium">Dokumen yang perlu dibawa {{ $row['is_override'] ? '(daftar khusus TPU ini)' : '(bawaan platform)' }}</dt>
                    <dd>
                        <ul class="list-disc pl-5">
                            @foreach ($row['documents'] as $label)
                                <li>{{ $label }}</li>
                            @endforeach
                        </ul>
                    </dd>
                </dl>
            </div>
        @empty
            <p class="mt-4 text-sm">Belum ada TPU/TPS yang ditugaskan ke akun Anda.</p>
        @endforelse
    </x-filament::section>
</x-filament-widgets::widget>
```

Register in `OperatorPanelProvider` next to `Widgets\AccountWidget::class`: `\App\Filament\Operator\Widgets\CemeteryHandoverWidget::class,`.

- [ ] **Step 4: Run the test and the Filament palette gate**

Run: `vendor/bin/phpunit tests/Feature/Filament/Operator/CemeteryHandoverWidgetTest.php`
Expected: PASS

Run: `php artisan design:verify-filament-palette` (if it runs on this host; otherwise NOT TESTED locally, CI runs it).

- [ ] **Step 5: Commit**

```bash
git add app/Filament/Operator/Widgets/CemeteryHandoverWidget.php resources/views/filament/operator/widgets/cemetery-handover.blade.php app/Providers/Filament/OperatorPanelProvider.php tests/Feature/Filament/Operator/CemeteryHandoverWidgetTest.php
git commit -m "feat(operator): read-only widget shows the contact and documents printed on confirmations"
```

---

### Task 11: `OrderConfirmation` schema in OpenAPI with a parity test

**Files:**
- Modify: `docs/contracts/openapi.yaml:429-440` (response) and `components.schemas` (add after `PlotReservation`, before `Error`)
- Test: `tests/Feature/OrderWorkflow/OrderReadModelTest.php` (add one test)

- [ ] **Step 1: Write the failing test**

```php
    public function test_the_read_model_matches_the_openapi_order_confirmation_schema(): void
    {
        $yaml = file_get_contents(base_path('docs/contracts/openapi.yaml'));
        self::assertIsString($yaml);

        preg_match('/^    OrderConfirmation:\n(?:.*\n)*?      properties:\n((?:        \S.*\n(?:          .*\n)*)+)/m', $yaml, $m);
        self::assertNotEmpty($m[1] ?? '', 'OrderConfirmation.properties not found in openapi.yaml');
        preg_match_all('/^        ([a-z_]+):/m', $m[1], $keys);

        $expected = ['order_reference', 'status_intent', 'invoice_state', 'channel_delivery_state', 'next_action', 'support_reference', 'manual_fallback_available', 'correlation_reference', 'handover'];
        self::assertSame($expected, $keys[1]);

        preg_match('/^    OrderConfirmationHandover:\n(?:.*\n)*?      properties:\n((?:        \S.*\n(?:          .*\n)*)+)/m', $yaml, $h);
        preg_match_all('/^        ([a-z_]+):/m', $h[1] ?? '', $handoverKeys);
        self::assertSame(['contact_name', 'contact_phone', 'contact_hours_text', 'contact_is_platform_fallback', 'required_documents'], $handoverKeys[1]);
    }
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit --filter test_the_read_model_matches_the_openapi_order_confirmation_schema`
Expected: FAIL — schema not found.

- [ ] **Step 3: Add the schema**

Response (replace the prose-only 200):

```yaml
      responses:
        '200':
          description: Order reference, status, invoice, notification state, next action, and the handover frozen at submission
          content:
            application/json:
              schema:
                $ref: '#/components/schemas/OrderConfirmation'
```

Components (insert before `    Error:`):

```yaml
    OrderConfirmation:
      type: object
      required:
      - order_reference
      - status_intent
      - invoice_state
      - channel_delivery_state
      - manual_fallback_available
      - correlation_reference
      properties:
        order_reference:
          type: string
        status_intent:
          type: string
        invoice_state:
          type: string
          enum:
          - pending
          - not_applicable
        channel_delivery_state:
          type: string
          enum:
          - pending
          - confirmed
          - fulfilled
        next_action:
          type: string
          nullable: true
        support_reference:
          type: string
          nullable: true
        manual_fallback_available:
          type: boolean
        correlation_reference:
          type: string
        handover:
          nullable: true
          allOf:
          - $ref: '#/components/schemas/OrderConfirmationHandover'
    OrderConfirmationHandover:
      type: object
      description: Frozen at submission (AC15). Orders that predate the snapshot table have no handover.
      required:
      - contact_is_platform_fallback
      - required_documents
      properties:
        contact_name:
          type: string
          nullable: true
        contact_phone:
          type: string
          nullable: true
          description: Never a placeholder; null when no real number exists
        contact_hours_text:
          type: string
          nullable: true
        contact_is_platform_fallback:
          type: boolean
        required_documents:
          type: array
          items:
            type: object
            required:
            - code
            - label
            properties:
              code:
                type: string
              label:
                type: string
```

- [ ] **Step 4: Run the test and the YAML gate**

Run: `vendor/bin/phpunit --filter test_the_read_model_matches_the_openapi_order_confirmation_schema`
Expected: PASS

Run: `bash ci/verify-docs.sh`
Expected: `RESULT: ALL DOC GATES PASS` (CI additionally validates OpenAPI; read that job's result after pushing).

- [ ] **Step 5: Commit**

```bash
git add docs/contracts/openapi.yaml tests/Feature/OrderWorkflow/OrderReadModelTest.php
git commit -m "docs(contracts): OrderConfirmation schema with the handover, pinned by a parity test"
```

---

### Task 12: Record what shipped

**Files:**
- Modify: `.kiro/specs/booking-and-order-orchestration/tasks.md` (AC15 task → done, tests named), `.kiro/specs/cemetery-directory-and-availability/tasks.md` (AC15 task), `.kiro/specs/admin-operations/tasks.md` (AC13 task), `.kiro/specs/platform-notifications/tasks.md` + `traceability-matrix.md` (AC15 → `Closed (local evidence)` with `BookingSubmittedEmailHandoverTest`).
- Modify: `docs/domain/traceability-matrix.md` §E row PRD-01: Test evidence = `tests/Feature/Domain/OrderWorkflow/CaptureOrderConfirmationSnapshotTest.php`, `tests/Feature/Livewire/Public/Booking/BookingWizardConfirmationHandoverTest.php`, `tests/Feature/Notification/BookingSubmittedEmailHandoverTest.php`; Status `Covered` **only after CI is green on the PR** (`AGENTS.md` §Testing); add a `### PRD-01 — raised to Covered <date>` evidence-trail entry in §D in the same shape as the neighbouring entries.
- Modify: `docs/product/screen-inventory.md` — the booking Step 4 / confirmation row: add a dated note listing the new "Yang perlu Anda bawa" and "Kontak pengelola" content and its two states (contact present / help-centre fallback).
- Modify: `docs/product/prd-yiem-2026-09-18.md` §15 MK-04 status column: "kontak pengelola, daftar dokumen: dibangun (PR #n)".

- [ ] **Step 1: Make the edits, run the docs gate**

Run: `bash ci/verify-docs.sh`
Expected: `RESULT: ALL DOC GATES PASS`

- [ ] **Step 2: Commit and open the PR**

```bash
git add .kiro/specs docs/domain/traceability-matrix.md docs/product/screen-inventory.md docs/product/prd-yiem-2026-09-18.md
git commit -m "docs: record the order confirmation snapshot in specs, traceability, and the screen inventory"
```

PR title: `feat(order): confirmation snapshot — documents to bring and operator contact on Step 4 and the Booking submitted email`. Base: `docs/design-system-and-planning`. Body lists the twelve tasks, the S-decisions, and states plainly which checks ran locally and which only CI ran.

---

## Self-review

- **Spec coverage.** booking AC15: Tasks 3–8. AC13 unchanged fields: untouched. cemetery-directory AC15 (columns, admin-editable, fallbacks): Tasks 2, 4, 9. platform-notifications AC15 (allowlisted variables, not restricted): Task 8. admin-operations AC13 (form fields; operator reads): Tasks 9, 10. Catalog rules (snapshot at confirmation, never empty list, codes closed): Tasks 1, 4. S15 OpenAPI: Task 11. S7 renewal: explicitly out. S17 finding: recorded in `findings.yml` (SCHEMA-01), no task here by decision.
- **Placeholders.** The three "copy the named fixture" instructions (Tasks 7, 8, 10) each name the exact file and method to copy; the one grep-then-edit (Task 8 provider) names the grep. No TBD/TODO.
- **Type consistency.** `CaptureOrderConfirmationSnapshot::__invoke(Order, Cemetery)` (Task 4) is what Task 5 calls; snapshot column names in Task 3's migration match Task 4's `create()` array and Task 6's `fromSnapshot()`; `OrderConfirmationHandover` fields match the OpenAPI `OrderConfirmationHandover` properties in Task 11 (`contact_name`, `contact_phone`, `contact_hours_text`, `contact_is_platform_fallback`, `required_documents`); `hasContactPhone` is used consistently as a property (or method, if Task 6 chose that — apply the same choice in Tasks 7 and 8).
