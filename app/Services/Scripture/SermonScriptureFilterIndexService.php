<?php

declare(strict_types=1);

namespace App\Services\Scripture;

use App\Enums\SermonContentType;
use App\Models\Sermon;
use App\Support\BibleCanon;
use InvalidArgumentException;
use TechWilk\BibleVerseParser\BiblePassageParser;
use TechWilk\BibleVerseParser\Exception\InvalidBookException;
use TechWilk\BibleVerseParser\Exception\UnableToParseException;

class SermonScriptureFilterIndexService
{
    public function __construct(
        private readonly BiblePassageParser $parser,
        private readonly BibleCanon $bibleCanon,
    ) {}

    /**
     * @return array<int, array{bible_book: string, bible_chapter: int}>
     */
    public function entriesForReference(?string $reference): array
    {
        $reference = $this->normalizeReference($reference);

        if ($reference === null) {
            return [];
        }

        try {
            $passages = $this->parser->parse($reference);
        } catch (UnableToParseException|InvalidBookException|InvalidArgumentException) {
            return [];
        }

        $entries = [];

        foreach ($passages as $passage) {
            foreach ($this->bibleCanon->expandPassageToChapters($passage) as $chapter) {
                $key = $chapter['book'].':'.$chapter['chapter'];

                $entries[$key] = [
                    'bible_book' => $chapter['book'],
                    'bible_chapter' => $chapter['chapter'],
                ];
            }
        }

        return array_values($entries);
    }

    /**
     * @param  array<int, array{bible_book: string, bible_chapter: int}>|null  $entries
     */
    public function syncForSermon(Sermon $sermon, ?array $entries = null): void
    {
        if ($sermon->content_type !== SermonContentType::Sermon) {
            $this->replaceEntriesForSermon($sermon, []);

            return;
        }

        if ($entries === null) {
            $entries = $this->entriesForReference($sermon->reference);
        }

        $this->replaceEntriesForSermon($sermon, $entries);
    }

    /**
     * @param  array<int, array{bible_book: string, bible_chapter: int}>  $entries
     */
    private function replaceEntriesForSermon(Sermon $sermon, array $entries): void
    {
        $sermon->scriptureFilters()->delete();

        if ($entries === []) {
            return;
        }

        $sermon->scriptureFilters()->createMany($entries);
    }

    private function normalizeReference(?string $reference): ?string
    {
        if (! is_string($reference)) {
            return null;
        }

        $trimmed = trim($reference);

        return $trimmed === '' ? null : $trimmed;
    }
}
