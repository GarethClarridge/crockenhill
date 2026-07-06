<?php

declare(strict_types=1);

namespace Tests\Feature\Components;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdminFormShellTest extends TestCase
{
    #[Test]
    public function save_hotkey_uses_the_explicit_livewire_action_contract(): void
    {
        $rendered = Blade::render(<<<'BLADE'
            <x-admin.form-shell title="Edit page" save-action="save">
                <x-slot:actions>
                    <x-form-button variant="primary" wire:click="save">Save</x-form-button>
                </x-slot:actions>

                <p>Form body</p>
            </x-admin.form-shell>
            BLADE);

        $this->assertStringContainsString('saveAction:', $rendered);
        $this->assertStringContainsString('this.$wire.call(this.saveAction)', $rendered);
        $this->assertStringContainsString('@keydown.window.ctrl.s.prevent="save()"', $rendered);
        $this->assertStringContainsString('@keydown.window.cmd.s.prevent="save()"', $rendered);
        $this->assertStringContainsString('wire:target="save"', $rendered);
        $this->assertStringNotContainsString('querySelector', $rendered);
        $this->assertStringNotContainsString('data-form-action', $rendered);
    }

    #[Test]
    public function unsaved_changes_guard_uses_the_livewire_dirty_api_when_a_save_action_exists(): void
    {
        $rendered = Blade::render(<<<'BLADE'
            <x-admin.form-shell title="Edit page" save-action="save">
                <x-slot:actions>
                    <x-form-button variant="primary" wire:click="save">Save</x-form-button>
                </x-slot:actions>

                <p>Form body</p>
            </x-admin.form-shell>
            BLADE);

        // Covers SPA navigation (breadcrumbs, header, in-form links) and hard unloads,
        // using the real Livewire dirty API rather than a non-existent $wire property.
        $this->assertStringContainsString('livewire:navigate.window', $rendered);
        $this->assertStringContainsString('beforeunload', $rendered);
        $this->assertStringContainsString('$wire.$dirty()', $rendered);
    }

    #[Test]
    public function unsaved_changes_guard_is_not_registered_without_an_explicit_save_action(): void
    {
        $rendered = Blade::render(<<<'BLADE'
            <x-admin.form-shell title="Read only">
                <p>Read-only body</p>
            </x-admin.form-shell>
            BLADE);

        // Read-only shells (e.g. the media upload form) must not attach a dirty guard.
        $this->assertStringNotContainsString('livewire:navigate.window', $rendered);
        $this->assertStringNotContainsString('beforeunload', $rendered);
        $this->assertStringNotContainsString('$dirty()', $rendered);
    }

    #[Test]
    public function save_hotkey_is_not_registered_without_an_explicit_save_action(): void
    {
        $rendered = Blade::render(<<<'BLADE'
            <x-admin.form-shell title="Read only">
                <p>Read-only body</p>
            </x-admin.form-shell>
            BLADE);

        $this->assertStringNotContainsString('this.$wire.call', $rendered);
        $this->assertStringNotContainsString('@keydown.window.ctrl.s.prevent', $rendered);
        $this->assertStringNotContainsString('@keydown.window.cmd.s.prevent', $rendered);
        $this->assertStringNotContainsString('wire:target=', $rendered);
    }

    #[Test]
    public function admin_views_do_not_use_legacy_entangle_or_save_button_markers(): void
    {
        foreach (File::allFiles(resource_path('views/livewire/admin')) as $file) {
            $contents = File::get($file->getPathname());
            $relativePath = $file->getRelativePathname();

            $this->assertStringNotContainsString('$wire.entangle', $contents, $relativePath);
            $this->assertStringNotContainsString('@entangle', $contents, $relativePath);
            $this->assertStringNotContainsString('data-form-action', $contents, $relativePath);
        }
    }
}
