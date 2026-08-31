<?php

declare(strict_types=1);

namespace App\Services\Sermon;

use App\Enums\SermonTitleProvenance;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use Illuminate\Support\Facades\Log;

class HistoricBankedSermonAnalysisReplay
{
    public function __construct(
        private readonly SermonSeriesCorroboration $seriesCorroboration,
        private readonly SermonSlugGenerator $slugGenerator,
    ) {}

    /**
     * Apply only the metadata the historic-video pilot left incomplete, from an
     * already stored analysis. This deliberately has no analysis-provider
     * dependency: replaying it must never create another model request.
     *
     * @return array{changed:list<string>, refused:list<string>}
     */
    public function replay(MediaProcessingLog $processingLog): array
    {
        $sermon = $processingLog->sermon;
        $analysis = $processingLog->ai_analysis;

        if (! $sermon instanceof Sermon || $analysis === null) {
            return ['changed' => [], 'refused' => ['missing_sermon_or_banked_analysis']];
        }

        $updates = [];
        $changed = [];
        $refused = [];

        if ($sermon->titleMayBeReplacedByAnalysis()) {
            $titleChanged = $analysis->title !== $sermon->title;

            if ($titleChanged) {
                $updates['title'] = $analysis->title;
            }

            if ($sermon->title_provenance !== SermonTitleProvenance::AiAnalysis) {
                $updates['title_provenance'] = SermonTitleProvenance::AiAnalysis;
            }

            if ($titleChanged || isset($updates['title_provenance'])) {
                $changed[] = 'title';
            }

            if ($this->slugGenerator->isDerivedFrom($sermon->slug, $sermon->title)) {
                $slug = $this->slugGenerator->generate($analysis->title, $sermon->id);

                if ($slug !== $sermon->slug) {
                    $updates['slug'] = $slug;
                    $changed[] = 'slug';
                }
            } else {
                $refused[] = 'slug_curated';
            }
        } else {
            $refused[] = 'title_curated';
        }

        if (blank($sermon->reference) && filled($analysis->reference)) {
            $updates['reference'] = $analysis->reference;
            $updates['scripture_passage_id'] = null;
            $changed[] = 'reference';
        } elseif (filled($sermon->reference)) {
            $refused[] = 'reference_curated';
        }

        if (($sermon->duration === null || $sermon->duration <= 0) && ($duration = $this->duration($processingLog)) !== null) {
            $updates['duration'] = $duration;
            $changed[] = 'duration';
        } elseif ($sermon->duration !== null && $sermon->duration > 0) {
            $refused[] = 'duration_curated';
        } else {
            $refused[] = 'duration_unavailable';
        }

        if (blank($sermon->series) && filled($analysis->series)) {
            $reference = $updates['reference'] ?? $sermon->reference;

            if ($this->seriesCorroboration->corroborates($analysis->series, $reference)) {
                $updates['series'] = $analysis->series;
                $changed[] = 'series';
            } else {
                $refused[] = 'series_not_corroborated';
            }
        } elseif (filled($sermon->series)) {
            $refused[] = 'series_curated';
        }

        if ($updates !== []) {
            $sermon->update($updates);
        }

        Log::info('Replayed banked historic sermon analysis', [
            'processing_id' => $processingLog->processing_id,
            'sermon_id' => $sermon->id,
            'changed' => $changed,
            'refused' => $refused,
        ]);

        return ['changed' => $changed, 'refused' => $refused];
    }

    private function duration(MediaProcessingLog $processingLog): ?float
    {
        if ($processingLog->sermon_start_time === null || $processingLog->sermon_end_time === null) {
            return null;
        }

        $duration = $processingLog->sermon_end_time - $processingLog->sermon_start_time;

        return $duration > 0 ? $duration : null;
    }
}
