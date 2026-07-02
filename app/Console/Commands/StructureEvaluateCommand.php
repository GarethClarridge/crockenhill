<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Contracts\ServiceStructureInterface;
use App\Data\ChurchServiceTranscript;
use App\Data\ServiceStructure;
use App\Data\ServiceStructureSection;
use App\Enums\ServiceSectionType;
use App\Models\ChurchServiceItem;
use App\Models\MediaProcessingLog;
use App\Services\ChurchService\Structure\ServiceStructureValidator;
use App\Services\ChurchService\Structure\SilenceSnapService;
use App\Services\ChurchService\Structure\ValidationContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * The maintainer's go/no-go instrument for the LLM structure detector: runs
 * detect + snap + validate over manifest entries and/or stored runs, compares
 * against human-reviewed expectations, and reports the promotion-gate metrics.
 *
 * Manifest format (JSON; see docs/operations/structure-eval-manifest.example.json):
 * {
 *   "services": [{
 *     "label": "2026-06-07 AM",
 *     "processing_id": null,            // use a stored run's transcript/OoS/RMS
 *     "transcript_file": "path.json",   // or a ChurchServiceTranscript JSON file,
 *                                       // relative to the manifest's directory
 *     "oos_items": [{"id": 1, "position": 1, "type": "welcome", "title": "Welcome", "song_id": null}],
 *     "expected": {
 *       "sections": [{"type": "welcome", "start_time": 0, "end_time": 20, "tolerance_seconds": 15}],
 *       "sermon": {"start_time": 430, "end_time": 2200},
 *       "song_titles": ["Praise my soul the King of heaven"],
 *       "reading_references": ["Joshua 1:1-9"],
 *       "oos_anchorings": [{"type": "sermon", "oos_item_id": 4}]
 *     }
 *   }]
 * }
 */
class StructureEvaluateCommand extends Command
{
    protected $signature = 'structure:evaluate
                            {--manifest= : Path to a JSON manifest of services with expectations}
                            {--processing-id=* : Evaluate stored runs by processing id (detection summary + validation only unless the manifest carries expectations)}
                            {--detector=openai : Structure detector to use (mock|openai)}
                            {--report= : Write the full JSON report to this path}';

    protected $description = 'Evaluate the LLM service-structure detector against human-reviewed expectations';

