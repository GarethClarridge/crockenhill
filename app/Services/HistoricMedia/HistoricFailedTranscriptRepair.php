<?php

declare(strict_types=1);

namespace App\Services\HistoricMedia;

use App\Data\ChurchServiceTranscript;
use App\Enums\MediaType;
use App\Enums\ProcessingStatus;
use App\Enums\ServiceSectionType;
use App\Exceptions\OutputEdgeTimingsMissing;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\ChurchService\CueSafeExtractionPlan;
use App\Services\ChurchService\OutputEdgeWordTimings;
use App\Services\ChurchService\PrepareOutputEdgeWordTimings;
use App\Services\Media\Audio\LocalWhisperServiceTranscriptionService;
use App\Services\Media\Audio\ServiceArtifactStorage;
use App\Services\Media\Audio\ServiceTranscriptReader;
use App\Services\Media\Audio\ServiceTranscriptRepetitionScreen;
use App\Services\Sermon\SermonExtractionPlanResolver;
use App\Support\CanonicalJson;
use App\Support\MediaProcessingVersion;
use App\Support\ServiceArtifactDisk;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use RuntimeException;
use Throwable;

/**
 * Source-bound, window-only adoption of banked H10b evidence.
 *
 * Deletion trigger: adopted repairs reconciled and historic closeout retention expired.
 *
 * @phpstan-import-type Report from HistoricFailedTranscriptCandidates
 */
class HistoricFailedTranscriptRepair
{
    public const SCHEMA = 'historic-failed-transcript-v1';

    public const RECEIPTS = 'historic_failed_transcript_repairs';

    private const SNAPSHOT_KEYS = ['service_transcript_path', 'service_transcript_unobservable_windows',
        'service_transcript_suspect_blocks', 'service_transcript_content'];

    public function __construct(
        private readonly HistoricStagingContextRegistry $contexts,
        private readonly ServiceTranscriptReader $reader,
        private readonly HistoricFailedTranscriptCandidates $candidates,
        private readonly ServiceArtifactStorage $artifacts,
        private readonly ServiceTranscriptRepetitionScreen $screen,
        private readonly CueSafeExtractionPlan $planner,
        private readonly SermonExtractionPlanResolver $sermons,
    ) {}

