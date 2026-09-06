<?php

declare(strict_types=1);

namespace App\Domain\VendorFulfillment;

/**
 * The audit action vocabulary for the VendorFulfillment domain —
 * written to `audit_events.action` by this module's Actions.
 *
 * None of these actions is added to `SensitiveActions::ACTIONS` —
 * they are routine fulfillment operations that do not require a
 * mandatory reason field.
 */
final class VendorFulfillmentAuditActions
{
    public const string WORK_ORDER_CREATED = 'WORK_ORDER_CREATED';

    public const string WORK_ORDER_ASSIGNED = 'WORK_ORDER_ASSIGNED';

    public const string EVIDENCE_UPLOADED = 'EVIDENCE_UPLOADED';

    public const string SERVICE_ACCEPTED = 'SERVICE_ACCEPTED';

    public const string COMPLAINT_FILED = 'COMPLAINT_FILED';

    public const string MAKE_GOOD_CREATED = 'MAKE_GOOD_CREATED';

    public const string COMPLAINT_INVESTIGATING = 'COMPLAINT_INVESTIGATING';

    public const string COMPLAINT_RESOLVED = 'COMPLAINT_RESOLVED';

    public const string COMPLAINT_DISMISSED = 'COMPLAINT_DISMISSED';

    public const string VENDOR_REPLACED = 'VENDOR_REPLACED';

    public const string TASK_COMPLETED = 'TASK_COMPLETED';
}
