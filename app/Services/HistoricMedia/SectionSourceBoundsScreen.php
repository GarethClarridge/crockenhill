<?php

declare(strict_types=1);

namespace App\Services\HistoricMedia;

use App\Actions\FlagSectionTruncatedBySource;
use App\Data\ChurchServiceTranscript;
use App\Data\ServiceSectionMetadata;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Support\ServiceArtifactDisk;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Check every section against the media it is timed on, and hold its bounds to
 * what that media actually contains.
 *
 * P8-Q17. A section end is a claim about the recording, and it is the only claim
 * in the structure that can be *refuted* rather than merely doubted: a file has
 * one length, FFprobe reports it, and no amount of transcript evidence can put
 * content after it. That makes this the one screen in the Phase 8 exit gate that
 * settles rather than escalates.
 *
 * **Clamping is safe here and is not safe for overlaps.** The plan warns against
 * blanket snapping because two adjacent sections disputing a boundary are both
 * describing real time, and snapping picks a winner by arithmetic. Past EOF
 * there is no time to dispute. The 30-second grid these ends sit on gives the
 * mechanism away — 2400.00 over a 2370.34s recording — and it traces to a
 * hallucinated final transcript cue, fixed at source in
 * {@see ChurchServiceTranscript::fromCues()}.
 *
 * **The repair is reversible.** The detector's end is preserved at
 * `source_bounds.recorded_end` and the clamp is derived from it every run, so a
 * restage that supplies a longer source restores the original bound rather than
 * leaving the section permanently shortened by a measurement taken when part of
 * the recording was missing.
 *
 * A run with no measured duration is reported as unmeasurable, never as passing.
 * {@see HistoricSourceDurationBackfill} resolves those from the archive.
 *
 * Deletion trigger: delete once Phase 8 closes and the release census has run
 * against a corpus with no unmeasurable runs left.
 */
class SectionSourceBoundsScreen
{
    public const DispositionWithin = 'within';

    public const DispositionPastSourceEnd = 'past_source_end';

    public const DispositionBeyondSourceStart = 'beyond_source_start';

    public const DispositionRestorable = 'restorable';

    public const DispositionUnmeasurable = 'unmeasurable';

    /**
     * Below this an overrun is float noise between a stored bound and an FFprobe
     * reading, not media that is missing.
     *
     * The corpus leaves no judgement to make: 18 sections overrun by 16.80s or
     * more and the next largest overrun is 0.01s. Any threshold in that gap
     * selects the same 18.
     */
    private const ToleranceSeconds = 0.05;

    public function __construct(
        private readonly HistoricStagingContextRegistry $stagingContexts,
        private readonly FlagSectionTruncatedBySource $flagTruncation,
    ) {}

    /**
     * @param  Collection<int, MediaProcessingLog>  $runs
     * @return list<SectionSourceBoundsEntry>
     */
    public function inspect(Collection $runs): array
    {
        $entries = [];

        foreach ($runs as $run) {
            $measured = $this->measuredDuration($run);
            $cuesPastEnd = $measured === null ? null : $this->cuesPastSourceEnd($run, $measured);

            $sections = $run->serviceSections()
                ->orderBy('section_order')
                ->orderBy('start_time')
                ->get();

            foreach ($sections as $section) {
                $entries[] = $this->assess($run, $section, $measured, $cuesPastEnd);
            }
        }

        return $entries;
    }

    /**
     * Write the resolved bounds, and hold what the media truncated.
     *
     * Each section is re-assessed under its own lock rather than trusting the
     * inspection that produced the entry: the clamp is a durable narrowing of a
     * published span, and a section edited between the dry run and the write
     * must not be narrowed against a bound nobody looked at.
     *
     * @param  list<SectionSourceBoundsEntry>  $entries
     * @return array{clamped: int, restored: int, held: int, released: int, unchanged: int, failures: list<string>}
     */
    public function apply(array $entries): array
    {
        $totals = ['clamped' => 0, 'restored' => 0, 'held' => 0, 'released' => 0, 'unchanged' => 0, 'failures' => []];

        foreach ($entries as $entry) {
            /*
             * Only a section whose bounds are actually in question is opened.
             * A section that sits inside its media has nothing to write and no
             * hold to withdraw — a hold can only exist where a clamp recorded
             * one, and such a section reports `restorable`, never `within`. So
             * this is a narrowing of work, not of correctness; without it the
             * pass would lock all 4,684 rows to change 18.
             */
            if (! $entry->isRepairable()) {
                continue;
            }

            try {
                $outcome = DB::transaction(fn (): array => $this->write($entry));
            } catch (Throwable $exception) {
                $totals['failures'][] = "Section {$entry->sectionId}: {$exception->getMessage()}";

                continue;
            }

            foreach ($outcome as $key => $count) {
                $totals[$key] += $count;
            }
        }

        return $totals;
    }

