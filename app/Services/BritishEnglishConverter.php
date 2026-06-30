<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class BritishEnglishConverter
{
    private const CACHE_KEY = 'british_english_corrections';

    private const CACHE_TTL = 86400; // 24 hours

    /** @var array<int, string>|null */
    private ?array $patterns = null;

    /** @var array<int, string>|null */
    private ?array $replacements = null;

    /**
     * Convert American English text to British English
     *
     * Performance Optimization: Uses array-based preg_replace to process all corrections
     * in a single pass through the regex engine. Patterns and replacements are memoized
     * in class properties after the first load to avoid redundant cache hits and
     * array_keys/array_values operations during bulk processing.
     *
     * Derived regex patterns and replacements are cached in local class properties
     * after the first extraction to avoid redundant array operations and cache
     * lookups across multiple calls in the same request or job.
     *
     * @param  string  $text  Text to convert
     * @return string Converted text
     */
    public function convert(string $text): string
    {
        if (blank($this->patterns) || blank($this->replacements)) {
            $corrections = $this->getCorrections();

            if (blank($corrections)) {
                return $text;
            }

            $this->patterns = array_keys($corrections);
            $this->replacements = array_values($corrections);
        }

        return (string) preg_replace($this->patterns, $this->replacements, $text);
    }

    /**
     * Get spelling corrections from cache or generate them
     *
     * @return array<string, string> Array of regex patterns and replacements
     */
    private function getCorrections(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function (): array {
            return $this->buildCorrections();
        });
    }

    /**
     * Build comprehensive spelling corrections
     *
     * @return array<string, string> Array of regex patterns and replacements
     */
    private function buildCorrections(): array
    {
        // Load from external word list if available, otherwise use built-in
        $wordList = $this->loadWordList();

        if (filled($wordList)) {
            return $this->buildCorrectionsFromWordList($wordList);
        }

        return $this->getBuiltInCorrections();
    }

    /**
     * Load word list from external source (JSON file, API, etc.)
     *
     * @return array<string, string>|null Word list or null if not available
     */
    private function loadWordList(): ?array
    {
        // Try to load from storage first
        $wordListPath = 'spelling/american-british-words.json';

        if (! Storage::exists($wordListPath)) {
            return null;
        }

        try {
            $content = Storage::get($wordListPath);

            if (! is_string($content)) {
                return null;
            }

            $decoded = json_decode($content, true);

            return is_array($decoded) ? $decoded : null;
        } catch (\Exception $e) {
            \Log::warning('Failed to load external word list', ['error' => $e->getMessage()]);
        }

        // Could also load from external API or package here
        return null;
    }

    /**
     * Build corrections from external word list
     *
     * @param  array<string, string>  $wordList  Word list data
     * @return array<string, string> Regex patterns and replacements
     */
    private function buildCorrectionsFromWordList(array $wordList): array
    {
        $corrections = [];

        foreach ($wordList as $american => $british) {
            // Create word boundary regex for exact matches
            $pattern = '/\b'.preg_quote($american, '/').'\b/i';
            $corrections[$pattern] = $british;
        }

        return $corrections;
    }

    /**
     * Get built-in spelling corrections (fallback)
     *
     * @return array<string, string> Array of regex patterns and replacements
     */
    private function getBuiltInCorrections(): array
    {
        return [
            // Pattern-based corrections (more efficient than individual words)

            // -ize to -ise endings (with exceptions)
            '/\b(?!capsize|seize|prize|maize|resize|oversize|downsize)([\w]{2,})ize\b/i' => '$1ise',
            '/\b(?!capsize|seize|prize|maize|resize|oversize|downsize)([\w]{2,})ization\b/i' => '$1isation',
            '/\b(?!capsize|seize|prize|maize|resize|oversize|downsize)([\w]{2,})izing\b/i' => '$1ising',
            '/\b(?!capsize|seize|prize|maize|resize|oversize|downsize)([\w]{2,})ized\b/i' => '$1ised',

            // -or to -our endings
            '/\b(armo|behavio|colo|enamo|favo|flavo|glamo|harbo|hono|humo|labo|neighbo|rumo|savio|splendo|vigo)r\b/i' => '$1ur',

            // -er to -re endings (supports compounds for known cases only)
            // Length units take -tre, but instrument/measurement words such as
            // "parameter", "diameter", "perimeter" and "thermometer" must be left alone.
            '/\b((?:kilo|centi|milli|deci|nano)?met)er\b/i' => '$1re',
            '/\b((?:epi)?cent|(?:amphi)?theat)er\b/i' => '$1re',
            '/\b(fib|goit|lit|lust|mano|mass|mit|nit|oct|salt|spect)er\b/i' => '$1re',

            // -ense to -ence endings
            '/\b(defen|licen|offen|preten)se\b/i' => '$1ce',

            // Double consonants in past tense
            '/\b(cancel|model|travel|marvel|level|label|fuel|tunnel|barrel|quarrel)ed\b/i' => '$1led',
            '/\b(cancel|model|travel|marvel|level|label|fuel|tunnel|barrel|quarrel)ing\b/i' => '$1ling',
            '/\b(cancel|model|travel|marvel|level|label|fuel|tunnel|barrel|quarrel)er\b/i' => '$1ler',

            // -ogue endings
            '/\bcatalog\b/i' => 'catalogue',
            '/\bdialog\b/i' => 'dialogue',
            '/\bmonolog\b/i' => 'monologue',
            '/\banalog\b/i' => 'analogue',

            // -yze to -yse
            '/\banalyze\b/i' => 'analyse',
            '/\banalyzing\b/i' => 'analysing',
            '/\banalyzed\b/i' => 'analysed',
            '/\bparalyze\b/i' => 'paralyse',
            '/\bparalyzing\b/i' => 'paralysing',
            '/\bparalyzed\b/i' => 'paralysed',

            // Common individual words
            '/\bgray\b/i' => 'grey',
            '/\bsulfur\b/i' => 'sulphur',
            '/\bskeptical\b/i' => 'sceptical',
            '/\bskepticism\b/i' => 'scepticism',
            '/\bplow\b/i' => 'plough',
            '/\bmold\b/i' => 'mould',
            '/\bmolding\b/i' => 'moulding',
            '/\bmolded\b/i' => 'moulded',
            '/\bsmolder\b/i' => 'smoulder',
            '/\bsmoldering\b/i' => 'smouldering',
            '/\btire\b/i' => 'tyre', // Only for the wheel context
            '/\bpajamas\b/i' => 'pyjamas',
            '/\bcozy\b/i' => 'cosy',
            '/\bdoughnut\b/i' => 'doughnut', // Already correct, but ensures consistency
            '/\bdonut\b/i' => 'doughnut',

            // -ction to -xion (traditional, though less common now)
            // Commented out as these are becoming archaic
            // '/\bconnection\b/i' => 'connexion',
            // '/\breflection\b/i' => 'reflexion',
        ];
    }

    /**
     * Clear the corrections cache
     */
    public function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY);
        $this->patterns = null;
        $this->replacements = null;
    }

    /**
     * Get statistics about available corrections
     *
     * @return array{total_patterns: int, cache_key: string, cache_ttl: int, external_wordlist_available: bool} Statistics
     */
    public function getStats(): array
    {
        $corrections = $this->getCorrections();

        return [
            'total_patterns' => count($corrections),
            'cache_key' => self::CACHE_KEY,
            'cache_ttl' => self::CACHE_TTL,
            'external_wordlist_available' => Storage::exists('spelling/american-british-words.json'),
        ];
    }
}
