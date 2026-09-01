<?php

declare(strict_types=1);

namespace App\Services\ChurchService\SectionPublication;

use App\Data\ChurchServiceTranscript;
use App\Models\ServiceSection;
use App\Services\Media\Audio\RmsAnalysisService;
use App\Support\ServiceArtifactDisk;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * @phpstan-type BoundaryEvidenceInputs array{
 *     transcript: ChurchServiceTranscript|null,
 *     transcript_input: array{path: string|null, status: string},
 *     rms_data: list<array{time: float, rms: float}>,
 *     rms_threshold: float|null,
 *     rms_input: array{path: string|null, status: string, sample_count: int, threshold: float|null}
 * }
 * @phpstan-type BoundaryEvidencePayload array{
 *     version: int,
 *     candidate: array{kind: 'inclusive', start_time: float, end_time: float},
 *     action: 'retain_inclusive_candidate',
 *     inputs: array{
 *         service_transcript: array{path: string|null, status: string},
 *         rms_log: array{path: string|null, status: string, sample_count: int, threshold: float|null}
 *     },
 *     start_evidence: array<string, mixed>,
 *     end_evidence: array<string, mixed>,
 *     risks: list<array{kind: string, detail: string}>,
 *     decision: 'release_eligible'|'review'
 * }
 * @phpstan-type BoundaryEvidence array{
 *     version: int,
 *     candidate: array{kind: 'inclusive', start_time: float, end_time: float},
 *     action: 'retain_inclusive_candidate',
 *     inputs: array{
 *         service_transcript: array{path: string|null, status: string},
 *         rms_log: array{path: string|null, status: string, sample_count: int, threshold: float|null}
 *     },
 *     start_evidence: array<string, mixed>,
 *     end_evidence: array<string, mixed>,
 *     risks: list<array{kind: string, detail: string}>,
 *     decision: 'release_eligible'|'review',
 *     recorded_at: string
 * }
 *
 * Records the timed evidence around an inclusive song candidate.
 *
 * This is deliberately an evidence and routing pass, not an interval cutter.
 * The service transcript supplies wordless gaps and the RMS log confirms that a
 * gap contains audio rather than silence or an unobservable scene change. A
 * corroborated gap near a candidate edge is enough to hold the inclusive clip
 * for review, but never changes the section's stored times.
 */
final class SongPublicationBoundaryEvidenceService
{
    public const METADATA_KEY = 'song_publication_boundary';

    private const VERSION = 1;

    private const LEADING_CUE_WINDOW_SECONDS = 5.0;

    private const CANDIDATE_END_TOLERANCE_SECONDS = 5.0;

    /**
     * Statuses that mean the storage layer itself failed rather than that the
     * artifact is genuinely absent. They are held for review just the same, but
     * they are named separately and logged, because a misconfigured disk or an
     * unmounted volume would otherwise convert a whole pass into a review
     * backlog with nothing recorded to say why.
     */
    private const STORAGE_ERROR_STATUSES = ['unavailable', 'threshold_unavailable'];

    public function __construct(
        private readonly RmsAnalysisService $rmsAnalysisService,
    ) {}