    /** @param list<string> $listeningPaths
     * @param  list<string>  $alternativePaths
     * @return array<string, mixed>
     */
    public function inspect(MediaProcessingLog $run, string $donorPath, array $listeningPaths = [], array $alternativePaths = []): array
    {
        return $this->within($run, function () use ($run, $donorPath, $listeningPaths, $alternativePaths): array {
            $current = $this->reader->read($run);
            $donor = $this->readJson($donorPath);
            Validator::make($donor, [
                'schema' => 'required|in:h10b-redecode-v1', 'run_id' => 'required|integer',
                'processing_id' => 'required|string', 'source.sha256' => 'required|string|size:64',
                'stored_transcript.sha256' => 'required|string|size:64', 'decode' => 'required|array',
                'new_transcript' => 'required|array', 'new_transcript.cues' => 'present|array',
                'new_transcript.source' => 'required|in:local_whisper',
            ])->validate();
            if ((int) $donor['run_id'] !== $run->id || $donor['processing_id'] !== $run->processing_id
                || data_get($donor, 'source.sha256') !== $run->stagedSourceFileHash()
                || $current->source !== ChurchServiceTranscript::SOURCE_LOCAL_WHISPER) {
                throw new RuntimeException('Donor run, processing identity, source hash or transcript source mismatch.');
            }
            /** @var array<string, mixed> $new */
            $new = $donor['new_transcript'];
            $report = $this->candidates->inspect($current, $new, $this->planner->audioTimeline($run));
            $alternatives = [];
            sort($alternativePaths);
            foreach ($alternativePaths as $alternativePath) {
                $alternative = $this->readJson($alternativePath);
                $other = $alternative['new_transcript'] ?? null;
                if (! is_array($other) || ($alternative['run_id'] ?? null) !== $run->id
                    || ($alternative['processing_id'] ?? null) !== $run->processing_id
                    || data_get($alternative, 'source.sha256') !== $run->stagedSourceFileHash()) {
                    throw new RuntimeException('Alternative decode identity or source mismatch.');
                }
                $otherReport = $this->candidates->inspect($current, $other, $this->planner->audioTimeline($run));
                $disagreements = [];
                foreach ($report['windows'] as $window) {
                    $text = static fn (array $r): array => array_map(static fn (array $c): array => $c['cue'],
                        array_values(array_filter($r['candidates'], static fn (array $c): bool => $c['window'] === $window)));
                    if (CanonicalJson::hash($text($report)) !== CanonicalJson::hash($text($otherReport))) {
                        $disagreements[] = $window;
                    }
                }
                $alternatives[] = ['path' => $alternativePath, 'sha256' => hash_file('sha256', $alternativePath),
                    'disagreements' => $disagreements, 'report' => $otherReport];
                foreach ($report['candidates'] as &$candidate) {
                    if (in_array($candidate['window'], $disagreements, true) && ! in_array('alternative_disagreement', $candidate['flags'], true)) {
                        $candidate['flags'][] = 'alternative_disagreement';
                    }
                }
                unset($candidate);
            }
            $listening = $this->listeningEvidence($run, $listeningPaths);
            foreach ($report['candidates'] as &$candidate) {
                foreach ($listening as $evidence) {
                    foreach ($evidence['verdicts'] as $verdict) {
                        if (in_array($verdict['verdict'] ?? null, ['new_wrong', 'both_wrong', 'cant_tell'], true)
                            && is_numeric($verdict['start'] ?? null)
                            && $candidate['cue']['start'] < (float) $verdict['start'] + 30
                            && $candidate['cue']['end'] > (float) $verdict['start']) {
                            $candidate['flags'][] = 'adverse_listening_verdict';
                        }
                    }
                }
            }
            unset($candidate);
            $sections = $run->serviceSections()->orderBy('id')->get();
            $edges = [];
            foreach ($sections as $section) {
                foreach (['start' => (float) $section->start_time, 'end' => (float) $section->end_time] as $edge => $time) {
                    foreach ($report['windows'] as $window) {
                        if ($time >= $window['start'] - 0.5 && $time <= $window['end'] + 0.5) {
                            $edges[] = ['section_id' => $section->id, 'type' => $section->section_type->value,
                                'publication_status' => $section->publication_status->value, 'edge' => $edge, 'time' => $time, 'window' => $window];
                        }
                    }
                }
            }

            $listeningIds = array_values(array_map(static fn (array $c): string => $c['id'], array_filter(
                $report['candidates'], static fn (array $c): bool => $c['flags'] === []
                    && array_any($edges, static fn (array $edge): bool => $edge['window'] === $c['window']),
            )));
            sort($listeningIds);

            return $this->seal([
                'schema' => self::SCHEMA, 'kind' => 'proposal', 'run_id' => $run->id, 'processing_id' => $run->processing_id,
                'source_sha256' => $run->stagedSourceFileHash(), 'state_sha256' => CanonicalJson::hash($run->getAttributes()),
                'sections_sha256' => CanonicalJson::hash($sections->map->getAttributes()->all()),
                'code_sha256' => $this->codeHash(), 'evidence_sha256' => $this->plannerEvidenceHash($run),
                'before_path' => $run->serviceTranscriptPath(), 'before' => $current->toArray(),
                'before_sha256' => CanonicalJson::hash($current->toArray()),
                'donor' => ['path' => $donorPath, 'sha256' => hash_file('sha256', $donorPath),
                    'stored_transcript_sha256' => data_get($donor, 'stored_transcript.sha256'), 'decode' => $donor['decode']],
                'requires_reconciliation' => data_get($donor, 'stored_transcript.sha256') !== CanonicalJson::hash($current->toArray()),
                'listening' => $listening, 'alternatives' => $alternatives, 'edges' => $edges, 'report' => $report,
                'listening_candidate_ids' => $listeningIds,
            ]);
        });
    }

