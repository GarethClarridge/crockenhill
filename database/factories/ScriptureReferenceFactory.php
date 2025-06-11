<?php

namespace Database\Factories;

use App\Models\ScriptureReference;
use App\Models\Song;
use Illuminate\Database\Eloquent\Factories\Factory;

class ScriptureReferenceFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = ScriptureReference::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition()
    {
        return [
            'song_id' => Song::factory(), // Associates with a song by default
            'reference_string' => $this->faker->randomElement(['John 3:16', 'Psalm 23', 'Romans 8:28', 'Genesis 1:1']),
            // Add any other relevant fields based on actual DB schema
        ];
    }

    /**
     * Associate the scripture reference with a specific song.
     *
     * @param \App\Models\Song|int $song
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function forSong($song)
    {
        return $this->state(function (array $attributes) use ($song) {
            return [
                'song_id' => $song instanceof Song ? $song->id : $song,
            ];
        });
    }

     /**
     * Set a specific reference string.
     *
     * @param string $reference
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function withReference(string $reference)
    {
        return $this->state(function (array $attributes) use ($reference) {
            return [
                'reference_string' => $reference,
            ];
        });
    }
}
