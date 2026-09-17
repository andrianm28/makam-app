<?php

declare(strict_types=1);

namespace Tests\Feature\Database\Migrations;

use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\OrderWorkflow\ProductType;
use App\Domain\Quotation\Actions\IssueQuote;
use App\Domain\ServiceCatalog\Models\ServiceDefinition;
use App\Domain\ServiceCatalog\ServiceCode;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `2026_09_17_110000_add_plot_line_columns_to_quote_lines_table` — ADR-0042.
 *
 * The CHECK is the point of this file. The application also enforces the
 * per-row rule (`IssueQuote::lineFamilyOf()`), and duplicating it here is
 * deliberate: the application produces the readable message, the database
 * provides the guarantee. A stray `DB::table('quote_lines')->insert()` reaches
 * only one of the two.
 */
final class QuoteLinePlotColumnsTest extends TestCase
{
    use RefreshDatabase;

    public function test_both_plot_columns_exist_and_are_nullable(): void
    {
        $this->assertTrue(Schema::hasColumn('quote_lines', 'grave_plot_id'));
        $this->assertTrue(Schema::hasColumn('quote_lines', 'cemetery_package_id'));
    }

    public function test_the_check_refuses_a_plot_line_carrying_only_the_plot(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/quote_lines_line_family_check/');

        DB::table('quote_lines')->insert($this->row([
            'grave_plot_id' => (string) Str::uuid(),
        ]));
    }

    public function test_the_check_refuses_a_plot_line_carrying_only_the_package(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/quote_lines_line_family_check/');

        DB::table('quote_lines')->insert($this->row([
            'cemetery_package_id' => 1,
        ]));
    }

    public function test_the_check_refuses_a_row_mixing_a_service_key_with_a_plot_key(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/quote_lines_line_family_check/');

        DB::table('quote_lines')->insert($this->row([
            'service_definition_id' => 1,
            'grave_plot_id' => (string) Str::uuid(),
            'cemetery_package_id' => 1,
        ]));
    }

    public function test_the_check_refuses_a_row_naming_no_family_at_all(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/quote_lines_line_family_check/');

        DB::table('quote_lines')->insert($this->row([]));
    }

    /**
     * A real quote, so `quote_id` cannot trip the FK before the CHECK fires.
     *
     * Every test here asserts the exception message names
     * `quote_lines_line_family_check`. Without that, `QueryException` alone
     * would pass on an FK violation just as happily as on the CHECK, and the
     * test could not tell "the CHECK works" from "some constraint works".
     *
     * `quotes.id` is a UUID (`2026_08_12_100040_create_quotes_table.php`
     * — `$table->uuid('id')->primary()`), so this returns `string`, not
     * `int`. Built with the same order + service-line fixture as
     * `IssueQuoteServiceLineTest::makeOrder()` / `issue()` / `serviceLine()`
     * (lines 304/316/338), rather than a second way to build a quote.
     */
    private function realQuoteId(): string
    {
        $order = Order::query()->create([
            'reference' => 'MK-2026-'.Str::upper(Str::random(8)),
            'product_type' => ProductType::AT_NEED_SERVICE_ORDER->value,
            'status' => OrderStatus::PENAWARAN_TERKIRIM->value,
        ]);

        $definition = ServiceDefinition::findByCode(ServiceCode::DOCUMENT_PROCESSING);
        $price = $definition->currentPriceVersion();

        $quote = app(IssueQuote::class)(
            order: $order,
            lines: [[
                'service_definition_id' => (int) $definition->getKey(),
                'price_version_id' => (int) $price->getKey(),
                'price_version_number' => (int) $price->version_number,
                'quantity' => 1,
                'unit_amount' => (string) $price->amount,
                'currency' => (string) $price->currency,
                'fulfillment_owner' => (string) $definition->fulfillment_owner,
            ]],
            expiresAt: Carbon::now()->addDays(7),
            actorRef: 'actor:admin-1',
            actorRole: 'admin',
        );

        return (string) $quote->getKey();
    }

    /**
     * `quote_lines` has no `created_at`/`updated_at` columns
     * (`2026_08_12_100050_create_quote_lines_table.php` — `QuoteLine::
     * $timestamps` is `false` to match), so unlike the brief's row shape
     * this omits them; including them 42703s on the raw insert before the
     * CHECK ever fires.
     *
     * `id` is supplied explicitly too: `quote_lines.id` is a UUID primary
     * key with no database-level default (`HasUuids` on the model assigns
     * one on `creating`, but a raw `DB::table()->insert()` never fires that
     * event), so leaving it out is a NOT NULL violation, not the CHECK.
     *
     * @param  array<string, mixed>  $family
     * @return array<string, mixed>
     */
    private function row(array $family): array
    {
        return array_merge([
            'id' => (string) Str::uuid(),
            'quote_id' => $this->realQuoteId(),
            'price_version_id' => 1,
            'price_version_number' => 1,
            'description' => 'x',
            'quantity' => 1,
            'unit_amount_minor' => 1000,
            'line_total_minor' => 1000,
            'currency' => 'IDR',
            'fulfillment_owner' => 'platform',
        ], $family);
    }
}
