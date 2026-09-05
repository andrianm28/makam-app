<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public\Home;

use App\Livewire\Public\Home\PlotAvailabilityPreview;
use Livewire\Component;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

final class PlotAvailabilityPreviewNeverMutatesTest extends TestCase
{
    /**
     * docs/superpowers/specs/2026-09-05-marketing-hero-plot-preview-design.md
     * §7/§9 — this component is read-only by construction: it must declare
     * no public method beyond render(), so no wire:click/wire:model binding
     * can ever be added to call into a mutating action without this test
     * failing first. Inherited Livewire framework methods (mount, boot,
     * updated, etc. declared on the base Component class) are excluded —
     * only methods DECLARED on PlotAvailabilityPreview itself count.
     */
    public function test_declares_no_public_method_other_than_render(): void
    {
        $ownClass = new ReflectionClass(PlotAvailabilityPreview::class);
        $baseClass = new ReflectionClass(Component::class);

        $baseMethodNames = array_map(
            static fn (ReflectionMethod $method): string => $method->getName(),
            $baseClass->getMethods(ReflectionMethod::IS_PUBLIC),
        );

        $declaredPublicMethods = array_filter(
            $ownClass->getMethods(ReflectionMethod::IS_PUBLIC),
            static fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === PlotAvailabilityPreview::class
                && ! in_array($method->getName(), $baseMethodNames, true),
        );

        $names = array_map(static fn (ReflectionMethod $method): string => $method->getName(), $declaredPublicMethods);

        $this->assertSame(
            ['render'],
            $names,
            'PlotAvailabilityPreview must declare no public method other than render() — '.
            'any additional public method is a potential wire:click/wire:model target on a read-only surface.',
        );
    }
}
