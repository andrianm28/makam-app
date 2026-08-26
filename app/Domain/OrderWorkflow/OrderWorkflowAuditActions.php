<?php

declare(strict_types=1);

namespace App\Domain\OrderWorkflow;

final class OrderWorkflowAuditActions
{
    public const string PAYMENT_OPENING_AUTHORIZED = 'ORDER_PAYMENT_OPENING_AUTHORIZED';

    public const string MANUAL_PAYMENT_VERIFICATION_STARTED = 'ORDER_MANUAL_PAYMENT_VERIFICATION_STARTED';

    public const string INVOICE_ISSUED = 'ORDER_INVOICE_ISSUED';
}