    /**
     * @return BoundaryEvidence
     */
    public function assess(ServiceSection $section): array
    {
        $start = (float) $section->start_time;
        $end = (float) $section->end_time;
        $inputs = $this->loadInputs($section);

        if ($this->unavailableBoundaryInputs($inputs) !== []) {
            $storageError = $this->hasStorageError($inputs);

            if ($storageError) {
                Log::warning('Song boundary evidence could not be read from storage; holding the clip for review', [
                    'service_section_id' => $section->id,
                    'processing_id' => $section->processingLog->processing_id,
                    'service_transcript_status' => $inputs['transcript_input']['status'],
                    'rms_log_status' => $inputs['rms_input']['status'],
                    'missing_inputs' => $this->unavailableBoundaryInputs($inputs),
                ]);
            }

            $evidence = [
                'version' => self::VERSION,
                'candidate' => [
                    'kind' => 'inclusive',
                    'start_time' => $start,
                    'end_time' => $end,
                ],
                'action' => 'retain_inclusive_candidate',
                'inputs' => [
                    'service_transcript' => $inputs['transcript_input'],
                    'rms_log' => $inputs['rms_input'],
                ],
                'start_evidence' => $this->unavailableBoundaryEvidence('start', $section, $inputs),
                'end_evidence' => $this->unavailableBoundaryEvidence('end', $section, $inputs),
                'risks' => [[
                    'kind' => $storageError
                        ? 'song_boundary_evidence_unreadable'
                        : 'song_boundary_evidence_unavailable',
                    'detail' => $this->unavailableBoundaryDetail($inputs, $storageError),
                ]],
                'decision' => 'review',
            ];

            return $this->withRecordedAt($section, $evidence);
        }

        $cues = $inputs['transcript'] instanceof ChurchServiceTranscript
            ? $this->sectionCues($inputs['transcript'], $start, $end)
            : [];
        $gaps = $this->wordlessGaps($cues, $start, $end);
        $risks = [];

        $startEvidence = $this->defaultBoundaryEvidence(
            side: 'start',
            section: $section,
            cues: $cues,
            inputs: $inputs,
        );
        $endEvidence = $this->defaultBoundaryEvidence(
            side: 'end',
            section: $section,
            cues: $cues,
            inputs: $inputs,
        );

        $leading = $this->leadingObservation($inputs, $cues, $gaps, $start);

        if ($leading !== null) {
            $startEvidence = $leading['evidence'];

            if ($leading['risk'] === true) {
                $risks[] = $leading['reason'];
            }
        }

        $trailing = $this->trailingObservation($inputs, $cues, $gaps, $end);

        if ($trailing !== null) {
            $endEvidence = $trailing['evidence'];

            if ($trailing['risk'] === true) {
                $risks[] = $trailing['reason'];
            }
        }

        return $this->withRecordedAt($section, [
            'version' => self::VERSION,
            'candidate' => [
                'kind' => 'inclusive',
                'start_time' => $start,
                'end_time' => $end,
            ],
            'action' => 'retain_inclusive_candidate',
            'inputs' => [
                'service_transcript' => $inputs['transcript_input'],
                'rms_log' => $inputs['rms_input'],
            ],
            'start_evidence' => $startEvidence,
            'end_evidence' => $endEvidence,
            'risks' => $risks,
            'decision' => $risks === [] ? 'release_eligible' : 'review',
        ]);
    }

    /**
     * @param  BoundaryEvidenceInputs  $inputs
     * @return list<string>
     */
    private function unavailableBoundaryInputs(array $inputs): array
    {
        $unavailable = [];

        if ($inputs['transcript_input']['status'] !== 'available') {
            $unavailable[] = 'service transcript ('.$inputs['transcript_input']['status'].')';
        }

        if ($inputs['rms_input']['status'] !== 'available') {
            $unavailable[] = 'RMS log ('.$inputs['rms_input']['status'].')';
        }

        return $unavailable;
    }

    /**
     * @param  BoundaryEvidenceInputs  $inputs
     */
    private function hasStorageError(array $inputs): bool
    {
        return in_array($inputs['transcript_input']['status'], self::STORAGE_ERROR_STATUSES, true)
            || in_array($inputs['rms_input']['status'], self::STORAGE_ERROR_STATUSES, true);
    }

    /**
     * @param  BoundaryEvidenceInputs  $inputs
     * @return array<string, mixed>
     */
    private function unavailableBoundaryEvidence(string $side, ServiceSection $section, array $inputs): array
    {
        return [
            'decision' => 'review',
            'basis' => 'boundary_evidence_unavailable',
            'side' => $side,
            'candidate_time' => $side === 'start' ? (float) $section->start_time : (float) $section->end_time,
            'transcript_status' => $inputs['transcript_input']['status'],
            'rms_status' => $inputs['rms_input']['status'],
            'missing_inputs' => $this->unavailableBoundaryInputs($inputs),
            'storage_error' => $this->hasStorageError($inputs),
            'method' => 'positive_start_end_evidence_required',
        ];
    }

