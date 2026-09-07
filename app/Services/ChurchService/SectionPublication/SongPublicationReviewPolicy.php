<?php

declare(strict_types=1);

namespace App\Services\ChurchService\SectionPublication;

use App\Enums\HistoricVideoCorroborationGrade;
use App\Enums\ServiceSectionType;
use App\Models\ChurchServiceItem;
use App\Models\ServiceSection;
use Illuminate\Database\Eloquent\Builder;

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
 *
 * The Phase 8 pass added a fourth class the pilot did not reach. Sections 1276
 * and 3869 each hold two different songs in one interval — OCR read both and
 * `MatchSongsFromTranscript` recorded the second in `additional_song_matches` —
 * yet a single clip was generated for the first song alone. Confident OCR
 * establishes that both songs were sung; it does not license presenting their
 * combined interval as one of them.
 */
class SongPublicationReviewPolicy
{
    /**
     * The shortest clip that could plausibly have absorbed a whole further song.
     *
     * The same six-minute ceiling a sung item is held to elsewhere
     * {@see \App\Services\ChurchService\Structure\ServiceStructureValidator::FLAG_MACRO_SECTION};
     * 1,049 of the corpus's 1,078 song sections sit inside it.
     */
    private const SWALLOWING_MINIMUM_SECONDS = 360.0;

    public function __construct(
        private readonly SongPublicationBoundaryEvidenceService $boundaryEvidence,
    ) {}

    /**
     * @return list<array{kind: string, detail: string}>
     */
    public function reviewReasons(ServiceSection $section): array
    {
        return $this->assess($section)['reasons'];
    }

    /**
     * @return array{
     *     reasons: list<array{kind: string, detail: string}>,
     *     boundary_evidence: array<string, mixed>
     * }
     */
    public function assess(ServiceSection $section): array
    {
        $boundaryEvidence = $this->boundaryEvidence->assess($section);
        $reasons = $this->nonBoundaryReviewReasons($section);

        foreach ($boundaryEvidence['risks'] as $risk) {
            $reasons[] = $risk;
        }

        return [
            'reasons' => $reasons,
            'boundary_evidence' => $boundaryEvidence,
        ];
    }

    /**
     * @return list<array{kind: string, detail: string}>
     */
    private function nonBoundaryReviewReasons(ServiceSection $section): array
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

        $additional = $this->unresolvedAdditionalSongs($section);

        if ($additional !== []) {
            $reasons[] = [
                'kind' => 'unresolved_multiple_songs',
                'detail' => sprintf(
                    'The interval also holds %s, and no internal boundary separates them, so one clip cannot represent it.',
                    $this->describeSongs($additional),
                ),
            ];
        }

        $unlocated = $this->unlocatedFollowingSong($section);

        if ($unlocated !== null) {
            $reasons[] = [
                'kind' => 'unlocated_adjacent_song',
                'detail' => sprintf(
                    'The order of service prints "%s" next and no section holds it, so this over-long clip may contain it.',
                    $unlocated,
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
     * Further songs OCR saw performed inside this section's interval.
     *
     * `MatchSongsFromTranscript` samples frames across a section precisely so
     * back-to-back songs merged into one interval are both identified: the first
     * becomes the section's match and the rest are banked here. A further match
     * resolving to the song the section is already assigned is corroboration
     * from a later frame, not a second performance, so it is not counted. One
     * that names no catalogue song still is: failing to place a title is not
     * evidence that only one song was sung.
     *
     * Nothing here clears the reason by discarding a match. The interval is
     * resolved by separating the performances, after which each section carries
     * a single song of its own.
     *
     * @return list<array{song_id: int|null, title: string|null}>
     */
    private function unresolvedAdditionalSongs(ServiceSection $section): array
    {
        $matches = $section->metadata?->toArray()['additional_song_matches'] ?? null;

        if (! is_array($matches)) {
            return [];
        }

        $songId = $this->songId($section);
        $unresolved = [];

        foreach ($matches as $match) {
            if (! is_array($match)) {
                continue;
            }

            $matchedSongId = is_int($match['song_id'] ?? null) ? $match['song_id'] : null;

            if ($matchedSongId !== null && $matchedSongId === $songId) {
                continue;
            }

            $title = $match['title'] ?? null;

            $unresolved[] = [
                'song_id' => $matchedSongId,
                'title' => is_string($title) && trim($title) !== '' ? trim($title) : null,
            ];
        }

        return $unresolved;
    }

    /**
     * The song the order of service prints next, when nothing holds it and this
     * clip is long enough to have swallowed it.
     *
     * The second route to the same defect {@see self::unresolvedAdditionalSongs()}
     * names, and it exists because that one reads OCR evidence that is often
     * simply absent. Section 306 — a publicly released 510-second clip issued for
     * "All creatures of our God and King" — has an empty `additional_song_matches`
     * and would pass that check, yet its own notes say "Introduced as two songs
     * together; this is the first" and the printed order's very next song, "King
     * Of The Ages", has no section anywhere in the service.
     *
     * The notes were considered as the signal and rejected: of the nine sections
     * corpus-wide whose notes mention multiple songs, three are negations — one
     * says only one of the pair was "evident in the transcript", another merely
     * identifies which of a pair this section is — so a gate built on free-form
     * prose would hold sections that are correct.
     *
     * The printed order is checked instead, and it corroborates itself: run over
     * the corpus it independently rediscovers section 335, the known mixed clip,
     * and names "When I Fear My Faith Will Fail" — exactly the song already
     * recorded as buried in it.
     *
     * Both conditions are required. An unlocated printed song is ordinary on its
     * own — only 19 of 326 services have none, because a printed song may go
     * unsung, be listed twice, or fall outside the recording — so the length is
     * what makes it evidence. A clip cannot have absorbed a whole further song
     * without running long.
     */
    private function unlocatedFollowingSong(ServiceSection $section): ?string
    {
        if ($section->end_time - $section->start_time <= self::SWALLOWING_MINIMUM_SECONDS) {
            return null;
        }

        $item = $section->churchServiceItem;

        if ($item === null || $item->type !== 'songs') {
            return null;
        }

        $following = ChurchServiceItem::query()
            ->where('church_service_id', $item->church_service_id)
            ->where('type', 'songs')
            ->where('position', '>', $item->position)
            ->orderBy('position')
            ->first();

        if ($following === null) {
            return null;
        }

        $located = ServiceSection::query()
            ->where('church_service_item_id', $following->id)
            ->whereHas('processingLog', fn (Builder $log): Builder => $log->whereNull('superseded_at'))
            ->exists();

        if ($located) {
            return null;
        }

        $title = trim((string) $following->title);

        return $title === '' ? 'an untitled item' : $title;
    }

    /**
     * @param  list<array{song_id: int|null, title: string|null}>  $songs
     */
    private function describeSongs(array $songs): string
    {
        $described = array_map(
            static fn (array $song): string => $song['title']
                ?? (is_int($song['song_id']) ? 'song '.$song['song_id'] : 'a song OCR could not place'),
            $songs,
        );

        if (count($described) === 1) {
            return $described[0];
        }

        $last = array_pop($described);

        return implode(', ', $described).' and '.$last;
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
