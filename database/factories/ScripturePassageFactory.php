<?php

namespace Database\Factories;

use App\Models\ScripturePassage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScripturePassage>
 */
class ScripturePassageFactory extends Factory
{
    private const DEFAULT_BIBLE_ID = 'de4e12af7f28f599-02';

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $references = ['John 3:16', 'Romans 8:28', 'Psalm 23:1', 'Genesis 1:1', 'Matthew 5:3-12'];
        $reference = fake()->randomElement($references);

        return [
            'bible_id' => self::DEFAULT_BIBLE_ID,
            'normalized_reference' => $reference,
            'api_passage_id' => fn (array $attributes): string => $this->makeApiPassageId(
                (string) ($attributes['normalized_reference'] ?? $reference)
            ),
            'display_reference' => fn (array $attributes): string => (string) ($attributes['normalized_reference'] ?? $reference),
            'html_content' => '<p><sup class="v">16</sup>For God so loved the world that he gave his one and only Son.</p>',
            'copyright' => 'THE HOLY BIBLE, NEW INTERNATIONAL VERSION®, NIV® Copyright © 1973, 1978, 1984, 2011 by Biblica, Inc.®',
            'fums_token' => fake()->uuid(),
            'fetched_at' => now(),
        ];
    }

    public function stale(): self
    {
        return $this->state(fn (array $attributes) => [
            'fetched_at' => now()->subDays(30),
        ]);
    }

    private function makeApiPassageId(string $reference): string
    {
        return strtoupper(str_replace([' ', ':'], ['.', '.'], $reference));
    }
}
