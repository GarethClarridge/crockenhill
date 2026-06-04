<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\PreacherSource;
use App\Models\Preacher;
use App\Models\Sermon;
use App\Services\Preacher\PreacherResolutionService;
use App\Services\Processing\SermonIdentitySyncService;
use App\Traits\SanitizesLogData;
use Illuminate\Support\Facades\Log;

class SaveSermonDetails
{
    use SanitizesLogData;

    public function __construct(
        private readonly PreacherResolutionService $preacherResolutionService,
        private readonly SermonIdentitySyncService $sermonIdentitySyncService,
        private readonly QueueScriptureEnrichment $queueScriptureEnrichment,
    ) {}

    /**
     * @param  array{
     *   title: string, slug: string, date: string, service: string,
     *   preacher: string, preacherId: ?int, preacherSource: ?string,
     *   preacherConfidence: ?float, duration: ?float,
     *   segmentStartTime: ?float, segmentEndTime: ?float,
     *   downloadCount: ?int, reference: ?string, series: ?string,
     *   summary: ?string, points: array<int,string>,
     *   showSummary: bool, showPoints: bool
     * } $validated
     */
    public function execute(Sermon $sermon, array $validated): void
    {
        if ($validated['preacherId']) {
            $preacher = Preacher::query()->find($validated['preacherId']);
        } else {
            $preacher = $this->preacherResolutionService->resolve($validated['preacher']);
        }

        if (! ($preacher instanceof Preacher)) {
            $preacher = null;
        }

        $referenceChanged = $sermon->reference !== $validated['reference'];
        $newReference = $validated['reference'];
        $scripturePassage = $this->sermonIdentitySyncService->findExistingScripturePassage($newReference);
        $scripturePassageId = ($referenceChanged || $sermon->scripture_passage_id === null)
            ? $scripturePassage?->id
            : $sermon->scripture_passage_id;

        $updateData = [
            'title' => $validated['title'],
            'slug' => $validated['slug'],
            'date' => $validated['date'],
            'service' => $validated['service'],
            'preacher' => $preacher ? $preacher->name : $validated['preacher'],
            'preacher_id' => $preacher?->id,
            'preacher_source' => $preacher ? PreacherSource::Manual->value : $validated['preacherSource'],
            'preacher_confidence' => $validated['preacherConfidence'],
            'duration' => $validated['duration'],
            'segment_start_time' => $validated['segmentStartTime'],
            'segment_end_time' => $validated['segmentEndTime'],
            'download_count' => $validated['downloadCount'] ?? 0,
            'needs_preacher_review' => false,
            'reference' => $newReference,
            'scripture_passage_id' => $scripturePassageId,
            'series' => $validated['series'],
            'summary' => $validated['summary'],
            'points' => array_filter($validated['points']),
            'show_summary' => $validated['showSummary'],
            'show_points' => $validated['showPoints'],
        ];

        $sermon->update($updateData);

        $fresh = $sermon->fresh();
        Log::warning('Sermon updated by admin', [
            'admin_id' => auth()->id(),
            'sermon_id' => $sermon->id,
            'title' => $this->sanitizeForLog($fresh instanceof Sermon ? $fresh->title : $sermon->title),
            'slug' => $this->sanitizeForLog($fresh instanceof Sermon ? $fresh->slug : $sermon->slug),
        ]);

        // Dispatch enrichment after saving if reference was set or changed
        if ($referenceChanged && ! empty($newReference)) {
            $this->queueScriptureEnrichment->dispatch($fresh instanceof Sermon ? $fresh : $sermon);
        }
    }
}
