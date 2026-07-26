<?php

declare(strict_types=1);

namespace Tests\Unit\Presenters;

use App\Models\Page;
use App\Presenters\PageLayoutPresenter;
use App\Services\SafeMarkdownRenderer;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PageLayoutPresenterTest extends TestCase
{
    private SafeMarkdownRenderer&MockInterface $markdownRenderer;

    private PageLayoutPresenter $presenter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->markdownRenderer = Mockery::mock(SafeMarkdownRenderer::class);
        $this->presenter = new PageLayoutPresenter($this->markdownRenderer);
    }

    #[Test]
    public function it_renders_converted_markdown_when_markdown_is_filled(): void
    {
        // Arrange
        $markdownContent = "# Hello World\nThis is a markdown content.";
        $expectedHtml = "<h1>Hello World</h1>\n<p>This is a markdown content.</p>\n";

        $page = Page::factory()->make([
            'markdown' => $markdownContent,
            'body' => 'Raw HTML body content.',
        ]);

        $this->markdownRenderer
            ->shouldReceive('convert')
            ->once()
            ->with($markdownContent)
            ->andReturn($expectedHtml);

        // Act
        $result = $this->presenter->renderContent($page);

        // Assert
        $this->assertSame($expectedHtml, $result);
    }

    #[Test]
    public function it_renders_decoded_html_body_when_markdown_is_null(): void
    {
        // Arrange
        $encodedHtml = 'Some HTML &lt;strong&gt;bold&lt;/strong&gt; text.';
        $expectedDecodedHtml = 'Some HTML <strong>bold</strong> text.';

        $page = Page::factory()->make([
            'markdown' => null,
            'body' => $encodedHtml,
        ]);

        $this->markdownRenderer->shouldNotReceive('convert');

        // Act
        $result = $this->presenter->renderContent($page);

        // Assert
        $this->assertSame($expectedDecodedHtml, $result);
    }

    #[Test]
    public function it_renders_decoded_html_body_when_markdown_is_empty_string(): void
    {
        // Arrange
        $encodedHtml = 'Some HTML &lt;strong&gt;bold&lt;/strong&gt; text.';
        $expectedDecodedHtml = 'Some HTML <strong>bold</strong> text.';

        $page = Page::factory()->make([
            'markdown' => '',
            'body' => $encodedHtml,
        ]);

        $this->markdownRenderer->shouldNotReceive('convert');

        // Act
        $result = $this->presenter->renderContent($page);

        // Assert
        $this->assertSame($expectedDecodedHtml, $result);
    }
}
