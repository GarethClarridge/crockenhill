<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\ServiceSectionPublicationStatus;
use App\Enums\ServiceSectionSongMatchType;
use App\Enums\ServiceSectionType;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;

final class ServiceFlowBuilder
{
    /**
     * Transform ServiceRecordTimeline rows into human-readable flow items.
     *
     * Consumes the same merged row data the existing table uses, so planned-only
     * items, mismatch detection, and review state are all preserved.
     *
     * @param  list<array<string, mixed>>  $timelineRows  Output from ServiceRecordTimeline::build()
     * @param  MediaProcessingLog  $processingLog  For sermon metadata from ai_analysis
     * @return list<array{
     *     section_id: int|null,
     *     row_type: string,
     *     start_time: float|null,
     *     end_time: float|null,
     *     duration_formatted: string|null,
     *     type: ServiceSectionType|null,
     *     type_label: string,
     *     icon: string,
     *     title_suffix: string|null,
     *     description: string,
     *     planned_title: string|null,
     *     planned_context: string|null,
     *     needs_review: bool,
     *     review_reason: string|null,
     *     mismatch_reason: string|null,
     *     confidence_level: string|null,
     *     song_match_type: ServiceSectionSongMatchType|null,
     *     song_title: string|null,
     *     published_sermon: Sermon|null,
     *     publication_status: ServiceSectionPublicationStatus|null,
     *     metadata: array<string, mixed>,
     *     transcript_excerpt: string|null,
     * }>
     */
    public static function build(array $timelineRows, MediaProcessingLog $processingLog): array
    {
        $sermonTitle = self::resolveSermonTitle($processingLog);

        $items = [];

        foreach ($timelineRows as $row) {
            /** @var ServiceSectionType|null $sectionType */
            $sectionType = $row['section_type'] instanceof ServiceSectionType ? $row['section_type'] : null;

            /** @var ServiceSectionSongMatchType|null $songMatchType */
            $songMatchType = $row['song_match_type'] instanceof ServiceSectionSongMatchType ? $row['song_match_type'] : null;

            /** @var ServiceSectionPublicationStatus|null $publicationStatus */
            $publicationStatus = $row['publication_status'] instanceof ServiceSectionPublicationStatus ? $row['publication_status'] : null;

            /** @var Sermon|null $publishedSermon */
            $publishedSermon = $row['published_sermon'] instanceof Sermon ? $row['published_sermon'] : null;

            $rowType = is_string($row['row_type']) ? $row['row_type'] : 'unplanned';
            $startTime = is_numeric($row['start_time']) ? (float) $row['start_time'] : null;
            $endTime = is_numeric($row['end_time']) ? (float) $row['end_time'] : null;
            $plannedTitle = filled($row['planned_title']) ? $row['planned_title'] : null;
            $needsReview = (bool) ($row['needs_review'] ?? false);
            $reviewReason = filled($row['review_reason']) ? $row['review_reason'] : null;
            $mismatchReason = filled($row['mismatch_reason']) ? $row['mismatch_reason'] : null;
            $confidenceLevel = filled($row['confidence_level']) ? $row['confidence_level'] : null;
            $songTitle = filled($row['song_title']) ? $row['song_title'] : null;
            $sectionId = is_int($row['section_id']) ? $row['section_id'] : null;

            // Load section metadata for ai_notes / transcript / song_title_hint
            $metadata = self::resolveMetadata($processingLog, $sectionId);

            $transcriptExcerpt = self::extractTranscriptExcerpt($metadata);
            $description = self::buildDescription($sectionType, $metadata, $transcriptExcerpt, $songTitle, $songMatchType, $rowType, $sermonTitle);
            $titleSuffix = self::buildTitleSuffix($sectionType, $row, $songTitle, $metadata, $sermonTitle);
            $durationFormatted = self::formatDuration($startTime, $endTime);
            $plannedContext = self::buildPlannedContext($rowType, $plannedTitle, $row);

            $items[] = [
                'section_id' => $sectionId,
                'row_type' => $rowType,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'duration_formatted' => $durationFormatted,
                'type' => $sectionType,
                'type_label' => $sectionType?->label() ?? (is_string($row['item_type']) ? ucfirst($row['item_type']) : 'Unknown'),
                'icon' => self::iconFor($sectionType),
                'title_suffix' => $titleSuffix,
                'description' => $description,
                'planned_title' => $plannedTitle,
                'planned_context' => $plannedContext,
                'needs_review' => $needsReview,
                'review_reason' => $reviewReason,
                'mismatch_reason' => $mismatchReason,
                'confidence_level' => $confidenceLevel,
                'song_match_type' => $songMatchType,
                'song_title' => $songTitle,
                'published_sermon' => $publishedSermon,
                'publication_status' => $publicationStatus,
                'metadata' => $metadata,
                'transcript_excerpt' => $transcriptExcerpt,
            ];
        }

        return $items;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private static function iconFor(?ServiceSectionType $type): string
    {
        if ($type === null) {
            return '';
        }

        return match ($type) {
            ServiceSectionType::Song => '♫',
            ServiceSectionType::Sermon => '🎤',
            ServiceSectionType::ChildrensTalk => '📖',
            ServiceSectionType::BibleReading => '📕',
            default => '',
        };
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $metadata
     */
    private static function buildTitleSuffix(
        ?ServiceSectionType $type,
        array $row,
        ?string $songTitle,
        array $metadata,
        ?string $sermonTitle,
    ): ?string {
        if ($type === ServiceSectionType::Song) {
            // Use OoS-matched song title, then the matched catalogue title
            // written back by song matching, then the heard song_title_hint
            if ($songTitle !== null) {
                return $songTitle;
            }

            $matched = is_string($metadata['song_title'] ?? null) ? (string) $metadata['song_title'] : null;

            if (filled($matched)) {
                return $matched;
            }

            $hint = is_string($metadata['song_title_hint'] ?? null) ? (string) $metadata['song_title_hint'] : null;

            return filled($hint) ? $hint : null;
        }

        if ($type === ServiceSectionType::Sermon) {
            return $sermonTitle;
        }

        if ($type === ServiceSectionType::BibleReading) {
            // Check ai_notes or reading_reference for a Bible ref
            $ref = is_string($metadata['reading_reference'] ?? null) ? (string) $metadata['reading_reference'] : null;
            if (filled($ref)) {
                return $ref;
            }

            // Try first ai_note for a reference-like string
            $aiNotes = self::aiNotesList($metadata);
            if ($aiNotes !== []) {
                return null; // let description carry it
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private static function buildDescription(
        ?ServiceSectionType $type,
        array $metadata,
        ?string $transcriptExcerpt,
        ?string $songTitle,
        ?ServiceSectionSongMatchType $songMatchType,
        string $rowType,
        ?string $sermonTitle,
    ): string {
        // ai_notes are the primary source — use the first note, truncated to 120 chars
        $aiNotes = self::aiNotesList($metadata);
        if ($aiNotes !== []) {
            $note = trim((string) $aiNotes[0]);
            if (filled($note)) {
                return mb_strlen($note) > 120 ? mb_substr($note, 0, 117).'...' : $note;
            }
        }

        // Transcript excerpt as fallback
        if ($transcriptExcerpt !== null) {
            return $transcriptExcerpt;
        }

        // Planned-only rows have no section data at all
        if ($rowType === 'planned_only') {
            return 'Expected from Order of Service — not detected in recording';
        }

        // Contextual defaults by section type
        return match ($type) {
            ServiceSectionType::Song => self::songDescription($songTitle, $songMatchType),
            ServiceSectionType::Sermon => $sermonTitle !== null ? $sermonTitle : 'No description available',
            default => 'No description available',
        };
    }

    private static function songDescription(?string $songTitle, ?ServiceSectionSongMatchType $matchType): string
    {
        $parts = ['Congregational singing'];

        if ($songTitle !== null) {
            $parts[] = "— \"{$songTitle}\"";
        } elseif ($matchType === ServiceSectionSongMatchType::Unmatched) {
            $parts[] = '· Unmatched';
        }

        return implode(' ', $parts);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private static function buildPlannedContext(string $rowType, ?string $plannedTitle, array $row): ?string
    {
        /** @var ServiceSectionType|null $expectedType */
        $expectedType = $row['expected_section_type'] instanceof ServiceSectionType ? $row['expected_section_type'] : null;
        /** @var ServiceSectionType|null $sectionType */
        $sectionType = $row['section_type'] instanceof ServiceSectionType ? $row['section_type'] : null;

        return match ($rowType) {
            'matched' => $plannedTitle !== null ? "Planned: {$plannedTitle}" : null,
            'mismatched' => $expectedType !== null && $sectionType !== null && $expectedType !== $sectionType
                ? "Expected {$expectedType->label()}, detected {$sectionType->label()}"
                : ($plannedTitle !== null ? "Planned: {$plannedTitle}" : null),
            'planned_only' => 'Expected from Order of Service — not detected',
            'unplanned' => 'Not in Order of Service',
            default => null,
        };
    }

    private static function formatDuration(?float $start, ?float $end): ?string
    {
        if ($start === null || $end === null) {
            return null;
        }

        $seconds = (int) round($end - $start);
        if ($seconds <= 0) {
            return null;
        }

        $minutes = intdiv($seconds, 60);
        $remaining = $seconds % 60;

        if ($minutes === 0) {
            return "{$seconds}s";
        }

        return $remaining > 0
            ? "{$minutes}m {$remaining}s"
            : "{$minutes}m";
    }

    private static function resolveSermonTitle(MediaProcessingLog $processingLog): ?string
    {
        $title = $processingLog->ai_analysis?->title;

        return filled($title) ? $title : null;
    }

    /**
     * Retrieve flattened raw metadata for a section by ID from the already-loaded relation.
     *
     * @return array<string, mixed>
     */
    private static function resolveMetadata(MediaProcessingLog $processingLog, ?int $sectionId): array
    {
        if ($sectionId === null) {
            return [];
        }

        if (! $processingLog->relationLoaded('serviceSections')) {
            return [];
        }

        $section = $processingLog->serviceSections->firstWhere('id', $sectionId);
        if ($section === null) {
            return [];
        }

        return $section->metadata?->toArray() ?? [];
    }

    /**
     * Extract a short transcript excerpt (first 120 chars of section transcript).
     *
     * @param  array<string, mixed>  $metadata
     */
    private static function extractTranscriptExcerpt(array $metadata): ?string
    {
        $transcript = is_string($metadata['transcript'] ?? null) ? trim((string) $metadata['transcript']) : null;
        if (blank($transcript)) {
            return null;
        }

        return mb_strlen($transcript) > 120 ? mb_substr($transcript, 0, 117).'...' : $transcript;
    }

    /**
     * Return ai_notes as a list, or empty array if not present/invalid.
     *
     * @param  array<string, mixed>  $metadata
     * @return list<string>
     */
    private static function aiNotesList(array $metadata): array
    {
        $notes = $metadata['ai_notes'] ?? null;
        if (! is_array($notes)) {
            return [];
        }

        return array_values(array_filter(
            array_map(fn ($n): string => is_string($n) ? trim($n) : '', $notes),
            fn (string $n): bool => filled($n),
        ));
    }
}
