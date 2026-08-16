<?php

declare(strict_types=1);

namespace App\Domain\Memorial;

use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\SvgWriter;

/**
 * The QR image renderer for the Memorial module — a thin, pure wrapper over
 * `endroid/qr-code` (`composer` dep, pinned `^6.1` in the lockfile; the
 * design spec §4.2: "endroid/qr-code encodes the token URL only").
 *
 * Encodes ONLY the URL the caller hands it (`route('memorial.show', $token)`
 * from the admin/family surfaces) — the QR never embeds any other
 * identifier, per `.kiro/specs/memorial-and-qr/requirements.md` AC4 ("THE
 * SYSTEM SHALL NOT embed a restricted identifier").
 *
 * ---------------------------------------------------------------------------
 * Why the SVG writer, and what its output contains (verified empirically)
 * ---------------------------------------------------------------------------
 * `SvgWriter` produces an SVG document whose payload exists ONLY inside the
 * QR matrix's encoded data — verified against the installed writer (6.1):
 * there is no title/aria/text node carrying the URL. That is a feature, not
 * a limitation: a QR image must not expose its payload as scrapeable text.
 * `MemorialQrImageTest` therefore asserts well-formedness, determinism per
 * payload, and distinctness across payloads rather than a payload substring.
 *
 * No PNG path anywhere: the writer is used in its SVG form, and the CI
 * runner has no gd extension (SVG output needs no gd).
 */
final class MemorialQrImage
{
    /**
     * Render the token URL as an SVG document.
     */
    public function svg(string $tokenUrl): string
    {
        $qrCode = new QrCode($tokenUrl);

        return (new SvgWriter)->write($qrCode)->getString();
    }
}
