<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Song;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Song>
 */
class SongFactory extends Factory
{
    protected $model = Song::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = $this->faker->unique()->sentence(3);

        return [
            'canonical_key' => Song::canonicalizeKey($title.'@'),
            'title' => $title,
            'alternate_title' => $this->faker->optional()->sentence(2),
            'lyrics_xml' => '<song><lyrics><verse type="v"><![CDATA[Verse line one]]></verse></lyrics></song>',
            'lyrics_plain' => 'Verse line one',
            'verse_order' => $this->faker->optional()->randomElement(['v1 c1 v2 c1', 'v1 v2']),
            'copyright' => $this->faker->optional()->company(),
            'comments' => $this->faker->optional()->paragraph(),
            'ccli_number' => $this->faker->optional()->numerify('######'),
            'import_metadata' => [
                'source_song_ids' => [$this->faker->numberBetween(1, 5000)],
                'duplicate_count' => 0,
            ],
        ];
    }
}
