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
 * The three package-authoring actions are not added to
 * `App\Platform\Audit\SensitiveActions::ACTIONS`; defining a package/version
 * or revising a published version is correctable by publishing a new version.
 * The price-version action is the exception: it is explicitly listed under
 * its emitted domain-qualified name because it changes a financial value and
 * requires a mandatory reason. Every Action below still calls
 * `Audit::record()` unconditionally, so a complete "who changed what, when"
 * history exists for service-catalogue authoring the same way it does for FAQ
 * content.
 *
 * ---------------------------------------------------------------------------
 * `SERVICE_DEFINITION_CREATED`/`SERVICE_DEFINITION_UPDATED` ARE on
 * `SensitiveActions::ACTIONS` (added 13 Aug 2026 by the admin-managed
 * master-data batch, Task 6) — deliberate, and different from the three
 * package-authoring actions above
 * ---------------------------------------------------------------------------
 * Editing a `service_definitions` row is not package authoring. The fields
 * an admin changes here — `fulfillment_owner`, `requires_manual_confirmation`,
 * `is_active` — alter how real orders are fulfilled and what the public
 * booking flow offers; silently deactivating a service or switching its
 * fulfillment owner has the same operational-harm shape `GATE_CHANGE` and
 * `TARIFF_SOURCE_CHANGE` protect on this list, so the change must carry a
 * recorded justification. The resource's create/edit forms therefore collect
 * a mandatory `reason` (see `Filament\Admin\Resources\
 * ServiceDefinitionResource\Schemas\ServiceDefinitionForm`), and the two
 * page classes pass it through to `Audit::record()`.
 */
final class ServiceCatalogAuditActions
{
    public const string PACKAGE_DEFINED = 'SERVICE_PACKAGE_DEFINED';

    public const string PACKAGE_VERSION_PUBLISHED = 'SERVICE_PACKAGE_VERSION_PUBLISHED';

    public const string PACKAGE_VERSION_REVISED = 'SERVICE_PACKAGE_VERSION_REVISED';

    public const string PRICE_VERSION_RECORDED = 'SERVICE_DEFINITION_PRICE_VERSION_RECORDED';

    public const string SERVICE_DEFINITION_CREATED = 'SERVICE_DEFINITION_CREATED';

    public const string SERVICE_DEFINITION_UPDATED = 'SERVICE_DEFINITION_UPDATED';
}
