<?php

declare(strict_types=1);

namespace App\Support\ExampleData;

use App\Domain\Booking\Actions\SaveBookingDraftStep;
use App\Domain\Booking\Actions\StartBookingDraft;
use App\Domain\Booking\BookingContactChannel;
use App\Domain\Booking\BookingPaymentMethod;
use App\Domain\Booking\BookingRelationshipCode;
use App\Domain\Booking\BookingServiceType;
use App\Domain\Booking\BookingWizardStep;
use App\Domain\Booking\Models\BookingDraft;
use App\Domain\CemeteryDirectory\CemeteryPublicationStatus;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\OrderWorkflow\Actions\CompleteOrder;
use App\Domain\OrderWorkflow\Actions\GrantOrderPaymentOpening;
use App\Domain\OrderWorkflow\Actions\IssueOrderQuote;
use App\Domain\OrderWorkflow\Actions\ManualPaymentVerification;
use App\Domain\OrderWorkflow\Actions\MarkOrderPaid;
use App\Domain\OrderWorkflow\Actions\ProcessOrder;
use App\Domain\OrderWorkflow\Actions\RecordBuyerApproval;
use App\Domain\OrderWorkflow\Actions\RejectOrder;
use App\Domain\OrderWorkflow\Actions\SubmitBookingDraft;
use App\Domain\OrderWorkflow\Actions\VerifyOrder;
use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\ServiceCatalog\ServiceCode;
use App\Support\ExampleData\Concerns\TaggedAsDemoData;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Booking orders spanning the 5 named commercial states this subsystem
 * demonstrates — `DIVERIFIKASI`, `PENAWARAN_TERKIRIM`, `DIBAYAR`, `SELESAI`,
 * `DITOLAK` — produced in exactly that order because Task 9's
 * `CertificateExampleData` consumes index `[2]` (the `DIBAYAR` order)
 * specifically: `CertificateType::OrderSettlement`'s eligibility rule
 * requires exactly that status, not `SELESAI`.
 *
 * Each order is driven through the real wizard/order-workflow Actions —
 * `StartBookingDraft` -> `SaveBookingDraftStep` (x3) -> `SubmitBookingDraft`
 * -> the relevant `OrderWorkflow` transition Actions — the same path a real
 * customer and operator take, never a direct model write.
 */
final class BookingOrderExampleData
{
    private const string ACTOR_REF = 'demo-data-seeder';

    private const string ACTOR_ROLE = 'system';

    /**
     * @return list<Order>
     */
    public static function seed(string $batchId): array
    {
        $cemetery = self::packagelessDemoCemetery();

        return [
            self::verifiedOnly($cemetery, $batchId, 0),
            self::quoted($cemetery, $batchId, 1),
            self::paid($cemetery, $batchId, 2),
            self::completed($cemetery, $batchId, 3),
            self::rejected($cemetery, $batchId, 4),
        ];
    }

