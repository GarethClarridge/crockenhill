<?php

namespace Tests\Feature\View\Components;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FormComponentsTest extends TestCase
{
    #[Test]
    public function input_component_renders_with_maxlength_and_counter(): void
    {
        $view = $this->withViewErrors([])->blade(
            '<x-input label="Title" maxlength="100" />'
        );

        $view->assertSee('Title');
        $view->assertSee('maxlength="100"', false);
        $view->assertSee('x-data="{ count: 0, limit: 100 }"', false);
        $view->assertSee('<span x-text="count"></span> / 100', false);
    }

    #[Test]
    public function input_component_applies_threshold_classes(): void
    {
        $view = $this->withViewErrors([])->blade(
            '<x-input label="Title" maxlength="100" />'
        );

        $view->assertSee(':class="{', false);
        $view->assertSee('\'text-red-600 font-bold\': limit && count >= limit', false);
        $view->assertSee('\'text-amber-600 font-medium\': limit && count >= (limit * 0.9) && count < limit', false);
    }

    #[Test]
    public function textarea_component_renders_with_maxlength_and_counter(): void
    {
        $view = $this->withViewErrors([])->blade(
            '<x-textarea label="Bio" maxlength="500" />'
        );

        $view->assertSee('Bio');
        $view->assertSee('maxlength="500"', false);
        $view->assertSee('x-data="{ count: 0, limit: 500 }"', false);
        $view->assertSee('<span x-text="count"></span> / 500', false);
    }

    #[Test]
    public function textarea_component_applies_threshold_classes(): void
    {
        $view = $this->withViewErrors([])->blade(
            '<x-textarea label="Bio" maxlength="500" />'
        );

        $view->assertSee(':class="{', false);
        $view->assertSee('\'text-red-600 font-bold\': limit && count >= limit', false);
        $view->assertSee('\'text-amber-600 font-medium\': limit && count >= (limit * 0.9) && count < limit', false);
    }

    #[Test]
    public function input_component_renders_clear_button_when_clearable_and_has_model(): void
    {
        $view = $this->withViewErrors([])->blade(
            '<x-input label="Search" wire:model="search" clearable="true" />'
        );

        $view->assertSee('aria-label="Clear input"', false);
        $view->assertSee('wire:click="$set(\'search\', \'\')"', false);
        $view->assertSee('$refs.input.value = \'\'; count = 0', false);
    }
}
