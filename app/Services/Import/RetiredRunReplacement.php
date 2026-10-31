<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Enums\SermonPublicationState;
use App\Models\HistoricImportOperation;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Models\ServiceSection;
use App\Models\SongUsageReport;
use App\Models\SongVideo;
use App\Models\SpeakerSample;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Release's replacement of what a weekly run retired into a round left public.
 *
 * Retirement keeps a weekly run's published sermon live and records it as awaiting replacement
 * (plan §4.1a step 3b). When the re-import's cut is released, its content is folded into that
 * published row, which keeps its id, slug and podcast GUID (`sermon-{id}`; the feed carries the
 * latest 100), and the released row is deleted (R11, amended 10-31). The old row's files stay
 * where they are, recorded in the retirement: release never deletes by path.
 *
 * The run's published song clips are withdrawn one song at a time, as the re-import's clip of
 * the same song in the same service is released; a song the re-import does not release keeps
 * its old clip.
 *
 * This runs after the release commit, never inside it, so HIR7's claim, ledger and commit are
 * unchanged. A failure here leaves the released row published beside the old one, and the next
 * release of the batch finishes the fold.
 *
 * Deletion trigger: Delete with {@see HistoricSermonPublicationService} once the accepted public
 * release and rollback observation window have closed.
 */
final class RetiredRunReplacement
{
    /**
     * What a cut is: everything the pipeline wrote. Identity (id, slug, date, service), the
     * public state, download count and the operator's display choices stay with the row.
     */
    private const CUT_COLUMNS = [
        'livestream_processing_id', 'content_type', 'asset_disk', 'historic_import_operation_id',
        'audio_file_path', 'video_file_path', 'transcript_file_path', 'thumbnail_file_path',
        'thumbnail_generated_at', 'thumbnail_metadata', 'video_quality_status', 'video_quality_reason',
        'video_quality_assessed_at', 'source_type', 'segment_start_time', 'segment_end_time', 'duration',
        'filetype', 'title', 'title_provenance', 'reference', 'scripture_passage_id', 'preacher',
        'preacher_id', 'preacher_source', 'preacher_confidence', 'needs_preacher_review', 'series',
        'points', 'partial_recording', 'summary', 'meta_description',
    ];

    public function __construct(
        private readonly HistoricImportJournal $journal,
    ) {}

    /**
     * @param  array{sermons: list<Sermon>, song_videos: list<SongVideo>, song_usage_reports: list<SongUsageReport>}  $released
     * @return array{sermons: list<Sermon>, song_videos: list<SongVideo>, song_usage_reports: list<SongUsageReport>}
     */
    public function replace(HistoricImportOperation $operation, array $released): array
    {
        $released['sermons'] = array_map(
            fn (Sermon $sermon): Sermon => $this->replaceSermon($operation, $sermon),
            $released['sermons'],
        );

        foreach ($released['song_videos'] as $songVideo) {
            $this->withdrawReplacedClips($operation, $songVideo);
        }

        return $released;
    }

    /**
     * The published sermon a released cut was folded into, so a retried batch naming the
     * deleted cut still resolves to what it became.
     */
    public function foldedInto(HistoricImportOperation $operation, int $sermonId): ?Sermon
    {
        $run = $this->retiredRuns($operation)->first(
            static fn (MediaProcessingLog $run): bool => ($run->retirementRecord()['sermon']['replaced_by'] ?? null) === $sermonId,
        );
        $publishedId = $run?->retirementRecord()['sermon']['id'] ?? null;

        return is_int($publishedId) ? Sermon::query()->find($publishedId) : null;
    }