    /**
     * @param  BoundaryEvidenceInputs  $inputs
     */
    private function unavailableBoundaryDetail(array $inputs, bool $storageError = false): string
    {
        $lead = $storageError
            ? 'Boundary evidence could not be read from storage, so release eligibility cannot be established'
            : 'Positive transcript and RMS evidence is required at both candidate boundaries';

        return $lead.'; unavailable inputs: '.implode(', ', $this->unavailableBoundaryInputs($inputs)).'.';
    }

    /**
     * @param  BoundaryEvidencePayload  $evidence
     * @return BoundaryEvidence
     */
    private function withRecordedAt(ServiceSection $section, array $evidence): array
    {
        $recordedAt = null;
        $existing = $section->metadata?->toArray()[self::METADATA_KEY] ?? null;

        if (is_array($existing)) {
            $existingRecordedAt = $existing['recorded_at'] ?? null;
            unset($existing['recorded_at']);

            if ($existing == $evidence && is_string($existingRecordedAt) && $existingRecordedAt !== '') {
                $recordedAt = $existingRecordedAt;
            }
        }

        $evidence['recorded_at'] = $recordedAt ?? now()->toIso8601String();

        return $evidence;
    }

    /**
     * @return array{
     *     transcript: ChurchServiceTranscript|null,
     *     transcript_input: array{path: string|null, status: string},
     *     rms_data: list<array{time: float, rms: float}>,
     *     rms_threshold: float|null,
     *     rms_input: array{path: string|null, status: string, sample_count: int, threshold: float|null}
     * }
     */
    private function loadInputs(ServiceSection $section): array
    {
        $processingLog = $section->processingLog;
        $transcriptPath = $processingLog->serviceTranscriptPath();
        $transcript = null;
        $transcriptStatus = $transcriptPath === null ? 'not_recorded' : 'missing';

        if ($transcriptPath !== null) {
            try {
                $disk = ServiceArtifactDisk::for($transcriptPath);

                if (Storage::disk($disk)->exists($transcriptPath)) {
                    $decoded = json_decode(
                        (string) Storage::disk($disk)->get($transcriptPath),
                        true,
                        512,
                        JSON_THROW_ON_ERROR,
                    );
                    $transcript = ChurchServiceTranscript::fromArray($decoded);
                    $transcriptStatus = $transcript->isEmpty() ? 'empty' : 'available';
                }
            } catch (\Throwable) {
                $transcript = null;
                $transcriptStatus = 'unavailable';
            }
        }

        $rmsPath = is_string($processingLog->rms_log_path) && $processingLog->rms_log_path !== ''
            ? $processingLog->rms_log_path
            : null;
        $rmsData = [];
        $rmsThreshold = null;
        $rmsStatus = $rmsPath === null ? 'not_recorded' : 'missing';

        if ($rmsPath !== null) {
            try {
                $disk = ServiceArtifactDisk::for($rmsPath);

                if (Storage::disk($disk)->exists($rmsPath)) {
                    $rmsContent = (string) Storage::disk($disk)->get($rmsPath);
                    $rmsData = $this->rmsAnalysisService->extractRmsData($rmsContent);

                    if ($rmsData === []) {
                        $rmsStatus = 'empty';
                    } else {
                        try {
                            $rmsThreshold = (float) $this->rmsAnalysisService->determineThreshold($rmsContent)['threshold'];
                            $rmsStatus = 'available';
                        } catch (\Throwable) {
                            $rmsStatus = 'threshold_unavailable';
                        }
                    }
                }
            } catch (\Throwable) {
                $rmsData = [];
                $rmsThreshold = null;
                $rmsStatus = 'unavailable';
            }
        }

        return [
            'transcript' => $transcript,
            'transcript_input' => [
                'path' => $transcriptPath,
                'status' => $transcriptStatus,
            ],
            'rms_data' => $rmsData,
            'rms_threshold' => $rmsThreshold,
            'rms_input' => [
                'path' => $rmsPath,
                'status' => $rmsStatus,
                'sample_count' => count($rmsData),
                'threshold' => $rmsThreshold,
            ],
        ];
    }

