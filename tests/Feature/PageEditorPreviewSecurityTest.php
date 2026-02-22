<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PageArea;
use App\Models\Page;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PageEditorPreviewSecurityTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function legacy_page_editor_preview_strips_script_tags_from_markdown(): void
    {
        $page = Page::factory()->create([
            'area' => PageArea::CHURCH->value,
            'slug' => 'legacy-preview-script-tag',
            'heading' => 'Legacy Preview Script Tag',
            'markdown' => '<script>alert("legacy-preview-xss")</script>'."\n\n".'Safe preview content.',
            'body' => '',
            'description' => 'Legacy preview security test',
            'admin' => 'yes',
        ]);

        $view = $this->withViewErrors([])->view('pages.edit', ['page' => $page]);

        $view->assertSee('Safe preview content.');
        $view->assertDontSee('<script>alert("legacy-preview-xss")</script>', false);
    }

    #[Test]
    public function legacy_page_editor_preview_blocks_javascript_links_from_markdown(): void
    {
        $page = Page::factory()->create([
            'area' => PageArea::CHURCH->value,
            'slug' => 'legacy-preview-javascript-link',
            'heading' => 'Legacy Preview Javascript Link',
            'markdown' => '[Click me](javascript:alert("legacy-preview-link"))',
            'body' => '',
            'description' => 'Legacy preview link security test',
            'admin' => 'yes',
        ]);

        $view = $this->withViewErrors([])->view('pages.edit', ['page' => $page]);

        $view->assertSee('Click me');
        $view->assertDontSee('javascript:alert("legacy-preview-link")', false);
    }
}
