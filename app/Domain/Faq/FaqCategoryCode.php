<?php

declare(strict_types=1);

namespace App\Domain\Faq;

use InvalidArgumentException;

/**
 * The closed list of `faq_categories.code` values — the six categories
 * `docs/product/faq-catalog.md` "Required categories" names, in that
 * document's own order. Plain string column with application-layer
 * validation, not a Postgres enum type — matching this codebase's
 * established convention for closed-list string columns
 * (`App\Platform\IdentityAccess\Mfa\MfaEnrolmentStatus`,
 * `App\Platform\IdentityAccess\Reauthentication\ReauthenticationOutcome`,
 * `App\Platform\IdentityAccess\Scopes\ScopeEntityType`).
 *
 * `AGENTS.md` §FAQ: "Seed and preserve six required categories." This list
 * is exactly those six, and is expected to stay exactly six — extending it
 * is a product/catalogue change (`docs/product/faq-catalog.md` itself would
 * need to change first), not a routine code change the way adding an MFA
 * status might be.
 */
final class FaqCategoryCode
{
    public const string CARA_MEMESAN = 'CARA_MEMESAN';

    public const string DOKUMEN = 'DOKUMEN';

    public const string PEMBAYARAN = 'PEMBAYARAN';

    public const string PERPANJANGAN = 'PERPANJANGAN';

    public const string PEMBAYARAN_GAGAL = 'PEMBAYARAN_GAGAL';

    public const string CUSTOMER_SERVICE = 'CUSTOMER_SERVICE';

    /**
     * In `faq-catalog.md`'s own listed order — the order the six categories
     * seed in, and the order a public catalogue page presents them in
     * (`faq_categories.sort_order` mirrors this array's order 1-indexed).
     *
     * @var list<string>
     */
    public const array KNOWN_CODES = [
        self::CARA_MEMESAN,
        self::DOKUMEN,
        self::PEMBAYARAN,
        self::PERPANJANGAN,
        self::PEMBAYARAN_GAGAL,
        self::CUSTOMER_SERVICE,
    ];

    public static function isKnown(string $code): bool
    {
        return in_array($code, self::KNOWN_CODES, true);
    }

    /**
     * @throws InvalidArgumentException when `$code` is not one of
     *                                  `self::KNOWN_CODES`.
     */
    public static function assertKnown(string $code): void
    {
        if (! self::isKnown($code)) {
            throw new InvalidArgumentException(
                "Unknown FAQ category code [{$code}]. Known codes: ".implode(', ', self::KNOWN_CODES).'.'
            );
        }
    }
}
