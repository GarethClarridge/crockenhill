<?php

declare(strict_types=1);

namespace Tests\Unit\Repositories;

use App\Repositories\SermonRepository;
use App\Support\BibleCanon;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonRepositoryTest extends TestCase
{
    private SermonRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new SermonRepository();
    }

    #[Test]
    public function it_normalizes_archive_filters_with_valid_data(): void
    {
        $bibleCanon = Mockery::mock(BibleCanon::class);
        $bibleCanon->shouldReceive('hasBook')->with('John')->andReturn(true);
        $bibleCanon->shouldReceive('chaptersInBook')->with('John')->andReturn(21);

        $result = $this->repository->normalizeArchiveFilters(
            $bibleCanon,
            '  John  ',
            3,
            123,
            '  Series Name  '
        );

        $this->assertEquals([
            'book' => 'John',
            'chapter' => 3,
            'preacherId' => 123,
            'series' => 'Series Name',
        ], $result);
    }

    #[Test]
    public function it_nullifies_invalid_book_and_corresponding_chapter(): void
    {
        $bibleCanon = Mockery::mock(BibleCanon::class);
        $bibleCanon->shouldReceive('hasBook')->with('InvalidBook')->andReturn(false);

        $result = $this->repository->normalizeArchiveFilters(
            $bibleCanon,
            'InvalidBook',
            1,
            null,
            null
        );

        $this->assertNull($result['book']);
        $this->assertNull($result['chapter']);
    }

    #[Test]
    public function it_nullifies_out_of_range_chapter(): void
    {
        $bibleCanon = Mockery::mock(BibleCanon::class);
        $bibleCanon->shouldReceive('hasBook')->with('John')->andReturn(true);
        $bibleCanon->shouldReceive('chaptersInBook')->with('John')->andReturn(21);

        // Chapter 22 is out of range for John
        $result = $this->repository->normalizeArchiveFilters(
            $bibleCanon,
            'John',
            22,
            null,
            null
        );

        $this->assertSame('John', $result['book']);
        $this->assertNull($result['chapter']);

        // Chapter 0 is invalid
        $result = $this->repository->normalizeArchiveFilters(
            $bibleCanon,
            'John',
            0,
            null,
            null
        );
        $this->assertNull($result['chapter']);
    }

    #[Test]
    public function it_handles_empty_or_whitespace_strings(): void
    {
        $bibleCanon = Mockery::mock(BibleCanon::class);
        $bibleCanon->shouldReceive('hasBook')->with(null)->andReturn(false);

        $result = $this->repository->normalizeArchiveFilters(
            $bibleCanon,
            '   ',
            null,
            null,
            '   '
        );

        $this->assertNull($result['book']);
        $this->assertNull($result['series']);
    }
}
