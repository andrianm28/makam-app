<?php

declare(strict_types=1);

namespace App\Support\ExampleData;

/**
 * The single source of every fake contact value this subsystem's
 * generators use (docs/superpowers/specs/2026-09-03-demo-seed-data-design.md,
 * decisions 2 and the email convention). No generator invents its own fake
 * email/phone/name — every one calls here.
 *
 * Email: `@example.com`/`.org`/`.net` — RFC 2606-reserved, guaranteed
 * non-deliverable, matching Faker's own `safeEmail()` convention.
 *
 * Phone: the `0811-8990-XXXX` block. `0811` is a real, allocated Telkomsel
 * mobile prefix — deliberately chosen so the generated value is
 * STRUCTURALLY VALID (passes `SaveBookingDraftStep::validateCustomer()`'s
 * `^(\+62|62|0)[0-9]{9,13}$` check the same way a real submission would),
 * which is both better demo fidelity and necessary for seeded booking
 * drafts to actually save. The `8990` block plus a 4-digit deterministic
 * suffix is a RESERVED, do-not-dial range for this codebase's own demo
 * data — never allocate a real customer or vendor a number in this exact
 * block. WhatsApp is not live yet (`WhatsAppMode` gate closed) so this
 * carries no live-notification risk today, but the reservation is
 * documented here so it still means something once WhatsApp is wired up.
 *
 * Name: extends the existing "Contoh" convention (`CemeteryExampleData`)
 * with realistic-looking Indonesian given/family name pairs — still
 * unmistakably fictional (every one contains the literal word "Contoh"),
 * but reads naturally in a live demo rather than as a placeholder string.
 */
final class DemoContactData
{
    private const array EMAIL_DOMAINS = ['example.com', 'example.org', 'example.net'];

    private const array GIVEN_NAMES = [
        'Budi', 'Siti', 'Andi', 'Dewi', 'Agus', 'Rina', 'Joko', 'Sri',
        'Hendra', 'Wati',
    ];

    public static function email(int $index): string
    {
        $domain = self::EMAIL_DOMAINS[$index % count(self::EMAIL_DOMAINS)];

        return sprintf('demo.contoh%d@%s', $index, $domain);
    }

    public static function phone(int $index): string
    {
        $suffix = str_pad((string) ($index % 10000), 4, '0', STR_PAD_LEFT);

        return '08118990'.$suffix;
    }

    public static function personName(int $index): string
    {
        $given = self::GIVEN_NAMES[$index % count(self::GIVEN_NAMES)];
        $sequence = intdiv($index, count(self::GIVEN_NAMES)) + 1;

        return sprintf('%s Contoh %d', $given, $sequence);
    }
}
