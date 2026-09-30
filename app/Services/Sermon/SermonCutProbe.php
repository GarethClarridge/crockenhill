<?php

declare(strict_types=1);

namespace App\Services\Sermon;

use App\Data\ChurchServiceTranscript;
use App\Data\ServiceStructure;
use App\Data\ServiceStructureSection;
use App\Models\MediaProcessingLog;
use App\Services\ChurchService\ServiceSectionSyncService;
use App\Services\ChurchService\Structure\ServiceStructureValidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * The sermon cut a proposed structure would produce, read without writing anything.
 *
 * The proposal is synced onto a copy of the run inside a transaction that is always rolled
 * back, and the production {@see SermonExtractionPlanResolver} plans the cut from it. The copy
 * has no sections and no media of its own, so the sync cannot delete an extracted file.
 */
class SermonCutProbe
{
    /** Flags the ensemble adds for its own questions; stripped to read the cut as written. */
    private const ENSEMBLE_FLAGS = [
        ServiceStructureValidator::FLAG_ENSEMBLE_DISAGREES,
        ServiceStructureValidator::FLAG_ENSEMBLE_DEGRADED,
    ];

    public function __construct(
        private readonly ServiceSectionSyncService $sync,
        private readonly SermonExtractionPlanResolver $resolver,
    ) {}

    /**
     * The cut as the pipeline would plan it now, and the cut it would plan if every
     * ensemble question were answered by accepting what was written.
     *
     * @return array{gated: array<string, mixed>, as_written: array<string, mixed>}
     */
    public function probe(MediaProcessingLog $log, ServiceStructure $structure, ChurchServiceTranscript $transcript): array
    {
        $accepted = ServiceStructure::fromSections(
            array_map(
                static fn (ServiceStructureSection $section): ServiceStructureSection => $section->withoutReviewFlags()
                    ->withReviewFlags(array_values(array_diff($section->reviewFlags, self::ENSEMBLE_FLAGS))),
                $structure->sections,
            ),
            $structure->notes,
            $structure->model,
            $structure->summary,
            $structure->notices,
            $structure->chapterMarkers,
            $structure->sermonAbsence,
        );

        return [
            'gated' => $this->plan($log, $structure, $transcript),
            'as_written' => $this->plan($log, $accepted, $transcript),
        ];
    }

    /** @return array<string, mixed> */
    private function plan(MediaProcessingLog $log, ServiceStructure $structure, ChurchServiceTranscript $transcript): array
    {
        DB::beginTransaction();

        try {
            $copy = $log->replicate(['dedup_key']);
            $copy->processing_id = Str::uuid()->toString();
            $copy->saveQuietly();

            $this->sync->sync($copy, $structure->toClassifiedSections($copy, $transcript));
            $plan = $this->resolver->resolve($copy->fresh() ?? $copy);

            return [
                'mode' => $plan['mode'],
                'strategy' => $plan['metadata']['strategy'] ?? $plan['metadata']['reason'] ?? null,
                'segments' => $plan['segments'],
                'from_sections' => $plan['source'] === 'service_sections',
            ];
        } catch (Throwable $exception) {
            return ['mode' => 'error', 'error' => $exception::class.': '.$exception->getMessage()];
        } finally {
            DB::rollBack();
        }
    }
}