    /**
     * @return array{clamped: int, restored: int, held: int, released: int, unchanged: int}
     */
    private function write(SectionSourceBoundsEntry $entry): array
    {
        $section = ServiceSection::query()->whereKey($entry->sectionId)->lockForUpdate()->first();

        if (! $section instanceof ServiceSection) {
            throw new RuntimeException('the section disappeared before its bounds were written');
        }

        $run = $section->processingLog()->first();

        if (! $run instanceof MediaProcessingLog) {
            throw new RuntimeException('the section has no processing run, so its media cannot be measured');
        }

        $measured = $this->measuredDuration($run);

        if ($measured === null) {
            throw new RuntimeException('the run lost its measured duration between inspection and the write');
        }

        $current = $this->assess($run, $section, $measured, $entry->cuesPastSourceEnd);
        $outcome = ['clamped' => 0, 'restored' => 0, 'held' => 0, 'released' => 0, 'unchanged' => 0];

        if ($current->disposition === self::DispositionBeyondSourceStart) {
            throw new RuntimeException(sprintf(
                'the whole section starts at %.2fs, past the %.2fs the media holds, so no clamp can express it',
                $current->startTime,
                $measured,
            ));
        }

        $resolvedEnd = $current->resolvedEnd();

        if ($resolvedEnd !== null && abs($resolvedEnd - $section->end_time) > self::ToleranceSeconds) {
            $restoring = $resolvedEnd > $section->end_time;

            $section->end_time = $resolvedEnd;
            $section->duration = $resolvedEnd - $section->start_time;
            $section->metadata = ServiceSectionMetadata::fromArray(
                $this->recordBounds($section, $current, $measured, $restoring),
            );
            $section->save();

            $outcome[$restoring ? 'restored' : 'clamped']++;
        }

        $held = ($this->flagTruncation)($section, $current->overrunSeconds());

        if ($held) {
            $outcome[$current->overrunSeconds() > 0.0 ? 'held' : 'released']++;
        }

        if (array_sum($outcome) === 0) {
            $outcome['unchanged']++;
        }

        return $outcome;
    }

    /**
     * Preserve what the detector claimed, so the overrun stays measurable.
     *
     * Written once and never overwritten by a later pass: after the first clamp
     * `end_time` *is* the measured duration, so re-deriving `recorded_end` from
     * it would erase the only surviving evidence that the section was truncated
     * and silently release the hold that evidence earns.
     *
     * A restore drops the record instead of updating it. The claim now fits the
     * media, there is nothing left to hold, and a lingering record would keep
     * describing a shortfall that no longer exists.
     *
     * @return array<string, mixed>
     */
    private function recordBounds(
        ServiceSection $section,
        SectionSourceBoundsEntry $entry,
        float $measured,
        bool $restoring,
    ): array {
        $metadata = $section->metadata?->toArray() ?? [];

        if ($restoring) {
            unset($metadata['source_bounds']);

            return $metadata;
        }

        $metadata['source_bounds'] = [
            'recorded_end' => $entry->recordedEnd,
            'measured_duration' => $measured,
            'removed_seconds' => $entry->overrunSeconds(),
            'cues_past_source_end' => $entry->cuesPastSourceEnd,
            'clamped_at' => now()->toISOString(),
        ];

        return $metadata;
    }

    private function assess(
        MediaProcessingLog $run,
        ServiceSection $section,
        ?float $measured,
        ?int $cuesPastEnd,
    ): SectionSourceBoundsEntry {
        $recordedEnd = $this->recordedEnd($section);

        $disposition = match (true) {
            $measured === null => self::DispositionUnmeasurable,
            $section->start_time >= $measured => self::DispositionBeyondSourceStart,
            $recordedEnd - $measured > self::ToleranceSeconds => self::DispositionPastSourceEnd,
            $recordedEnd - $section->end_time > self::ToleranceSeconds => self::DispositionRestorable,
            default => self::DispositionWithin,
        };

        return new SectionSourceBoundsEntry(
            sectionId: $section->id,
            logId: $run->id,
            processingId: $run->processing_id,
            sectionType: $section->section_type,
            publicationStatus: $section->publication_status->value,
            startTime: $section->start_time,
            endTime: $section->end_time,
            disposition: $disposition,
            recordedEnd: $recordedEnd,
            measuredDuration: $measured,
            cuesPastSourceEnd: $cuesPastEnd,
            reason: $disposition === self::DispositionUnmeasurable
                ? 'the run records no positive source duration'
                : null,
        );
    }

    /**
     * The end the detector produced, which outlives any clamp applied to it.
     */
    private function recordedEnd(ServiceSection $section): float
    {
        $recorded = data_get($section->metadata?->toArray() ?? [], 'source_bounds.recorded_end');

        return is_numeric($recorded) ? (float) $recorded : $section->end_time;
    }

    private function measuredDuration(MediaProcessingLog $run): ?float
    {
        $duration = $run->duration;

        if (! is_numeric($duration)) {
            return null;
        }

        $duration = (float) $duration;

        return is_finite($duration) && $duration > 0.0 ? $duration : null;
    }

    /**
     * How many stored transcript cues reach past the media, or null when the
     * transcript cannot be read.
     *
     * Corroboration rather than a verdict: it says the overrunning bound came
     * from an overrunning cue instead of from a mis-stored duration, which is
     * what makes the clamp obviously right rather than merely arithmetically
     * available. A missing transcript does not stop the screen — the media's
     * length is sufficient on its own — so every failure resolves to null.
     */
    private function cuesPastSourceEnd(MediaProcessingLog $run, float $measured): ?int
    {
        $path = $run->serviceTranscriptPath();

        if (! is_string($path) || $path === '') {
            return null;
        }

        $read = function () use ($path, $measured): ?int {
            $disk = Storage::disk(ServiceArtifactDisk::for($path));

            if (! $disk->exists($path)) {
                return null;
            }

            $decoded = json_decode((string) $disk->get($path), true);

            if (! is_array($decoded)) {
                return null;
            }

            $cues = is_array($decoded['cues'] ?? null) ? $decoded['cues'] : [];
            $past = 0;

            foreach ($cues as $cue) {
                if (is_array($cue) && is_numeric($cue['end'] ?? null) && (float) $cue['end'] - $measured > self::ToleranceSeconds) {
                    $past++;
                }
            }

            return $past;
        };

        try {
            $context = $run->historicStagingContext();

            return $context === null ? $read() : $this->stagingContexts->within($context, $read);
        } catch (Throwable) {
            return null;
        }
    }
}
