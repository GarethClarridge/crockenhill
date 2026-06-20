<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Enums\ServiceSectionType;
use App\Models\ChurchServiceItem;
use App\Models\ServiceSection;
use App\Traits\ReadsSectionMetadata;

/**
 * Scores a (section, item) pairing for the global Needleman–Wunsch alignment in
 * {@see StructuralSectionAligner}, and prices the two gap moves.
 *
 * The governing invariant is CONFLICT_SCORE > SECTION_GAP_SCORE + ITEM_GAP_SCORE.
 * A detected section and an OoS item that sit at the same position but disagree on type
 * are cheaper to align as a single counted conflict than to gap independently, so genuine
 * structural conflicts are still surfaced. A section with no opposing item (for example a
 * prayer that the sparse slide-deck OoS never lists) becomes a free section gap and never
 * counts as a mismatch — which is the core noise reduction this scorer enables.
 *
 * Type agreement dominates every other signal (the match scores dwarf the conflict and gap
 * scores), so the content-similarity bonus only ever disambiguates between competing
 * same-type candidates; it can never turn a type conflict into a match.
 */
class SectionItemAlignmentScorer
{
    use ReadsSectionMetadata;

    public const EXACT_MATCH_SCORE = 10.0;

    public const RECLASSIFY_MATCH_SCORE = 7.0;

    public const MEDIA_INTERLUDE_SCORE = 6.0;

    public const MEDIA_CUE_BONUS = 3.0;

    public const CONFLICT_SCORE = -2.0;

    public const SECTION_GAP_SCORE = -1.5;

    public const ITEM_GAP_SCORE = -1.5;

    private const MAX_CONTENT_BONUS = 2.0;

    /**
     * @var list<string>
     */
    private const STOP_TOKENS = ['the', 'and', 'a', 'an', 'of', 'to', 'our', 'for', 'is', 'in', 'on'];

    /**
     * @return array{kind: 'match'|'reclassify'|'media'|'conflict', score: float}
     */
    public function scorePair(
        ServiceSection $section,
        ChurchServiceItem $item,
        ServiceSectionType $resolvedItemType,
        bool $treatAsMedia,
        bool $hasMediaCue,
    ): array {
        if ($treatAsMedia) {
            return [
                'kind' => 'media',
                'score' => self::MEDIA_INTERLUDE_SCORE + ($hasMediaCue ? self::MEDIA_CUE_BONUS : 0.0),
            ];
        }

        if ($section->section_type === $resolvedItemType) {
            return [
                'kind' => 'match',
                'score' => self::EXACT_MATCH_SCORE + $this->contentBonus($section, $item),
            ];
        }

        if ($section->section_type === ServiceSectionType::Other && $this->isReclassifiableType($resolvedItemType)) {
            return [
                'kind' => 'reclassify',
                'score' => self::RECLASSIFY_MATCH_SCORE + $this->contentBonus($section, $item),
            ];
        }

        return ['kind' => 'conflict', 'score' => self::CONFLICT_SCORE];
    }

    public function sectionGapScore(): float
    {
        return self::SECTION_GAP_SCORE;
    }

    public function itemGapScore(): float
    {
        return self::ITEM_GAP_SCORE;
    }

    /**
     * Structural types the OoS is authoritative enough to reclassify an audio-only
     * OTHER segment into. Mirrors the legacy aligner's reclassification gate.
     */
    private function isReclassifiableType(ServiceSectionType $type): bool
    {
        return in_array($type, [
            ServiceSectionType::ChildrensTalk,
            ServiceSectionType::BibleReading,
            ServiceSectionType::Prayer,
            ServiceSectionType::Notices,
            ServiceSectionType::Welcome,
        ], true);
    }

    /**
     * Token-overlap bonus between the item title (e.g. a reading reference or a notices
     * heading) and the section's own title/transcript. Used only to break ties between
     * two same-type candidates; capped well below the match scores.
     */
    private function contentBonus(ServiceSection $section, ChurchServiceItem $item): float
    {
        $itemTokens = $this->tokens((string) $item->title);
        if ($itemTokens === []) {
            return 0.0;
        }

        $haystack = trim(((string) ($section->title ?? '')).' '.$this->sectionTranscript($section));
        $haystackTokens = array_flip($this->tokens($haystack));
        if ($haystackTokens === []) {
            return 0.0;
        }

        $overlap = 0;
        foreach ($itemTokens as $token) {
            if (isset($haystackTokens[$token])) {
                $overlap++;
            }
        }

        if ($overlap === 0) {
            return 0.0;
        }

        return min(self::MAX_CONTENT_BONUS, $overlap * 0.5);
    }

    /**
     * @return list<string>
     */
    private function tokens(string $value): array
    {
        $parts = preg_split('/[^a-z0-9]+/', strtolower($value), -1, PREG_SPLIT_NO_EMPTY);

        if (! is_array($parts)) {
            return [];
        }

        return array_values(array_filter(
            $parts,
            static fn (string $token): bool => strlen($token) > 1 && ! in_array($token, self::STOP_TOKENS, true)
        ));
    }

    private function sectionTranscript(ServiceSection $section): string
    {
        $transcript = $this->metadata($section)['transcript'] ?? null;

        return is_string($transcript) ? $transcript : '';
    }
}
