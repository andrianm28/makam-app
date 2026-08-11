<?php

declare(strict_types=1);

namespace App\Console\Commands\Concerns;

/**
 * Supplies the `actor_ref` recorded on the `audit_events` row for a console
 * grant or revoke — "who ran this command".
 *
 * ---------------------------------------------------------------------------
 * Why this exists
 * ---------------------------------------------------------------------------
 * The four `identity:*` commands are the only write path to this platform's
 * authorization tables. They originally passed `grantedBy: null` on the
 * grounds that a console invocation has no authenticated `ActorContext`.
 * That is true of `ActorContext`, but it does not follow that no identity
 * exists — and `null` is not a neutral value here. The `audit_events`
 * migration documents a null `actor_ref` as the unattended/system case, so
 * recording `null` for a human-initiated grant actively mislabels it as a
 * machine action. Every role and scope grant would have been attributable to
 * nobody.
 *
 * ---------------------------------------------------------------------------
 * What this value does and does NOT prove
 * ---------------------------------------------------------------------------
 * It is the operating-system account that ran the command, read from the
 * process itself rather than from anything the caller supplies — so it cannot
 * be forged by passing a flag. That makes it real evidence.
 *
 * It identifies a SHELL ACCOUNT, not necessarily a person. Where several
 * operators share one login (a deploy account, for instance), this narrows
 * the grant to that account and no further. It is deliberately not paired
 * with a `--granted-by` option: a self-asserted name would be forgeable free
 * text, and recording it beside an unforgeable value would invite readers to
 * trust both equally. Per-person attribution needs real console
 * authentication, which does not exist in this repository today and is not
 * this lane's to invent.
 *
 * ---------------------------------------------------------------------------
 * Why the `console:` prefix is load-bearing
 * ---------------------------------------------------------------------------
 * `audit_events.actor_ref` otherwise holds `ActorContext::$identityReference`
 * values — local `users.id` today, possibly an external string id under a
 * future K1/K2 adapter. An OS account named, say, `42` would be
 * indistinguishable from user 42 without a namespace. The prefix keeps the
 * two spaces from colliding and makes the provenance readable at a glance.
 */
trait IdentifiesConsoleOperator
{
    /**
     * `console:<os-account>` for the account running this command.
     *
     * Falls back progressively rather than throwing: an audit row with a
     * less precise operator is far better than a grant that cannot be
     * written at all, and `console:unknown` is still honest — it says the
     * command ran on the console and the account could not be determined.
     */
    private function consoleOperatorRef(): string
    {
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $account = posix_getpwuid(posix_geteuid());

            if (is_array($account) && ($account['name'] ?? '') !== '') {
                return 'console:'.$account['name'];
            }
        }

        $fallback = get_current_user();

        return 'console:'.($fallback !== '' ? $fallback : 'unknown');
    }
}
