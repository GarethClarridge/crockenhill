<?php

declare(strict_types=1);

namespace Tests\Feature\Components;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class SearchShortcutTest extends TestCase
{
    /** @test */
    public function it_renders_slash_to_focus_directive_when_magnifying_glass_icon_is_present(): void
    {
        $rendered = Blade::render('<x-input icon="magnifying-glass" />');

        $this->assertStringContainsString('@keydown.window.slash="if (![\'INPUT\', \'TEXTAREA\', \'SELECT\'].includes(document.activeElement.tagName) && !document.activeElement.isContentEditable) { $event.preventDefault(); $refs.input.focus(); }"', $rendered);
    }

    /** @test */
    public function it_does_not_render_slash_to_focus_directive_when_icon_is_not_magnifying_glass(): void
    {
        $rendered = Blade::render('<x-input icon="envelope" />');

        $this->assertStringNotContainsString('@keydown.window.prevent.slash', $rendered);
    }

    /** @test */
    public function it_does_not_render_slash_to_focus_directive_when_no_icon_is_present(): void
    {
        $rendered = Blade::render('<x-input />');

        $this->assertStringNotContainsString('@keydown.window.prevent.slash', $rendered);
    }
}
