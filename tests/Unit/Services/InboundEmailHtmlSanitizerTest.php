<?php

namespace Tests\Unit\Services;

use App\Services\InboundEmailHtmlSanitizer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InboundEmailHtmlSanitizerTest extends TestCase
{
    use DatabaseTransactions;

    private InboundEmailHtmlSanitizer $sanitizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sanitizer = new InboundEmailHtmlSanitizer;
    }

    #[Test]
    public function it_returns_null_for_null_input(): void
    {
        $this->assertNull($this->sanitizer->sanitize(null));
    }

    #[Test]
    public function it_returns_null_for_empty_string_input(): void
    {
        $this->assertNull($this->sanitizer->sanitize(''));
        $this->assertNull($this->sanitizer->sanitize('   '));
    }

    #[Test]
    public function it_allows_safe_tags(): void
    {
        $html = '<p>This is a <strong>strong</strong> paragraph with <em>emphasis</em>.</p>'.
                '<ul><li>Item 1</li><li>Item 2</li></ul>'.
                '<table><thead><tr><th>Header</th></tr></thead><tbody><tr><td>Data</td></tr></tbody></table>';

        $sanitized = $this->sanitizer->sanitize($html);

        $this->assertStringContainsString('<p>This is a <strong>strong</strong> paragraph with <em>emphasis</em>.</p>', $sanitized);
        $this->assertStringContainsString('<ul><li>Item 1</li><li>Item 2</li></ul>', $sanitized);
        $this->assertStringContainsString('<table><thead><tr><th>Header</th></tr></thead><tbody><tr><td>Data</td></tr></tbody></table>', $sanitized);
    }

    #[Test]
    public function it_allows_blockquote_pre_and_formatting_tags(): void
    {
        $html = '<blockquote>Quote</blockquote><pre>Code</pre><s>strike</s><u>u</u><hr>';
        $sanitized = $this->sanitizer->sanitize($html);

        $this->assertStringContainsString('<blockquote>Quote</blockquote>', $sanitized);
        $this->assertStringContainsString('<pre>Code</pre>', $sanitized);
        $this->assertStringContainsString('<s>strike</s>', $sanitized);
        $this->assertStringContainsString('<u>u</u>', $sanitized);
        $this->assertStringContainsString('<hr>', $sanitized);
    }

    #[Test]
    public function it_removes_unsafe_tags_with_content(): void
    {
        $html = '<div>Safe content <script>alert("xss")</script> <style>body { color: red; }</style> <iframe src="https://evil.com"></iframe><link rel="stylesheet" href="style.css"><meta name="description" content="test"><svg>dangerous content</svg></div>';
        $sanitized = $this->sanitizer->sanitize($html);

        $this->assertStringContainsString('Safe content', $sanitized);
        $this->assertStringNotContainsString('<script', $sanitized);
        $this->assertStringNotContainsString('alert("xss")', $sanitized);
        $this->assertStringNotContainsString('<style', $sanitized);
        $this->assertStringNotContainsString('body { color: red; }', $sanitized);
        $this->assertStringNotContainsString('<iframe', $sanitized);
        $this->assertStringNotContainsString('<link', $sanitized);
        $this->assertStringNotContainsString('<meta', $sanitized);
        $this->assertStringNotContainsString('<svg', $sanitized);
        $this->assertStringNotContainsString('dangerous content', $sanitized);
    }

    #[Test]
    public function it_unwraps_unlisted_tags(): void
    {
        $html = '<section><article><p>Keep this content</p></article></section>';
        $sanitized = $this->sanitizer->sanitize($html);

        $this->assertStringContainsString('<p>Keep this content</p>', $sanitized);
        $this->assertStringNotContainsString('<section>', $sanitized);
        $this->assertStringNotContainsString('<article>', $sanitized);
    }

    #[Test]
    public function it_removes_disallowed_attributes(): void
    {
        $html = '<p class="text-red" style="color: red;" id="my-para" data-extra="value">Content</p>';
        $sanitized = $this->sanitizer->sanitize($html);

        $this->assertEquals('<p>Content</p>', $sanitized);
    }

    #[Test]
    public function it_removes_event_handlers(): void
    {
        $html = '<button onclick="alert(\'xss\')">Click me</button>';
        $sanitized = $this->sanitizer->sanitize($html);

        // button is in REMOVE_WITH_CONTENT_TAGS
        $this->assertNull($sanitized);

        $html = '<p onmouseover="evil()">Hover me</p>';
        $sanitized = $this->sanitizer->sanitize($html);
        $this->assertEquals('<p>Hover me</p>', $sanitized);
    }

    #[Test]
    public function it_sanitizes_links(): void
    {
        $html = '<a href="https://example.com" title="Example" class="link" onclick="evil()">Click here</a>';
        $sanitized = $this->sanitizer->sanitize($html);

        $this->assertStringContainsString('href="https://example.com"', $sanitized);
        $this->assertStringContainsString('title="Example"', $sanitized);
        $this->assertStringNotContainsString('class="link"', $sanitized);
        $this->assertStringNotContainsString('onclick="evil()"', $sanitized);
    }

    #[Test]
    public function it_enforces_safe_link_protocols(): void
    {
        $html = '<a href="javascript:alert(\'xss\')">Evil</a>'.
                '<a href="https://example.com">Good HTTPS</a>'.
                '<a href="http://example.com">Good HTTP</a>'.
                '<a href="mailto:test@example.com">Good Mailto</a>'.
                '<a href="ftp://example.com">Bad FTP</a>';

        $sanitized = $this->sanitizer->sanitize($html);

        $this->assertStringNotContainsString('href="javascript:alert(\'xss\')"', $sanitized);
        $this->assertStringNotContainsString('href="ftp://example.com"', $sanitized);
        $this->assertStringContainsString('href="https://example.com"', $sanitized);
        $this->assertStringContainsString('href="http://example.com"', $sanitized);
        $this->assertStringContainsString('href="mailto:test@example.com"', $sanitized);
    }

    #[Test]
    public function it_adds_security_attributes_to_links(): void
    {
        $html = '<a href="https://example.com">Link</a>';
        $sanitized = $this->sanitizer->sanitize($html);

        $this->assertStringContainsString('rel="noopener noreferrer nofollow"', $sanitized);
        $this->assertStringContainsString('target="_blank"', $sanitized);
    }

    #[Test]
    public function it_handles_utf8_characters(): void
    {
        $html = '<p>Hello, 世界! 🌍</p>';
        $sanitized = $this->sanitizer->sanitize($html);

        $this->assertStringContainsString('Hello, 世界! 🌍', $sanitized);
    }

    #[Test]
    public function it_preserves_plain_text(): void
    {
        $text = 'Just some plain text';
        $sanitized = $this->sanitizer->sanitize($text);

        $this->assertEquals('Just some plain text', $sanitized);
    }
}
