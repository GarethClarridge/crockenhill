<?php

declare(strict_types=1);

namespace App\Services\ChurchService\SectionPublication;

use App\Enums\HistoricVideoCorroborationGrade;
use App\Enums\ServiceSectionType;
use App\Models\ServiceSection;

/**
 * Names the reasons a song clip must reach a reviewer before it is published.
 *
 * A song section publishes itself. That is right for the ordinary case — a
 * confirmed match on a whole hymn needs nobody — and it is what let the historic
 * video pilot publish three clips it should not have:
 *
 *  - a 20-second clip of a hymn's spoken introduction, whose own notes recorded
 *    that the transcript held no sung lyrics at all;
 *  - a 23-second Doxology sung straight after another hymn, which the matcher
 *    resolved to that hymn, so the same song was published twice from adjacent
 *    seconds of one recording;
 *  - a 57-second fragment from a recording graded `short_partial`;
 *  - an inferred catalogue match, whose confidence or missing metadata does not
 *    settle the identity without a person's review.
 *
 * None of these is provably wrong: a doxology really is short, and a short
 * recording can still hold a whole hymn. That is the argument for review rather
 * than rejection. Each reason is recorded on the section so whoever opens it can
 * tell an intentionally short song from a fragment of a longer one.
 */
class SongPublicationReviewPolicy
{
    /**
     * @return list<array{kind: string, detail: string}>
     */
    public function reviewReasons(ServiceSection $section): array
    {
        $reasons = [];

        if ($section->hasInferredSongMatch()) {
            $reasons[] = [
                'kind' => 'inferred_song_match',
                'detail' => 'The catalogue song was inferred rather than confirmed, so the match needs review before publication.',
            ];
        }

        $duration = (float) $section->duration;
        $minimum = $this->minimumAutomaticDuration();

        if ($duration > 0.0 && $duration < $minimum) {
            $reasons[] = [
                'kind' => 'short_song_clip',
                'detail' => sprintf(
                    'The clip runs %.1fs, under the %.1fs a whole sung item is expected to reach.',
                    $duration,
                    $minimum,
                ),
            ];
        }

        $neighbour = $this->adjacentSameSongSection($section);

        if ($neighbour instanceof ServiceSection) {
            $reasons[] = [
                'kind' => 'adjacent_same_song',
                'detail' => sprintf(
                    'Section %d sits against this one and resolves to the same song, so one of them is a fragment or a mismatch.',
                    $neighbour->id,
                ),
            ];
        }

        $grade = $this->corroborationGrade($section);

        if ($grade !== null && ! $this->independentlyCorroborated($section)) {
            $reasons[] = [
                'kind' => 'uncorroborated_partial_recording',
                'detail' => sprintf(
                    'The recording is graded %s and no other source corroborates this item.',
                    $grade->value,
                ),
            ];
        }

        return $reasons;
    }

    /**
     * The shortest clip that may publish itself.
     *
     * Every pilot clip a reviewer should have seen ran under a minute and every
     * clip that was fine ran over two, so the default sits in that gap.
     */
    private function minimumAutomaticDuration(): float
    {
        return (float) config(
            'media-processing.section_classification.song_minimum_automatic_duration_seconds',
            90,
        );
    }

    /**
     * A song section touching this one that resolves to the same song.
     *
     * Contiguity is read from the boundaries rather than the ordering, because
     * the ordering says only that two sections follow one another.
     */
    private function adjacentSameSongSection(ServiceSection $section): ?ServiceSection
    {
        $songId = $this->songId($section);

        if ($songId === null) {
            return null;
        }

        $gap = (float) config(
            'media-processing.section_classification.adjacent_merge_max_gap_seconds',
            2,
        );

        return $section->processingLog->serviceSections()
            ->where('id', '!=', $section->id)
            ->where('section_type', ServiceSectionType::Song)
            ->get()
            ->first(function (ServiceSection $other) use ($section, $songId, $gap): bool {
                if ($this->songId($other) !== $songId) {
                    return false;
                }

                return abs((float) $other->start_time - (float) $section->end_time) <= $gap
                    || abs((float) $section->start_time - (float) $other->end_time) <= $gap;
            });
    }

    private function songId(ServiceSection $section): ?int
    {
        $songId = $section->churchServiceItem?->song_id;

        if (is_int($songId)) {
            return $songId;
        }

        $matched = $section->metadata?->toArray()['transcript_song_match']['song_id'] ?? null;

        return is_int($matched) ? $matched : null;
    }

    /** The corroboration grade of a partial historic recording, or null when it is whole. */
    private function corroborationGrade(ServiceSection $section): ?HistoricVideoCorroborationGrade
    {
        $grade = HistoricVideoCorroborationGrade::tryFrom((string) data_get(
            $section->processingLog->processing_metadata?->toArray(),
            'historic_import.corroboration_grade',
        ));

        return in_array($grade, [
            HistoricVideoCorroborationGrade::ShortPartial,
            HistoricVideoCorroborationGrade::Fragmented,
        ], true) ? $grade : null;
    }

    /**
     * Whether a source other than this recording also attests the item.
     *
     * An order of service or a service email naming the same song is exactly the
     * corroboration a partial recording lacks on its own.
     */
    private function independentlyCorroborated(ServiceSection $section): bool
    {
        $item = $section->churchServiceItem;

        if ($item === null) {
            return false;
        }

        return collect($item->provenanceSources())
            ->reject(fn ($source): bool => $source->value === 'livestream')
            ->isNotEmpty();
    }
}
