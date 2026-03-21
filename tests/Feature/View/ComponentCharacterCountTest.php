<?php

namespace Tests\Feature\View;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ComponentCharacterCountTest extends TestCase
{
    #[Test]
    public function it_renders_input_with_character_count_warning_colors()
    {
        $view = $this->withViewErrors([])->blade(
            '<x-input :maxlength="120" />'
        );

        $view->assertSee('text-amber-600 font-medium');
        $view->assertSee('text-red-600 font-bold');
    }

    #[Test]
    public function it_renders_textarea_with_character_count_warning_colors()
    {
        $view = $this->withViewErrors([])->blade(
            '<x-textarea :maxlength="500" />'
        );

        $view->assertSee('text-amber-600 font-medium');
        $view->assertSee('text-red-600 font-bold');
    }

    #[Test]
    public function it_renders_clear_button_with_transition_and_reset()
    {
        $view = $this->withViewErrors([])->blade(
            '<x-input clearable wire:model="test" />',
            ['test' => 'some value']
        );

        $view->assertSee('x-transition');
        $view->assertSee('@click="$refs.input.value = \'\'; count = 0"', false);
    }
}