    /**
     * A review accepts exact cue IDs, with a source interval covering every word.
     * This creates a reviewable adoption artifact; it never installs it.
     *
     * @param  array<string, mixed>  $proposal
     * @param  array<string, mixed>  $review
     * @return array<string, mixed>
     */
    public function prepare(array $proposal, array $review): array
    {
        $this->verifySeal($proposal, 'proposal');
        $run = $this->proposalRun($proposal);
        $proposal = $this->refreshProposal($run, $proposal);
        Validator::make($review, [
            'operator' => 'required|string|min:1',
            'decisions' => 'present|array', 'decisions.*.id' => 'required|string|distinct',
            'decisions.*.verdict' => 'required|in:accept,reject,cannot_tell',
            'decisions.*.evidence' => 'required|string|min:1',
        ])->validate();
        if (($proposal['requires_reconciliation'] ?? true) && trim((string) ($review['reconciliation'] ?? '')) === '') {
            throw new RuntimeException('Changed September transcript requires explicit window reconciliation.');
        }

        return $this->within($run, function () use ($run, $proposal, $review): array {
            /** @var Report $report */
            $report = $proposal['report'];
            $known = array_column($report['candidates'], null, 'id');
            $accepted = [];
            /** @var list<array<string, mixed>> $decisions */
            $decisions = $review['decisions'];
            foreach ($decisions as $decision) {
                $id = (string) $decision['id'];
                if (! isset($known[$id])) {
                    throw new RuntimeException('Review names an unknown candidate: '.$id);
                }
                if ($decision['verdict'] !== 'accept') {
                    continue;
                }
                $cue = $known[$id]['cue'];
                if ($known[$id]['flags'] !== [] || ($decision['source_sha256'] ?? null) !== $proposal['source_sha256']
                    || ($decision['donor_sha256'] ?? null) !== data_get($proposal, 'donor.sha256')
                    || ! is_numeric($decision['audio_start'] ?? null) || ! is_numeric($decision['audio_end'] ?? null)
                    || ! is_finite((float) $decision['audio_start']) || ! is_finite((float) $decision['audio_end'])
                    || (float) $decision['audio_start'] < 0 || (float) $decision['audio_start'] > $cue['start']
                    || (float) $decision['audio_end'] < $cue['end'] || (float) $decision['audio_end'] > (float) data_get($proposal, 'before.duration')) {
                    throw new RuntimeException('Acceptance lacks full source coverage or names quarantined text: '.$id);
                }
                $accepted[] = $id;
            }
            sort($accepted);
            $current = $this->reader->read($run);
            $output = $this->candidates->materialize($current, $report, $accepted);
            $impacts = [];
            foreach ($run->serviceSections()->orderBy('id')->get() as $section) {
                $before = $this->plan($section, $current);
                $after = $this->plan($section, $output);
                $impacts[] = ['section_id' => $section->id, 'publication_status' => $section->publication_status->value,
                    'review_flags' => $section->metadata->reviewFlags ?? [], 'before' => $before, 'after' => $after,
                    'changed' => CanonicalJson::hash($before) !== CanonicalJson::hash($after)];
            }
            if ($run->serviceSections()->where('section_type', 'sermon')->exists()) {
                $before = $this->sermonPlan($run, $current);
                $after = $this->sermonPlan($run, $output);
                $impacts[] = ['output' => 'sermon', 'before' => $before, 'after' => $after,
                    'changed' => CanonicalJson::hash($before) !== CanonicalJson::hash($after)];
            }
            // Evidence missing identically with and without the candidate text is not the repair's to resolve:
            // report it, and block only on a failure the candidate text introduces or changes.
            foreach ($impacts as &$impact) {
                $impact['pre_existing_error'] = isset($impact['before']['error'], $impact['after']['error'])
                    && $impact['before']['error'] === $impact['after']['error'];
            }
            unset($impact);
            $selected = array_values(array_filter($report['candidates'], static fn (array $c): bool => in_array($c['id'], $accepted, true)));

            return $this->seal(['schema' => self::SCHEMA, 'kind' => 'adoption', 'proposal' => $proposal, 'review' => $review,
                'accepted' => $accepted, 'output' => $output->toArray(), 'output_sha256' => CanonicalJson::hash($output->toArray()),
                'verified_seconds' => array_sum(array_map(static fn (array $c): float => $c['cue']['end'] - $c['cue']['start'], $selected)),
                'verified_words' => array_sum(array_map(static fn (array $c): int => str_word_count($c['cue']['text']), $selected)),
                'impacts' => $impacts,
                'pre_existing_errors' => array_values(array_map(static fn (array $i): int|string => $i['section_id'] ?? 'sermon',
                    array_filter($impacts, static fn (array $i): bool => $i['pre_existing_error']))),
                'impact_complete' => ! array_any($impacts, static fn (array $i): bool => isset($i['after']['error']) && ! $i['pre_existing_error'])]);
        });
    }