    /**
     * @return list<array{start: float, end: float, text: string}>
     */
    private function sectionCues(ChurchServiceTranscript $transcript, float $start, float $end): array
    {
        return array_values(array_filter(
            $transcript->cues,
            static fn (array $cue): bool => $cue['end'] > $start && $cue['start'] < $end,
        ));
    }

    /**
     * @param  list<array{start: float, end: float, text: string}>  $cues
     * @return list<array{
     *     start_time: float,
     *     end_time: float,
     *     duration: float,
     *     previous_index: int,
     *     next_index: int,
     *     previous_cue_end: float,
     *     next_cue_start: float
     * }>
     */
    private function wordlessGaps(array $cues, float $start, float $end): array
    {
        $minimumGap = (float) config(
            'media-processing.section_publishing.song_boundary.minimum_wordless_gap_seconds',
            3,
        );
        $gaps = [];

        for ($index = 0; $index < count($cues) - 1; $index++) {
            $previousEnd = max($start, $cues[$index]['end']);
            $nextStart = min($end, $cues[$index + 1]['start']);
            $duration = $nextStart - $previousEnd;

            if ($duration < $minimumGap) {
                continue;
            }

            $gaps[] = [
                'start_time' => $previousEnd,
                'end_time' => $nextStart,
                'duration' => $duration,
                'previous_index' => $index,
                'next_index' => $index + 1,
                'previous_cue_end' => $cues[$index]['end'],
                'next_cue_start' => $cues[$index + 1]['start'],
            ];
        }

        return $gaps;
    }

    /**
     * @param  BoundaryEvidenceInputs  $inputs
     * @param  list<array{start: float, end: float, text: string}>  $cues
     * @param  list<array{
     *     start_time: float,
     *     end_time: float,
     *     duration: float,
     *     previous_index: int,
     *     next_index: int,
     *     previous_cue_end: float,
     *     next_cue_start: float
     * }>  $gaps
     * @return array{risk: bool, evidence: array<string, mixed>, reason: array{kind: string, detail: string}}|null
     */
    private function leadingObservation(array $inputs, array $cues, array $gaps, float $start): ?array
    {
        if ($cues === [] || $gaps === [] || $cues[0]['start'] > $start + self::LEADING_CUE_WINDOW_SECONDS) {
            return null;
        }

        $gap = $gaps[0];
        $rms = $this->rmsEvidence($inputs, $gap['start_time'], $gap['end_time']);
        $baseEvidence = [
            'basis' => 'timed_transcript_wordless_gap',
            'candidate_start_time' => $start,
            'first_cue_start_time' => $cues[0]['start'],
            'gap' => $gap,
            'rms' => $rms,
        ];

        if ($this->gapIsUnobservable($inputs['transcript'], $gap['start_time'], $gap['end_time'])) {
            return [
                'risk' => false,
                'evidence' => [
                    ...$baseEvidence,
                    'decision' => 'keep_inclusive',
                    'basis' => 'unobservable_gap',
                ],
                'reason' => [
                    'kind' => 'song_boundary_unobservable_gap',
                    'detail' => 'The first transcript gap overlaps an unobservable window, so it is not treated as evidence for a song boundary.',
                ],
            ];
        }

        if ($rms['status'] !== 'audio_present') {
            return [
                'risk' => false,
                'evidence' => [
                    ...$baseEvidence,
                    'decision' => 'keep_inclusive',
                    'basis' => 'transcript_gap_without_rms_corroboration',
                ],
                'reason' => [
                    'kind' => 'song_boundary_without_rms_corroboration',
                    'detail' => 'A transcript gap was observed, but the RMS log does not independently corroborate audio in it.',
                ],
            ];
        }

        $gapOffset = $gap['start_time'] - $start;
        $maximum = (float) config(
            'media-processing.section_publishing.song_boundary.max_spoken_framing_seconds',
            30,
        );
        $exceedsMaximum = $maximum > 0.0 && $gapOffset > $maximum;
        $kind = $exceedsMaximum
            ? 'song_boundary_spoken_framing_exceeds_limit'
            : 'song_boundary_spoken_framing';
        $detail = $exceedsMaximum
            ? sprintf(
                'Timed transcript and RMS evidence place the first audio-backed wordless gap %.1fs into the candidate, beyond the %.1fs framing limit; the inclusive clip is held for review.',
                $gapOffset,
                $maximum,
            )
            : sprintf(
                'Timed transcript and RMS evidence show spoken framing followed by an audio-backed wordless gap %.1fs into the candidate; the inclusive clip is held for review.',
                $gapOffset,
            );

        return [
            'risk' => true,
            'evidence' => [
                ...$baseEvidence,
                'decision' => 'review',
                'gap_offset_seconds' => $gapOffset,
                'maximum_spoken_framing_seconds' => $maximum,
            ],
            'reason' => [
                'kind' => $kind,
                'detail' => $detail,
            ],
        ];
    }

