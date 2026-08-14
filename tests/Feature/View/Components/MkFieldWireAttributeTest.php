<?php

declare(strict_types=1);

namespace Tests\Feature\View\Components;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * Regression for the UAT-caught bug: `<x-mk.field wire:model="...">` dropped
 * the `wire:*` attribute (it landed on the wrapper div where Livewire never
 * reads it), so the marketplace checkout's recipient inputs never bound —
 * the submit always failed with "The recipient name field is required"
 * despite the fields being filled. The fix forwards `wire:*` attributes to
 * the inner control element (input/select/textarea).
 */
final class MkFieldWireAttributeTest extends TestCase
{
    public function test_wire_model_lands_on_the_inner_input(): void
    {
        $html = Blade::render(
            '<x-mk.field label="Nama penerima" name="recipientName" type="text" wire:model="recipientName" />',
        );

        $this->assertStringContainsString(
            'wire:model="recipientName"',
            $html,
            'The wire:model attribute must be rendered on the inner input element.',
        );
    }

    public function test_wire_model_with_modifier_lands_on_the_inner_input(): void
    {
        $html = Blade::render(
            '<x-mk.field label="Kota" name="city" type="select" wire:model.live="city" />',
        );

        $this->assertStringContainsString(
            'wire:model.live="city"',
            $html,
            'A modified wire:model.live attribute must be forwarded to the inner control.',
        );
    }

    public function test_non_wire_attributes_still_compose_the_wrapper_class(): void
    {
        $html = Blade::render(
            '<x-mk.field label="Catatan" name="note" type="textarea" class="extra-caller-class" />',
        );

        $this->assertStringContainsString('extra-caller-class', $html);
        $this->assertStringNotContainsString('wire:', $html);
    }
}
