<?php

declare(strict_types=1);

namespace App\Domain\ServiceCatalog;

/**
 * The action names this module writes to `audit_events` via
 * `App\Platform\Audit\Audit::record()`. Named constants (not inline string
 * literals scattered across the four Action classes) so tests and any
 * future caller reference the same values the Actions actually emit —
 * mirrors `App\Domain\Faq\FaqAuditActions`'s own doc block and naming shape.
 *
 * ---------------------------------------------------------------------------
 * None of these are added to `App\Platform\Audit\SensitiveActions::ACTIONS`
 * — a deliberate judgement call, made explicitly here, same reasoning
 * `FaqAuditActions`'s own doc block already applies
 * ---------------------------------------------------------------------------
 * `app/Platform/**` is a read-only dependency for this batch (this batch's
 * own scope is `app/Domain/ServiceCatalog/**` plus its migrations/tests
 * only), so `SensitiveActions.php` is not edited here regardless. Even
 * setting that constraint aside: defining a package/version/item, publishing
 * a version, or recording a price version are catalogue-authoring actions —
 * mistakes here are correctable by publishing a new version (AC2's own
 * immutability design already makes every published mistake reversible via
 * a new version, never a silent overwrite), not fraud- or harm-shaped the
 * way `SensitiveActions`'s own list (DITOLAK, plot override, tariff-source
 * change, manual payment verification, certificate revoke, vendor payout,
 * MFA reset) is. Every Action below still calls `Audit::record()`
 * unconditionally, so a complete "who changed what, when" history exists for
 * service-catalogue authoring the same way it does for FAQ content.
 */
final class ServiceCatalogAuditActions
{
    public const string PACKAGE_DEFINED = 'SERVICE_PACKAGE_DEFINED';

    public const string PACKAGE_VERSION_PUBLISHED = 'SERVICE_PACKAGE_VERSION_PUBLISHED';

    public const string PACKAGE_VERSION_REVISED = 'SERVICE_PACKAGE_VERSION_REVISED';

    public const string PRICE_VERSION_RECORDED = 'SERVICE_DEFINITION_PRICE_VERSION_RECORDED';
}