    private function replaceSermon(HistoricImportOperation $operation, Sermon $cut): Sermon
    {
        $retired = $this->retiredRuns($operation)->first(
            static fn (MediaProcessingLog $run): bool => ($run->retirementRecord()['sermon']['awaiting_replacement'] ?? false) === true
                && $run->extracted_date?->toDateString() === $cut->date->toDateString()
                && $run->extracted_service === $cut->service,
        );

        if (! $retired instanceof MediaProcessingLog
            || $cut->publication_state !== SermonPublicationState::Published
            || $cut->historic_import_operation_id !== $operation->id) {
            return $cut;
        }

        return DB::transaction(function () use ($operation, $cut, $retired): Sermon {
            $record = $retired->retirementRecord() ?? [];
            $published = Sermon::query()->whereKey($record['sermon']['id'] ?? null)->lockForUpdate()->first();

            if (! $published instanceof Sermon
                || $published->is($cut)
                || $published->publication_state !== SermonPublicationState::Published) {
                throw new RuntimeException(
                    "Run [{$retired->processing_id}] awaits replacement of a sermon that is no longer published; nothing was folded."
                );
            }

            $replacedContent = $published->only(self::CUT_COLUMNS);
            $published->forceFill($cut->only(self::CUT_COLUMNS))->save();

            $cut->scriptureFilters()->delete();
            MediaProcessingLog::query()->where('sermon_id', $cut->id)->update(['sermon_id' => $published->id]);
            ServiceSection::query()->where('published_sermon_id', $cut->id)->update(['published_sermon_id' => $published->id]);
            SpeakerSample::query()->where('sermon_id', $cut->id)->update(['sermon_id' => $published->id]);
            $cut->delete();

            $record['sermon'] = [
                ...$record['sermon'],
                'awaiting_replacement' => false,
                'replaced_by' => $cut->id,
                'replaced_at' => now()->toIso8601String(),
                'replaced_content' => $replacedContent,
            ];
            $retired->putRetirement($record);

            $this->journal->append($operation, 'sermon_replaced', [
                'sermon_id' => $published->id,
                'released_sermon_id' => $cut->id,
                'retired_processing_id' => $retired->processing_id,
                'retained_asset_paths' => array_values(array_filter([
                    $replacedContent['audio_file_path'] ?? null,
                    $replacedContent['video_file_path'] ?? null,
                    $replacedContent['transcript_file_path'] ?? null,
                    $replacedContent['thumbnail_file_path'] ?? null,
                ])),
            ]);

            return $published->fresh() ?? $published;
        });
    }

    private function withdrawReplacedClips(HistoricImportOperation $operation, SongVideo $released): void
    {
        if ($released->church_service_id === null || $released->publication_state !== SermonPublicationState::Published) {
            return;
        }

        $sectionIds = $this->retiredRuns($operation)
            ->filter(static fn (MediaProcessingLog $run): bool => $run->church_service_id === $released->church_service_id)
            ->flatMap(static fn (MediaProcessingLog $run): array => $run->retirementRecord()['published_sections'] ?? [])
            ->all();

        if ($sectionIds === []) {
            return;
        }

        $replaced = SongVideo::query()
            ->whereIn('service_section_id', $sectionIds)
            ->where('song_id', $released->song_id)
            ->where('publication_state', SermonPublicationState::Published)
            ->whereKeyNot($released->id)
            ->get();

        foreach ($replaced as $clip) {
            $clip->forceFill(['publication_state' => SermonPublicationState::Quarantined])->save();

            $this->journal->append($operation, 'song_video_withdrawn', [
                'song_video_id' => $clip->id,
                'replaced_by_song_video_id' => $released->id,
                'retained_asset_path' => $clip->video_file_path,
            ]);
        }
    }

    /**
     * Weekly runs retired into this operation.
     *
     * @return Collection<int, MediaProcessingLog>
     */
    private function retiredRuns(HistoricImportOperation $operation): Collection
    {
        return MediaProcessingLog::query()
            ->whereNull('historic_import_operation_id')
            ->whereNotNull('superseded_at')
            ->get()
            ->filter(static fn (MediaProcessingLog $run): bool => ($run->retirementRecord()['lane'] ?? null) === 'weekly'
                && ($run->retirementRecord()['operation_id'] ?? null) === $operation->operation_id)
            ->values();
    }
}
