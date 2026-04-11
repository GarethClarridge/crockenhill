<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PageArea;
use App\Livewire\Admin\Pages\EditPage;
use App\Models\Page;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PageEditorPreviewSecurityTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'is_admin' => true,
            'email_verified_at' => now(),
        ]);
    }

    #[Test]
    public function page_editor_strips_script_tags_from_markdown_when_saved(): void
    {
        $this->actingAs($this->admin);

        $page = Page::factory()->create([
            'area' => PageArea::Church->value,
            'slug' => 'preview-script-tag',
            'heading' => 'Preview Script Tag',
            'markdown' => '',
            'description' => 'Preview security test',
        ]);

        Livewire::test(EditPage::class, ['page' => $page])
            ->set('form.markdown', '<script>alert("xss")</script>'."\n\n".'Safe content.')
            ->call('save');

        $page->refresh();

        $this->assertStringContainsString('Safe content.', $page->body);
        $this->assertStringNotContainsString('<script>', $page->body);
        $this->assertStringNotContainsString('alert("xss")', $page->body);
    }

    #[Test]
    public function page_editor_blocks_javascript_links_from_markdown_when_saved(): void
    {
        $this->actingAs($this->admin);

        $page = Page::factory()->create([
            'area' => PageArea::Church->value,
            'slug' => 'preview-javascript-link',
            'heading' => 'Preview Javascript Link',
            'markdown' => '',
            'description' => 'Preview link security test',
        ]);

        Livewire::test(EditPage::class, ['page' => $page])
            ->set('form.markdown', '[Click me](javascript:alert("link"))')
            ->call('save');

        $page->refresh();

        $this->assertStringContainsString('Click me', $page->body);
        $this->assertStringNotContainsString('javascript:', $page->body);
    }
}
