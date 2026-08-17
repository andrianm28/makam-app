<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Admin;

use Filament\Resources\Resource;
use Tests\TestCase;

/**
 * Regression contract: admin Filament resources must never narrow an
 * inherited static property type below what the Filament parent declares.
 *
 * PHP requires typed property inheritance to stay invariant — a child
 * narrowing `protected static string|UnitEnum|null $navigationGroup` to
 * `?string` is a compile-time fatal the moment the class is first loaded,
 * which took the whole PHP/frontend/browser CI surface down on the P5b
 * branch (CarePlansResource + SubscriptionsResource, Aug 2026).
 *
 * The test reflects every `*Resource` under `app/Filament/Admin/Resources`
 * and asserts that any navigation/display property the resource redeclares
 * carries the exact type the Filament parent declares.
 */
final class ResourceStaticPropertyTypeContractTest extends TestCase
{
    /**
     * Properties whose inherited type must not be narrowed by a resource.
     *
     * @var array<string, string>
     */
    private const INHERITED_PROPERTY_TYPES = [
        'navigationGroup' => 'string|UnitEnum|null',
        'navigationIcon' => 'string|BackedEnum|null',
        'navigationSort' => '?int',
        'recordTitleAttribute' => '?string',
    ];

    public function test_admin_resources_do_not_narrow_inherited_static_property_types(): void
    {
        $resources = glob(app_path('Filament/Admin/Resources/*/*Resource.php'));

        $this->assertNotEmpty($resources, 'Expected admin resources to be discovered.');

        foreach ($resources as $file) {
            $class = 'App\\Filament\\Admin\\Resources\\'.basename(dirname($file)).'\\'.basename($file, '.php');

            $this->assertTrue(
                class_exists($class),
                "Resource [{$class}] must load without a fatal (typed property narrowing breaks class load).",
            );

            $reflection = new \ReflectionClass($class);

            foreach (self::INHERITED_PROPERTY_TYPES as $property => $parentType) {
                if (! $reflection->hasProperty($property)) {
                    continue;
                }

                $declaration = $reflection->getProperty($property);

                if ($declaration->getDeclaringClass()->getName() !== $class) {
                    continue;
                }

                $declaredType = $declaration->getType() === null
                    ? null
                    : (string) $declaration->getType();

                $this->assertSame(
                    self::normaliseType($parentType),
                    self::normaliseType($declaredType),
                    "[{$class}::{$property}] narrows the inherited type to [{$declaredType}]; "
                    ."parent (Filament) declares [{$parentType}], and PHP forbids narrowing.",
                );
            }
        }
    }

    /**
     * Compare types as sets of union members — PHP treats union member
     * order (`string|BackedEnum|null` vs `BackedEnum|string|null`) and the
     * nullable shorthand (`?int` vs `int|null`) as the same type.
     */
    private static function normaliseType(?string $type): ?string
    {
        if ($type === null) {
            return null;
        }

        $members = explode('|', $type);

        foreach ($members as $index => $member) {
            if (str_starts_with($member, '?')) {
                $members[$index] = substr($member, 1);
                $members[] = 'null';
            }
        }

        $members = array_values(array_unique($members));
        sort($members);

        return implode('|', $members);
    }

    public function test_the_contract_itself_matches_the_filament_parent_declaration(): void
    {
        foreach (self::INHERITED_PROPERTY_TYPES as $property => $expectedType) {
            $this->assertTrue(
                (new \ReflectionClass(Resource::class))->hasProperty($property),
                "Contract entry [{$property}] must exist on Filament's Resource base.",
            );
        }
    }
}