    /**
     * Probe clean edge-window candidates, without treating them as source-verified text.
     * Only explicit execution banks local word evidence; no transcript is installed.
     *
     * @param  array<string, mixed>  $proposal
     * @param  list<int>  $sectionIds
     * @return array<string, mixed>
     */
    public function prepareWords(array $proposal, array $sectionIds, bool $execute): array
    {
        $this->verifySeal($proposal, 'proposal');
        if (config('media-processing.service_structure.transcription_service') !== 'local') {
            throw new RuntimeException('Failed-stretch word evidence requires local Whisper; no other provider is allowed.');
        }
        $run = $this->proposalRun($proposal);
        $proposal = $this->refreshProposal($run, $proposal);

        return $this->within($run, function () use ($run, $proposal, $sectionIds, $execute): array {
            $sections = $run->serviceSections()->whereKey($sectionIds)->orderBy('id')->get();
            if ($sectionIds === [] || $sections->count() !== count(array_unique($sectionIds))) {
                throw new RuntimeException('Word evidence requires explicit sections belonging to this run.');
            }
            /** @var Report $report */
            $report = $proposal['report'];
            $current = $this->reader->read($run);
            $candidate = $this->candidates->materialize($current, $report, $proposal['listening_candidate_ids']);
            $spans = [];
            foreach ($sections as $section) {
                $span = ['start_time' => (float) $section->start_time, 'end_time' => (float) $section->end_time];
                $spans[] = $span;
                if ($section->section_type === ServiceSectionType::Song) {
                    foreach ($this->planner->songEndSpeechEdges($run, [$span], $candidate) as $edge) {
                        $spans[] = ['start_time' => $edge, 'end_time' => $edge];
                    }
                }
            }
            $prepare = app()->makeWith(PrepareOutputEdgeWordTimings::class, ['transcription' => app(LocalWhisperServiceTranscriptionService::class)]);
            $evidence = app(OutputEdgeWordTimings::class);
            $windows = [];
            foreach ($prepare->windows($run, $spans, $candidate) as $window) {
                try {
                    $evidence->read($run, $window);
                    $missing = false;
                } catch (OutputEdgeTimingsMissing) {
                    $missing = true;
                }
                $windows[] = [...$window, 'missing' => $missing];
            }
            $summary = $execute ? $prepare->prepare($run, $spans, $candidate) : null;
            $run->refresh();
            $impacts = [];
            foreach ($sections as $section) {
                $impacts[] = ['section_id' => $section->id, 'current' => $this->plan($section, $current),
                    'candidate' => $this->plan($section, $candidate)];
            }

            return ['run_id' => $run->id, 'outcome' => $execute ? 'word_evidence_prepared' : 'dry_run',
                'probe_only' => true, 'candidate_ids' => $proposal['listening_candidate_ids'],
                'windows' => $windows, 'summary' => $summary, 'impacts' => $impacts];
        });
    }

