<?php

declare(strict_types=1);

namespace Tests\Feature\FinancialLedger;

use App\Platform\FinancialLedger\PayoutMethod;
use App\Platform\FinancialLedger\PayoutState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * AC9's negative criterion — "No automated payout while `G-PAYOUT-01` is
 * closed" — as a STRUCTURAL assertion rather than a runtime one.
 *
 * The plan's Global Constraints are deliberately stronger than a feature flag:
 * "no automated transfer code path exists at all (structural, not just
 * gated)." A test that called a payout and asserted no HTTP request went out
 * would only prove the path was not taken on that call; this one asserts the
 * path is not in the tree.
 *
 * ---------------------------------------------------------------------------
 * Why this is not a grep for a string nobody would write
 * ---------------------------------------------------------------------------
 * The failure mode a structural test like this usually has is that it looks
 * for `'automated_payout'` — a literal no real implementation would ever
 * contain — and therefore passes forever regardless of what is in the tree.
 *
 * This one detects the things an automated payout would ACTUALLY need in order
 * to work, and proves its own detector fires by running it over synthetic
 * samples written the way a real implementation would be
 * (`test_the_detector_catches_a_realistic_automated_payout_implementation`).
 * If those samples ever stop being flagged, this test fails just as loudly as
 * if a real one appeared.
 *
 * Three signals, each with its own scope and its own reason:
 *
 *  1. **An outbound network call from `app/Platform/FinancialLedger/**`.**
 *     Moving money to a vendor bank account requires talking to something
 *     outside this process. Scoped to the ledger module rather than the whole
 *     platform because sibling modules legitimately make HTTP calls —
 *     `platform-payment-adapter` (`app/Platform/Payment/**`) exists precisely
 *     to talk to a payment provider, and `platform-notifications` to a
 *     WhatsApp/email provider. Forbidding HTTP everywhere would be a rule this
 *     repository could not keep, and a rule that has to be suppressed is worse
 *     than no rule.
 *  2. **A reference from the ledger module to the provider adapter's
 *     namespace.** The obvious way to sidestep signal 1 is to let
 *     `app/Platform/Payment/**` make the call and have the ledger ask it to.
 *  3. **A transfer-verb method declared anywhere under `app/Platform/**`.**
 *     The way to sidestep both of the above is to put the method behind an
 *     interface the ledger resolves from the container without naming. The
 *     method still has to be declared somewhere, and it still has to be called
 *     something.
 *
 * Residual gap, stated rather than hidden: a determined author could declare
 * `function execute()` on a class the ledger resolves through a
 * container-bound interface, and no textual rule would catch it. That is why
 * this file also asserts the closed lists and the schema — an automated payout
 * still needs somewhere to record that it happened, and `PayoutMethod`,
 * `PayoutState` and the `payouts` table give it nowhere.
 */
final class NoAutomatedPayoutPathTest extends TestCase
{
    use RefreshDatabase;

    private const string PLATFORM_PATH = 'app/Platform';

    private const string LEDGER_PATH = 'app/Platform/FinancialLedger';

    /**
     * An outbound network call. Namespace separators are doubled because these
     * are PHP regexes matching PHP source.
     *
     * @var array<string, string>
     */
    private const array OUTBOUND_CALL_PATTERNS = [
        'guzzle_import' => '/\buse\s+GuzzleHttp\\\\/',
        'http_facade_import' => '/\buse\s+Illuminate\\\\Support\\\\Facades\\\\Http\s*[;{]/',
        'http_client_import' => '/\buse\s+Illuminate\\\\Http\\\\Client\\\\/',
        'http_facade_call' => '/\bHttp::(get|post|put|patch|delete|send|pool|withToken|withHeaders|withBasicAuth)\s*\(/',
        'curl' => '/\bcurl_(init|exec|setopt)\s*\(/',
        'raw_socket' => '/\b(fsockopen|stream_socket_client)\s*\(/',
    ];

    /**
     * A reference from the ledger module into the provider adapter module.
     *
     * @var array<string, string>
     */
    private const array PROVIDER_REFERENCE_PATTERNS = [
        'payment_adapter_namespace' => '/\bApp\\\\Platform\\\\Payment\\\\/',
    ];

    /**
     * A method whose name is a verb for moving money out. Matches a
     * declaration only (`function ...(`), so a string constant such as
     * `manual_bank_transfer` or prose in a doc block never trips it.
     *
     * @var array<string, string>
     */
    private const array TRANSFER_VERB_PATTERNS = [
        'transfer_verb_method' => '/\bfunction\s+\w*(transfer|disburse|remit|wire)\w*\s*\(/i',
    ];