    public function handle(
        ServiceStructureValidator $validator,
        SilenceSnapService $snapService,
    ): int {
        config(['media-processing.service_structure.detector' => (string) $this->option('detector')]);
        $detector = app(ServiceStructureInterface::class);

        $entries = $this->collectEntries();

        if ($entries === []) {
            $this->error('Nothing to evaluate: provide --manifest and/or --processing-id.');

            return self::FAILURE;
        }

        $results = [];

        foreach ($entries as $entry) {
            $results[] = $this->evaluateEntry($entry, $detector, $snapService, $validator);
        }

        $report = [
            'generated_at' => now()->toIso8601String(),
            'detector' => (string) $this->option('detector'),
            'services' => $results,
            'aggregate' => $this->aggregate($results),
        ];

        $this->renderReport($report);
        $this->writeReport($report);

        return self::SUCCESS;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function collectEntries(): array
    {
        $entries = [];

        $manifestPath = $this->option('manifest');

        if (is_string($manifestPath) && $manifestPath !== '') {
            if (! is_file($manifestPath)) {
                $this->error("Manifest not found: {$manifestPath}");

                return [];
            }

            $manifest = json_decode((string) file_get_contents($manifestPath), true);

            foreach (is_array($manifest['services'] ?? null) ? $manifest['services'] : [] as $service) {
                if (is_array($service)) {
                    $service['__manifest_dir'] = dirname($manifestPath);
                    $entries[] = $service;
                }
            }
        }

        foreach ((array) $this->option('processing-id') as $processingId) {
            $entries[] = ['label' => (string) $processingId, 'processing_id' => (string) $processingId];
        }

        return $entries;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private function evaluateEntry(
        array $entry,
        ServiceStructureInterface $detector,
        SilenceSnapService $snapService,
        ServiceStructureValidator $validator,
    ): array {
        $label = is_string($entry['label'] ?? null) ? $entry['label'] : ($entry['processing_id'] ?? 'unlabelled');

        try {
            $log = $this->resolveProcessingLog($entry);
            $transcript = $this->resolveTranscript($entry, $log);
            [$oosPayloads, $oosItemTypes] = $this->resolveOosItems($entry, $log);

            $startedAt = microtime(true);
            $structure = $detector->detect($transcript, $oosPayloads, $log?->processing_id);
            $latency = round(microtime(true) - $startedAt, 3);

            $structure = $this->snapIfPossible($structure, $log, $snapService);

            $result = $validator->validate(
                $structure,
                new ValidationContext($transcript->duration, $transcript->speechDuration(), $oosItemTypes, $transcript->cues)
            );

            $expected = is_array($entry['expected'] ?? null) ? $entry['expected'] : [];

            return [
                'label' => $label,
                'error' => null,
                'latency_seconds' => $latency,
                'hard_failure_codes' => $result->failureCodes(),
                'soft_flag_count' => array_sum(array_map(
                    static fn (ServiceStructureSection $section): int => count($section->reviewFlags),
                    $result->structure->sections
                )),
                'detected_types' => array_map(
                    static fn (ServiceStructureSection $section): string => $section->type->value,
                    $result->structure->sections
                ),
                'sermon' => $this->sermonMetrics($result->structure, $expected),
                'section_types' => $this->sectionTypeMetrics($result->structure, $expected),
                'oos_anchoring' => $this->oosAnchoringMetrics($result->structure, $expected),
                'song_titles' => $this->titleMetrics($result->structure, $expected, 'song_titles', 'songTitle'),
                'reading_references' => $this->titleMetrics($result->structure, $expected, 'reading_references', 'readingReference'),
            ];
        } catch (\Throwable $throwable) {
            return ['label' => $label, 'error' => $throwable->getMessage()];
        }
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function resolveProcessingLog(array $entry): ?MediaProcessingLog
    {
        $processingId = $entry['processing_id'] ?? null;

        if (! is_string($processingId) || $processingId === '') {
            return null;
        }

        $log = MediaProcessingLog::query()->where('processing_id', $processingId)->first();

        if (! $log instanceof MediaProcessingLog) {
            throw new \RuntimeException("Processing run not found: {$processingId}");
        }

        return $log;
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function resolveTranscript(array $entry, ?MediaProcessingLog $log): ChurchServiceTranscript
    {
        $transcriptFile = $entry['transcript_file'] ?? null;

        if (is_string($transcriptFile) && $transcriptFile !== '') {
            $path = str_starts_with($transcriptFile, '/')
                ? $transcriptFile
                : ($entry['__manifest_dir'] ?? '.').'/'.$transcriptFile;

            if (! is_file($path)) {
                throw new \RuntimeException("Transcript file not found: {$path}");
            }

            $transcript = ChurchServiceTranscript::fromArray(json_decode((string) file_get_contents($path), true));
        } else {
            $transcriptPath = $log?->serviceTranscriptPath();

            if ($transcriptPath === null) {
                throw new \RuntimeException('No transcript available: give the entry a transcript_file or a processing_id with a stored transcript.');
            }

            $tempDisk = (string) config('media-processing.storage.temp_disk', 'local');
            $transcript = ChurchServiceTranscript::fromArray(
                json_decode((string) Storage::disk($tempDisk)->get($transcriptPath), true)
            );
        }

        if ($transcript->isEmpty()) {
            throw new \RuntimeException('Transcript contains no cues.');
        }

        return $transcript;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array{0: array<int, array{id: int, position: int, type: string, title: ?string, song_id: ?int}>, 1: array<int, ServiceSectionType>}
     */
    private function resolveOosItems(array $entry, ?MediaProcessingLog $log): array
    {
        if (is_array($entry['oos_items'] ?? null)) {
            $payloads = [];
            $types = [];

            foreach ($entry['oos_items'] as $item) {
                if (! is_array($item) || ! is_numeric($item['id'] ?? null)) {
                    continue;
                }

                $payloads[] = [
                    'id' => (int) $item['id'],
                    'position' => (int) ($item['position'] ?? 0),
                    'type' => (string) ($item['type'] ?? 'other'),
                    'title' => is_string($item['title'] ?? null) ? $item['title'] : null,
                    'song_id' => is_numeric($item['song_id'] ?? null) ? (int) $item['song_id'] : null,
                ];
                $types[(int) $item['id']] = ServiceSectionType::tryFrom((string) ($item['type'] ?? 'other')) ?? ServiceSectionType::Other;
            }

            return [$payloads, $types];
        }

        $churchService = $log?->churchService()->first();

        if ($churchService === null) {
            return [[], []];
        }

        $items = $churchService->items()->orderBy('position')->orderBy('id')->get();

        $payloads = $items->map(static fn (ChurchServiceItem $item): array => [
            'id' => (int) $item->id,
            'position' => (int) $item->position,
            'type' => $item->semanticSectionType()->value,
            'title' => $item->title,
            'song_id' => $item->song_id === null ? null : (int) $item->song_id,
        ])->all();

        $types = $items->mapWithKeys(
            static fn (ChurchServiceItem $item): array => [(int) $item->id => $item->semanticSectionType()]
        )->all();

        return [$payloads, $types];
    }

    private function snapIfPossible(
        ServiceStructure $structure,
        ?MediaProcessingLog $log,
        SilenceSnapService $snapService,
    ): ServiceStructure {
        $rmsLogPath = $log?->rms_log_path;

        if (! is_string($rmsLogPath) || $rmsLogPath === '') {
            return $structure;
        }

        $tempDisk = (string) config('media-processing.storage.temp_disk', 'local');

        if (! Storage::disk($tempDisk)->exists($rmsLogPath)) {
            return $structure;
        }

        return $snapService->snap($structure, (string) Storage::disk($tempDisk)->get($rmsLogPath));
    }

    /**
     * @param  array<string, mixed>  $expected
     * @return array<string, mixed>|null
     */
    private function sermonMetrics(ServiceStructure $structure, array $expected): ?array
    {
        $expectedSermon = $expected['sermon'] ?? null;

        if (! is_array($expectedSermon)) {
            return null;
        }

        $detected = $structure->sectionsOfType(ServiceSectionType::Sermon)[0] ?? null;

        if (! $detected instanceof ServiceStructureSection) {
            return ['detected' => false];
        }

        $startDelta = round($detected->startTime - (float) $expectedSermon['start_time'], 2);
        $endDelta = round($detected->endTime - (float) $expectedSermon['end_time'], 2);

        return [
            'detected' => true,
            'start_delta' => $startDelta,
            'end_delta' => $endDelta,
            'within_15s' => abs($startDelta) <= 15.0 && abs($endDelta) <= 15.0,
            'within_30s' => abs($startDelta) <= 30.0 && abs($endDelta) <= 30.0,
        ];
    }

    /**
     * @param  array<string, mixed>  $expected
     * @return array<string, mixed>|null
     */
    private function sectionTypeMetrics(ServiceStructure $structure, array $expected): ?array
    {
        $expectedSections = $expected['sections'] ?? null;

        if (! is_array($expectedSections) || $expectedSections === []) {
            return null;
        }

        $available = $structure->sections;
        $matched = 0;

        foreach ($expectedSections as $expectedSection) {
            if (! is_array($expectedSection)) {
                continue;
            }

            $tolerance = (float) ($expectedSection['tolerance_seconds'] ?? 15);

            foreach ($available as $index => $candidate) {
                if ($candidate->type->value !== ($expectedSection['type'] ?? null)) {
                    continue;
                }

                if (abs($candidate->startTime - (float) $expectedSection['start_time']) <= $tolerance
                    && abs($candidate->endTime - (float) $expectedSection['end_time']) <= $tolerance) {
                    $matched++;
                    unset($available[$index]);

                    break;
                }
            }
        }

        $expectedTypes = array_values(array_map(
            static fn (array $section): string => (string) ($section['type'] ?? 'other'),
            array_filter($expectedSections, 'is_array')
        ));
        $detectedTypes = array_map(
            static fn (ServiceStructureSection $section): string => $section->type->value,
            $structure->sections
        );

        return [
            'expected' => count($expectedSections),
            'matched' => $matched,
            'accuracy' => round($matched / max(1, count($expectedSections)), 3),
            'ordering_match' => $expectedTypes === $detectedTypes,
        ];
    }

    /**
     * @param  array<string, mixed>  $expected
     * @return array<string, mixed>|null
     */
    private function oosAnchoringMetrics(ServiceStructure $structure, array $expected): ?array
    {
        $expectedAnchorings = $expected['oos_anchorings'] ?? null;

        if (! is_array($expectedAnchorings)) {
            return null;
        }

        $expectedPairs = array_values(array_filter(array_map(
            static fn (mixed $anchor): ?string => is_array($anchor)
                ? ($anchor['type'] ?? 'other').':'.(int) ($anchor['oos_item_id'] ?? 0)
                : null,
            $expectedAnchorings
        )));

        $detectedPairs = [];

        foreach ($structure->sections as $section) {
            if ($section->oosItemId !== null) {
                $detectedPairs[] = $section->type->value.':'.$section->oosItemId;
            }
        }

        $correct = count(array_intersect($detectedPairs, $expectedPairs));

        return [
            'precision' => $detectedPairs === [] ? null : round($correct / count($detectedPairs), 3),
            'recall' => $expectedPairs === [] ? null : round($correct / count($expectedPairs), 3),
        ];
    }

    /**
     * @param  array<string, mixed>  $expected
     * @return array<string, mixed>|null
     */
    private function titleMetrics(ServiceStructure $structure, array $expected, string $expectedKey, string $property): ?array
    {
        $expectedValues = $expected[$expectedKey] ?? null;

        if (! is_array($expectedValues)) {
            return null;
        }

        $detectedValues = array_values(array_filter(array_map(
            static fn (ServiceStructureSection $section): ?string => is_string($section->{$property})
                ? mb_strtolower(trim($section->{$property}))
                : null,
            $structure->sections
        )));

        $matched = 0;

        foreach ($expectedValues as $expectedValue) {
            if (is_string($expectedValue) && in_array(mb_strtolower(trim($expectedValue)), $detectedValues, true)) {
                $matched++;
            }
        }

        return [
            'expected' => count($expectedValues),
            'matched' => $matched,
            'rate' => $expectedValues === [] ? null : round($matched / count($expectedValues), 3),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $results
     * @return array<string, mixed>
     */
    private function aggregate(array $results): array
    {
        $evaluated = array_values(array_filter($results, static fn (array $result): bool => ($result['error'] ?? null) === null));

        $sermons = array_values(array_filter(array_column($evaluated, 'sermon'), 'is_array'));
        $detectedSermons = array_values(array_filter($sermons, static fn (array $sermon): bool => (bool) ($sermon['detected'] ?? false)));

        $rate = static fn (int $count, int $total): ?float => $total === 0 ? null : round($count / $total, 3);
        $mean = static fn (array $values): ?float => $values === [] ? null : round(array_sum($values) / count($values), 3);

        $typeAccuracies = array_column(array_filter(array_column($evaluated, 'section_types'), 'is_array'), 'accuracy');
        $songRates = array_values(array_filter(
            array_column(array_filter(array_column($evaluated, 'song_titles'), 'is_array'), 'rate'),
            'is_numeric'
        ));
        $readingRates = array_values(array_filter(
            array_column(array_filter(array_column($evaluated, 'reading_references'), 'is_array'), 'rate'),
            'is_numeric'
        ));

        return [
            'service_count' => count($results),
            'error_count' => count($results) - count($evaluated),
            'sermon' => [
                'expected' => count($sermons),
                'detected' => count($detectedSermons),
                'within_15s_rate' => $rate(count(array_filter($detectedSermons, static fn (array $sermon): bool => (bool) $sermon['within_15s'])), count($sermons)),
                'within_30s_rate' => $rate(count(array_filter($detectedSermons, static fn (array $sermon): bool => (bool) $sermon['within_30s'])), count($sermons)),
                'mean_abs_start_delta' => $mean(array_map(static fn (array $sermon): float => abs((float) $sermon['start_delta']), $detectedSermons)),
                'mean_abs_end_delta' => $mean(array_map(static fn (array $sermon): float => abs((float) $sermon['end_delta']), $detectedSermons)),
            ],
            'section_type_accuracy' => $mean(array_map('floatval', $typeAccuracies)),
            'song_title_match_rate' => $mean(array_map('floatval', $songRates)),
            'reading_reference_accuracy' => $mean(array_map('floatval', $readingRates)),
            'hard_validation_failure_rate' => $rate(
                count(array_filter($evaluated, static fn (array $result): bool => ($result['hard_failure_codes'] ?? []) !== [])),
                count($evaluated)
            ),
            'mean_latency_seconds' => $mean(array_map(
                static fn (array $result): float => (float) ($result['latency_seconds'] ?? 0.0),
                $evaluated
            )),
        ];
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function renderReport(array $report): void
    {
        $rows = [];

        foreach ($report['services'] as $service) {
            if (($service['error'] ?? null) !== null) {
                $rows[] = [$service['label'], 'ERROR: '.$service['error'], '', '', '', ''];

                continue;
            }

            $sermon = $service['sermon'];
            $rows[] = [
                $service['label'],
                is_array($sermon) && ($sermon['detected'] ?? false)
                    ? sprintf('%+.1fs / %+.1fs', $sermon['start_delta'], $sermon['end_delta'])
                    : '—',
                is_array($service['section_types']) ? sprintf('%.0f%%', $service['section_types']['accuracy'] * 100) : '—',
                is_array($service['oos_anchoring']) && $service['oos_anchoring']['recall'] !== null
                    ? sprintf('%.0f%%', $service['oos_anchoring']['recall'] * 100)
                    : '—',
                is_array($service['song_titles']) && $service['song_titles']['rate'] !== null
                    ? sprintf('%.0f%%', $service['song_titles']['rate'] * 100)
                    : '—',
                implode(', ', $service['hard_failure_codes']),
            ];
        }

        $this->table(['Service', 'Sermon Δstart/Δend', 'Types', 'OoS recall', 'Songs', 'Hard failures'], $rows);

        $aggregate = $report['aggregate'];
        $this->info(sprintf(
            'Aggregate: %d service(s), sermon within 30s: %s, type accuracy: %s, hard failure rate: %s',
            $aggregate['service_count'],
            $aggregate['sermon']['within_30s_rate'] === null ? 'n/a' : sprintf('%.0f%%', $aggregate['sermon']['within_30s_rate'] * 100),
            $aggregate['section_type_accuracy'] === null ? 'n/a' : sprintf('%.0f%%', $aggregate['section_type_accuracy'] * 100),
            $aggregate['hard_validation_failure_rate'] === null ? 'n/a' : sprintf('%.0f%%', $aggregate['hard_validation_failure_rate'] * 100),
        ));
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function writeReport(array $report): void
    {
        $reportPath = $this->option('report');

        if (! is_string($reportPath) || $reportPath === '') {
            return;
        }

        $directory = dirname($reportPath);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $this->info("Report written to {$reportPath}");
    }
}
