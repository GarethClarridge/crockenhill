<?php

declare(strict_types=1);

namespace Tests\Feature\View\Components;

use Tests\TestCase;
use Illuminate\Foundation\Testing\Concerns\InteractsWithViews;
use Illuminate\Support\ViewErrorBag;
use PHPUnit\Framework\Attributes\Test;

class FormAccessibilityTest extends TestCase
{
    use InteractsWithViews;

    protected function setUp(): void
    {
        parent::setUp();
        view()->share('errors', new ViewErrorBag());
    }

    #[Test]
    public function input_component_renders_required_attributes(): void
    {
        $view = $this->blade('<x-input label="Full Name" required />');

        $view->assertSee('required');
        $view->assertSeeHtml('aria-required="true"');
    }

    #[Test]
    public function input_component_renders_contextual_clear_label_from_label(): void
    {
        $view = $this->blade('<x-input label="Search Term" clearable wire:model="search" />');

        $view->assertSee('aria-label="Clear Search Term"', false);
        $view->assertSee('title="Clear Search Term"', false);
    }

    #[Test]
    public function input_component_renders_contextual_clear_label_from_placeholder(): void
    {
        $view = $this->blade('<x-input placeholder="Find sermons..." clearable wire:model="search" />');

        $view->assertSee('aria-label="Clear Find sermons..."', false);
        $view->assertSee('title="Clear Find sermons..."', false);
    }

    #[Test]
    public function textarea_component_renders_required_attributes(): void
    {
        $view = $this->blade('<x-textarea label="Description" required />');

        $view->assertSee('required');
        $view->assertSeeHtml('aria-required="true"');
    }

    #[Test]
    public function select_component_renders_required_attributes(): void
    {
        $view = $this->blade('<x-select label="Category" required :options="[]" />');

        $view->assertSee('required');
        $view->assertSeeHtml('aria-required="true"');
    }

    #[Test]
    public function checkbox_component_renders_required_attributes(): void
    {
        $view = $this->blade('<x-checkbox label="Agree to terms" required />');

        $view->assertSee('required');
        $view->assertSeeHtml('aria-required="true"');
    }
}
