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
 * Four signals, each with its own scope and its own reason:
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
 *  3. **A transfer-verb method declared anywhere under `app/**`.**
 *     The way to sidestep both of the above is to put the method behind an
 *     interface the ledger resolves from the container without naming. The
 *     method still has to be declared somewhere, and it still has to be called
 *     something. (Scoped to `app/Platform` until Task 9b; see below.)
 *  4. **Payout ORCHESTRATION from outside the ledger module** — the gap the
 *     three rules above left wide open, closed at Task 9b.
 *
 * ---------------------------------------------------------------------------
 * Why signal 4 exists: where an automated payout would actually be written
 * ---------------------------------------------------------------------------
 * Three of the four rules used to scan only `app/Platform/FinancialLedger`, and
 * the fourth only `app/Platform`. `app/Console`, `app/Http`, `app/Domain`,
 * `app/Filament`, `app/Livewire` and anything else under `app/` were scanned by
 * NOTHING. So this, which is exactly where somebody would put it, passed every
 * assertion in this file:
 *
 *     // app/Console/Commands/SettleVendorPayables.php
 *     public function handle(): int
 *     {
 *         $response = Http::withToken($token)->post($bankApi, [...]);
 *         $this->payout->pay($payable->id, ...);   // records it as MANUAL
 *     }
 *
 * The outbound-call and gateway rules never looked outside the module, and
 * `handle` is not a transfer verb. The schema then records the result as
 * `method = 'manual_bank_transfer'`, because nothing distinguishes "a human
 * moved this money" from "a program did".
 *
 * Signal 4's rule is deliberately conditional rather than a blanket ban, so it
 * stays keepable: a file anywhere under `app/` that REFERENCES the payout
 * surface must carry no outbound-call, provider-adapter or transfer-gateway
 * signal. A notification module may call an HTTP API; a class that calls
 * `ManualPayout::pay()` may not.
 *
 * ---------------------------------------------------------------------------
 * The real residual gap, corrected
 * ---------------------------------------------------------------------------
 * This doc block used to claim the residual gap was "`function execute()` on a
 * container-bound interface". That is FALSE — `execute(` is covered, by the
 * `transfer_gateway_call` pattern. For a test whose whole purpose is honest
 * structural absence, a disclosed gap that is not real is worse than no
 * disclosure: it invites a reader to trust the parts that ARE claimed.
 *
 * The genuine residual gaps, as of Task 9b:
 *
 *  - A payout orchestrated from a file that names NONE of the payout-surface
 *    tokens signal 4 keys on — writing raw SQL against `payouts` through a
 *    variable table name, for instance.
 *  - Anything outside `app/` entirely: `routes/`, `database/`, a queue worker
 *    configured to call a webhook, or infrastructure.
 *  - A provider that accepts a transfer by e-mail or file drop, which needs no
 *    HTTP call at all.
 *
 * Which is why this file also asserts the closed lists and the schema — an
 * automated payout still needs somewhere to record that it happened, and
 * `PayoutMethod`, `PayoutState` and the `payouts` table give it nowhere.
 */
final class NoAutomatedPayoutPathTest extends TestCase
{
    use RefreshDatabase;

    private const string APP_PATH = 'app';

    private const string LEDGER_PATH = 'app/Platform/FinancialLedger';

    /**
     * "This file is about paying vendors." Any one of these tokens is enough to
     * bring a file under signal 4's stricter rule, wherever it lives.
     *
     * Kept broad on purpose: a false positive here costs a developer one
     * conversation about why their payout orchestrator wants an HTTP client,
     * which is a conversation AC9 wants to happen.
     *
     * @var array<string, string>
     */
    private const array PAYOUT_SURFACE_PATTERNS = [
        'names_manual_payout' => '/\bManualPayout\b/',
        'names_vendor_payable' => '/\bVendorPayable\b/',
        'names_payout_vocabulary' => '/\bPayout(Method|State|Proof|Authorizer|ProofVerifier)\b/',
        'touches_the_payouts_table' => '/[\'"]payouts[\'"]/',
        'calls_pay' => '/->\s*pay\s*\(/',
    ];

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

    /**
     * Widened at Task 9b from `app/Platform` to the whole of `app/`. Verified
     * before widening that the tree is genuinely clean — no method matching
     * `transfer|disburse|remit|wire` is declared anywhere under `app/` — so
     * this is a rule the repository can actually keep rather than one that will
     * need suppressing next week.
     */
    public function test_no_transfer_verb_method_is_declared_anywhere_under_app(): void
    {
        $this->assertNoMatches(
            self::APP_PATH,
            self::TRANSFER_VERB_PATTERNS,
            'A payout that moves money by itself needs a method that does the moving.',
        );
    }

