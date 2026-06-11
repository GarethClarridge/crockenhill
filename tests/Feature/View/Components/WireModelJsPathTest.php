<?php

declare(strict_types=1);

namespace Tests\Feature\View\Components;

use Illuminate\Foundation\Testing\Concerns\InteractsWithViews;
use Illuminate\Support\ViewErrorBag;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Numeric wire:model segments (form.items.0.title) are invalid JavaScript property
 * access, so any $wire expression rendered into Alpine must use bracket notation
 * (form.items[0].title) or the browser throws "Unexpected number" on every input.
 */
class WireModelJsPathTest extends TestCase
{
    use InteractsWithViews;

    protected function setUp(): void
    {
        parent::setUp();
        view()->share('errors', new ViewErrorBag);
    }

    #[Test]
    public function input_watch_expression_bracketises_numeric_path_segments(): void
    {
        $view = $this->blade('<x-input wire:model="form.items.0.title" />');

        $view->assertSee("\$watch('\$wire.form.items[0].title'", false);
        $view->assertDontSee('$wire.form.items.0.title', false);
    }

    #[Test]
    public function input_clearable_toggle_bracketises_numeric_path_segments(): void
    {
        $view = $this->blade('<x-input wire:model="form.items.0.title" clearable />');

        $view->assertSee('x-show="$wire.form.items[0].title"', false);
        $view->assertDontSee('$wire.form.items.0.title', false);
    }

    #[Test]
    public function textarea_watch_expression_bracketises_numeric_path_segments(): void
    {
        $view = $this->blade('<x-textarea wire:model="form.items.10.notes" />');

        $view->assertSee("\$watch('\$wire.form.items[10].notes'", false);
        $view->assertDontSee('$wire.form.items.10.notes', false);
    }

    #[Test]
    public function trailing_numeric_segment_is_bracketised(): void
    {
        $view = $this->blade('<x-input wire:model="form.readings.2" />');

        $view->assertSee("\$watch('\$wire.form.readings[2]'", false);
    }

    #[Test]
    public function plain_paths_are_left_unchanged(): void
    {
        $view = $this->blade('<x-input wire:model="form.title" />');

        $view->assertSee("\$watch('\$wire.form.title'", false);
    }

    #[Test]
    public function string_contexts_keep_the_dotted_path(): void
    {
        $view = $this->blade('<x-input wire:model="form.items.0.title" clearable />');

        $view->assertSee('wire:target="form.items.0.title"', false);
        $view->assertSee("\$set('form.items.0.title', '')", false);
    }
}
