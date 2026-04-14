<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Sermon;
use App\Models\SermonScriptureFilter;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SermonScriptureFilter>
 */
class SermonScriptureFilterFactory extends Factory
{
    protected $model = SermonScriptureFilter::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sermon_id' => Sermon::factory(),
            'bible_book' => 'John',
            'bible_chapter' => 3,
        ];
    }

    public function forPassage(string $book, int $chapter): static
    {
        return $this->state(fn (): array => [
            'bible_book' => $book,
            'bible_chapter' => $chapter,
        ]);
    }
}
