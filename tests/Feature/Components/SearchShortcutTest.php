<?php

declare(strict_types=1);

namespace Tests\Feature\Components;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class SearchShortcutTest extends TestCase
{
    /** @test */
    public function it_renders_slash_to_focus_directive_when_shortcut_slash_is_provided(): void
    {
        $rendered = Blade::render('<x-input shortcut="slash" />');

        $this->assertStringContainsString('@keydown.window.slash="if (![\'INPUT\', \'TEXTAREA\', \'SELECT\'].includes(document.activeElement.tagName) && !document.activeElement.isContentEditable) { $event.preventDefault(); $refs.input.focus(); }"', $rendered);
    }

    /** @test */
    public function it_does_not_render_slash_to_focus_directive_when_shortcut_is_not_provided(): void
    {
        $rendered = Blade::render('<x-input icon="magnifying-glass" />');

        $this->assertStringNotContainsString('@keydown.window.slash', $rendered);
    }

    /** @test */
    public function it_does_not_render_slash_to_focus_directive_when_other_icon_is_present(): void
    {
        $rendered = Blade::render('<x-input icon="envelope" />');

        $this->assertStringNotContainsString('@keydown.window.slash', $rendered);
    }
}
