<?php

namespace Tests\Unit\Services;

use App\Services\SafeMarkdownRenderer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SafeMarkdownRendererTest extends TestCase
{
    use DatabaseTransactions;

    private SafeMarkdownRenderer $renderer;

    protected function setUp(): void
    {
        parent::setUp();
        // Clear config to ensure default options are used
        Config::set('markdown.safe_options', null);
        $this->renderer = new SafeMarkdownRenderer;
    }

    #[Test]
    public function it_converts_markdown_to_html(): void
    {
        $markdown = '# Heading 1'."\n\n".'**Bold Text** and *Italic Text*';
        $html = $this->renderer->convert($markdown);

        $this->assertStringContainsString('<h1>Heading 1</h1>', $html);
        $this->assertStringContainsString('<strong>Bold Text</strong>', $html);
        $this->assertStringContainsString('<em>Italic Text</em>', $html);
    }

    #[Test]
    public function it_strips_html_tags_by_default(): void
    {
        // Inline HTML tags are stripped but content is kept (and escaped if necessary)
        $markdown = 'Before <script>alert("xss")</script> After';
        $html = $this->renderer->convert($markdown);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('Before alert(&quot;xss&quot;) After', $html);

        // Block-level HTML tags and their content are stripped entirely by the 'strip' option
        $markdown = "<div>\nSafe content\n</div>";
        $html = $this->renderer->convert($markdown);

        $this->assertStringNotContainsString('Safe content', $html);
        $this->assertStringNotContainsString('<div>', $html);
    }

    #[Test]
    public function it_blocks_unsafe_links_by_default(): void
    {
        $markdown = '[Click Me](javascript:alert("xss"))'."\n".'[Safe Link](https://example.com)';
        $html = $this->renderer->convert($markdown);

        $this->assertStringNotContainsString('javascript:alert("xss")', $html);
        $this->assertStringContainsString('https://example.com', $html);
    }

    #[Test]
    public function it_respects_custom_configuration_to_allow_html(): void
    {
        Config::set('markdown.safe_options', [
            'html_input' => 'allow',
        ]);

        $renderer = new SafeMarkdownRenderer;

        $markdown = '<div>Safe content</div>';
        $html = $renderer->convert($markdown);

        $this->assertStringContainsString('<div>Safe content</div>', $html);
    }

    #[Test]
    public function it_handles_null_input(): void
    {
        $this->assertSame('', $this->renderer->convert(null));
    }

    #[Test]
    public function it_handles_empty_string_input(): void
    {
        $this->assertSame('', $this->renderer->convert(''));
    }

    #[Test]
    public function it_handles_complex_markdown_structures(): void
    {
        $markdown = "- Item 1\n- Item 2\n\n1. First\n2. Second";
        $html = $this->renderer->convert($markdown);

        $this->assertStringContainsString('<ul>', $html);
        $this->assertStringContainsString('<li>Item 1</li>', $html);
        $this->assertStringContainsString('<ol>', $html);
        $this->assertStringContainsString('<li>First</li>', $html);
    }

    #[Test]
    public function it_escapes_unsafe_characters_within_code_blocks(): void
    {
        $markdown = "```html\n<script>alert(1)</script>\n```";
        $html = $this->renderer->convert($markdown);

        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }
}
