<?php

declare(strict_types=1);

namespace App\Domain\Faq\Exceptions;

use RuntimeException;

/**
 * The server-resolved actor may not manage FAQ content — thrown by
 * `Contracts\FaqAuthorizer::authorizeManage()` and, through it, by the first
 * statement of every FAQ write path exposed in the admin panel.
 *
 * Its own type rather than a reuse of any `…NotAuthorisedException` from
 * `App\Platform\FinancialLedger\Exceptions`, for the reason those classes
 * already state about each other: sharing a refusal type between two
 * different permissions is the first step towards sharing the check. Managing
 * public FAQ content and moving money are unrelated authorities.
 *
 * Carries no identity detail, no role list, and no record identifier: the
 * message is identical whether the actor holds no roles at all or holds a
 * role that simply is not `admin`, so a refusal cannot be used to probe this
 * deployment's role assignments.
 */
final class FaqActionNotAuthorisedException extends RuntimeException
{
    public static function forActorContext(): self
    {
        return new self(
            'The resolved actor has no authority to manage FAQ content.'
        );
    }
}