    private static function packagelessDemoCemetery(): Cemetery
    {
        return Cemetery::query()
            ->where('city', LaunchCityCode::JAKARTA)
            ->where('publication_status', CemeteryPublicationStatus::PUBLISHED)
            ->whereDoesntHave('packages')
            ->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private static function discoveryPayload(Cemetery $cemetery): array
    {
        return [
            'city_code' => LaunchCityCode::JAKARTA,
            'cemetery_id' => $cemetery->id,
            'cemetery_package_id' => null,
            'service_type' => BookingServiceType::NEW_GRAVE,
            'selected_services' => array_map(
                static fn (string $code): array => ['code' => $code, 'quantity' => 1],
                ServiceCode::BASIC_CODES,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function customerAndDeceasedPayload(int $index): array
    {
        return [
            'customer_full_name' => DemoContactData::personName($index),
            'customer_mobile' => DemoContactData::phone($index),
            'customer_email' => DemoContactData::email($index),
            'customer_address' => 'Jl. Contoh Demo No. '.($index + 1),
            'customer_relationship' => BookingRelationshipCode::ANAK,
            'customer_contact_channel' => BookingContactChannel::WHATSAPP,
            'privacy_notice_accepted' => true,
            'deceased_full_name' => 'Almarhum Contoh '.($index + 1),
            'deceased_date_of_birth' => '1950-01-01',
            'deceased_date_of_death' => '2026-08-01',
            'deceased_relationship' => BookingRelationshipCode::ORANG_TUA,
        ];
    }

    private static function draftThroughPayment(Cemetery $cemetery, int $index): BookingDraft
    {
        $draft = app(StartBookingDraft::class)();

        $draft = app(SaveBookingDraftStep::class)(
            $draft,
            BookingWizardStep::DISCOVERY,
            self::discoveryPayload($cemetery),
            "demo-discovery-{$index}-{$draft->id}",
        );

        $draft = app(SaveBookingDraftStep::class)(
            $draft,
            BookingWizardStep::CUSTOMER_AND_DECEASED_DATA,
            self::customerAndDeceasedPayload($index),
            "demo-customer-{$index}-{$draft->id}",
        );

        return app(SaveBookingDraftStep::class)(
            $draft,
            BookingWizardStep::PAYMENT,
            [
                'payment_method' => BookingPaymentMethod::MANUAL,
                'payment_reference' => 'DEMO-REF-'.($index + 1),
            ],
            "demo-payment-{$index}-{$draft->id}",
        );
    }

    private static function submittedOnly(Cemetery $cemetery, string $batchId, int $index): Order
    {
        $draft = self::draftThroughPayment($cemetery, $index);
        TaggedAsDemoData::tag($draft, $batchId);

        $order = app(SubmitBookingDraft::class)($draft, "demo-submit-{$index}-{$draft->id}");
        self::tagOrder($order, $batchId);

        return $order;
    }

    /**
     * `Order` overrides `performUpdate()` to throw for every write except
     * `applyStatus()`/`stampPaidSource()` (`Order::$statusWriteAuthorized`)
     * — see `OrderIsGuardedException`. `TaggedAsDemoData::tag()`'s
     * `forceFill()->save()` is exactly the kind of write that guard exists
     * to block, so tagging an `Order` cannot go through that shared helper
     * the way tagging every other model in this subsystem does. A plain
     * query-builder update bypasses Eloquent's write path entirely, the
     * same way the guard's own doc block describes raw SQL as the one
     * route it does not (and cannot) intercept.
     */
    private static function tagOrder(Order $order, string $batchId): void
    {
        DB::table('orders')->where('id', $order->getKey())->update(['demo_batch_id' => $batchId]);
    }

    /**
     * `VerifyOrder`'s target status, `DIVERIFIKASI`, is the Task 9 brief's
     * named state 1 ("Diverifikasi") — the brief's own draft code left this
     * hop out and left `SubmitBookingDraft`'s `MASUK` order in the list
     * instead, which never satisfies the `DIVERIFIKASI` assertion the task
     * brief's own test requires. Fixed here by actually calling
     * `VerifyOrder`, matching the state's name.
     */
    private static function verifiedOnly(Cemetery $cemetery, string $batchId, int $index): Order
    {
        $order = self::submittedOnly($cemetery, $batchId, $index);

        app(VerifyOrder::class)($order, self::ACTOR_REF, self::ACTOR_ROLE);

        return $order->fresh();
    }

    private static function quoted(Cemetery $cemetery, string $batchId, int $index): Order
    {
        $order = self::verifiedOnly($cemetery, $batchId, $index);

        app(IssueOrderQuote::class)($order, CarbonImmutable::now()->addDays(7), self::ACTOR_REF, self::ACTOR_ROLE);

        return $order->fresh();
    }

    /**
     * `OrderTransition::ALLOWED` has no direct `PENAWARAN_TERKIRIM ->
     * MENUNGGU_VERIFIKASI_PEMBAYARAN` edge — confirmed by running this
     * generator against real Postgres, which is what surfaced the two
     * intermediate hops below (`DISETUJUI_PEMESAN`, then
     * `MENUNGGU_PEMBAYARAN`) that the task brief's draft chain omitted.
     * `App\Filament\Admin\Resources\BookingOrders\Actions\
     * TransitionOrderAction::run()` is the real caller this mirrors: buyer
     * approval accepts the current quote, then payment opening is granted
     * (self-granted here, the same `(int) $actorRef` pattern that resource
     * uses for its own acting admin) before a manual payment can even be
     * recorded.
     */
    private static function paid(Cemetery $cemetery, string $batchId, int $index): Order
    {
        $order = self::quoted($cemetery, $batchId, $index);

        app(RecordBuyerApproval::class)($order, self::ACTOR_REF, self::ACTOR_ROLE);
        app(GrantOrderPaymentOpening::class)($order->fresh(), self::ACTOR_REF, self::ACTOR_REF, self::ACTOR_ROLE);
        app(ManualPaymentVerification::class)($order->fresh(), self::ACTOR_REF, self::ACTOR_ROLE, 'Bukti transfer demo diverifikasi.');
        app(MarkOrderPaid::class)($order->fresh(), self::ACTOR_REF, self::ACTOR_ROLE);

        return $order->fresh();
    }

    private static function completed(Cemetery $cemetery, string $batchId, int $index): Order
    {
        $order = self::paid($cemetery, $batchId, $index);

        app(ProcessOrder::class)($order, self::ACTOR_REF, self::ACTOR_ROLE);
        app(CompleteOrder::class)($order->fresh(), self::ACTOR_REF, self::ACTOR_ROLE);

        return $order->fresh();
    }

    private static function rejected(Cemetery $cemetery, string $batchId, int $index): Order
    {
        $order = self::submittedOnly($cemetery, $batchId, $index);

        app(VerifyOrder::class)($order, self::ACTOR_REF, self::ACTOR_ROLE);
        app(RejectOrder::class)($order, self::ACTOR_REF, self::ACTOR_ROLE, 'Data pemesan demo tidak lengkap.');

        return $order->fresh();
    }
}
