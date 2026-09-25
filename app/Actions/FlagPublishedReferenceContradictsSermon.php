<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\ServiceSectionMetadata;
use App\Enums\ServiceSectionType;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\Scripture\ScriptureReferenceResolver;
use App\Support\SectionReviewFlagPolicy;

/**
 * Hold a sermon whose published reference shares no verse with the passage it was heard to
 * preach.
 *
 * Analysis writes the published reference from the sermon's own text, and it can take a
 * reading that came before the sermon instead: 899 was published as Matthew 2:1-12, the carol
 * reading, while the preacher gave 2 Corinthians 9:15. Structure detection records what the
 * sermon itself announced as `sermon_reference`, independently of that analysis, so the two
 * are compared. Measured 2026-09-24 over 425 historic sermons carrying both, the six that
 * share no verse are exactly the six known contradictions (881, 899, 954, 844, 845, 850).
 *
 * Derived, not stored as a verdict: each analysis, and each edit to the reference, raises or
 * withdraws it. A reference that is missing or cannot be parsed on either side makes no claim,
 * which withdraws one made earlier.
 */
class FlagPublishedReferenceContradictsSermon
{
    /** The sermon's published reference shares no verse with its heard reference. */
    public const FLAG = 'published_reference_contradicts_sermon';

    public function __construct(
        private readonly ScriptureReferenceResolver $resolver,
    ) {}

    /**
     * @return array{raised: int, withdrawn: int}
     */
    public function __invoke(MediaProcessingLog $run): array
    {
        $outcome = ['raised' => 0, 'withdrawn' => 0];
        $published = trim((string) $run->sermon?->reference);

        $sections = $run->serviceSections()
            ->where('section_type', ServiceSectionType::Sermon->value)
            ->orderBy('start_time')
            ->get();

        $heard = $sections
            ->map(static fn (ServiceSection $section): mixed => $section->metadata?->toArray()['sermon_reference'] ?? null)
            ->filter(fn (mixed $reference): bool => is_string($reference) && $this->resolver->normalize($reference) !== null)
            ->values();

        // No claim either way withdraws an earlier one, or clearing a wrong reference would
        // leave a hold nothing lifts.
        $contradicts = $published !== ''
            && $this->resolver->normalize($published) !== null
            && $heard->isNotEmpty()
            && $heard->every(fn (string $reference): bool => ! $this->resolver->referencesOverlap($published, $reference));

        foreach ($sections as $section) {
            if ($this->apply($section, $contradicts)) {
                $outcome[$contradicts ? 'raised' : 'withdrawn']++;
            }
        }

        return $outcome;
    }

    private function apply(ServiceSection $section, bool $owed): bool
    {
        $metadata = $section->metadata?->toArray() ?? [];
        $flags = array_values(array_filter(
            is_array($metadata['review_flags'] ?? null) ? $metadata['review_flags'] : [],
            'is_string',
        ));

        if (in_array(self::FLAG, $flags, true) === $owed) {
            return false;
        }

        $without = array_values(array_filter($flags, static fn (string $flag): bool => $flag !== self::FLAG));
        $metadata['review_flags'] = $owed ? [...$without, self::FLAG] : $without;

        $section->metadata = ServiceSectionMetadata::fromArray($metadata);
        $section->needs_manual_review = SectionReviewFlagPolicy::requiresManualReview(
            $section->section_type,
            $metadata['review_flags'],
            is_string($metadata['sermon_reference'] ?? null) ? $metadata['sermon_reference'] : null,
        );
        $section->save();

        return true;
    }
}