    /** @param array<string, mixed> $adoption */
    public function apply(array $adoption): string
    {
        $this->verifySeal($adoption, 'adoption');
        /** @var array<string, mixed> $proposal */
        $proposal = $adoption['proposal'];
        /** @var array<string, mixed> $review */
        $review = $adoption['review'];
        $run = $this->proposalRun($proposal);

        return $this->within($run, fn (): string => DB::transaction(function () use ($run, $proposal, $review, $adoption): string {
            $locked = MediaProcessingLog::query()->lockForUpdate()->findOrFail($run->id);
            $key = (string) $adoption['sha256'];
            if ($this->alreadyApplied($locked, $key)) {
                return 'already_applied';
            }
            $fresh = $this->validateAdoption($adoption);
            if (! $fresh['impact_complete'] || $fresh['accepted'] === []) {
                throw new RuntimeException('Adoption changed, has unresolved planner evidence, or accepts no text.');
            }
            /** @var array<string, mixed> $payload */
            $payload = $fresh['output'];
            $output = ChurchServiceTranscript::fromArray($payload);
            $metadata = $locked->processing_metadata?->toArray() ?? [];
            $previous = array_intersect_key($metadata, array_flip(self::SNAPSHOT_KEYS));
            $kind = 'normalized-h10b-repair-'.$key;
            $path = $this->artifacts->putJsonOnce($locked->processing_id, $kind, $payload, ['repair_sha256' => $key]);
            $locked->refresh();
            $locked->putServiceTranscriptPath($path, $output->unobservableWindows,
                array_map(static fn ($block): array => $block->toArray(), $this->screen->screen($output)));
            $locked->recordServiceTranscriptContent(MediaProcessingLog::hashServiceTranscriptContent($output));
            $locked->refresh();
            $installed = array_intersect_key($locked->processing_metadata?->toArray() ?? [], array_flip(self::SNAPSHOT_KEYS));
            $receipt = ['adoption_sha256' => $key, 'proposal_sha256' => $proposal['sha256'], 'operator' => $review['operator'],
                'processing_id' => $locked->processing_id,
                'donor' => $proposal['donor'], 'source_sha256' => $proposal['source_sha256'], 'accepted' => $fresh['accepted'],
                'previous' => $previous, 'previous_sha256' => $proposal['before_sha256'], 'installed_path' => $path,
                'output_sha256' => $fresh['output_sha256']];
            $receiptPath = $this->artifacts->putJsonOnce($locked->processing_id, 'h10b-repair-receipt-'.$key, $receipt);
            $locked->refresh();
            $locked->writeProcessingMetadata(static function (array $m) use ($key, $receipt, $receiptPath, $installed): array {
                $m[self::RECEIPTS][$key] = [...$receipt, 'receipt_path' => $receiptPath,
                    'installed' => $installed, 'applied_at' => now()->toIso8601String()];

                return $m;
            });

            return 'applied';
        }));
    }

    public function rollback(MediaProcessingLog $run, string $key, bool $execute): string
    {
        return $this->within($run, fn (): string => DB::transaction(function () use ($run, $key, $execute): string {
            $locked = MediaProcessingLog::query()->lockForUpdate()->findOrFail($run->id);
            if ($locked->status !== ProcessingStatus::Completed) {
                throw new RuntimeException('Cannot roll back an active run.');
            }
            $receipt = $this->receipts($locked)[$key] ?? null;
            if ($receipt === null || ($receipt['rolled_back_at'] ?? null) !== null) {
                throw new RuntimeException('No active repair receipt for this run.');
            }
            if (! $this->installedMatches($locked, $receipt)) {
                throw new RuntimeException('Refusing rollback over a later transcript or metadata change.');
            }
            /** @var array<string, mixed> $previous */
            $previous = $receipt['previous'];
            $oldPath = $previous['service_transcript_path'] ?? null;
            $raw = is_string($oldPath) ? Storage::disk(ServiceArtifactDisk::for($oldPath))->get($oldPath) : null;
            if (! is_string($raw) || CanonicalJson::hash(ChurchServiceTranscript::fromArray(json_decode($raw, true, flags: JSON_THROW_ON_ERROR))->toArray()) !== $receipt['previous_sha256']) {
                throw new RuntimeException('Previous transcript is missing or changed.');
            }
            if (! $execute) {
                return 'would_rollback';
            }
            $locked->writeProcessingMetadata(static function (array $m) use ($previous, $key): array {
                foreach (self::SNAPSHOT_KEYS as $field) {
                    unset($m[$field]);
                }
                $m = [...$m, ...$previous];
                $m[self::RECEIPTS][$key]['rolled_back_at'] = now()->toIso8601String();

                return $m;
            });

            return 'rolled_back';
        }));
    }

