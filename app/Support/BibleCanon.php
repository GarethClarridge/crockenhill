<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Collection;
use RuntimeException;
use TechWilk\BibleVerseParser\BiblePassage;

class BibleCanon
{
    /**
     * @var array<int, array{name: string, abbreviations: array<int, string>, chapterStructure: array<int, int>}>
     */
    private array $structure;

    /**
     * @var array<int, array{number: int, name: string, chapters: int}>
     */
    private array $books;

    /**
     * @var array<string, int>
     */
    private array $bookNumbersByName;

    public function __construct()
    {
        $structure = require base_path('vendor/techwilk/bible-verse-parser/data/bibleStructure.php');

        if (! is_array($structure) || count($structure) !== 66) {
            throw new RuntimeException('Bible parser structure shape changed: expected 66 books.');
        }

        ksort($structure);

        $this->structure = [];
        $this->books = [];
        $this->bookNumbersByName = [];

        foreach ($structure as $bookNumber => $bookData) {
            $this->guardBookStructure((int) $bookNumber, $bookData);

            /** @var array{name: string, abbreviations: array<int, string>, chapterStructure: array<int, int>} $bookData */
            $this->structure[(int) $bookNumber] = $bookData;
            $this->bookNumbersByName[$bookData['name']] = (int) $bookNumber;
            $this->books[] = [
                'number' => (int) $bookNumber,
                'name' => $bookData['name'],
                'chapters' => count($bookData['chapterStructure']),
            ];
        }
    }

    /**
     * @return array<int, array{number: int, name: string, chapters: int}>
     */
    public function allBooks(): array
    {
        return $this->books;
    }

    public function hasBook(string $bookName): bool
    {
        return $this->bookNumberFor($bookName) !== null;
    }

    public function chaptersInBook(string $bookName): int
    {
        $bookNumber = $this->bookNumberFor($bookName);

        if ($bookNumber === null) {
            throw new RuntimeException("Unknown Bible book [{$bookName}].");
        }

        return count($this->structure[$bookNumber]['chapterStructure']);
    }

    /**
     * @return array{book: string|null, chapter: int|null, preacherId: int|null, series: string|null}
     */
    public function normalizeArchiveFilters(
        string|int|null $book,
        string|int|null $chapter,
        string|int|null $preacherId,
        string|int|null $series,
    ): array {
        $book = filled($book) ? trim((string) $book) : null;
        $series = filled($series) ? trim((string) $series) : null;
        $preacherId = filter_var($preacherId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
        $chapter = filter_var($chapter, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;

        if ($book !== null && ! $this->hasBook($book)) {
            $book = null;
        }

        if ($book === null) {
            $chapter = null;
        } elseif ($chapter !== null && $chapter > $this->chaptersInBook($book)) {
            $chapter = null;
        }

        return compact('book', 'chapter', 'preacherId', 'series');
    }

    /**
     * @param  Collection<int, string>  $enabledBooks
     * @return array<int, array{id: string, name: string, disabled: bool}>
     */
    public function bookOptions(Collection $enabledBooks): array
    {
        $enabledLookup = array_fill_keys(
            $enabledBooks
                ->map(fn (mixed $book): string => (string) $book)
                ->all(),
            true
        );

        return array_map(
            fn (array $book): array => [
                'id' => $book['name'],
                'name' => $book['name'],
                'disabled' => ! isset($enabledLookup[$book['name']]),
            ],
            $this->allBooks()
        );
    }

    /**
     * @param  Collection<int, int>  $enabledChapters
     * @return array<int, array{id: int, name: string, disabled: bool}>
     */
    public function chapterOptions(string $bookName, Collection $enabledChapters): array
    {
        $enabledLookup = array_fill_keys(
            $enabledChapters
                ->map(fn (mixed $chapter): int => (int) $chapter)
                ->all(),
            true
        );

        $options = [];

        for ($chapter = 1; $chapter <= $this->chaptersInBook($bookName); $chapter++) {
            $options[] = [
                'id' => $chapter,
                'name' => (string) $chapter,
                'disabled' => ! isset($enabledLookup[$chapter]),
            ];
        }

        return $options;
    }

    /**
     * @return array<int, array{book: string, chapter: int}>
     */
    public function expandPassageToChapters(BiblePassage $passage): array
    {
        $fromBookNumber = $passage->from()->book()->number();
        $toBookNumber = $passage->to()->book()->number();
        $chapters = [];

        for ($bookNumber = $fromBookNumber; $bookNumber <= $toBookNumber; $bookNumber++) {
            $maxChapter = $this->maxChapterForBookNumber($bookNumber);

            if ($fromBookNumber === $toBookNumber) {
                $startChapter = $passage->from()->chapter();
                $endChapter = $passage->to()->chapter();
            } elseif ($bookNumber === $fromBookNumber) {
                $startChapter = $passage->from()->chapter();
                $endChapter = $maxChapter;
            } elseif ($bookNumber === $toBookNumber) {
                $startChapter = 1;
                $endChapter = $passage->to()->chapter();
            } else {
                $startChapter = 1;
                $endChapter = $maxChapter;
            }

            for ($chapter = $startChapter; $chapter <= $endChapter; $chapter++) {
                $chapters[] = [
                    'book' => $this->bookNameForNumber($bookNumber),
                    'chapter' => $chapter,
                ];
            }
        }

        return $chapters;
    }

    private function bookNumberFor(string $bookName): ?int
    {
        return $this->bookNumbersByName[$bookName] ?? null;
    }

    private function bookNameForNumber(int $bookNumber): string
    {
        if (! isset($this->structure[$bookNumber])) {
            throw new RuntimeException("Unknown Bible book number [{$bookNumber}].");
        }

        return $this->structure[$bookNumber]['name'];
    }

    private function maxChapterForBookNumber(int $bookNumber): int
    {
        if (! isset($this->structure[$bookNumber])) {
            throw new RuntimeException("Unknown Bible book number [{$bookNumber}].");
        }

        return count($this->structure[$bookNumber]['chapterStructure']);
    }

    private function guardBookStructure(int $bookNumber, mixed $bookData): void
    {
        if (! is_array($bookData)) {
            throw new RuntimeException("Bible parser structure changed for book [{$bookNumber}].");
        }

        if (! isset($bookData['name']) || ! is_string($bookData['name'])) {
            throw new RuntimeException("Bible parser structure missing string name for book [{$bookNumber}].");
        }

        if (! isset($bookData['abbreviations']) || ! is_array($bookData['abbreviations'])) {
            throw new RuntimeException("Bible parser structure missing abbreviations for book [{$bookNumber}].");
        }

        if (! isset($bookData['chapterStructure']) || ! is_array($bookData['chapterStructure'])) {
            throw new RuntimeException("Bible parser structure missing chapterStructure for book [{$bookNumber}].");
        }
    }
}
