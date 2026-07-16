<?php

declare(strict_types=1);

namespace Tests\Feature\View\Components;

use App\Actions\GetMediaProcessingStatus;
use App\Data\StandardProcessingResponse;
use App\Livewire\ProcessingLogsViewer;
use Illuminate\Foundation\Testing\Concerns\InteractsWithViews;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FormAccessibilityTest extends TestCase
{
    use InteractsWithViews;

    protected function setUp(): void
    {
        parent::setUp();
        view()->share('errors', new ViewErrorBag);
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

    #[Test]
    public function toggle_component_renders_accessibility_attributes(): void
    {
        $view = $this->blade('<x-toggle label="Publish" hint="Makes it visible" required />');

        $view->assertSeeHtml('aria-labelledby="publish-label"');
        $view->assertSeeHtml('aria-describedby="publish-hint"');
        $view->assertSeeHtml('aria-required="true"');
        $view->assertSeeHtml('id="publish-hint"');
        $view->assertSee('Makes it visible');
    }

    #[Test]
    public function toggle_component_renders_livewire_accessibility_attributes(): void
    {
        $view = $this->blade('<x-toggle label="Publish" wire:model="active" hint="Makes it visible" />');

        $view->assertSeeHtml('aria-labelledby="active-label"');
        $view->assertSeeHtml('aria-describedby="active-hint"');
        $view->assertSeeHtml('id="active-hint"');
    }

    #[Test]
    public function form_components_render_role_alert_for_errors(): void
    {
        $errors = new ViewErrorBag;
        $errors->put('default', new MessageBag(['field' => ['Error message']]));
        view()->share('errors', $errors);

        $this->blade('<x-input wire:model="field" />')->assertSeeHtml('role="alert"');
        $this->blade('<x-textarea wire:model="field" />')->assertSeeHtml('role="alert"');
        $this->blade('<x-select wire:model="field" :options="[]" />')->assertSeeHtml('role="alert"');
        $this->blade('<x-checkbox wire:model="field" />')->assertSeeHtml('role="alert"');
        $this->blade('<x-toggle wire:model="field" />')->assertSeeHtml('role="alert"');
    }

    #[Test]
    public function form_components_render_loading_screen_reader_text(): void
    {
        $this->blade('<x-input wire:model="field" />')->assertSee('sr-only', false)->assertSee('Loading...');
        $this->blade('<x-textarea wire:model="field" />')->assertSee('sr-only', false)->assertSee('Loading...');
        $this->blade('<x-select wire:model="field" :options="[]" />')->assertSee('sr-only', false)->assertSee('Loading...');
        $this->blade('<x-toggle wire:model="field" />')->assertSee('sr-only', false)->assertSee('Loading...');
    }

    #[Test]
    public function form_controls_render_visible_keyboard_focus_colour_and_offset(): void
    {
        $expectedFocusClasses = 'focus-visible:ring-2 focus-visible:ring-cbc-teal focus-visible:ring-offset-2';

        $this->blade('<x-input wire:model="field" />')->assertSee($expectedFocusClasses, false);
        $this->blade('<x-textarea wire:model="field" />')->assertSee($expectedFocusClasses, false);
        $this->blade('<x-select wire:model="field" :options="[]" />')->assertSee($expectedFocusClasses, false);
        $this->blade('<x-checkbox wire:model="field" />')->assertSee($expectedFocusClasses, false);
    }

    #[Test]
    public function processing_logs_auto_refresh_checkbox_renders_visible_keyboard_focus_colour_and_offset(): void
    {
        $this->mock(GetMediaProcessingStatus::class, function ($mock): void {
            $mock
                ->shouldReceive('getWithLogs')
                ->once()
                ->with('processing-123', 20)
                ->andReturn(StandardProcessingResponse::notFound());
        });

        Livewire::test(ProcessingLogsViewer::class, ['processingId' => 'processing-123'])
            ->assertSeeHtml('class="rounded border-gray-300 text-cbc-teal focus:ring-cbc-teal focus-visible:ring-2 focus-visible:ring-cbc-teal focus-visible:ring-offset-2"');
    }
}
