<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\PreNeedCases\Actions;

use App\Domain\AgreementCertificate\Actions\AcceptAgreement;
use App\Domain\AgreementCertificate\Actions\CreateAgreement;
use App\Domain\AgreementCertificate\AgreementType;
use App\Domain\Booking\Models\BookingDraft;
use App\Domain\CemeteryCapability\Models\CemeteryPackage;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\FuneralCase\Models\FuneralCase;
use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\PlotInventory\Models\CemeteryBlock;
use App\Domain\PlotInventory\Models\GravePlot;
use App\Domain\PlotInventory\PlotState;
use App\Domain\PreNeed\Actions\AcceptPreNeedAgreement;
use App\Domain\PreNeed\Actions\ActivatePreNeed;
use App\Domain\PreNeed\Actions\ProposePreNeedPackage;
use App\Domain\PreNeed\Actions\QuotePreNeed;
use App\Domain\PreNeed\Actions\ReservePreNeedPlot;
use App\Domain\PreNeed\Actions\SchedulePreNeedPayments;
use App\Domain\PreNeed\Actions\SettlePreNeed;
use App\Domain\PreNeed\Exceptions\IllegalPreNeedCaseTransitionException;
use App\Domain\PreNeed\Exceptions\PreNeedGateClosedException;
use App\Domain\PreNeed\Models\PreNeedCase;
use App\Domain\PreNeed\Models\PreNeedPaymentScheduleItem;
use App\Domain\PreNeed\PreNeedGate;
use App\Domain\PreNeed\PreNeedInstallmentState;
use App\Filament\Admin\Resources\PreNeedCases\PreNeedCaseResource;
use App\Http\Middleware\RequireRecentAuthentication;
use App\Platform\Audit\AuditSource;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\Reauthentication\Exceptions\ReauthenticationRequiredException;
use App\Platform\IdentityAccess\Reauthentication\ReauthenticationGuard;
use App\Platform\IdentityAccess\Roles\ActorRole;
use App\Platform\Payment\Actions\OpenPaymentSession;
use App\Platform\Payment\Actions\OpenPaymentSessionCommand;
use App\Platform\Payment\OrderType;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The paid-flow header action factories for `PreNeedCaseResource` — the
 * plan's Task 4 "action-factory + redirect pattern" (the P1 shape
 * `TransitionOrderAction` / `MarkMarketplaceOrderPaidAction` set).
 *
 * ---------------------------------------------------------------------------
 * Two enforcement layers, and neither is redundant
 * ---------------------------------------------------------------------------
 * `->authorize()` is the RENDER/mount gate — it decides whether the button
 * is drawn and whether `mountAction()` accepts it. The `->action()` closure
 * (`run()`) re-checks the SAME role gate (plus, for money moves,
 * `ReauthenticationGuard::assertFresh()`) as its first act — the
 * enforcement that survives a direct wire call, because "the button was not
 * rendered" is not a security property.
 *
 * Role split: the CONTRACT/planning steps (propose / reserve / quote /
 * accept-agreement) admit admin + restricted_admin — the same gravity the
 * certificate-issuer gate applies to issuance. The MONEY steps (schedule /
 * settle / per-installment payment-link) admit admin + finance AND require
 * recent re-authentication (`AGENTS.md` §Authentication and uploads).
 *
 * ---------------------------------------------------------------------------
 * The G-LEGAL-01 fail-closed denial surfaces honestly, with no state change
 * ---------------------------------------------------------------------------
 * A paid-flow attempt while the legal gate is closed (dev's default) throws
 * the uniform `PreNeedGateClosedException` from `PreNeedGate::assertOpen()`
 * — after the `PRENEED_GATE_DENIED` audit row is written. Every `run()`
 * catches that ONE exception and surfaces the honest 'Belum dapat
 * diaktifkan' notification; state is only ever changed by the owning domain
 * Actions, never by a factory here.
 *
 * `runAcceptAgreement` composes TWO aggregates (Lane 1's `agreements` row
 * AND the case-level binding), so it asserts the gate HERE FIRST — before
 * `CreateAgreement`/`AcceptAgreement` run — and then executes the whole
 * composition in ONE transaction: a closed gate leaves zero rows behind,
 * and a case-level failure can never orphan an accepted agreement
 * (whole-branch review fix).
 *
 * ---------------------------------------------------------------------------
 * The AC2 binding: the case's own quote, never a caller-chosen one
 * ---------------------------------------------------------------------------
 * `AcceptPreNeedAgreement` records the CALLER-supplied quote reference
 * (Lane-2 review finding). This call site is the only caller of that
 * Action from the panel, and it passes `$case->quote_id` — the quote the
 * case is bound to — so a wire-level call cannot bind the acceptance to
 * anything but the case's own issued quote. The `agreements` row gets the
 * same quote id through Lane 1's `AcceptAgreement`, so the two bindings
 * agree.
 */
final class PreNeedCaseActions
{
    /**
     * The contract/planning-step roles.
     *
     * @var list<string>
     */
    private const array CONTRACT_ROLES = [
        ActorRole::ADMIN,
        ActorRole::RESTRICTED_ADMIN,
    ];

    /**
     * The money-step roles (admin is in both lists — an admin runs a money
     * step as its operator; finance's domain is money).
     *
     * @var list<string>
     */
    private const array MONEY_ROLES = [
        ActorRole::ADMIN,
        ActorRole::FINANCE,
    ];

    private const string MONEY_CHECKPOINT_REASON = 'paid_pre_need_money_action';

    // ---------------------------------------------------------------------
    // Factories.
    // ---------------------------------------------------------------------

    public static function propose(PreNeedCase $case): Action
    {
        return Action::make('propose')
            ->label('Buat Proposal')
            ->icon(Heroicon::OutlinedClipboardDocumentList)
            ->authorize(fn (): bool => self::roleAllowed(self::CONTRACT_ROLES))
            ->schema([
                Select::make('cemetery_id')
                    ->label('Lokasi (TPU)')
                    ->options(fn (): array => Cemetery::query()
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->required()
                    ->live(),
                Select::make('cemetery_package_id')
                    ->label('Paket')
                    ->placeholder('Tanpa paket tertentu')
                    ->options(fn (Get $get): array => self::packagesFor((string) ($get('cemetery_id') ?? '')))
                    ->searchable(),
            ])
            ->action(fn (array $data) => self::runPropose($case, $data));
    }

    public static function reserve(PreNeedCase $case): Action
    {
        return Action::make('reserve')
            ->label('Reservasi Plot')
            ->icon(Heroicon::OutlinedMapPin)
            ->authorize(fn (): bool => self::roleAllowed(self::CONTRACT_ROLES))
            ->schema([
                Select::make('plot_id')
                    ->label('Plot')
                    ->options(fn (): array => self::availablePlots($case)
                        ->mapWithKeys(fn (GravePlot $plot): array => [
                            (string) $plot->getKey() => "{$plot->block->code} — {$plot->slot}",
                        ])
                        ->all())
                    ->required(),
            ])
            ->action(fn (array $data) => self::runReserve($case, $data['plot_id'] ?? null));
    }

    public static function quote(PreNeedCase $case): Action
    {
        return Action::make('quote')
            ->label('Terbitkan Penawaran')
            ->icon(Heroicon::OutlinedDocumentText)
            ->requiresConfirmation()
            ->modalHeading('Terbitkan penawaran')
            ->modalDescription('Penawaran dibuat dari layanan pada draft pemesanan dan dicatat di audit.')
            ->authorize(fn (): bool => self::roleAllowed(self::CONTRACT_ROLES))
            ->action(fn () => self::runQuote($case));
    }

    public static function acceptAgreement(PreNeedCase $case): Action
    {
        return Action::make('accept_agreement')
            ->label('Ikutkan Kesepakatan')
            ->icon(Heroicon::OutlinedDocumentCheck)
            ->requiresConfirmation()
            ->modalHeading('Ikutkan kesepakatan pra-layanan')
            ->modalDescription('Kesepakatan diikat ke penawaran kasus sendiri (AC2) dan dicatat di audit.')
            ->authorize(fn (): bool => self::roleAllowed(self::CONTRACT_ROLES))
            ->schema(self::agreementFields())
            ->action(fn (array $data) => self::runAcceptAgreement($case, $data));
    }

    public static function schedule(PreNeedCase $case): Action
    {
        return Action::make('schedule')
            ->label('Buat Jadwal Pembayaran')
            ->icon(Heroicon::OutlinedCalendarDays)
            ->color('warning')
            ->authorize(fn (): bool => self::roleAllowed(self::MONEY_ROLES))
            ->schema([
                Repeater::make('installments')
                    ->label('Cicilan')
                    ->schema([
                        TextInput::make('amount_minor')
                            ->label('Jumlah')
                            ->numeric()
                            ->required()
                            ->minValue(1)
                            ->hint('Dalam satuan kecil mata uang penawaran (minor unit).'),
                        DatePicker::make('due_date')
                            ->label('Jatuh Tempo')
                            ->required(),
                    ])
                    ->addActionLabel('Tambah cicilan')
                    ->columns(2)
                    ->defaultItems(1)
                    ->minItems(1),
            ])
            ->action(fn (array $data) => self::runSchedule($case, $data['installments'] ?? []));
    }

    public static function settle(PreNeedCase $case): Action
    {
        return Action::make('settle')
            ->label('Selesaikan Kasus')
            ->icon(Heroicon::OutlinedBanknotes)
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading('Selesaikan kasus pra-layanan')
            ->modalDescription('Kasus diselesaikan hanya setelah bukti pembayaran terverifikasi (pesanan berstatus Dibayar).')
            ->authorize(fn (): bool => self::roleAllowed(self::MONEY_ROLES))
            ->schema([
                TextInput::make('paid_source_ref')
                    ->label('Referensi Bukti Pembayaran')
                    ->required()
                    ->maxLength(255),
            ])
            ->action(fn (array $data) => self::runSettle($case, $data['paid_source_ref'] ?? ''));
    }

    public static function activate(PreNeedCase $case): Action
    {
        return Action::make('activate')
            ->label('Aktifkan Kasus')
            ->icon(Heroicon::OutlinedShieldCheck)
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Aktifkan kasus pra-layanan')
            ->modalDescription('Aktivasi membuka Kasus Pemakaman At-Need baru (AC8) tanpa kehilangan riwayat kontrak.')
            ->authorize(fn (): bool => self::roleAllowed(self::CONTRACT_ROLES))
            ->schema([
                Select::make('booking_draft_id')
                    ->label('Draft Pemakaman At-Need')
                    ->options(fn (): array => self::activationDrafts()
                        ->mapWithKeys(fn (BookingDraft $draft): array => [
                            (string) $draft->getKey() => self::draftLabel($draft),
                        ])
                        ->all())
                    ->searchable()
                    ->required(),
            ])
            ->action(fn (array $data) => self::runActivate($case, $data['booking_draft_id'] ?? null));
    }

    public static function paymentLink(PreNeedCase $case, PreNeedPaymentScheduleItem $installment): Action
    {
        return Action::make('payment_link_'.$installment->getKey())
            ->label('Tautan Cicilan #'.$installment->installment_number)
            ->icon(Heroicon::OutlinedBanknotes)
            ->color('warning')
            ->authorize(fn (): bool => self::roleAllowed(self::MONEY_ROLES))
            ->visible(fn (): bool => $installment->state === PreNeedInstallmentState::PENDING->value)
            ->action(fn () => self::runPaymentLink($case, $installment));
    }

    // ---------------------------------------------------------------------
    // Run paths — every domain write happens here, never in a factory.
    // ---------------------------------------------------------------------

    private static function runPropose(PreNeedCase $case, array $data): void
    {
        if (! self::roleAllowed(self::CONTRACT_ROLES)) {
            self::denied('menyusun proposal.');

            return;
        }

        $cemetery = isset($data['cemetery_id']) ? Cemetery::query()->find($data['cemetery_id']) : null;

        if (! $cemetery instanceof Cemetery) {
            self::error('Lokasi (TPU) tidak ditemukan.');

            return;
        }

        $package = null;

        if (($data['cemetery_package_id'] ?? null) !== null && $data['cemetery_package_id'] !== '') {
            $package = CemeteryPackage::query()
                ->whereKey($data['cemetery_package_id'])
                ->where('cemetery_id', $cemetery->getKey())
                ->first();

            if (! $package instanceof CemeteryPackage) {
                self::error('Paket tidak ditemukan atau bukan milik lokasi terpilih.');

                return;
            }
        }

        $actor = app(ActorContext::class);

        if (! self::tryPaidFlowGate(fn () => app(ProposePreNeedPackage::class)(
            $case,
            $cemetery,
            $package,
            (string) $actor->identityReference,
            PreNeedCaseResource::auditRoleFor($actor),
            AuditSource::Panel,
        ), $case)) {
            return;
        }

        self::done('Proposal tersimpan.', $case);
    }

    private static function runReserve(PreNeedCase $case, int|string|null $plotId): void
    {
        if (! self::roleAllowed(self::CONTRACT_ROLES)) {
            self::denied('mereservasi plot.');

            return;
        }

        $plot = $plotId === null ? null : self::availablePlots($case)->first(
            fn (GravePlot $plot): bool => (string) $plot->getKey() === (string) $plotId,
        );

        if (! $plot instanceof GravePlot) {
            self::error('Plot tidak ditemukan atau tidak tersedia di lokasi kasus.');

            return;
        }

        $actor = app(ActorContext::class);

        if (! self::tryPaidFlowGate(fn () => app(ReservePreNeedPlot::class)(
            $case,
            $plot,
            (string) $actor->identityReference,
            PreNeedCaseResource::auditRoleFor($actor),
            AuditSource::Panel,
        ), $case)) {
            return;
        }

        self::done('Plot berhasil direservasi.', $case);
    }

    private static function runQuote(PreNeedCase $case): void
    {
        if (! self::roleAllowed(self::CONTRACT_ROLES)) {
            self::denied('menerbitkan penawaran.');

            return;
        }

        $actor = app(ActorContext::class);

        if (! self::tryPaidFlowGate(fn () => app(QuotePreNeed::class)(
            $case,
            CarbonImmutable::now()->addDays(30),
            (string) $actor->identityReference,
            PreNeedCaseResource::auditRoleFor($actor),
            AuditSource::Panel,
        ), $case)) {
            return;
        }

        self::done('Penawaran diterbitkan.', $case);
    }

    private static function runAcceptAgreement(PreNeedCase $case, array $data): void
    {
        if (! self::roleAllowed(self::CONTRACT_ROLES)) {
            self::denied('mengikat kesepakatan.');

            return;
        }

        $displayFields = array_intersect_key($data, array_flip(self::agreementFieldNames()));

        $actor = app(ActorContext::class);

        // The G-LEGAL-01 paid-flow gate runs FIRST, before any Lane-1
        // write: a closed gate audits the single `PRENEED_GATE_DENIED`
        // row and no `agreements`/outbox row can exist (whole-branch
        // review finding).
        if (! self::tryPaidFlowGate(fn () => PreNeedGate::assertOpen(
            (string) $actor->identityReference,
            PreNeedCaseResource::auditRoleFor($actor),
            AuditSource::Panel,
        ), $case)) {
            return;
        }

        try {
            // The whole composition is ONE transaction: Lane 1's row
            // (create + accept, which emits `agreement.accepted.v1`) and
            // the case-level binding (AC2) either all land or none do — a
            // case-level failure can never leave an accepted agreement
            // behind (whole-branch review finding).
            DB::transaction(function () use ($case, $actor, $displayFields): void {
                // Lane 1's machinery first: a fresh `agreements` version
                // row for this case, accepted against the case's own
                // bound quote (AC2).
                $created = app(CreateAgreement::class)(
                    AgreementType::PreNeedAgreement,
                    $case,
                    (string) $actor->identityReference,
                    PreNeedCaseResource::auditRoleFor($actor),
                    $displayFields,
                    AuditSource::Panel,
                );

                app(AcceptAgreement::class)(
                    $created,
                    (string) $actor->identityReference,
                    (string) $case->quote_id,
                    (string) $created->getKey(),
                    AuditSource::Panel,
                );

                // The case-level acceptance — its own quote id, never a
                // caller choice (Lane-2 review finding). The domain
                // action re-asserts the gate inside this transaction.
                app(AcceptPreNeedAgreement::class)(
                    $case,
                    $created->reference,
                    (string) $actor->identityReference,
                    PreNeedCaseResource::auditRoleFor($actor),
                    quoteId: (string) $case->quote_id,
                    auditSource: AuditSource::Panel,
                );
            });
        } catch (\Throwable $exception) {
            Notification::make()
                ->danger()
                ->title('Gagal mengikat kesepakatan')
                ->body($exception->getMessage())
                ->send();

            return;
        }

        self::done('Kesepakatan diikat.', $case);
    }

    private static function runSchedule(PreNeedCase $case, array $installments): void
    {
        $actor = app(ActorContext::class);

        if (! self::withReauthenticationAndRole(self::MONEY_ROLES, 'jadwal pembayaran', $case)) {
            return;
        }

        $installments = array_values(array_filter(
            $installments,
            static fn (mixed $row): bool => is_array($row) && isset($row['amount_minor'], $row['due_date']),
        ));

        if ($installments === []) {
            self::error('Jadwal pembayaran harus memuat paling tidak satu cicilan.');

            return;
        }

        if (! self::tryPaidFlowGate(fn () => app(SchedulePreNeedPayments::class)(
            $case,
            $installments,
            (string) $actor->identityReference,
            PreNeedCaseResource::auditRoleFor($actor),
            AuditSource::Panel,
        ), $case)) {
            return;
        }

        self::done('Jadwal pembayaran dibuat.', $case);
    }

    private static function runSettle(PreNeedCase $case, string $paidSourceRef): void
    {
        $actor = app(ActorContext::class);

        if (! self::withReauthenticationAndRole(self::MONEY_ROLES, 'penyelesaian kasus', $case)) {
            return;
        }

        if (trim($paidSourceRef) === '') {
            self::error('Referensi bukti pembayaran wajib diisi.');

            return;
        }

        if (! self::tryPaidFlowGate(fn () => app(SettlePreNeed::class)(
            $case,
            trim($paidSourceRef),
            (string) $actor->identityReference,
            PreNeedCaseResource::auditRoleFor($actor),
            AuditSource::Panel,
        ), $case)) {
            return;
        }

        self::done('Kasus diselesaikan.', $case);
    }

    private static function runActivate(PreNeedCase $case, int|string|null $draftId): void
    {
        if (! self::roleAllowed(self::CONTRACT_ROLES)) {
            self::denied('mengaktifkan kasus.');

            return;
        }

        $draft = $draftId === null ? null : self::activationDrafts()->first(
            fn (BookingDraft $draft): bool => (string) $draft->getKey() === (string) $draftId,
        );

        if (! $draft instanceof BookingDraft) {
            self::error('Draft pemakaman at-need tidak ditemukan.');

            return;
        }

        $actor = app(ActorContext::class);

        if (! self::tryPaidFlowGate(fn () => app(ActivatePreNeed::class)(
            $case,
            $draft,
            (string) $actor->identityReference,
            PreNeedCaseResource::auditRoleFor($actor),
            AuditSource::Panel,
        ), $case)) {
            return;
        }

        self::done('Kasus pra-layanan diaktifkan.', $case);
    }

    private static function runPaymentLink(PreNeedCase $case, PreNeedPaymentScheduleItem $installment): void
    {
        $actor = app(ActorContext::class);

        if (! self::withReauthenticationAndRole(self::MONEY_ROLES, 'tautan pembayaran', $case)) {
            return;
        }

        if ($installment->payment_session_id !== null) {
            self::warning('Tautan untuk cicilan ini sudah dibuat.', 'Sesi pembayaran sudah ada; tidak dibuka sesi ganda.');

            return;
        }

        $order = $case->order();

        if (! $order instanceof Order) {
            self::error(IllegalPreNeedCaseTransitionException::missingOrder((string) $case->getKey(), 'payment link')->getMessage());

            return;
        }

        $returnUrl = route('filament.admin.resources.pre-need-cases.view', ['record' => $case->getKey()]);

        try {
            $session = app(OpenPaymentSession::class)->__invoke(new OpenPaymentSessionCommand(
                orderType: OrderType::Booking,
                orderRef: $order->reference,
                amountMinor: $installment->amount_minor,
                merchantRef: (string) config('payment.merchant_ref', ''),
                successReturnUrl: $returnUrl,
                cancelReturnUrl: $returnUrl,
            ));

            $installment->forceFill(['payment_session_id' => $session->getKey()])->save();

            Notification::make()
                ->success()
                ->title('Tautan pembayaran dibuat.')
                ->body('Cicilan #'.$installment->installment_number.' kini memiliki sesi pembayaran ('.($session->payment_link_url ?? 'lihat sesi').').')
                ->send();

            redirect()->route('filament.admin.resources.pre-need-cases.view', ['record' => $case->getKey()]);
        } catch (\Throwable $exception) {
            Notification::make()
                ->danger()
                ->title('Gagal membuat tautan pembayaran')
                ->body($exception->getMessage())
                ->send();
        }
    }

    // ---------------------------------------------------------------------
    // Guards.
    // ---------------------------------------------------------------------

    /**
     * The render gate: the resource must admit the actor AND the actor must
     * hold one of the step's roles.
     *
     * @param  list<string>  $roles
     */
    private static function roleAllowed(array $roles): bool
    {
        if (! PreNeedCaseResource::canAccess()) {
            return false;
        }

        $actor = app(ActorContext::class);

        foreach ($roles as $role) {
            if ($actor->hasRole($role)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Re-checks the role gate AND the money re-authentication freshness as
     * the run-time gate for a money step. On stale re-authentication the
     * actor is bounced to the MFA challenge (the `FeatureGateAdmin` / P1
     * money-action shape) with `url.intended` set so they return here.
     *
     * @param  list<string>  $roles
     */
    private static function withReauthenticationAndRole(array $roles, string $what, PreNeedCase $case): bool
    {
        if (! self::roleAllowed($roles)) {
            self::denied($what.'.');

            return false;
        }

        try {
            app(ReauthenticationGuard::class)->assertFresh(app(ActorContext::class));

            return true;
        } catch (ReauthenticationRequiredException) {
            session()->put(RequireRecentAuthentication::REASON_SESSION_KEY, self::MONEY_CHECKPOINT_REASON);
            session()->put('url.intended', route('filament.admin.resources.pre-need-cases.view', ['record' => $case->getKey()]));

            Notification::make()
                ->warning()
                ->title('Perlu verifikasi ulang')
                ->body('Lakukan verifikasi ulang untuk tindakan ini.')
                ->send();

            redirect()->route('filament.admin.pages.mfa-challenge');

            return false;
        }
    }

    /**
     * Runs an allowed step. The step's domain Action performs its own
     * `PreNeedGate::assertOpen()` (auditing the denial and throwing the
     * uniform `PreNeedGateClosedException`); here the ONE exception is
     * caught and surfaced as the honest 'Belum dapat diaktifkan'
     * notification — with no state change (the denial audit happened in
     * the gate, and nothing else was written).
     *
     * @param  callable(): mixed  $step
     */
    private static function tryPaidFlowGate(callable $step, PreNeedCase $case): bool
    {
        try {
            $step();

            return true;
        } catch (PreNeedGateClosedException $exception) {
            Notification::make()
                ->warning()
                ->title('Belum dapat diaktifkan')
                ->body('Layanan pembayaran pra-layanan masih ditutup (G-LEGAL-01). '.$exception->getMessage())
                ->send();

            redirect()->route('filament.admin.resources.pre-need-cases.view', ['record' => $case->getKey()]);

            return false;
        } catch (\Throwable $exception) {
            Notification::make()
                ->danger()
                ->title('Aksi gagal')
                ->body($exception->getMessage())
                ->send();

            return false;
        }
    }

    // ---------------------------------------------------------------------
    // Notifications + redirect (the P1 pattern: state changes only through
    // the domain; this surface just reports and returns to the view page).
    // ---------------------------------------------------------------------

    private static function done(string $title, PreNeedCase $case): void
    {
        Notification::make()->success()->title($title)->send();
        redirect()->route('filament.admin.resources.pre-need-cases.view', ['record' => $case->getKey()]);
    }

    private static function denied(string $what): void
    {
        Notification::make()
            ->danger()
            ->title('Anda tidak berwenang '.$what)
            ->send();
    }

    private static function error(string $message): void
    {
        Notification::make()->danger()->title($message)->send();
    }

    private static function warning(string $title, string $body): void
    {
        Notification::make()->warning()->title($title)->body($body)->send();
    }

    // ---------------------------------------------------------------------
    // Data sources — the single read path for every select's options AND the
    // run-time re-read, so a wire-level call can never name a row this
    // query would not return.
    // ---------------------------------------------------------------------

    /**
     * @return array<int|string, string>
     */
    private static function packagesFor(string $cemeteryId): array
    {
        if ($cemeteryId === '') {
            return [];
        }

        return CemeteryPackage::query()
            ->where('cemetery_id', $cemeteryId)
            ->orderBy('sort_order')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * @return Collection<int, GravePlot>
     */
    private static function availablePlots(PreNeedCase $case): Collection
    {
        if ($case->cemetery_id === null) {
            return new Collection;
        }

        return GravePlot::query()
            ->with('block')
            ->whereIn(
                'block_id',
                CemeteryBlock::query()->where('cemetery_id', $case->cemetery_id)->pluck('id'),
            )
            ->where('plot_state', PlotState::AVAILABLE)
            ->orderBy('slot')
            ->get();
    }

    /**
     * The AC4 display fields both the accept modal and `CreateAgreement`
     * share — one list, filtered to the plan's approved keys.
     *
     * @return list<string>
     */
    private static function agreementFieldNames(): array
    {
        return [
            'price_guarantee',
            'cancellation_refund',
            'transferability',
            'term',
            'included_services',
            'responsible_entity',
        ];
    }

    /**
     * @return list<Component>
     */
    private static function agreementFields(): array
    {
        return [
            Textarea::make('price_guarantee')->label('Jaminan Harga')->required(),
            Textarea::make('cancellation_refund')->label('Pengembalian Pembatalan')->required(),
            Textarea::make('transferability')->label('Dapat Dialihkan')->required(),
            Textarea::make('term')->label('Jangka Waktu')->required(),
            Textarea::make('included_services')->label('Layanan Termasuk')->required(),
            Textarea::make('responsible_entity')->label('Pihak yang Bertanggung Jawab')->required(),
        ];
    }

    /**
     * Drafts that can anchor an At-Need `FuneralCase`: any draft not yet
     * consumed by an existing funeral case.
     *
     * @return Collection<int, BookingDraft>
     */
    private static function activationDrafts(): Collection
    {
        $consumedDraftIds = FuneralCase::query()
            ->whereNotNull('booking_draft_id')
            ->pluck('booking_draft_id');

        return BookingDraft::query()
            ->whereNotIn('id', $consumedDraftIds)
            ->latest('created_at')
            ->get();
    }

    private static function draftLabel(BookingDraft $draft): string
    {
        $name = trim((string) $draft->customer_full_name);

        return ($name === '' ? 'Draft #'.$draft->getKey() : $name).' · '.$draft->service_type;
    }
}
