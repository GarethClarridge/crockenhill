<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Song\SongLyricSnippetBuilder;
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

    #[Test]
    public function has_lyric_match_returns_true_when_tokens_are_present(): void
    {
        $lyrics = "Amazing grace how sweet the sound\nThat saved a wretch like me";
        $tokens = collect(['grace', 'saved']);

        $this->assertTrue($this->builder->hasLyricMatch($lyrics, $tokens));
    }

    #[Test]
    public function has_lyric_match_returns_false_when_any_token_is_missing(): void
    {
        $lyrics = 'Amazing grace how sweet the sound';
        $tokens = collect(['grace', 'notpresent']);

        $this->assertFalse($this->builder->hasLyricMatch($lyrics, $tokens));
    }

    #[Test]
    public function has_lyric_match_is_case_insensitive(): void
    {
        $lyrics = 'AMAZING GRACE';
        $tokens = collect(['amazing', 'grace']);

        $this->assertTrue($this->builder->hasLyricMatch($lyrics, $tokens));
    }

    #[Test]
    public function has_lyric_match_handles_empty_inputs(): void
    {
        $this->assertFalse($this->builder->hasLyricMatch('', collect(['grace'])));
        $this->assertFalse($this->builder->hasLyricMatch('Amazing grace', collect([])));
    }

    #[Test]
    public function build_snippets_returns_matching_lines_with_highlights(): void
    {
        $lyrics = "Line one\nAmazing grace\nLine three\nHow sweet the sound";
        $tokens = collect(['grace', 'sweet']);

        $snippets = $this->builder->buildSnippets($lyrics, $tokens);

        $this->assertCount(2, $snippets);
        $this->assertEquals('Amazing <mark>grace</mark>', $snippets[0]);
        $this->assertEquals('How <mark>sweet</mark> the sound', $snippets[1]);
    }

    #[Test]
    public function build_snippets_limits_to_max_snippets(): void
    {
        $lyrics = "Match 1\nMatch 2\nMatch 3\nMatch 4\nMatch 5\nMatch 6";
        $tokens = collect(['Match']);

        $snippets = $this->builder->buildSnippets($lyrics, $tokens);

        $this->assertCount(5, $snippets);
    }

    #[Test]
    public function build_snippets_skips_blank_lines(): void
    {
        $lyrics = "Match 1\n\nMatch 2";
        $tokens = collect(['Match']);

        $snippets = $this->builder->buildSnippets($lyrics, $tokens);

        $this->assertCount(2, $snippets);
    }

    #[Test]
    public function build_snippets_deduplicates_lines_case_insensitively(): void
    {
        $lyrics = "Amazing grace\nAMAZING GRACE\nAmazing Grace";
        $tokens = collect(['grace']);

        $snippets = $this->builder->buildSnippets($lyrics, $tokens);

        $this->assertCount(1, $snippets);
    }

    #[Test]
    public function build_snippets_escapes_html_and_highlights(): void
    {
        $lyrics = "Grace & Peace\n<script>alert('xss')</script>";
        $tokens = collect(['&', 'alert']);

        $snippets = $this->builder->buildSnippets($lyrics, $tokens);

        $this->assertEquals('Grace <mark>&amp;</mark> Peace', $snippets[0]);
        $this->assertEquals('&lt;script&gt;<mark>alert</mark>(&#039;xss&#039;)&lt;/script&gt;', $snippets[1]);
    }

    #[Test]
    public function highlight_handles_overlapping_or_adjacent_tokens(): void
    {
        $lyrics = 'Amazing grace';
        // 'grace' and 'race' overlap
        $tokens = collect(['grace', 'race']);

        $snippets = $this->builder->buildSnippets($lyrics, $tokens);

        $this->assertEquals('Amazing <mark>grace</mark>', $snippets[0]);
    }

    #[Test]
    public function highlight_handles_multiple_tokens_in_one_line(): void
    {
        $lyrics = 'The grace of God is amazing';
        $tokens = collect(['grace', 'amazing']);

        $snippets = $this->builder->buildSnippets($lyrics, $tokens);

        $this->assertEquals('The <mark>grace</mark> of God is <mark>amazing</mark>', $snippets[0]);
    }

    #[Test]
    public function highlight_is_case_insensitive_for_marking(): void
    {
        $lyrics = 'Amazing Grace';
        $tokens = collect(['grace']);

        $snippets = $this->builder->buildSnippets($lyrics, $tokens);

        $this->assertEquals('Amazing <mark>Grace</mark>', $snippets[0]);
    }
}