    /**
     * @param  BoundaryEvidenceInputs  $inputs
     * @param  list<array{start: float, end: float, text: string}>  $cues
     * @param  list<array{
     *     start_time: float,
     *     end_time: float,
     *     duration: float,
     *     previous_index: int,
     *     next_index: int,
     *     previous_cue_end: float,
     *     next_cue_start: float
     * }>  $gaps
     * @return array{risk: bool, evidence: array<string, mixed>, reason: array{kind: string, detail: string}}|null
     */
    private function trailingObservation(array $inputs, array $cues, array $gaps, float $end): ?array
    {
        if (count($cues) < 2 || $gaps === []) {
            return null;
        }

        $tailWindow = (float) config(
            'media-processing.section_publishing.song_boundary.trailing_evidence_window_seconds',
            60,
        );
        $minimumTrailingContent = (float) config(
            'media-processing.section_publishing.song_boundary.minimum_trailing_content_seconds',
            10,
        );

        if ($tailWindow <= 0.0) {
            return null;
        }

        $lastCue = $cues[count($cues) - 1];

        // Without timed content reaching the candidate's edge there is no tail
        // to judge, only a candidate that stops before its recorded end.
        if ($lastCue['end'] < $end - self::CANDIDATE_END_TOLERANCE_SECONDS) {
            return null;
        }

        /**
         * Take the last wordless gap inside the tail window, whatever cue
         * follows it.
         *
         * Requiring the gap to sit immediately before the *final* cue only ever
         * matched a tail transcribed as one cue. A benediction arrives as
         * several, so the case this exists to catch -- roughly 27 seconds of
         * speech after the singing -- matched nothing at all. Taking the last
         * qualifying gap also measures the tail conservatively: an internal
         * pause inside the tail shortens the measured span, which errs towards
         * keeping the clip inclusive rather than towards a review hold.
         */
        $gap = null;

        foreach (array_reverse($gaps) as $candidateGap) {
            if ($candidateGap['end_time'] < $end - $tailWindow) {
                break;
            }

            $gap = $candidateGap;

            break;
        }

        if ($gap === null) {
            return null;
        }

        /**
         * Measure the trailing span -- the end of the gap to the end of the
         * candidate -- not the final cue's own length. A closing cue of four
         * seconds at the end of a 27-second tail is still a 27-second tail.
         */
        $trailingContentSeconds = $end - $gap['end_time'];

        if ($trailingContentSeconds < $minimumTrailingContent) {
            return null;
        }

        $rms = $this->rmsEvidence($inputs, $gap['start_time'], $gap['end_time']);
        $baseEvidence = [
            'basis' => 'timed_transcript_wordless_gap_before_final_cue',
            'candidate_end_time' => $end,
            'final_cue' => [
                'start' => $lastCue['start'],
                'end' => $lastCue['end'],
                'duration' => $lastCue['end'] - $lastCue['start'],
            ],
            'trailing_content_seconds' => $trailingContentSeconds,
            'gap' => $gap,
            'rms' => $rms,
        ];

        if ($this->gapIsUnobservable($inputs['transcript'], $gap['start_time'], $gap['end_time'])) {
            return [
                'risk' => false,
                'evidence' => [
                    ...$baseEvidence,
                    'decision' => 'keep_inclusive',
                    'basis' => 'unobservable_gap',
                ],
                'reason' => [
                    'kind' => 'song_boundary_unobservable_gap',
                    'detail' => 'The final transcript gap overlaps an unobservable window, so it is not treated as evidence of following content.',
                ],
            ];
        }

        if ($rms['status'] !== 'audio_present') {
            return [
                'risk' => false,
                'evidence' => [
                    ...$baseEvidence,
                    'decision' => 'keep_inclusive',
                    'basis' => 'transcript_gap_without_rms_corroboration',
                ],
                'reason' => [
                    'kind' => 'song_boundary_without_rms_corroboration',
                    'detail' => 'A final transcript gap was observed, but the RMS log does not independently corroborate audio in it.',
                ],
            ];
        }

        return [
            'risk' => true,
            'evidence' => [
                ...$baseEvidence,
                'decision' => 'review',
                'tail_window_seconds' => $tailWindow,
                'minimum_trailing_content_seconds' => $minimumTrailingContent,
            ],
            'reason' => [
                'kind' => 'song_boundary_trailing_content',
                'detail' => sprintf(
                    'Timed transcript and RMS evidence show a final audio-backed wordless gap followed by %.1fs of timed content at the candidate edge; the inclusive clip is held for review.',
                    $trailingContentSeconds,
                ),
            ],
        ];
    }

