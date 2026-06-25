<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Scripture\ScriptureReferenceResolver;
use PHPUnit\Framework\TestCase;
use TechWilk\BibleVerseParser\BiblePassageParser;

class ScriptureReferenceResolverTest extends TestCase
{
    private ScriptureReferenceResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new ScriptureReferenceResolver(new BiblePassageParser);
    }

    public function test_normalizes_standard_reference(): void
    {
        $result = $this->resolver->normalize('John 3:16');
        $this->assertSame('John 3:16', $result);
    }

    public function test_normalizes_abbreviated_book(): void
    {
        $result = $this->resolver->normalize('Jn 3:16');
        $this->assertNotNull($result);
        $this->assertStringContainsString('John', $result);
    }

    public function test_normalizes_range(): void
    {
        $result = $this->resolver->normalize('John 3:16-21');
        $this->assertNotNull($result);
        $this->assertStringContainsString('John', $result);
        $this->assertStringContainsString('3', $result);
    }

    public function test_normalizes_numbered_book(): void
    {
        $result = $this->resolver->normalize('1 John 3:16');
        $this->assertNotNull($result);
        $this->assertStringContainsString('John', $result);
    }

    public function test_normalizes_first_abbreviated_to_1(): void
    {
        $result = $this->resolver->normalize('1Jn 3:16-18');
        $this->assertNotNull($result);
    }

    public function test_returns_null_for_empty_string(): void
    {
        $this->assertNull($this->resolver->normalize(''));
        $this->assertNull($this->resolver->normalize('   '));
    }

    public function test_returns_null_for_gibberish(): void
    {
        $result = $this->resolver->normalize('xyzzy 99:99');
        $this->assertNull($result);
    }

    public function test_normalizes_verse_word_format(): void
    {
        $result = $this->resolver->normalize('John chapter 3 verse 16');
        $this->assertNotNull($result);
        $this->assertStringContainsString('John', $result);
    }
}
