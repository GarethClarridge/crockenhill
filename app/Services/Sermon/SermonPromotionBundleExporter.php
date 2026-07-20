<?php

declare(strict_types=1);

namespace App\Services\Sermon;

use App\Enums\MediaType;
use App\Enums\ProcessingStatus;
use App\Enums\SermonContentType;
use App\Enums\SermonSourceType;
use App\Models\MediaProcessingLog;
use App\Models\Preacher;
use App\Models\Sermon;
use App\Models\SermonProcessingStep;
use App\Services\Scripture\SermonScriptureFilterIndexService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use RuntimeException;

class SermonPromotionBundleExporter
{
    public const string FORMAT = 'crockenhill-sermon-promotion';

    public const int VERSION = 1;

    public function __construct(
        private readonly SermonPromotionAssets $assets,
        private readonly SermonPromotionBundleFiles $files,
        private readonly SermonScriptureFilterIndexService $scriptureFilters,
    ) {}

    /**
     * @param  list<int>  $sermonIds
     * @return array{path: string, sermon_count: int}
     */
    public function export(array $sermonIds, string $outputPath): array
    {
        $sermonIds = $this->validatedIds($sermonIds);

        /** @var Collection<int, Sermon> $sermons */
        $sermons = Sermon::query()
            ->with([
                'preacherProfile.aliases',
                'processingLogs.processingSteps',
                'scriptureFilters',
            ])
            ->whereIn('id', $sermonIds)
            ->get()
            ->keyBy('id');

        $missingIds = array_values(array_diff($sermonIds, $sermons->keys()->map(fn (mixed $id): int => (int) $id)->all()));

        if ($missingIds !== []) {
            throw new RuntimeException('Selected sermons do not exist: '.implode(', ', $missingIds).'.');
        }

        $entries = [];

        foreach ($sermonIds as $sermonId) {
            $sermon = $sermons->get($sermonId);

            if (! $sermon instanceof Sermon) {
                throw new RuntimeException("Selected sermon {$sermonId} could not be loaded.");
            }

            $entries[] = $this->entryForSermon($sermon);
        }

        $bundle = [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'generated_at' => now()->toIso8601String(),
            'sermons' => $entries,
        ];

        return [
            'path' => $this->files->write($outputPath, $bundle),
            'sermon_count' => count($entries),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function entryForSermon(Sermon $sermon): array
    {
        if ($sermon->content_type !== SermonContentType::Sermon) {
            throw new RuntimeException("Sermon {$sermon->id} is not eligible: children's talks use the supported admin flow.");
        }

        if ($sermon->source_type !== SermonSourceType::AudioUpload || blank($sermon->audio_file_path)) {
            throw new RuntimeException("Sermon {$sermon->id} is not an eligible legacy audio-upload record.");
        }

        $preacher = $sermon->preacherProfile;

        if (! $preacher instanceof Preacher) {
            throw new RuntimeException("Sermon {$sermon->id} has no canonical preacher.");
        }

        $processingLog = $this->selectedProcessingLog($sermon);
        $bundledFilters = $sermon->scriptureFilters
            ->map(fn ($filter): array => [
                'bible_book' => $filter->bible_book,
                'bible_chapter' => $filter->bible_chapter,
            ])
            ->values()
            ->all();
        $expectedFilters = $this->scriptureFilters->entriesForReference($sermon->reference);

        if ($this->normalizedFilters($bundledFilters) !== $this->normalizedFilters($expectedFilters)) {
            throw new RuntimeException("Sermon {$sermon->id} scripture filters do not match its current reference.");
        }

        return [
            'local_id' => $sermon->id,
            'sermon' => $this->portableSermonData($sermon),
            'preacher' => [
                'name' => $preacher->name,
                'slug' => $preacher->slug,
                'aliases' => $preacher->aliases
                    ->pluck('alias')
                    ->map(fn (mixed $alias): string => (string) $alias)
                    ->sort()
                    ->values()
                    ->all(),
            ],
            'provenance' => $this->portableProvenance($processingLog),
            'scripture_filters' => $this->normalizedFilters($bundledFilters),
            'assets' => $this->assets->manifestForSermon($sermon),
        ];
    }

    private function selectedProcessingLog(Sermon $sermon): MediaProcessingLog
    {
        $processingLog = $sermon->processingLogs
            ->filter(fn (MediaProcessingLog $log): bool => $log->status === ProcessingStatus::Completed)
            ->sortByDesc(fn (MediaProcessingLog $log): string => sprintf(
                '%s-%020d',
                $log->completed_at?->format('Y-m-d H:i:s.u') ?? '',
                $log->id,
            ))
            ->first();

        if (! $processingLog instanceof MediaProcessingLog) {
            throw new RuntimeException("Sermon {$sermon->id} has no completed processing provenance.");
        }

        if ($processingLog->processing_type !== MediaType::Audio) {
            throw new RuntimeException("Sermon {$sermon->id} completed provenance is not an audio run.");
        }

        if (! Str::isUuid($processingLog->processing_id)) {
            throw new RuntimeException("Sermon {$sermon->id} processing provenance has an invalid UUID.");
        }

        if (! is_string($processingLog->file_hash) || preg_match('/\A[a-f0-9]{64}\z/', $processingLog->file_hash) !== 1) {
            throw new RuntimeException("Sermon {$sermon->id} processing provenance has no valid source SHA-256.");
        }

        if (! is_int($processingLog->file_size) || $processingLog->file_size < 1) {
            throw new RuntimeException("Sermon {$sermon->id} processing provenance has no valid recorded size.");
        }

        return $processingLog;
    }

    /**
     * @return array<string, mixed>
     */
    private function portableSermonData(Sermon $sermon): array
    {
        return [
            'date' => $sermon->date->toDateString(),
            'service' => $sermon->service?->value,
            'content_type' => $sermon->content_type->value,
            'audio_file_path' => $sermon->audio_file_path,
            'video_file_path' => $sermon->video_file_path,
            'video_quality_status' => $sermon->video_quality_status?->value,
            'video_quality_reason' => $sermon->video_quality_reason,
            'video_visibility_override' => $sermon->video_visibility_override?->value,
            'video_quality_assessed_at' => $sermon->video_quality_assessed_at?->toIso8601String(),
            'source_type' => $sermon->source_type?->value,
            'segment_start_time' => $sermon->segment_start_time,
            'segment_end_time' => $sermon->segment_end_time,
            'duration' => $sermon->duration,
            'filetype' => $sermon->filetype,
            'title' => $sermon->title,
            'slug' => $sermon->slug,
            'reference' => $sermon->reference,
            'preacher' => $sermon->preacher,
            'preacher_source' => $sermon->preacher_source?->value,
            'preacher_confidence' => $sermon->preacher_confidence,
            'needs_preacher_review' => $sermon->needs_preacher_review,
            'series' => $sermon->series,
            'points' => $sermon->points,
            'show_points' => $sermon->show_points,
            'transcript_file_path' => $sermon->transcript_file_path,
            'thumbnail_file_path' => $sermon->thumbnail_file_path,
            'thumbnail_generated_at' => $sermon->thumbnail_generated_at?->toIso8601String(),
            'thumbnail_metadata' => $sermon->thumbnail_metadata?->toArray(),
            'summary' => $sermon->summary,
            'meta_description' => $sermon->getAttribute('meta_description'),
            'show_summary' => $sermon->show_summary,
            'created_at' => $sermon->created_at?->toIso8601String(),
            'updated_at' => $sermon->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function portableProvenance(MediaProcessingLog $log): array
    {
        return [
            'processing_id' => $log->processing_id,
            'processing_type' => $log->processing_type->value,
            'status' => $log->status->value,
            'current_step' => $log->current_step,
            'original_filename' => $log->original_filename,
            'file_hash' => $log->file_hash,
            'file_size' => $log->file_size,
            'duration' => $log->duration,
            'extracted_date' => $log->extracted_date?->toDateString(),
            'extracted_service' => $log->extracted_service?->value,
            'source_file_path' => $log->source_file_path,
            'audio_file_path' => $log->audio_file_path,
            'enhanced_audio_file_path' => $log->enhanced_audio_file_path,
            'video_file_path' => $log->video_file_path,
            'transcript_file_path' => $log->transcript_file_path,
            'sermon_start_time' => $log->sermon_start_time,
            'sermon_end_time' => $log->sermon_end_time,
            'started_at' => $log->started_at?->toIso8601String(),
            'completed_at' => $log->completed_at?->toIso8601String(),
            'is_degraded_completion' => $log->is_degraded_completion,
            'created_at' => $log->created_at?->toIso8601String(),
            'updated_at' => $log->updated_at?->toIso8601String(),
            'steps' => $log->processingSteps
                ->sortBy(fn (SermonProcessingStep $step): string => sprintf('%s-%s', $step->created_at->toIso8601String(), $step->step))
                ->map(fn (SermonProcessingStep $step): array => [
                    'step' => $step->step,
                    'status' => $step->status->value,
                    'message' => $step->message,
                    'started_at' => $step->started_at?->toIso8601String(),
                    'completed_at' => $step->completed_at?->toIso8601String(),
                    'created_at' => $step->created_at->toIso8601String(),
                    'updated_at' => $step->updated_at->toIso8601String(),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  list<int>  $sermonIds
     * @return list<int>
     */
    private function validatedIds(array $sermonIds): array
    {
        $sermonIds = array_values(array_unique($sermonIds));

        if ($sermonIds === [] || count($sermonIds) > 100) {
            throw new RuntimeException('Select between 1 and 100 sermon IDs per promotion bundle.');
        }

        foreach ($sermonIds as $sermonId) {
            if ($sermonId < 1) {
                throw new RuntimeException('Promotion sermon IDs must be positive integers.');
            }
        }

        return $sermonIds;
    }

    /**
     * @param  array<int, array{bible_book: string, bible_chapter: int}>  $filters
     * @return list<array{bible_book: string, bible_chapter: int}>
     */
    private function normalizedFilters(array $filters): array
    {
        usort($filters, static fn (array $left, array $right): int => [
            $left['bible_book'],
            $left['bible_chapter'],
        ] <=> [
            $right['bible_book'],
            $right['bible_chapter'],
        ]);

        return $filters;
    }
}
