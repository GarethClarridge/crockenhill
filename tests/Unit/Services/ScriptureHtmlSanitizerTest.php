<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Scripture\ScriptureHtmlSanitizer;
use PHPUnit\Framework\TestCase;

class ScriptureHtmlSanitizerTest extends TestCase
{
    private ScriptureHtmlSanitizer $sanitizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sanitizer = new ScriptureHtmlSanitizer;
    }

    public function test_preserves_sup_for_verse_numbers(): void
    {
        $html = '<p><sup class="v">16</sup>For God so loved the world.</p>';
        $result = $this->sanitizer->sanitize($html);
        $this->assertNotNull($result);
        $this->assertStringContainsString('<sup', $result);
        $this->assertStringContainsString('16', $result);
    }

    public function test_preserves_span_with_class(): void
    {
        $html = '<span class="verse-num">3</span>Blessed are the poor in spirit.';
        $result = $this->sanitizer->sanitize($html);
        $this->assertNotNull($result);
        $this->assertStringContainsString('<span', $result);
        $this->assertStringContainsString('class="verse-num"', $result);
    }

    public function test_strips_script_tags(): void
    {
        $html = '<p>Safe text</p><script>alert("xss")</script>';
        $result = $this->sanitizer->sanitize($html);
        $this->assertNotNull($result);
        $this->assertStringNotContainsString('<script', $result);
        $this->assertStringNotContainsString('alert', $result);
    }

    public function test_strips_inline_event_handlers(): void
    {
        $html = '<p onclick="evil()">Text</p>';
        $result = $this->sanitizer->sanitize($html);
        $this->assertNotNull($result);
        $this->assertStringNotContainsString('onclick', $result);
        $this->assertStringContainsString('Text', $result);
    }

    public function test_strips_style_tags(): void
    {
        $html = '<style>body { display: none }</style><p>Passage</p>';
        $result = $this->sanitizer->sanitize($html);
        $this->assertNotNull($result);
        $this->assertStringNotContainsString('<style', $result);
        $this->assertStringContainsString('Passage', $result);
    }

    public function test_strips_iframe(): void
    {
        $html = '<p>Text</p><iframe src="https://evil.example.com"></iframe>';
        $result = $this->sanitizer->sanitize($html);
        $this->assertNotNull($result);
        $this->assertStringNotContainsString('<iframe', $result);
    }

    public function test_returns_null_for_null_input(): void
    {
        $this->assertNull($this->sanitizer->sanitize(null));
    }

    public function test_returns_null_for_empty_input(): void
    {
        $this->assertNull($this->sanitizer->sanitize(''));
        $this->assertNull($this->sanitizer->sanitize('   '));
    }

    public function test_preserves_basic_paragraph_text(): void
    {
        $html = '<p>In the beginning God created the heavens and the earth.</p>';
        $result = $this->sanitizer->sanitize($html);
        $this->assertNotNull($result);
        $this->assertStringContainsString('In the beginning', $result);
    }

    public function test_strips_disallowed_attribute_from_sup(): void
    {
        $html = '<sup onclick="evil()">1</sup>';
        $result = $this->sanitizer->sanitize($html);
        $this->assertNotNull($result);
        $this->assertStringNotContainsString('onclick', $result);
        $this->assertStringContainsString('<sup', $result);
    }
}
