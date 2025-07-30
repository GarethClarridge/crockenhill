<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class BritishEnglishConverter
{
  private const CACHE_KEY = 'british_english_corrections';
  private const CACHE_TTL = 86400; // 24 hours

  /**
   * Convert American English text to British English
   *
   * @param string $text Text to convert
   * @return string Converted text
   */
  public function convert(string $text): string
  {
    $corrections = $this->getCorrections();

    foreach ($corrections as $pattern => $replacement) {
      $text = preg_replace($pattern, $replacement, $text);
    }

    return $text;
  }

  /**
   * Get spelling corrections from cache or generate them
   *
   * @return array Array of regex patterns and replacements
   */
  private function getCorrections(): array
  {
    return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
      return $this->buildCorrections();
    });
  }

  /**
   * Build comprehensive spelling corrections
   *
   * @return array Array of regex patterns and replacements
   */
  private function buildCorrections(): array
  {
    // Load from external word list if available, otherwise use built-in
    $wordList = $this->loadWordList();

    if (!empty($wordList)) {
      return $this->buildCorrectionsFromWordList($wordList);
    }

    return $this->getBuiltInCorrections();
  }

  /**
   * Load word list from external source (JSON file, API, etc.)
   *
   * @return array|null Word list or null if not available
   */
  private function loadWordList(): ?array
  {
    // Try to load from storage first
    $wordListPath = 'spelling/american-british-words.json';

    if (Storage::exists($wordListPath)) {
      try {
        $content = Storage::get($wordListPath);
        return json_decode($content, true);
      } catch (\Exception $e) {
        \Log::warning('Failed to load external word list', ['error' => $e->getMessage()]);
      }
    }

    // Could also load from external API or package here
    return null;
  }

  /**
   * Build corrections from external word list
   *
   * @param array $wordList Word list data
   * @return array Regex patterns and replacements
   */
  private function buildCorrectionsFromWordList(array $wordList): array
  {
    $corrections = [];

    foreach ($wordList as $american => $british) {
      // Create word boundary regex for exact matches
      $pattern = '/\b' . preg_quote($american, '/') . '\b/i';
      $corrections[$pattern] = $british;
    }

    return $corrections;
  }

  /**
   * Get built-in spelling corrections (fallback)
   *
   * @return array Array of regex patterns and replacements
   */
  private function getBuiltInCorrections(): array
  {
    return [
      // Pattern-based corrections (more efficient than individual words)

      // -ize to -ise endings (with exceptions)
      '/\b(?!capsize|seize|prize|maize)([\w]+)ize\b/i' => '$1ise',
      '/\b(?!capsize|seize|prize|maize)([\w]+)ization\b/i' => '$1isation',
      '/\b(?!capsize|seize|prize|maize)([\w]+)izing\b/i' => '$1ising',
      '/\b(?!capsize|seize|prize|maize)([\w]+)ized\b/i' => '$1ised',

      // -or to -our endings
      '/\b(behavio|colo|favo|flavo|hono|humo|labo|neighbo|rumo|splendo|vigo)r\b/i' => '$1ur',

      // -er to -re endings
      '/\b(cent|met|theat|fib|goit|lit|lust|mano|mass|mit|nit|oct|salt|spect)er\b/i' => '$1re',

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
   *
   * @return void
   */
  public function clearCache(): void
  {
    Cache::forget(self::CACHE_KEY);
  }

  /**
   * Get statistics about available corrections
   *
   * @return array Statistics
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
