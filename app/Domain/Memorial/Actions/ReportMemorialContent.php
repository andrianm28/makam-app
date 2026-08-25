<?php

declare(strict_types=1);

namespace App\Domain\Memorial\Actions;

use App\Domain\Memorial\Models\AbuseReport;
use App\Domain\Memorial\Models\MemorialProfile;
use App\Domain\Memorial\Models\ModerationCase;
use App\Platform\Audit\Audit;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * AC6's report intake: creates an OPEN `ModerationCase` anchored to the
 * profile plus one `AbuseReport` carrying the reporter's identity
 * reference and the REQUIRED reason — in a single transaction (a report
 * without its case, or a case without its report, would be a dangling
 * half-intake).
 *
 * `$reportedContentType` is a polymorphic discriminator
 * (`memorial_content` / `memorial_media`) naming which row
 * `$reportedContentId` refers to — deliberately not validated against a
 * closed list here because a future type (e.g. a comment) should not
 * need a Task 3 code change to become reportable; the case's own
 * profile anchor keeps the report scoped.
 *
 * A blank reason is refused with `InvalidArgumentException` up front
 * (the `AbuseReport` model guard backstops every other write path with
 * the same Unicode-aware blank check).
 */
final readonly class ReportMemorialContent
{
    public function __invoke(
        MemorialProfile $profile,
        string $reportedContentType,
        int|string $reportedContentId,
        int|string $reporterReference,
        string $reporterRole,
        string $reason,
    ): ModerationCase {
        if (Audit::reasonIsBlank($reason)) {
            throw new InvalidArgumentException(
                "Cannot report memorial content [{$reportedContentId}] on profile [{$profile->getKey()}]: ".
                'an abuse report requires a reason.'
            );
        }

        return DB::transaction(function () use (
            $profile,
            $reportedContentType,
            $reportedContentId,
            $reporterReference,
            $reason,
        ): ModerationCase {
            $case = $profile->moderationCases()->create([
                'reported_content_type' => $reportedContentType,
                'reported_content_id' => (string) $reportedContentId,
                'status' => ModerationCase::STATUS_OPEN,
            ]);

            AbuseReport::query()->create([
                'moderation_case_id' => $case->getKey(),
                'reporter_ref' => (string) $reporterReference,
                'reason' => $reason,
            ]);

            return $case;
        });
    }
}