    /**
     * Signal 4: nothing outside the ledger module orchestrates a payout AND
     * talks to something outside this process.
     *
     * This is the rule that would have caught
     * `app/Console/Commands/SettleVendorPayables.php` calling a bank API and
     * then recording the result through `ManualPayout::pay()` — a file none of
     * the other three rules ever looked at.
     */
    public function test_nothing_outside_the_ledger_module_orchestrates_a_payout_over_the_network(): void
    {
        $forbidden = array_merge(
            self::OUTBOUND_CALL_PATTERNS,
            self::PROVIDER_REFERENCE_PATTERNS,
            self::AUTOMATED_TRANSFER_ARCHITECTURE_PATTERNS,
        );

        $offences = [];
        $payoutAwareFiles = 0;

        foreach ($this->phpFilesIn(self::APP_PATH) as $path => $contents) {
            // The ledger module has its own, stricter, unconditional rules
            // above; scanning it again here would double-report.
            if (str_starts_with($path, 'Platform/FinancialLedger/')) {
                continue;
            }

            if ($this->signalsIn($contents, self::PAYOUT_SURFACE_PATTERNS) === []) {
                continue;
            }

            $payoutAwareFiles++;

            foreach ($this->signalsIn($contents, $forbidden) as $signal) {
                $offences[] = "{$path}: {$signal}";
            }
        }

        $this->assertSame(
            [],
            $offences,
            'A file outside the ledger module both orchestrates a payout and reaches outside this '
            ."process. That is the automated transfer path AC9 forbids while G-PAYOUT-01 is closed.\nFound:\n"
            .implode("\n", $offences),
        );

        // A conditional rule whose condition never matches asserts nothing.
        // This is that rule's own teeth-check, in the same spirit as
        // `phpFilesIn()`'s empty-directory guard: if the payout surface is ever
        // renamed so no file outside the module references it any more, this
        // fails rather than passing vacuously forever.
        $this->assertGreaterThan(
            0,
            $payoutAwareFiles,
            'No file outside the ledger module references the payout surface at all, so this rule '
            .'scanned nothing. Either the surface was renamed and PAYOUT_SURFACE_PATTERNS is stale, '
            .'or the condition needs rethinking — it is not currently proving anything.',
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

    /**
     * Signal 4's teeth-check, written as the real thing rather than as a canary
     * string: the nightly settlement command from the review's own failure
     * scenario. Before Task 9b this file passed every assertion in this test
     * class. It must now be flagged BOTH as payout-orchestrating AND as
     * reaching outside the process — either half alone is insufficient, so both
     * are asserted separately.
     */
    public function test_the_detector_catches_a_settlement_command_that_calls_a_bank_api(): void
    {
        $sample = <<<'PHP'
            <?php

            namespace App\Console\Commands;

            use App\Platform\FinancialLedger\Actions\ManualPayout;
            use Illuminate\Console\Command;
            use Illuminate\Support\Facades\Http;

            final class SettleVendorPayables extends Command
            {
                protected $signature = 'vendor:settle';

                public function handle(ManualPayout $payout): int
                {
                    foreach ($this->duePayables() as $payable) {
                        $response = Http::withToken(config('bank.token'))->post(config('bank.transfer_url'), [
                            'account_number' => $payable->vendor_account,
                            'amount' => $payable->amount_minor,
                        ]);

                        $payout->pay($payable->id, $payable->amount(), $this->proofFrom($response));
                    }

                    return self::SUCCESS;
                }
            }
            PHP;

        $this->assertNotSame(
            [],
            $this->signalsIn($sample, self::PAYOUT_SURFACE_PATTERNS),
            'The sample must be recognised as payout orchestration, or the conditional rule never '
            .'applies to it and the outbound-call check below is never reached.',
        );

        $this->assertNotSame(
            [],
            $this->signalsIn($sample, array_merge(
                self::OUTBOUND_CALL_PATTERNS,
                self::PROVIDER_REFERENCE_PATTERNS,
                self::AUTOMATED_TRANSFER_ARCHITECTURE_PATTERNS,
            )),
            'The sample reaches a bank API and must be flagged for it.',
        );
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

    /**
     * Task #27 (Option D) — the guard must fire on CODE, not on prose.
     *
     * Before this test, `signalsIn()` ran the patterns against the raw file
     * contents, so a doc-block that merely DESCRIBED an automated payout —
     * "use GuzzleHttp\Client to post to the bank API", "a
     * VendorSettlementGateway would call Http::post" — tripped the guard even
     * though no such code exists. That is exactly the false positive that hit
     * the payment-auth-hotfix lane: its new files' doc blocks named
     * `ManualPayout`/`PayoutAuthorizer` as words, and the AC9 guard red-flagged
     * them as a real automated-payout path. The fix strips comments and string
     * literals before matching, so a guard whose whole purpose is structural
     * honesty does not cry wolf about a paragraph.
     */
    public function test_prose_describing_a_payout_in_a_doc_block_does_not_trip_the_guard(): void
    {
        $sample = <<<'PHP'
            <?php

            /**
             * An automated payout would reach the bank API here:
             *
             *     use GuzzleHttp\Client;
             *     $response = (new Client())->post($bankUrl, [...]);
             *
             * It would route through ManualPayout::pay() after a
             * VendorSettlementGateway transfer. AC9 forbids that while
             * G-PAYOUT-01 is closed — but this doc block only says so.
             */
            final class NotActuallyAnAutomatedPayout {}
            PHP;

        $allPatterns = array_merge(
            self::OUTBOUND_CALL_PATTERNS,
            self::PROVIDER_REFERENCE_PATTERNS,
            self::TRANSFER_VERB_PATTERNS,
            self::AUTOMATED_TRANSFER_ARCHITECTURE_PATTERNS,
            self::PAYOUT_SURFACE_PATTERNS,
        );

        $this->assertSame(
            [],
            $this->signalsIn($sample, $allPatterns),
            'Doc-block prose describing a payout must not trip a structural guard: '
            .'comments are not code, and a guard that flags its own documentation '
            .'is exactly the crying-wolf failure this test exists to prevent.',
        );
    }

    /**
     * The counterpart to the prose test above: after stripping, the guard must
     * STILL catch the same patterns in real code. Comment-stripping must not
     * blind the detector — a genuine `GuzzleHttp\Client` import in an actual
     * `use` statement stays flagged even though this file's own doc block
     * (which is full of payout prose) is now invisible to it.
     */
    public function test_real_code_patterns_still_trip_the_guard_after_comment_stripping(): void
    {
        $sample = <<<'PHP'
            <?php
            use GuzzleHttp\Client;
            final class BankDisbursementClient {}
            PHP;

        $this->assertContains(
            'guzzle_import',
            $this->signalsIn($sample, self::OUTBOUND_CALL_PATTERNS),
            'Comment-stripping must not hide a real outbound-call import.',
        );
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

        $code = self::stripCommentsAndStrings($contents);

        foreach ($patterns as $signal => $pattern) {
            if (preg_match($pattern, $code) === 1) {
                $signals[] = $signal;
            }
        }

        return $signals;
    }

    /**
     * Tokenize the file and keep only the code tokens, so the patterns match
     * against what is actually EXECUTED rather than what a doc block or string
     * literal happens to say.
     *
     * This is task #27 (Option D) — the guard's original failure mode. It ran
     * its regexes over raw file contents, so a doc block that merely DESCRIBED
     * an automated payout ("use GuzzleHttp\Client to post to the bank API", "a
     * VendorSettlementGateway transfer") tripped the guard even though no such
     * code existed. The payment-auth-hotfix lane hit exactly this: its new
     * files' doc blocks named `ManualPayout`/`PayoutAuthorizer` as words and
     * the AC9 guard red-flagged them as a real automated-payout path.
     *
     * Tokenization keeps every signal honest:
     *  - A real `use GuzzleHttp\Client;` is a T_USE token — still matched.
     *  - `Http::post(...)` in code is T_STRING + T_DOUBLE_COLON — still matched.
     *  - The same words inside a `/** ... *\/` doc block or a `'...'` literal
     *    are T_DOC_COMMENT / T_COMMENT / T_CONSTANT_ENCAPSED_STRING — dropped.
     */
    private static function stripCommentsAndStrings(string $contents): string
    {
        $code = '';
        $tokens = token_get_all($contents);

        foreach ($tokens as $token) {
            if (! is_array($token)) {
                $code .= $token;

                continue;
            }

            [$id, $text] = $token;

            // Drop comments and every kind of string literal. T_OPEN_TAG is
            // kept so heredocs/nowdocs inside comments cannot smuggle tokens.
            if (in_array($id, [
                T_COMMENT,
                T_DOC_COMMENT,
                T_OPEN_TAG,
                T_OPEN_TAG_WITH_ECHO,
                T_CLOSE_TAG,
                T_CONSTANT_ENCAPSED_STRING,
                T_ENCAPSED_AND_WHITESPACE,
                T_START_HEREDOC,
                T_END_HEREDOC,
                T_STRING_VARNAME,
                T_NUM_STRING,
            ], true)) {
                continue;
            }

            $code .= $text;
        }

        return $code;
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
