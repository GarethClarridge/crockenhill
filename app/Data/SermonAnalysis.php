<?php

namespace App\Data;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Data;

class SermonAnalysis extends Data
{
  public function __construct(
    #[Required]
    #[Max(255)]
    public readonly string $title,

    public readonly ?string $series,

    public readonly ?string $reference,

    #[Required]
    public readonly array $points,

    public readonly ?string $summary,

    #[Required]
    public readonly string $transcript,
  ) {}

  /**
   * Create SermonAnalysis with validation
   */
  public static function create(
    string $title,
    ?string $series,
    ?string $reference,
    array $points,
    ?string $summary,
    string $transcript
  ): self {
    // Validate and truncate title to max 12 words
    $validatedTitle = self::validateAndTruncateTitle($title);

    return new self(
      title: $validatedTitle,
      series: $series,
      reference: $reference,
      points: $points,
      summary: $summary,
      transcript: $transcript
    );
  }

  /**
   * Validate title length and truncate to max 12 words if necessary
   */
  private static function validateAndTruncateTitle(string $title): string
  {
    $words = explode(' ', trim($title));

    if (count($words) > 12) {
      $words = array_slice($words, 0, 12);
    }

    return implode(' ', $words);
  }

  /**
   * Get the title word count
   */
  public function getTitleWordCount(): int
  {
    return count(explode(' ', trim($this->title)));
  }

  /**
   * Check if the title is within the word limit
   */
  public function isTitleValid(): bool
  {
    return $this->getTitleWordCount() <= 12;
  }

  /**
   * Transform points array to JSON format for database storage
   */
  public function getPointsAsJson(): string
  {
    return json_encode($this->points);
  }

  /**
   * Create from raw AI analysis data with validation
   */
  public static function fromAiAnalysis(array $analysisData): self
  {
    $title = $analysisData['title'] ?? 'Untitled Sermon';
    $series = !empty($analysisData['series']) ? $analysisData['series'] : null;
    $reference = !empty($analysisData['reference']) ? $analysisData['reference'] : null;
    $points = $analysisData['points'] ?? [];
    $summary = !empty($analysisData['summary']) ? $analysisData['summary'] : null;
    $transcript = $analysisData['transcript'] ?? '';

    return self::create($title, $series, $reference, $points, $summary, $transcript);
  }

  /**
   * Transform the analysis for database insertion
   */
  public function toSermonAttributes(): array
  {
    return [
      'title' => $this->title,
      'series' => $this->series,
      'reference' => $this->reference,
      'points' => $this->getPointsAsJson(),
      'summary' => $this->summary,
    ];
  }

  /**
   * Validate that the transcript contains meaningful content
   */
  public function hasValidTranscript(): bool
  {
    $cleanTranscript = trim(strip_tags($this->transcript));
    return strlen($cleanTranscript) > 100; // Minimum 100 characters for meaningful content
  }

  /**
   * Get a summary of the analysis for logging/debugging
   */
  public function getSummary(): array
  {
    return [
      'title' => $this->title,
      'title_word_count' => $this->getTitleWordCount(),
      'series' => $this->series ?? 'None',
      'reference' => $this->reference ?? 'None',
      'points_count' => count($this->points),
      'transcript_length' => strlen($this->transcript),
      'has_valid_transcript' => $this->hasValidTranscript(),
    ];
  }
}
