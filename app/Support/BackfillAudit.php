<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\SermonContentType;
use App\Models\ChurchServiceItem;
use App\Models\Preacher;
use App\Models\Sermon;
use App\Models\Song;
use App\Services\ChurchService\ChurchServiceSongLinker;
use App\Services\Scripture\SermonScriptureFilterIndexService;
use Illuminate\Database\Eloquent\Builder;

/**
 * Verifies the postcondition of every one-off backfill command against the
 * current database, so a backfill that was never run in an environment is
 * detectable from data state alone. Each check reuses the exact predicate of
 * the backfill it audits, so a passing check means a re-run would change
 * nothing.
 */
class BackfillAudit
{
    public function __construct(
        private readonly SermonScriptureFilterIndexService $scriptureFilterIndexService,
        private readonly ChurchServiceSongLinker $songLinker,
    ) {}

    /**
     * @return array{
     *     sermons_missing_scripture_filters: int,
     *     songs_missing_praise_numbers: int,
     *     song_link_drift: int,
     *     songs_catalogue_missing: bool,
     *     speaker_profiles_missing: bool,
     *     sermons_missing_scripture_passage: int
     * }
     */
    public function report(): array
    {
        return [
            'sermons_missing_scripture_filters' => $this->sermonsMissingScriptureFilters(),
            'songs_missing_praise_numbers' => $this->songsMissingPraiseNumbers(),
            'song_link_drift' => $this->songLinkDrift(),
            'songs_catalogue_missing' => $this->songsCatalogueMissing(),
            'speaker_profiles_missing' => $this->speakerProfilesMissing(),
            'sermons_missing_scripture_passage' => $this->sermonsMissingScripturePassage(),
        ];
    }

    /**
     * The scripture-passage advisory is excluded because references api.bible
     * cannot resolve can leave a legitimate residue.
     *
     * @param  array{
     *     sermons_missing_scripture_filters: int,
     *     songs_missing_praise_numbers: int,
     *     song_link_drift: int,
     *     songs_catalogue_missing: bool,
     *     speaker_profiles_missing: bool,
     *     sermons_missing_scripture_passage: int
     * }|null  $report
     */
    public function hasFailures(?array $report = null): bool
    {
        $report ??= $this->report();

        return $report['sermons_missing_scripture_filters'] > 0
            || $report['songs_missing_praise_numbers'] > 0
            || $report['song_link_drift'] > 0
            || $report['songs_catalogue_missing']
            || $report['speaker_profiles_missing'];
    }

    /**
     * Postcondition of sermons:sync-scripture-filters. SermonObserver indexes
     * every sermon on save, so a parseable reference without derived filter
     * rows can only mean the backfill never ran (or the observer regressed).
     */
    public function sermonsMissingScriptureFilters(): int
    {
        return Sermon::query()
            ->select(['id', 'reference', 'content_type'])
            ->where('content_type', SermonContentType::Sermon)
            ->whereNotNull('reference')
            ->where('reference', '!=', '')
            ->doesntHave('scriptureFilters')
            ->lazyById(200)
            ->filter(fn (Sermon $sermon): bool => $this->scriptureFilterIndexService->entriesForReference($sermon->reference) !== [])
            ->count();
    }

    /**
     * Postcondition of songs:backfill-praise-numbers.
     */
    public function songsMissingPraiseNumbers(): int
    {
        return Song::query()
            ->withTrashed()
            ->select(['id', 'title', 'praise_number'])
            ->where('title', 'like', '%#%')
            ->lazyById(250)
            ->filter(function (Song $song): bool {
                $praiseNumber = Song::praiseNumberFromTitle($song->title);

                return $praiseNumber !== null && $song->praise_number !== $praiseNumber;
            })
            ->count();
    }

    /**
     * Postcondition of service-tracking:link-songs: a dry run of the linker
     * itself reports how many links it would write or clear, so any drift is
     * measured with the linker's own matching logic. The linker titles items
     * from the catalogue as well as linking them, so a title that no longer
     * matches the song it points at counts as drift too.
     */
    public function songLinkDrift(): int
    {
        $metrics = $this->songLinker->linkAll(dryRun: true);

        return $metrics['updated'] + $metrics['cleared'];
    }

    /**
     * Postcondition of service-tracking:sync-songs: song service items cannot
     * be linked while the canonical songs catalogue has never been imported.
     */
    public function songsCatalogueMissing(): bool
    {
        if (Song::query()->withTrashed()->exists()) {
            return false;
        }

        return ChurchServiceItem::query()->where('type', 'songs')->exists();
    }

    /**
     * Postcondition of speaker-profiles:bootstrap, only meaningful where
     * speaker identification is switched on and sermons exist to sample.
     */
    public function speakerProfilesMissing(): bool
    {
        if (! (bool) config('media-processing.speaker_identification.enabled')) {
            return false;
        }

        if (! Sermon::query()->exists()) {
            return false;
        }

        $provider = (string) config('media-processing.speaker_identification.provider', 'null');
        $modelVersion = (string) config('media-processing.speaker_identification.model_version', 'v1.0');

        return Preacher::query()
            ->whereHas('sermons', function (Builder $query): void {
                $query->whereNotNull('audio_file_path')
                    ->where('audio_file_path', '!=', '');
            }, '>=', 5)
            ->whereDoesntHave('speakerProfiles', function (Builder $query) use ($provider, $modelVersion): void {
                $query->where('is_active', true)
                    ->where('provider', $provider)
                    ->where('model_version', $modelVersion);
            })
            ->exists();
    }

    /**
     * Advisory drift for sermons:enrich-scripture; mirrors
     * ScriptureOperatorService::countEnrichmentCandidates() without its limit.
     * Unparseable references and api.bible misses leave a permanent residue.
     */
    public function sermonsMissingScripturePassage(): int
    {
        return Sermon::query()
            ->whereNotNull('reference')
            ->where('reference', '!=', '')
            ->whereNull('scripture_passage_id')
            ->count();
    }
}
