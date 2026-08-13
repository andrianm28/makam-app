<?php

declare(strict_types=1);

namespace App\Domain\Quotation\Models;

use App\Domain\Quotation\Exceptions\IssuedQuoteIsImmutableException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eloquent model for `quote_lines` — the frozen line-level snapshot of a
 * quote version. See `2026_08_12_100050_create_quote_lines_table.php` for
 * the schema and why the two version references are real restrict FKs.
 *
 * Write-once outright, mirroring `App\Platform\Payment\Models\PaymentIntent`
 * / `App\Domain\OrderWorkflow\Models\OrderStatusEvent`: `update()`,
 * `performUpdate()`, and `delete()` throw unconditionally, and `create()` is
 * deliberately left alone — `Actions\IssueQuote` is the only writer, and
 * there is no legal update for a line (unlike a `Quote`, whose
 * `accept()`/`supersede()` doors exist), so no authorization flag is needed.
 *
 * As with those models, this closes every model-level write path
 * (`fill()+save()`, `update()`, `saveQuietly()`/`updateQuietly()`,
 * `delete()`) but NOT a builder-level mass write or a raw
 * `DB::table('quote_lines')` update — those never instantiate the model.
 */
final class QuoteLine extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'quote_lines';

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'quote_id',
        'service_package_version_id',
        'price_version_id',
        'price_version_number',
        'description',
        'quantity',
        'unit_amount_minor',
        'line_total_minor',
        'currency',
        'fulfillment_owner',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'service_package_version_id' => 'integer',
            'price_version_id' => 'integer',
            'price_version_number' => 'integer',
            'quantity' => 'integer',
            'unit_amount_minor' => 'integer',
            'line_total_minor' => 'integer',
        ];
    }

    /**
     * Always throws — see the class-level doc block.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $options
     */
    public function update(array $attributes = [], array $options = []): bool
    {
        throw IssuedQuoteIsImmutableException::forQuoteLine($this->getKey(), 'update');
    }

    protected function performUpdate(Builder $query): bool
    {
        throw IssuedQuoteIsImmutableException::forQuoteLine($this->getKey(), 'save');
    }

    public function delete(): ?bool
    {
        throw IssuedQuoteIsImmutableException::forQuoteLine($this->getKey(), 'delete');
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class, 'quote_id');
    }
}
