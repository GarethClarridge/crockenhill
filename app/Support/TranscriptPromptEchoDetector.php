<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;

class TranscriptPromptEchoDetector
{
    private const SIMILARITY_THRESHOLD = 0.72;

    public function isPromptEcho(string $text): bool
    {
        $normalisedText = $this->normalise($text);

        if (! str_contains($normalisedText, 'crockenhill baptist church')
            || ! str_contains($normalisedText, 'conservative evangelical tradition')) {
            return false;
        }

        foreach ($this->prompts() as $prompt) {
            $normalisedPrompt = $this->normalise($prompt);

            if ($normalisedText === $normalisedPrompt) {
                return true;
            }

            similar_text($normalisedText, $normalisedPrompt, $similarityPercent);
            $maxLength = max(mb_strlen($normalisedText), mb_strlen($normalisedPrompt));
            $levenshteinRatio = 1.0 - (levenshtein($normalisedText, $normalisedPrompt) / $maxLength);

            if (max($similarityPercent / 100, $levenshteinRatio) >= self::SIMILARITY_THRESHOLD) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function prompts(): array
    {
        $configured = config('media-processing.transcription.prompts', []);

        if (! is_array($configured)) {
            return [];
        }

        return array_values(array_filter($configured, is_string(...)));
    }

    private function normalise(string $text): string
    {
        return (string) Str::of(Str::ascii($text))
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->squish();
    }
}
