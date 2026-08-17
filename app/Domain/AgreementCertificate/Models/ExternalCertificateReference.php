<?php

declare(strict_types=1);

namespace App\Domain\AgreementCertificate\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent model for `external_certificate_references` — see
 * `2026_08_17_100020_create_external_certificate_references_table.php`.
 * The AC8 manual-external record: a certificate issued by an EXTERNAL
 * authority (a cemetery, a municipal office) that the platform
 * references without claiming it issued it.
 *
 * `isExternal()` is structural, not a column: a row of THIS table is the
 * flag — the model exists precisely so the platform's own certificates
 * table stays free of any external document number, and every display of
 * an external reference can and must mark it external (the kiro
 * design-system task: `intent=info`, "must not claim platform
 * issuance").
 *
 * Rows are recorded at the DOMAIN level, straight through this model
 * (the plan's file list assigns no Action to this table; the certificate
 * test suite drives it directly) — no admin resource surfaces a write
 * path for this table on this branch. Surfacing external-reference
 * capture is deferred/recorded, not shipped.
 */
final class ExternalCertificateReference extends Model
{
    use HasUuids;

    protected $table = 'external_certificate_references';

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'issuer_ref',
        'reference',
        'type',
        'subject_type',
        'subject_id',
    ];

    /**
     * This record is by definition an external (non-platform) issuance.
     */
    public function isExternal(): bool
    {
        return true;
    }
}
