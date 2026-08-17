<?php

declare(strict_types=1);

namespace App\Livewire\Public\Certificates;

use App\Domain\AgreementCertificate\CertificateStatusView;
use App\Domain\OrderWorkflow\Models\Order;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Livewire\Component;

/**
 * The public `/sertifikat/{subjectType}/{subjectId}` certificate status
 * page — Task 2 (P5a, Lane 1), AC6 of `.kiro/specs/certificates-and-
 * agreements/requirements.md`: "WHEN a customer views delivery/issuance
 * status THE SYSTEM SHALL display it without exposing restricted source
 * documents."
 *
 * ---------------------------------------------------------------------------
 * State-only by construction
 * ---------------------------------------------------------------------------
 * The page never touches `certificates.document_id` or the certificate's
 * document number: it renders ONLY the `CertificateStatusView` projection
 * (type / status / version / effective_at / issued_by_role), whose set of
 * keys is pinned by `tests/Feature/Domain/AgreementCertificate/
 * CertificateTest` so an accidental widening fails the suite. The vault
 * reference and subject internals never leave the server through this
 * route.
 *
 * ---------------------------------------------------------------------------
 * 404 discipline
 * ---------------------------------------------------------------------------
 * `{subjectType}` is the subject's fully-qualified class name (the same
 * no-morph-map convention the `certificates` table stores), `{subjectId}`
 * its primary key. Resolution is restricted to a closed allowlist of
 * subject types (the classes this codebase may own certificates for) and
 * the subject row must exist — an unknown type, an unknown id, and an
 * ineligible type are all indistinguishable 404s: no enumeration, no
 * existence leak. Lane 2 extends `SUPPORTED_SUBJECTS` when the pre-need
 * case can own certificates.
 */
final class CertificateStatusPage extends Component
{
    /**
     * The closed allowlist of subject classes this page resolves via the
     * route parameters — a wire-level `subjectType` can never name a class
     * outside it.
     *
     * @var list<class-string>
     */
    private const array SUPPORTED_SUBJECTS = [Order::class];

    public string $subjectType = '';

    public string $subjectId = '';

    public function mount(string $subjectType, string $subjectId): void
    {
        $this->subjectType = $subjectType;
        $this->subjectId = $subjectId;

        if (! $this->resolveSubject() instanceof Model) {
            abort(404);
        }
    }

    public function render(): View
    {
        $subject = $this->resolveSubject();

        if (! $subject instanceof Model) {
            abort(404);
        }

        return view('livewire.public.certificates.certificate-status-page', [
            'statusRows' => (new CertificateStatusView)->forSubject($subject),
        ])->layout('layouts.app', [
            'title' => 'Status Sertifikat - Makam.co.id',
            'active' => null,
        ]);
    }

    private function resolveSubject(): ?Model
    {
        if (! in_array($this->subjectType, self::SUPPORTED_SUBJECTS, true) || ! class_exists($this->subjectType)) {
            return null;
        }

        /** @var class-string<Model> $class */
        $class = $this->subjectType;

        return $class::query()->whereKey($this->subjectId)->first();
    }
}
