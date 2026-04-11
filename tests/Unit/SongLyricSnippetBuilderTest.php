<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\SongLyricSnippetBuilder;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class SongLyricSnippetBuilderTest extends TestCase
{
    private SongLyricSnippetBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = new SongLyricSnippetBuilder;
    }

    private function tokens(string ...$tokens): Collection
    {
        return collect($tokens);
    }

    // ── hasLyricMatch ─────────────────────────────────────────────────────

    #[Test]
    public function returns_true_when_token_appears_in_lyrics(): void
    {
        $this->assertTrue($this->builder->hasLyricMatch("Amazing grace how sweet the sound\nThat saved a wretch like me", $this->tokens('grace')));
    }

    #[Test]
    public function returns_false_when_token_not_in_lyrics(): void
    {
        $this->assertFalse($this->builder->hasLyricMatch('Amazing grace how sweet the sound', $this->tokens('hallelujah')));
    }

    #[Test]
    public function returns_false_for_empty_tokens(): void
    {
        $this->assertFalse($this->builder->hasLyricMatch('Amazing grace', $this->tokens()));
    }

    #[Test]
    public function returns_false_for_empty_lyrics(): void
    {
        $this->assertFalse($this->builder->hasLyricMatch('', $this->tokens('grace')));
    }

    #[Test]
    public function requires_all_tokens_to_match(): void
    {
        $this->assertFalse($this->builder->hasLyricMatch('Amazing grace', $this->tokens('grace', 'hallelujah')));
        $this->assertTrue($this->builder->hasLyricMatch('Amazing grace hallelujah', $this->tokens('grace', 'hallelujah')));
    }

    // ── buildSnippets ─────────────────────────────────────────────────────

    #[Test]
    public function extracts_matching_lines(): void
    {
        $lyrics = "Amazing grace how sweet the sound\nThat saved a wretch like me";
        $snippets = $this->builder->buildSnippets($lyrics, $this->tokens('grace'));

        $this->assertCount(1, $snippets);
        $this->assertStringContainsString('grace', $snippets[0]);
    }

    #[Test]
    public function highlights_matched_token_with_mark_tag(): void
    {
        $lyrics = 'Amazing grace how sweet the sound';
        $snippets = $this->builder->buildSnippets($lyrics, $this->tokens('grace'));

        $this->assertStringContainsString('<mark>', $snippets[0]);
        $this->assertStringContainsString('</mark>', $snippets[0]);
    }

    #[Test]
    public function highlight_is_case_insensitive(): void
    {
        $lyrics = 'Amazing Grace how sweet the sound';
        $snippets = $this->builder->buildSnippets($lyrics, $this->tokens('grace'));

        $this->assertStringContainsString('<mark>Grace</mark>', $snippets[0]);
    }

    #[Test]
    public function skips_blank_lines(): void
    {
        $lyrics = "First verse line\n\nSecond verse line with grace";
        $snippets = $this->builder->buildSnippets($lyrics, $this->tokens('grace'));

        $this->assertCount(1, $snippets);
    }

    #[Test]
    public function deduplicates_identical_lines_case_insensitively(): void
    {
        $lyrics = "Amazing grace\namazing GRACE\nAMAZING GRACE";
        $snippets = $this->builder->buildSnippets($lyrics, $this->tokens('grace'));

        $this->assertCount(1, $snippets);
    }

    #[Test]
    public function caps_at_five_snippets(): void
    {
        $lines = [];
        for ($i = 1; $i <= 10; $i++) {
            $lines[] = "Grace line number {$i}";
        }

        $snippets = $this->builder->buildSnippets(implode("\n", $lines), $this->tokens('grace'));

        $this->assertCount(5, $snippets);
    }

    #[Test]
    public function returns_empty_array_for_empty_tokens(): void
    {
        $this->assertSame([], $this->builder->buildSnippets('some lyrics', $this->tokens()));
    }

    #[Test]
    public function returns_empty_array_for_empty_lyrics(): void
    {
        $this->assertSame([], $this->builder->buildSnippets('', $this->tokens('grace')));
    }

    #[Test]
    public function html_escapes_line_before_highlighting(): void
    {
        $lyrics = 'Line with <script>alert("xss")</script> and grace';
        $snippets = $this->builder->buildSnippets($lyrics, $this->tokens('grace'));

        $this->assertStringNotContainsString('<script>', $snippets[0]);
        $this->assertStringContainsString('&lt;script&gt;', $snippets[0]);
    }

    #[Test]
    public function only_returns_lines_containing_a_token(): void
    {
        $lyrics = "This line has nothing\nThis line has grace\nAnother nothing line";
        $snippets = $this->builder->buildSnippets($lyrics, $this->tokens('grace'));

        $this->assertCount(1, $snippets);
        $this->assertStringContainsString('grace', strip_tags($snippets[0]));
    }
}
