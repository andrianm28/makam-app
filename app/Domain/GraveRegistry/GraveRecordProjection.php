<?php

declare(strict_types=1);

namespace App\Domain\GraveRegistry;

use App\Domain\GraveRegistry\Models\GraveRecord;

/**
 * One matched grave record, reduced to exactly the fields
 * requirements.md AC14's configured `access_mode` allows a PUBLIC caller to
 * see — `.kiro/specs/renewal-and-grave-registry/design.md`'s Privacy
 * section: "Public results must only return fields allowed by configured
 * access mode."
 *
 * This exists so no public surface ever holds a `GraveRecord` model. A
 * model instance carries every column whether or not the view renders it,
 * so "we just don't print it in the Blade template" is not a privacy
 * control — one `@dump`, one serialised Livewire payload, or one future
 * `toArray()` and the withheld field is on the wire. Reducing the row to a
 * value object at the query boundary makes the withheld field genuinely
 * absent rather than merely unrendered.
 *
 * ---------------------------------------------------------------------------
 * `heir_contact_reference` is projected by NO mode, including `open`
 * ---------------------------------------------------------------------------
 * That is not an oversight and not a mode this class forgot. `design.md`'s
 * Privacy section states it as an absolute — "Contact details must never
 * appear in public search by default" — and `AGENTS.md` §Authorization and
 * files puts heir contact in the same category as KTP/KK/death documents.
 * There is deliberately no property for it here, so no future edit to the
 * mode table can accidentally start exposing it.
 */
final readonly class GraveRecordProjection
{
    /**
     * @param  string  $accessMode  one of `GraveRecordAccessMode::KNOWN_MODES`
     * @param  string|null  $deceasedName  `null` unless the mode is `open`
     * @param  string|null  $cemeteryName  `null` when the mode is `closed`
     * @param  string|null  $block  `null` when the mode is `closed`
     * @param  string|null  $deathDate  ISO-8601 date; `null` unless the mode is `open`
     * @param  string|null  $dueDate  ISO-8601 date; `null` unless the mode is `open`
     */
    private function __construct(
        public string $accessMode,
        public bool $isExampleData,
        public ?string $deceasedName,
        public ?string $cemeteryName,
        public ?string $block,
        public ?string $deathDate,
        public ?string $dueDate,
    ) {}

    /**
     * The single place the AC14 mode table is applied. Read it as a table,
     * because that is what it is:
     *
     *   open    — every publicly-allowed field (AC12's list minus heir
     *             contact): deceased name, cemetery, block, death date,
     *             due date.
     *   limited — location only (cemetery, block). Enough for a family to
     *             recognise that the record they are looking for is the one
     *             being withheld, and to take the manual-assistance path
     *             with something concrete to quote — without disclosing the
     *             deceased's identity or dates to whoever typed the name.
     *   closed  — nothing. The row still EXISTS in the outcome and is still
     *             counted; see `GraveRecordAccessMode`'s doc block for why
     *             dropping it instead would be the exact defect this spec
     *             names.
     *
     * `$cemeteryName` is passed in rather than read off `$record->cemetery`
     * so this method never triggers a lazy N+1 load; the caller
     * (`GraveRegistryPublicQuery`) eager-loads it once.
     */
    public static function fromRecord(GraveRecord $record, ?string $cemeteryName): self
    {
        $mode = (string) $record->access_mode;
        GraveRecordAccessMode::assertKnown($mode);

        return match ($mode) {
            GraveRecordAccessMode::OPEN => new self(
                accessMode: $mode,
                isExampleData: $record->isExampleData(),
                deceasedName: (string) $record->deceased_name,
                cemeteryName: $cemeteryName,
                block: (string) $record->block,
                deathDate: $record->death_date?->toDateString(),
                dueDate: $record->due_date?->toDateString(),
            ),
            GraveRecordAccessMode::LIMITED => new self(
                accessMode: $mode,
                isExampleData: $record->isExampleData(),
                deceasedName: null,
                cemeteryName: $cemeteryName,
                block: (string) $record->block,
                deathDate: null,
                dueDate: null,
            ),
            default => new self(
                accessMode: $mode,
                isExampleData: $record->isExampleData(),
                deceasedName: null,
                cemeteryName: null,
                block: null,
                deathDate: null,
                dueDate: null,
            ),
        };
    }

    public function isRestricted(): bool
    {
        return GraveRecordAccessMode::isRestricted($this->accessMode);
    }
}
