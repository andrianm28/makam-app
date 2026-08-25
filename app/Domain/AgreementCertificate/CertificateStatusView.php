<?php

declare(strict_types=1);

namespace App\Domain\AgreementCertificate;

use App\Domain\AgreementCertificate\Models\Certificate;
use Illuminate\Database\Eloquent\Model;

/**
 * AC6's customer-facing projection: for a subject, the certificate state
 * ONLY. The plan pins the per-certificate key set to
 * `type` / `status` / `version` / `effective_at` / `issued_by_role` —
 * the vault document reference, the certificate's own document number,
 * and every subject internal NEVER leave the server through this view.
 * `tests/Feature/Domain/AgreementCertificate/CertificateTest.php` pins
 * the exact key set so an accidental widening fails the suite.
 *
 * `effective_at` is rendered as an ISO-8601 string (or null when a draft
 * row carries none) so a Livewire/blade consumer renders state without
 * needing to format a Carbon value.
 *
 * @return list<array{
 *     type: string,
 *     status: string,
 *     version: int,
 *     effective_at: string|null,
 *     issued_by_role: string,
 * }>
 */
final readonly class CertificateStatusView
{
    public function forSubject(Model $subject): array
    {
        return Certificate::query()
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', (string) $subject->getKey())
            ->orderByDesc('version_number')
            ->get()
            ->map(fn (Certificate $certificate): array => [
                'type' => $certificate->type,
                'status' => $certificate->status,
                'version' => $certificate->version_number,
                'effective_at' => $certificate->effective_at?->toIso8601String(),
                'issued_by_role' => $certificate->issued_by_role,
            ])
            ->all();
    }
}
