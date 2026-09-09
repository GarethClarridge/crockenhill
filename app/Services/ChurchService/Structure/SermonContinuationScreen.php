<?php

declare(strict_types=1);

namespace App\Services\ChurchService\Structure;

use App\Data\SermonContinuationMetadata;
use App\Enums\ServiceSectionType;
use App\Models\ServiceSection;
use Illuminate\Support\Collection;

/**
 * Find the sections that carry part of a sermon the detector split (P8-Q15).
 *
 * The detector already said what these are. Asked to describe a section it typed
 * `other`, it wrote "This is a continuation of the single sermon, separated by a
 * congregational song" — and the pipeline then dropped the sentence and published
 * the sermon without that part. So this reads the banked notes rather than
 * re-detecting anything, which is what keeps the whole item to six sections
 * instead of a corpus pass.
 *
 * **The rule was measured, not assumed.** Sixty non-sermon sections across the
 * historic corpus mention a sermon in their notes; every one was read. The
 * patterns below select exactly the six known parts and nothing else. What they
 * must keep out is more instructive than what they admit:
 *
 * - *Rival candidates.* "This is a substantial biblical address, but the later
 *   Joshua exposition is the clearest primary sermon" (§1147, and §643, §766).
 *   That asks which section is the sermon, not whether one sermon has two parts.
 * - *Framing.* "This is treated as the planned presentation rather than a second
 *   sermon" (§2201) sits beside a genuine part on the same run — run 1148 holds
 *   both — so length, position and type all fail to separate them. Only the
 *   sentence does.
 *
 * **Deliberately out of scope: material before the sermon.** Four sections
 * describe sermon-like content preceding the sermon section, §4538 explicitly —
 * "sermon-like exposition before the main passage reading, but it is treated as
 * other to preserve one primary sermon". That is the same defect on the leading
 * edge, but its notes are not separable from ordinary introductions: "This is an
 * introduction to the sermon rather than a separate sermon" (§2015, §4006, §4568)
 * may be the preacher's own opening or a service leader introducing the preacher,
 * and nothing in the note says which. Admitting them on the same words would
 * publish a stranger's introduction inside the sermon. They need evidence this
 * screen does not have.
 *
 * Note that a part may still precede the sermon *section* — run 1073's §1711 is
 * "the first part of the main sermon, which resumes after the intervening hymn" —
 * so position is never a criterion here. Ordering is the plan resolver's job.
 */
final class SermonContinuationScreen
{
    /**
     * Sentences that assert the section is part of the sermon rather than about it.
     *
     * @var list<string>
     */
    private const CONTINUATION_PATTERNS = [
        '/\bcontinuation\b/i',
        '/\bcontinues the sermon\b/i',
        '/\bpart of the (?:main|primary|single) sermon\b/i',
    ];

    /**
     * @param  Collection<int, ServiceSection>  $sections  every section on one run
     * @return list<array{section: ServiceSection, sermon_section_id: int, evidence: string}>
     */
    public function screen(Collection $sections): array
    {
        $sermons = $sections
            ->filter(static fn (ServiceSection $section): bool => $section->section_type === ServiceSectionType::Sermon)
            ->values();

        // With no sermon there is nothing to be part of, and with more than one the
        // question is which sermon this continues — that is P8-Q7/Q9's adjudication,
        // not something to guess by proximity.
        $sermon = $sermons->first();

        if ($sermons->count() !== 1 || ! $sermon instanceof ServiceSection) {
            return [];
        }

        $sermonSectionId = (int) $sermon->id;
        $candidates = [];

        foreach ($sections as $section) {
            if ($section->section_type === ServiceSectionType::Sermon) {
                continue;
            }

            $evidence = $this->continuationEvidence($section);

            if ($evidence === null) {
                continue;
            }

            $candidates[] = [
                'section' => $section,
                'sermon_section_id' => $sermonSectionId,
                'evidence' => $evidence,
            ];
        }

        return $candidates;
    }

    /**
     * Whether this section already records the marker the screen would write.
     */
    public function isRecorded(ServiceSection $section, int $sermonSectionId): bool
    {
        return $section->metadata?->sermonContinuation?->continues($sermonSectionId) === true;
    }

    /**
     * The banked sentence that names this section a part of the sermon, if any.
     *
     * The note must mention a sermon *and* assert part-hood. Either alone is
     * ordinary: almost every section near a sermon mentions one, and "continuation"
     * without a sermon in view describes a song's second half.
     */
    private function continuationEvidence(ServiceSection $section): ?string
    {
        foreach ($this->notes($section) as $note) {
            if (stripos($note, 'sermon') === false) {
                continue;
            }

            foreach (self::CONTINUATION_PATTERNS as $pattern) {
                if (preg_match($pattern, $note) === 1) {
                    return trim($note);
                }
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function notes(ServiceSection $section): array
    {
        $notes = $section->metadata?->raw['ai_notes'] ?? null;

        if (! is_array($notes)) {
            return [];
        }

        return array_values(array_filter($notes, 'is_string'));
    }

    /**
     * The marker to record for an admitted candidate.
     */
    public function markerFor(int $sermonSectionId, string $evidence): SermonContinuationMetadata
    {
        return new SermonContinuationMetadata(
            ofSectionId: $sermonSectionId,
            evidence: $evidence,
            source: 'detector_notes',
        );
    }
}