    /**
     * Architectural signals for a gateway-backed transfer that avoids a
     * transfer-named method. These scan the ledger module because unrelated
     * platform modules may legitimately have senders or gateways.
     *
     * @var array<string, string>
     */
    private const array AUTOMATED_TRANSFER_ARCHITECTURE_PATTERNS = [
        'transfer_gateway_import' => '/\buse\s+[^;]*(?:Transfer|Disbursement|Settlement|Payout)Gateway\w*\s*;/i',
        'transfer_gateway_interface' => '/\binterface\s+\w*(?:Transfer|Disbursement|Settlement|Payout)Gateway\w*\b/i',
        'transfer_gateway_dependency' => '/\b(?:private|protected|public)\s+(?:readonly\s+)?\w*(?:Transfer|Disbursement|Settlement|Payout)Gateway\w*\s+\$\w+\b/i',
        'transfer_client_dependency' => '/\b(?:private|protected|public)\s+(?:readonly\s+)?\w*(?:Transfer|Disbursement|Settlement)Client\w*\s+\$\w+\b/i',
        'transfer_gateway_call' => '/->\s*(?:transfer|disburse|remit|wire|send|execute|submit)\s*\(/i',
    ];

    public function test_the_ledger_module_makes_no_outbound_network_call(): void
    {
        $this->assertNoMatches(
            self::LEDGER_PATH,
            self::OUTBOUND_CALL_PATTERNS,
            'An automated payout has to talk to something outside this process. '.
            'AC9 forbids that path existing while G-PAYOUT-01 is closed.',
        );
    }

    public function test_the_ledger_module_never_reaches_for_the_provider_adapter(): void
    {
        $this->assertNoMatches(
            self::LEDGER_PATH,
            self::PROVIDER_REFERENCE_PATTERNS,
            'Routing a payout through the payment adapter would be an automated '.
            'transfer with an extra hop in it.',
        );
    }

    public function test_no_transfer_verb_method_is_declared_anywhere_under_app_platform(): void
    {
        $this->assertNoMatches(
            self::PLATFORM_PATH,
            self::TRANSFER_VERB_PATTERNS,
            'A payout that moves money by itself needs a method that does the moving.',
        );
    }

    public function test_the_ledger_has_no_gateway_dependency_or_transfer_call(): void
    {
        $this->assertNoMatches(
            self::LEDGER_PATH,
            self::AUTOMATED_TRANSFER_ARCHITECTURE_PATTERNS,
            'A provider-backed gateway dependency or call would create an automated payout path.',
        );
    }

    public function test_the_detector_catches_a_realistic_automated_payout_implementation(): void
    {
        // Written the way somebody actually implementing this would write it,
        // not as a canary string. If a change to the patterns above stops
        // flagging these, the three tests above have quietly become theatre
        // and this one fails first.
        $samples = [
            'guzzle_import' => <<<'PHP'
                <?php
                use GuzzleHttp\Client;
                final class BankDisbursementClient {}
                PHP,
            'http_facade_import' => <<<'PHP'
                <?php
                use Illuminate\Support\Facades\Http;
                final class VendorPayoutSender {}
                PHP,
            'http_client_import' => <<<'PHP'
                <?php
                use Illuminate\Http\Client\PendingRequest;
                final class VendorPayoutSender {}
                PHP,
            'http_facade_call' => <<<'PHP'
                <?php
                $response = Http::withToken($token)->post($endpoint, [
                    'account_number' => $account,
                    'amount' => $amountMinor,
                ]);
                PHP,
            'curl' => <<<'PHP'
                <?php
                $handle = curl_init($endpoint);
                PHP,
            'raw_socket' => <<<'PHP'
                <?php
                $socket = fsockopen($host, 443);
                PHP,
            'payment_adapter_namespace' => <<<'PHP'
                <?php
                use App\Platform\Payment\ProviderGateway;
                final class ManualPayout {}
                PHP,
            'transfer_verb_method' => <<<'PHP'
                <?php
                interface PayoutGateway
                {
                    public function transferToVendorAccount(string $destination, int $amountMinor): string;
                }
                PHP,
            'transfer_gateway_import' => <<<'PHP'
                <?php
                use App\Platform\Banking\Contracts\VendorSettlementGateway;
                PHP,
            'transfer_gateway_interface' => <<<'PHP'
                <?php
                interface VendorSettlementGateway
                {
                    public function execute(string $destination, int $amountMinor): string;
                }
                PHP,
            'transfer_gateway_dependency' => <<<'PHP'
                <?php
                final class VendorPayoutSender
                {
                    public function __construct(private VendorSettlementGateway $gateway) {}
                }
                PHP,
            'transfer_client_dependency' => <<<'PHP'
                <?php
                final class VendorPayoutSender
                {
                    public function __construct(private BankTransferClient $client) {}
                }
                PHP,
            'transfer_gateway_call' => <<<'PHP'
                <?php
                $this->gateway->execute($destination, $amountMinor);
                PHP,
        ];

        $allPatterns = array_merge(
            self::OUTBOUND_CALL_PATTERNS,
            self::PROVIDER_REFERENCE_PATTERNS,
            self::TRANSFER_VERB_PATTERNS,
            self::AUTOMATED_TRANSFER_ARCHITECTURE_PATTERNS,
        );

        foreach ($samples as $expectedSignal => $sample) {
            $this->assertContains(
                $expectedSignal,
                $this->signalsIn($sample, $allPatterns),
                "The [{$expectedSignal}] rule no longer flags a realistic automated-payout ".
                'implementation, so the structural assertions above prove nothing.',
            );
        }
    }

