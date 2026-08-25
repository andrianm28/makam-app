<?php

declare(strict_types=1);

namespace App\Domain\PlotInventory\Models;

use App\Domain\CemeteryDirectory\Models\Cemetery;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Eloquent model for `cemetery_blocks` — the plot-inventory parent row
 * (`docs/superpowers/specs/2026-08-16-plot-inventory-reservation-design.md`
 * §4.1). One row per physical block of plots in a cemetery; plots are
 * bulk-generated into `grave_plots` by
 * `App\Domain\PlotInventory\Actions\CreateCemeteryBlock` — never created
 * one-off by hand, so every block's plot count matches its `capacity`.
 *
 * `id` is a UUID (`HasUuids`), the same contract-wide shape
 * `docs/contracts/openapi.yaml` fixes for every domain-facing resource id
 * (`PlotId` included) and the shape `cemeteries` already follows.
 *
 * ---------------------------------------------------------------------------
 * Guards (booted)
 * ---------------------------------------------------------------------------
 * On every save, `code` is normalized to uppercase + trimmed (an operator
 * typing "blok-a" stores `BLOK-A`) and asserted non-blank, and `capacity`
 * is asserted ≥ 1 — both as `InvalidArgumentException`, plain argument
 * errors rather than database errors, before the row is written.
 */
final class CemeteryBlock extends Model
{
    use HasUuids;

    protected $table = 'cemetery_blocks';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'cemetery_id',
        'code',
        'name',
        'capacity',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        self::saving(function (self $block): void {
            $block->code = Str::upper(trim((string) $block->code));

            if ($block->code === '') {
                throw new InvalidArgumentException('Cemetery block code must not be blank.');
            }

            if ((int) $block->capacity < 1) {
                throw new InvalidArgumentException('Cemetery block capacity must be at least 1.');
            }
        });
    }

    /**
     * @return BelongsTo<Cemetery, $this>
     */
    public function cemetery(): BelongsTo
    {
        return $this->belongsTo(Cemetery::class, 'cemetery_id');
    }

    /**
     * @return HasMany<GravePlot, $this>
     */
    public function plots(): HasMany
    {
        return $this->hasMany(GravePlot::class, 'block_id');
    }
}
