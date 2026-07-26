<?php

declare(strict_types=1);

namespace App\Domain\ServiceCatalog;

use App\Domain\ServiceCatalog\Models\ServiceDefinition;
use Illuminate\Database\Eloquent\Collection;

/**
 * The recommended read entry point for the service catalogue — a thin
 * wrapper around `ServiceDefinition`'s own scopes/helpers, mirroring
 * `App\Domain\Faq\FaqPublicQuery`'s own role and doc-block reasoning: a
 * later batch building a public/booking-wizard consumer of this catalogue
 * never needs to write a raw Eloquent query against `ServiceDefinition`
 * itself.
 *
 * This class is a convenience, not a NEW mechanism — everything it exposes
 * is also directly reachable via `ServiceDefinition`'s own public
 * scopes/statics.
 */
final class ServiceCatalogQuery
{
    /**
     * The 2 catalogue-defined "Basic services", active only, in
     * `service-catalog.md`'s own table order (mirrored by seed insertion
     * order, `id` ascending).
     *
     * @return Collection<int, ServiceDefinition>
     */
    public static function basicServices(): Collection
    {
        return ServiceDefinition::active()->ofCategory(ServiceCategory::BASIC)->orderBy('id')->get();
    }

    /**
     * The 11 catalogue-defined "Additional services", active only, in
     * `service-catalog.md`'s own table order.
     *
     * @return Collection<int, ServiceDefinition>
     */
    public static function additionalServices(): Collection
    {
        return ServiceDefinition::active()->ofCategory(ServiceCategory::ADDITIONAL)->orderBy('id')->get();
    }

    /**
     * All 12 active catalogue services, basic then additional.
     *
     * @return Collection<int, ServiceDefinition>
     */
    public static function allActive(): Collection
    {
        return ServiceDefinition::active()->orderByRaw("CASE category WHEN 'basic' THEN 0 ELSE 1 END")->orderBy('id')->get();
    }

    public static function findByCode(string $code): ?ServiceDefinition
    {
        return ServiceDefinition::findByCode($code);
    }
}
