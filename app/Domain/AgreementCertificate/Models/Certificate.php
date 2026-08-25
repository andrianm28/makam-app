<?php

declare(strict_types=1);

namespace App\Domain\AgreementCertificate\Models;

use App\Domain\AgreementCertificate\CertificateStatus;
use App\Domain\AgreementCertificate\CertificateType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Eloquent model for `certificates` — see
 * `2026_08_17_100010_create_certificates_table.php` for the schema. One
 * row per CERTIFICATE VERSION; replacement preserves every earlier row
 * (AC5).
 *
 * Status moves ONLY through the module's Actions (`IssueCertificate`,
 * `RevokeCertificate`, `ReplaceCertificate`), which carry the AC4 role
 * gate, the eligibility evaluation, the vault-document Accepted check,
 * the audit row, and the outbox events. The model itself has no write
 * guard for the same reason `Agreement` (and `FuneralCase`) decline one:
 * a certificate status is an operational/document state, not a
 * money-bearing column, and the actions' transaction wraps every write
 * with its audit pair.
 *
 * `document_id` references the vault `Document` row behind the
 * certificate. The reference is only usable when that document is
 * `DocumentState::Accepted` — enforced by the issuing Actions, never by
 * this model (a Quarantined/Scanning/Rejected document must not be
 * attachable at any write path).
 */
final class Certificate extends Model
{
    use HasUuids;

    protected $table = 'certificates';

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'reference',
        'type',
        'version_number',
        'status',
        'subject_type',
        'subject_id',
        'issued_by_ref',
        'issued_by_role',
        'effective_at',
        'document_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version_number' => 'integer',
            'effective_at' => 'immutable_datetime',
        ];
    }

    public function status(): CertificateStatus
    {
        return CertificateStatus::from($this->status);
    }

    public function type(): CertificateType
    {
        return CertificateType::from($this->type);
    }

    /**
     * Always throws for any non-draft row. Issued, revoked, and replaced
     * rows are preserved history (AC5) — the certificate's document
     * number, version, and status trail must outlive any cleanup.
     *
     * @throws LogicException when the row is not a draft.
     */
    public function delete(): ?bool
    {
        if ($this->status !== CertificateStatus::Draft->value) {
            throw new LogicException(
                "certificates row [{$this->getKey()}] is [{$this->status}]; issued/revoked/replaced rows are preserved history (AC5) and cannot be deleted."
            );
        }

        return parent::delete();
    }
}