    /**
     * @param  array{
     *     rms_data: list<array{time: float, rms: float}>,
     *     rms_threshold: float|null
     * }  $inputs
     * @return array{status: string, sample_count: int, active_sample_count: int, active_ratio: float, threshold: float|null, peak_rms: float|null}
     */
    private function rmsEvidence(array $inputs, float $start, float $end): array
    {
        $threshold = $inputs['rms_threshold'];

        if ($threshold === null) {
            return [
                'status' => 'unavailable',
                'sample_count' => 0,
                'active_sample_count' => 0,
                'active_ratio' => 0.0,
                'threshold' => null,
                'peak_rms' => null,
            ];
        }

        $samples = array_values(array_filter(
            $inputs['rms_data'],
            static fn (array $sample): bool => $sample['time'] >= $start
                && $sample['time'] <= $end
                && $sample['rms'] > -999.0,
        ));

        if ($samples === []) {
            return [
                'status' => 'no_samples',
                'sample_count' => 0,
                'active_sample_count' => 0,
                'active_ratio' => 0.0,
                'threshold' => $threshold,
                'peak_rms' => null,
            ];
        }

        $activeSamples = array_filter(
            $samples,
            static fn (array $sample): bool => $sample['rms'] > $threshold,
        );
        $activeRatio = count($activeSamples) / count($samples);
        $minimumActiveRatio = max(0.0, min(1.0, (float) config(
            'media-processing.section_publishing.song_boundary.minimum_rms_active_ratio',
            0.25,
        )));

        return [
            'status' => $activeRatio >= $minimumActiveRatio ? 'audio_present' : 'quiet',
            'sample_count' => count($samples),
            'active_sample_count' => count($activeSamples),
            'active_ratio' => round($activeRatio, 3),
            'threshold' => $threshold,
            'peak_rms' => round(max(array_column($samples, 'rms')), 1),
        ];
    }

    /**
     * @param  BoundaryEvidenceInputs  $inputs
     * @param  list<array{start: float, end: float, text: string}>  $cues
     * @return array<string, mixed>
     */
    private function defaultBoundaryEvidence(string $side, ServiceSection $section, array $cues, array $inputs): array
    {
        return [
            'decision' => 'keep_inclusive',
            'basis' => 'inclusive_candidate',
            'side' => $side,
            'candidate_time' => $side === 'start' ? (float) $section->start_time : (float) $section->end_time,
            'transcript_status' => $inputs['transcript_input']['status'],
            'rms_status' => $inputs['rms_input']['status'],
            'timed_cues_in_candidate' => count($cues),
            'method' => 'no_recut_before_bulk',
        ];
    }

    private function gapIsUnobservable(?ChurchServiceTranscript $transcript, float $start, float $end): bool
    {
        if (! $transcript instanceof ChurchServiceTranscript) {
            return false;
        }

        foreach ($transcript->unobservableWindows as $window) {
            if ($window['end'] > $start && $window['start'] < $end) {
                return true;
            }
        }

        return false;
    }
}
