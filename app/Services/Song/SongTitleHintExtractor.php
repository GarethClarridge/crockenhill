<?php

declare(strict_types=1);

namespace App\Services\Song;

use App\Enums\ServiceSectionType;

/**
 * @phpstan-type ClassifiedSectionPayload array{
 *     church_service_item_id: int|null,
 *     section_type: string,
 *     section_order: int,
 *     title: ?string,
 *     start_time: float,
 *     end_time: float,
 *     duration: float,
 *     confidence: float,
 *     status: string,
 *     needs_manual_review: bool,
 *     source_segment_ids: array<int, int>,
 *     metadata: array<string, mixed>
 * }
 */
class SongTitleHintExtractor
{
    /**
     * Patterns used to extract a song title from an announcement transcript.
     * Each pattern captures the title in named group "title".
     *
     * @var list<string>
     */
    private const array TITLE_PATTERNS = [
        // Quoted titles — highest precision, matched first.
        '/\b(?:sing|song|hymn)\s+["\'](?P<title>[^"\']{3,80})["\']/',

        // "song entitled/called X" and "hymn entitled/called X".
        '/\bsong\s+(?:entitled|called)\s+(?P<title>[A-Za-z][\w\s\'\-\(\),:!?]{2,60})(?:\s*[,\.?!]|$)/i',
        '/\bhymn\s+(?:number\s+\d+\s+)?(?:entitled|called)\s+(?P<title>[A-Za-z][\w\s\'\-\(\),:!?]{2,60})(?:\s*[,\.?!]|$)/i',

        // "song is entitled/called X" — must precede bare "song is" to avoid capturing "called" in title.
        '/\bsong\s+is\s+(?:entitled|called)\s+(?P<title>[A-Za-z][\w\s\'\-\(\),:!?]{2,60})(?:\s*[,\.?!]|$)/i',

        // "song is X" — bare form, excludes "entitled/called" prefix via negative lookahead.
        '/\bsong\s+is\s+(?!(?:entitled|called)\s)(?P<title>[A-Za-z][\w\s\'\-\(\),:!?]{2,60})(?:\s*[,\.?!]|$)/i',

        // "stand and sing [together] X" — common church introduction phrase.
        '/\bstand\s+and\s+sing\s+(?:together\s+)?(?P<title>[A-Za-z][\w\s\'\-\(\),:!?]{2,60})(?:\s*[,\.?!]|$)/i',

        // "going to sing X" / "going to sing a song [entitled/called] X".
        '/\bgoing\s+to\s+sing\s+(?:a\s+song\s+(?:entitled\s+|called\s+)?)?(?P<title>[A-Za-z][\w\s\'\-\(\),:!?]{2,60})(?:\s*[,\.?!]|$)/i',

        // "worship [god] with the song X" / "worship by singing X".
        '/\bworship\s+(?:god\s+)?(?:with\s+the\s+song\s+|by\s+singing\s+)(?P<title>[A-Za-z][\w\s\'\-\(\),:!?]{2,60})(?:\s*[,\.?!]|$)/i',

        // Bare "let's/we'll/please/shall sing X" — lowest priority fallback.
        '/\b(?:let\'s|lets|please|now|we\'ll|will|shall)\s+(?:now\s+)?sing\s+(?:together\s+)?(?P<title>[A-Za-z][\w\s\'\-\(\),:!?]{2,60})(?:\s*[,\.?!]|$)/i',
    ];

    /**
     * Scan classified section payloads for song announcement patterns.
     *
     * Looks for a speech section classified as `section_type: song` (via AI transcript)
     * that immediately precedes an RMS-detected `section_type: song` (`classification_mode: audio_only`).
     * Extracts a title hint from the speech section's transcript and writes it into
     * the following audio-only song section's metadata as `song_title_hint`.
     *
     * @param  array<int, ClassifiedSectionPayload>  $sections  Rewritten section payloads
     * @return array<int, ClassifiedSectionPayload> Sections with `song_title_hint` written into matching audio-only song sections
     */
    public function extract(array $sections): array
    {
        $count = count($sections);

        for ($i = 0; $i < $count; $i++) {
            $current = $sections[$i];

            if (! $this->isTranscriptSongAnnouncement($current)) {
                continue;
            }

            $transcript = $current['metadata']['transcript'] ?? '';
            if (! is_string($transcript) || trim($transcript) === '') {
                continue;
            }

            $hint = $this->extractTitleFromTranscript($transcript);

            // Also check ai_notes for an alternative title mention.
            if ($hint === null) {
                $hint = $this->extractTitleFromAiNotes($current['metadata']['ai_notes'] ?? []);
            }

            if ($hint === null) {
                continue;
            }

            // Look ahead for the immediately following audio-only song section.
            if ($i + 1 < $count && $this->isAudioOnlySong($sections[$i + 1])) {
                $sections[$i + 1] = $this->withSongTitleHint($sections[$i + 1], $hint);
            }
        }

        return $sections;
    }

    /**
     * Returns true when a section is a speech-classified song announcement:
     * classified by AI transcript and typed as song by the AI.
     *
     * @param  ClassifiedSectionPayload  $section
     */
    private function isTranscriptSongAnnouncement(array $section): bool
    {
        if ($section['section_type'] !== ServiceSectionType::Song->value) {
            return false;
        }

        $classificationMode = $section['metadata']['classification_mode'] ?? null;
        if ($classificationMode !== 'ai_transcript') {
            return false;
        }

        return true;
    }

    /**
     * Returns true when a section is an RMS-detected audio-only song section.
     *
     * @param  ClassifiedSectionPayload  $section
     */
    private function isAudioOnlySong(array $section): bool
    {
        if ($section['section_type'] !== ServiceSectionType::Song->value) {
            return false;
        }

        $classificationMode = $section['metadata']['classification_mode'] ?? null;

        return $classificationMode === 'audio_only';
    }

    /**
     * @param  ClassifiedSectionPayload  $section
     * @return ClassifiedSectionPayload
     */
    private function withSongTitleHint(array $section, string $hint): array
    {
        /** @var ClassifiedSectionPayload $updated */
        $updated = array_replace($section, [
            'metadata' => array_merge($section['metadata'], [
                'song_title_hint' => $hint,
            ]),
        ]);

        return $updated;
    }

    /**
     * Apply each TITLE_PATTERNS regex against the transcript and return the first match,
     * cleaned and trimmed.
     */
    private function extractTitleFromTranscript(string $transcript): ?string
    {
        foreach (self::TITLE_PATTERNS as $pattern) {
            if (preg_match($pattern, $transcript, $matches) === 1) {
                $title = trim((string) $matches['title']);
                if ($title !== '') {
                    return $title;
                }
            }
        }

        return null;
    }

    /**
     * Scan AI notes for a quoted title or title-prefixed phrase.
     */
    private function extractTitleFromAiNotes(mixed $aiNotes): ?string
    {
        if (! is_array($aiNotes)) {
            return null;
        }

        foreach ($aiNotes as $note) {
            if (! is_string($note)) {
                continue;
            }

            // Prefer quoted titles in notes.
            if (preg_match('/["\'](?P<title>[^"\']{3,60})["\']/', $note, $matches)) {
                $title = trim($matches['title']);
                if ($title !== '') {
                    return $title;
                }
            }
        }

        return null;
    }
}
