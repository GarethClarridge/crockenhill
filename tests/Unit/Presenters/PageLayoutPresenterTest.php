<?php

declare(strict_types=1);

namespace Tests\Unit\Presenters;

use App\Models\Page;
use App\Presenters\PageLayoutPresenter;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PageLayoutPresenterTest extends TestCase
{
    private PageLayoutPresenter $presenter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->presenter = $this->app->make(PageLayoutPresenter::class);
    }

    #[Test]
    public function it_renders_markdown_content_when_markdown_field_is_filled(): void
    {
        $page = Page::factory()->make([
            'markdown' => '# Heading 1',
            'body' => 'Original body text',
        ]);

        $result = $this->presenter->renderContent($page);

        $this->assertStringContainsString('<h1>Heading 1</h1>', $result);
        $this->assertStringNotContainsString('Original body text', $result);
    }

    #[Test]
    public function it_renders_decoded_body_content_when_markdown_field_is_null(): void
    {
        $page = Page::factory()->make([
            'markdown' => null,
            'body' => 'Before &amp; After',
        ]);

        $result = $this->presenter->renderContent($page);

        $this->assertSame('Before & After', $result);
    }

    #[Test]
    public function it_renders_decoded_body_content_when_markdown_field_is_empty_string(): void
    {
        $page = Page::factory()->make([
            'markdown' => '',
            'body' => 'Before &amp; After',
        ]);

        $result = $this->presenter->renderContent($page);

        $this->assertSame('Before & After', $result);
    }

    #[Test]
    public function it_renders_empty_string_when_both_markdown_and_body_are_empty(): void
    {
        $page = Page::factory()->make([
            'markdown' => '',
            'body' => '',
        ]);

        $result = $this->presenter->renderContent($page);

        $this->assertSame('', $result);
    }
}