    /** @param array<string, mixed> $adoption
     * @return array<string, mixed>
     */
    public function validateAdoption(array $adoption): array
    {
        $this->verifySeal($adoption, 'adoption');
        $proposal = $adoption['proposal'] ?? null;
        $review = $adoption['review'] ?? null;
        if (! is_array($proposal) || ! is_array($review)) {
            throw new RuntimeException('Adoption must contain its proposal and review.');
        }
        $fresh = $this->prepare($proposal, $review);
        if ($fresh['sha256'] !== $adoption['sha256']) {
            throw new RuntimeException('Adoption changed since preparation.');
        }

        return $fresh;
    }

    /** @param array<string, mixed> $adoption
     * @return array<string, mixed>
     */
    public function previewAdoption(array $adoption): array
    {
        $this->verifySeal($adoption, 'adoption');
        $proposal = $adoption['proposal'] ?? null;
        if (! is_array($proposal)) {
            throw new RuntimeException('Adoption must contain its proposal.');
        }
        $run = $this->proposalRun($proposal);

        return $this->within($run, fn (): array => $this->alreadyApplied($run, (string) $adoption['sha256'])
            ? ['run_id' => $run->id, 'outcome' => 'already_applied']
            : ['run_id' => $run->id, 'outcome' => 'dry_run', 'prepared' => $this->validateAdoption($adoption)]);
    }

    /** @return array<string, mixed> */
    public function readJson(string $path): array
    {
        $bytes = is_file($path) ? file_get_contents($path) : false;
        $data = is_string($bytes) ? json_decode($bytes, true, flags: JSON_THROW_ON_ERROR) : null;
        if (! is_array($data) || array_is_list($data)) {
            throw new RuntimeException('Unreadable JSON object: '.$path);
        }

        return $data;
    }

    /** @param array<string, mixed> $proposal
     * @return array<string, mixed>
     */
    private function refreshProposal(MediaProcessingLog $run, array $proposal): array
    {
        $paths = array_column((array) $proposal['listening'], 'path');
        $alternativePaths = array_column((array) ($proposal['alternatives'] ?? []), 'path');

        return $this->inspect($run, (string) data_get($proposal, 'donor.path'), $paths, $alternativePaths);
    }

    /** @param array<string, mixed> $proposal */
    private function proposalRun(array $proposal): MediaProcessingLog
    {
        $id = $proposal['run_id'] ?? null;
        if (! is_int($id) || $id < 1) {
            throw new RuntimeException('Proposal must name one positive integer run ID.');
        }

        return MediaProcessingLog::query()->findOrFail($id);
    }

    /** @param array<string, mixed> $receipt */
    private function installedMatches(MediaProcessingLog $run, array $receipt): bool
    {
        return $run->processing_id === ($receipt['processing_id'] ?? null)
            && $run->stagedSourceFileHash() === ($receipt['source_sha256'] ?? null)
            && CanonicalJson::hash(array_intersect_key($run->processing_metadata?->toArray() ?? [], array_flip(self::SNAPSHOT_KEYS)))
                === CanonicalJson::hash($receipt['installed'])
            && CanonicalJson::hash($this->reader->read($run)->toArray()) === $receipt['output_sha256'];
    }

    private function alreadyApplied(MediaProcessingLog $run, string $key): bool
    {
        $receipt = $this->receipts($run)[$key] ?? null;
        if ($receipt === null) {
            return false;
        }
        if (($receipt['rolled_back_at'] ?? null) !== null || ! $this->installedMatches($run, $receipt)) {
            throw new RuntimeException('Receipt exists but its installed transcript is no longer current.');
        }

        return true;
    }

    /** @return array<string, array<string, mixed>> */
    private function receipts(MediaProcessingLog $run): array
    {
        $receipts = $run->processing_metadata?->toArray()[self::RECEIPTS] ?? [];

        return is_array($receipts) ? $receipts : [];
    }

    /** @template T
     * @param  Closure(): T  $callback
     * @return T
     */
    private function within(MediaProcessingLog $run, Closure $callback): mixed
    {
        $context = $run->historicStagingContext();
        if ($context === null || $run->status !== ProcessingStatus::Completed || $run->processing_type !== MediaType::Livestream
            || $run->historicImportOperation === null
            || data_get($run->processing_metadata?->toArray(), 'historic_import.operation_id') !== $run->historicImportOperation->operation_id) {
            throw new RuntimeException('Repair requires a completed historic livestream with matching operation and staging context.');
        }

        return $this->contexts->within($context, $callback);
    }