    public function test_the_detector_does_not_flag_the_module_as_it_stands(): void
    {
        // The counterpart to the test above: the rules must be discriminating,
        // not merely loud. This module's own manual payout — string constants
        // containing "transfer" and all — must come back clean.
        $manualPayout = file_get_contents(
            base_path('app/Platform/FinancialLedger/Actions/ManualPayout.php')
        );

        $this->assertIsString($manualPayout);
        $this->assertSame([], $this->signalsIn($manualPayout, array_merge(
            self::OUTBOUND_CALL_PATTERNS,
            self::PROVIDER_REFERENCE_PATTERNS,
            self::TRANSFER_VERB_PATTERNS,
            self::AUTOMATED_TRANSFER_ARCHITECTURE_PATTERNS,
        )));
    }

    public function test_there_is_no_automated_payout_method_or_state_to_record_one_with(): void
    {
        // The textual rules above can be sidestepped by a sufficiently
        // determined author. This cannot: an automated payout still has to be
        // recorded, and there is nowhere to record it as one.
        $this->assertSame([PayoutMethod::MANUAL_BANK_TRANSFER], PayoutMethod::KNOWN_METHODS);
        $this->assertSame([PayoutState::RECORDED], PayoutState::KNOWN_STATES);
    }

    public function test_the_payouts_table_has_no_column_for_a_provider_transfer(): void
    {
        $columns = Schema::getColumnListing('payouts');

        foreach ([
            'provider_ref',
            'provider_transaction_id',
            'transfer_id',
            'transfer_status',
            'external_transfer_id',
            'disbursement_id',
            'destination_account_ref',
        ] as $forbidden) {
            $this->assertNotContains(
                $forbidden,
                $columns,
                "The payouts table gained a [{$forbidden}] column. A column named for a ".
                'provider transfer is a place to record the thing AC9 forbids happening.',
            );
        }

        // And the columns AC9 does require are all there.
        foreach (['amount_minor', 'proof_document_kind', 'proof_document_ref', 'approver_ref', 'journal_business_key'] as $required) {
            $this->assertContains($required, $columns);
        }
    }

    /**
     * @param  array<string, string>  $patterns
     */
    private function assertNoMatches(string $relativePath, array $patterns, string $why): void
    {
        $offences = [];

        foreach ($this->phpFilesIn($relativePath) as $path => $contents) {
            foreach ($this->signalsIn($contents, $patterns) as $signal) {
                $offences[] = "{$path}: {$signal}";
            }
        }

        $this->assertSame([], $offences, $why."\nFound:\n".implode("\n", $offences));
    }

    /**
     * @param  array<string, string>  $patterns
     * @return list<string>
     */
    private function signalsIn(string $contents, array $patterns): array
    {
        $signals = [];

        foreach ($patterns as $signal => $pattern) {
            if (preg_match($pattern, $contents) === 1) {
                $signals[] = $signal;
            }
        }

        return $signals;
    }

    /**
     * @return array<string, string>
     */
    private function phpFilesIn(string $relativePath): array
    {
        $files = [];

        foreach (Finder::create()->files()->in(base_path($relativePath))->name('*.php') as $file) {
            $files[$file->getRelativePathname()] = $file->getContents();
        }

        // A rule that scans an empty directory passes vacuously. Assert there
        // is really something to scan.
        $this->assertNotSame([], $files, "No PHP files found under [{$relativePath}].");

        return $files;
    }
}
