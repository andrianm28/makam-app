<?php

declare(strict_types=1);

namespace App\Domain\Faq\Exceptions;

use RuntimeException;

/**
 * Thrown by `Models\FaqArticleVersion`'s `update()`/`performUpdate()`/
 * `delete()` overrides — the application-level half of append-only
 * enforcement for `faq_article_versions`. See that model's class-level doc
 * block for exactly what this does and does not guarantee (there is no
 * database-level enforcement; a raw `DB::table()` write still bypasses it).
 *
 * Deliberately NOT a reuse of
 * `App\Platform\Audit\Exceptions\AuditRecordIsImmutableException`: that
 * class's message names `audit_events` specifically, so reusing it here
 * would report the wrong table and would couple a Domain module to the
 * Audit platform module for nothing. This mirrors the shape
 * `App\Domain\ServiceCatalog\Exceptions\PublishedServicePackageVersionIsImmutableException`
 * already established for exactly this situation — a plain
 * `RuntimeException` with a named static factory per call site.
 */
final class FaqArticleVersionIsImmutableException extends RuntimeException
{
    public static function forOperation(string $operation): self
    {
        return new self(
            "faq_article_versions rows are append-only; {$operation}() is not permitted on ".
            'App\Domain\Faq\Models\FaqArticleVersion. A new version row is written only by '.
            'Actions\PublishFaqArticle, one per successful publish.'
        );
    }
}