    /** @return array<string, mixed> */
    private function plan(ServiceSection $section, ChurchServiceTranscript $transcript): array
    {
        try {
            return $this->planner->forSection($section, $transcript);
        } catch (Throwable $exception) {
            return ['error' => $exception->getMessage(), 'exception' => $exception::class];
        }
    }

    /** @return array<string, mixed> */
    private function sermonPlan(MediaProcessingLog $run, ChurchServiceTranscript $transcript): array
    {
        try {
            $plan = $this->sermons->resolve($run, transcript: $transcript);
            if (data_get($plan, 'metadata.reason') === 'edge_word_timings_missing') {
                return [...$plan, 'error' => data_get($plan, 'metadata.edge_word_timings_error')];
            }

            return $plan;
        } catch (Throwable $exception) {
            return ['error' => $exception->getMessage(), 'exception' => $exception::class];
        }
    }

    /** @param list<string> $paths
     * @return list<array{path: string, sha256: string, route: mixed, verdicts: list<array<string, mixed>>}>
     */
    private function listeningEvidence(MediaProcessingLog $run, array $paths): array
    {
        sort($paths);
        $result = [];
        foreach ($paths as $path) {
            $data = $this->readJson($path);
            $verdicts = $data['verdicts'] ?? [];
            $result[] = ['path' => $path, 'sha256' => (string) hash_file('sha256', $path),
                'route' => data_get($data, 'runs.'.$run->id),
                'verdicts' => array_values(array_filter(is_array($verdicts) ? $verdicts : [], static fn (mixed $v): bool => is_array($v) && ($v['run'] ?? null) === $run->id))];
        }

        return $result;
    }

    private function plannerEvidenceHash(MediaProcessingLog $run): string
    {
        $paths = [];
        foreach (ServiceArtifactStorage::recordedFor($run) as $entry) {
            if ($entry['kind'] !== 'audio') {
                $paths[$entry['disk'].'|'.$entry['path']] = [$entry['disk'], $entry['path']];
            }
        }
        foreach ([$run->rms_log_path, $run->audio_timeline_path] as $path) {
            if (is_string($path) && $path !== '') {
                $disk = ServiceArtifactDisk::for($path);
                $paths[$disk.'|'.$path] = [$disk, $path];
            }
        }
        ksort($paths);
        $hashes = [];
        foreach ($paths as $key => [$disk, $path]) {
            $storage = Storage::disk($disk);
            $hashes[$key] = $storage->exists($path) ? hash('sha256', (string) $storage->get($path)) : null;
        }

        return CanonicalJson::hash($hashes);
    }

    private function codeHash(): string
    {
        $hashes = [];
        foreach (['app/Services/ChurchService', 'app/Services/Media/Audio', 'app/Services/HistoricMedia', 'app/Services/Sermon'] as $directory) {
            foreach (File::allFiles(base_path($directory)) as $file) {
                if ($file->getExtension() === 'php') {
                    $hashes[$directory.'/'.$file->getRelativePathname()] = hash_file('sha256', $file->getPathname());
                }
            }
        }
        ksort($hashes);

        return CanonicalJson::hash(['code' => $hashes, 'media' => MediaProcessingVersion::signature(),
            'structure' => config('media-processing.service_structure'), 'transcription' => config('media-processing.transcription')]);
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function seal(array $data): array
    {
        return [...$data, 'sha256' => CanonicalJson::hash($data)];
    }

    /** @param array<string, mixed> $data */
    private function verifySeal(array $data, string $kind): void
    {
        $hash = $data['sha256'] ?? null;
        unset($data['sha256']);
        if (($data['schema'] ?? null) !== self::SCHEMA || ($data['kind'] ?? null) !== $kind || $hash !== CanonicalJson::hash($data)) {
            throw new RuntimeException('Invalid or changed '.$kind.' artifact.');
        }
    }
}
