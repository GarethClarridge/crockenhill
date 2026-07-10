<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Data\OosArchiveEntry;
use App\Data\OosEmailParseResult;
use App\Enums\InboundEmailStatus;
use App\Enums\SermonService;
use App\Models\InboundEmail;
use App\Models\Song;
use App\Services\Email\InboundEmailImportService;
use App\Services\Email\OosArchiveEvaluator;
use App\Services\Email\OosArchiveMarkdownParser;
use App\Services\Email\OosEmailParserService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Throwable;

class ImportOosArchiveCommand extends Command
{
    private const PARSER_VERSION = 'archive-v1';

    /** @var list<string> */
    private const BLOCKING_FLAGS = [
        'weekday_mismatch',
        'date_discrepancy',
        'source_date_discrepancy',
        'multi_date',
    ];

    protected $signature = 'oos:import-archive
                            {path? : Archive markdown path}
                            {--dry-run : Split and validate only, without database or extractor access}
                            {--import : Create eligible missing church services}
                            {--fresh-parse : Ignore cached parse results}
                            {--limit= : Maximum entries to process}
                            {--date=* : Include only these ground-truth dates}
                            {--from= : Include dates on or after this date}
                            {--to= : Include dates on or before this date}
                            {--report= : JSON report path}';

    protected $description = 'Evaluate and conservatively import the order-of-service email archive';

    public function handle(
        OosArchiveMarkdownParser $archiveParser,
        OosEmailParserService $emailParser,
        InboundEmailImportService $importService,
        OosArchiveEvaluator $evaluator,
    ): int {
        $path = $this->resolvePath($this->argument('path'));

        if (! is_file($path) || ! is_readable($path)) {
            $this->error("Archive not found or unreadable: {$path}");

            return self::FAILURE;
        }

        $markdown = file_get_contents($path);
        if (! is_string($markdown)) {
            $this->error("Could not read archive: {$path}");

            return self::FAILURE;
        }

        $allEntries = $archiveParser->parse($markdown);
        $entries = $this->filteredEntries($allEntries);
        $dryRun = (bool) $this->option('dry-run');
        $shouldImport = (bool) $this->option('import') && ! $dryRun;
        $songCanonicalKeys = $dryRun ? [] : $this->songCanonicalKeys();
        $results = [];

        foreach ($entries as $entry) {
            $this->line("Evaluating #{$entry->index}: {$entry->heading}");

            if ($dryRun || $entry->groundTruthDate === null || ! $entry->syntheticReceivedAt instanceof CarbonImmutable) {
                $reasons = $entry->groundTruthDate === null ? ['unresolved_date'] : ['dry_run'];
                $results[] = $evaluator->evaluate(
                    $entry,
                    null,
                    $entry->groundTruthDate === null ? 'unresolved' : 'dry_run',
                    $reasons,
                );

                continue;
            }

            try {
                [$inboundEmail, $sourceUpdatedAfterImport] = $this->synchroniseEmail($entry);
                $parseResult = $this->parseResult($entry, $inboundEmail, $emailParser, $importService);
                $gateReasons = $this->gateReasons($entry, $parseResult, $sourceUpdatedAfterImport);
                $disposition = $gateReasons === [] ? 'eligible' : 'skipped';

                if ($shouldImport && $gateReasons === []) {
                    $importResult = $importService->import($inboundEmail, $parseResult, createOnly: true);
                    $disposition = $importResult->created() !== [] ? 'created' : 'skipped_existing';
                }

                $result = $evaluator->evaluate(
                    $entry,
                    $parseResult,
                    $disposition,
                    $gateReasons,
                    $songCanonicalKeys,
                );

                if ($sourceUpdatedAfterImport) {
                    $result['flags'][] = 'source_updated_after_import';
                }

                $results[] = $result;
            } catch (Throwable $throwable) {
                $this->recordFailure($entry, $throwable);
                $results[] = $evaluator->evaluate(
                    $entry,
                    null,
                    'failed',
                    ['processing_failure'],
                    $songCanonicalKeys,
                    $throwable->getMessage(),
                );
            }
        }

        $report = [
            'generated_at' => now()->toIso8601String(),
            'mode' => $dryRun ? 'dry_run' : ($shouldImport ? 'import' : 'evaluate'),
            'pipeline_mode' => 'legacy_single_plan',
            'source_path' => $path,
            'archive_entry_count' => count($allEntries),
            'selected_entry_count' => count($entries),
            'cohorts' => [
                'full' => count(array_filter($allEntries, fn (OosArchiveEntry $entry): bool => $entry->labelQuality === 'full')),
                'unverified' => count(array_filter($allEntries, fn (OosArchiveEntry $entry): bool => $entry->labelQuality === 'unverified')),
            ],
            'known_gaps' => $archiveParser->knownGaps($markdown),
            'entries' => $results,
            'aggregate' => $evaluator->aggregate($results),
        ];

        $this->renderSummary($report);

        try {
            $reportPath = $this->writeReport($report);
        } catch (Throwable $throwable) {
            $this->error("Could not write report: {$throwable->getMessage()}");

            return self::FAILURE;
        }

        $this->info("Report written to {$reportPath}");

        return self::SUCCESS;
    }

    /**
     * @param  list<OosArchiveEntry>  $entries
     * @return list<OosArchiveEntry>
     */
    private function filteredEntries(array $entries): array
    {
        $dates = array_values(array_filter((array) $this->option('date'), 'is_string'));
        $from = $this->stringOption('from');
        $to = $this->stringOption('to');

        $entries = array_values(array_filter($entries, function (OosArchiveEntry $entry) use ($dates, $from, $to): bool {
            if ($dates !== [] && ! in_array($entry->groundTruthDate, $dates, true)) {
                return false;
            }

            if ($from !== null && ($entry->groundTruthDate === null || $entry->groundTruthDate < $from)) {
                return false;
            }

            return ! ($to !== null && ($entry->groundTruthDate === null || $entry->groundTruthDate > $to));
        }));

        $limit = $this->stringOption('limit');

        return $limit !== null && (int) $limit >= 0
            ? array_slice($entries, 0, (int) $limit)
            : $entries;
    }

    /**
     * @return array{InboundEmail, bool}
     */
    private function synchroniseEmail(OosArchiveEntry $entry): array
    {
        $inboundEmail = InboundEmail::query()->where('message_id', $entry->syntheticMessageId)->first();
        $wasProcessed = $inboundEmail?->status === InboundEmailStatus::Processed;
        $previousHash = $inboundEmail instanceof InboundEmail
            ? Arr::get($inboundEmail->processing_metadata ?? [], 'archive.input_hash')
            : null;
        $sourceChanged = $inboundEmail instanceof InboundEmail && $previousHash !== $entry->inputHash;

        if (! $inboundEmail instanceof InboundEmail) {
            $inboundEmail = new InboundEmail;
            $inboundEmail->status = InboundEmailStatus::ArchiveEval;
            $inboundEmail->message_id = $entry->syntheticMessageId;
            $inboundEmail->from = 'Order of Service Archive <archive@crockenhill.local>';
        }

        if (! $inboundEmail->exists || $sourceChanged) {
            $inboundEmail->subject = $entry->subject;
            $inboundEmail->body_plain = $entry->bodyPlain;
            $inboundEmail->body_html = null;
            $receivedAt = $entry->syntheticReceivedAt
                ?? throw new \RuntimeException('Resolved archive entries require a synthetic received date.');
            $inboundEmail->received_at = Carbon::instance($receivedAt);
        }

        $metadata = is_array($inboundEmail->processing_metadata) ? $inboundEmail->processing_metadata : [];
        $metadata['archive'] = $this->archiveMetadata($entry);

        if ($sourceChanged && $wasProcessed) {
            $metadata['archive']['flags'][] = 'source_updated_after_import';
        }

        $inboundEmail->processing_metadata = $metadata;
        $inboundEmail->save();

        return [$inboundEmail, $sourceChanged && $wasProcessed];
    }

    private function parseResult(
        OosArchiveEntry $entry,
        InboundEmail $inboundEmail,
        OosEmailParserService $emailParser,
        InboundEmailImportService $importService,
    ): OosEmailParseResult {
        $parsing = Arr::get($inboundEmail->processing_metadata ?? [], 'parsing', []);
        $cacheMatches = ! (bool) $this->option('fresh-parse')
            && is_array($parsing)
            && ($parsing['input_hash'] ?? null) === $entry->inputHash
            && ($parsing['parser_version'] ?? null) === self::PARSER_VERSION;

        if ($cacheMatches) {
            $stored = $importService->storedParseResult($inboundEmail);

            if ($stored instanceof OosEmailParseResult) {
                return $stored;
            }
        }

        $parseResult = $emailParser->parse($inboundEmail);
        $importService->storeParseResult($inboundEmail, $parseResult, $parsing !== []);
        $inboundEmail->refresh();
        $metadata = $inboundEmail->processing_metadata ?? [];
        $metadata['parsing']['input_hash'] = $entry->inputHash;
        $metadata['parsing']['parser_version'] = self::PARSER_VERSION;
        $inboundEmail->processing_metadata = $metadata;
        $inboundEmail->save();

        return $parseResult;
    }

    /**
     * @return list<string>
     */
    private function gateReasons(
        OosArchiveEntry $entry,
        OosEmailParseResult $parseResult,
        bool $sourceUpdatedAfterImport,
    ): array {
        $reasons = [];

        if ($entry->labelQuality !== 'full') {
            $reasons[] = 'unverified_service_ground_truth';
        }

        foreach (self::BLOCKING_FLAGS as $flag) {
            if (in_array($flag, $entry->flags, true)) {
                $reasons[] = $flag;
            }
        }

        if ($sourceUpdatedAfterImport) {
            $reasons[] = 'source_updated_after_import';
        }

        if ($parseResult->date === null || ! $this->isValidDate($parseResult->date) || $parseResult->date !== $entry->groundTruthDate) {
            $reasons[] = 'date_mismatch';
        }

        if (! $parseResult->service instanceof SermonService) {
            $reasons[] = 'invalid_service';
        } elseif (! in_array($parseResult->service->value, $entry->servicesPresent, true)) {
            $reasons[] = 'service_not_in_ground_truth';
        }

        if ($parseResult->items === []) {
            $reasons[] = 'empty_items';
        }

        $threshold = (float) config('service-tracking.email_parsing.auto_import_threshold', 0.90);
        if ($parseResult->confidenceScore < $threshold) {
            $reasons[] = 'low_confidence';
        }

        return array_values(array_unique($reasons));
    }

    private function isValidDate(string $date): bool
    {
        try {
            $candidate = CarbonImmutable::createFromFormat('!Y-m-d', $date, 'Europe/London');

            return $candidate instanceof CarbonImmutable && $candidate->format('Y-m-d') === $date;
        } catch (Throwable) {
            return false;
        }
    }

    private function recordFailure(OosArchiveEntry $entry, Throwable $throwable): void
    {
        $inboundEmail = InboundEmail::query()->where('message_id', $entry->syntheticMessageId)->first();

        if (! $inboundEmail instanceof InboundEmail) {
            return;
        }

        $metadata = $inboundEmail->processing_metadata ?? [];
        $metadata['archive']['failure'] = [
            'message' => $throwable->getMessage(),
            'recorded_at' => now()->toIso8601String(),
        ];
        $inboundEmail->processing_metadata = $metadata;
        $inboundEmail->save();
    }

    /** @return array<string, mixed> */
    private function archiveMetadata(OosArchiveEntry $entry): array
    {
        return [
            'entry_index' => $entry->index,
            'heading' => $entry->heading,
            'heading_date' => $entry->headingDate,
            'corrected_date' => $entry->correctedDate,
            'ground_truth_date' => $entry->groundTruthDate,
            'label_quality' => $entry->labelQuality,
            'services_present' => $entry->servicesPresent,
            'item_line_counts' => $entry->itemLineCounts,
            'flags' => $entry->flags,
            'input_hash' => $entry->inputHash,
        ];
    }

    /** @param array<string, mixed> $report */
    private function renderSummary(array $report): void
    {
        $aggregate = $report['aggregate'];

        $this->table(['Metric', 'Value'], [
            ['Archive entries', (string) $report['archive_entry_count']],
            ['Selected entries', (string) $report['selected_entry_count']],
            ['Full / unverified', "{$report['cohorts']['full']} / {$report['cohorts']['unverified']}"],
            ['Date accuracy', $this->percentage($aggregate['date_accuracy']['all']['rate'])],
            ['Morning recall', $this->percentage($aggregate['service_metrics']['morning']['recall'])],
            ['Evening recall', $this->percentage($aggregate['service_metrics']['evening']['recall'])],
            ['Auto-import precision', $this->percentage($aggregate['auto_import_precision']['rate'])],
            ['Song-link hit rate', $this->percentage($aggregate['song_link_hit_rate']['rate'])],
        ]);
    }

    /** @param array<string, mixed> $report */
    private function writeReport(array $report): string
    {
        $option = $this->stringOption('report');
        $path = $option === null
            ? storage_path('scratch/oos_archive_eval_'.now()->format('Ymd_His').'.json')
            : $this->resolvePath($option);
        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new \RuntimeException("Could not create report directory: {$directory}");
        }

        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        if (file_put_contents($path, $json."\n") === false) {
            throw new \RuntimeException("Could not write report file: {$path}");
        }

        return $path;
    }

    private function resolvePath(mixed $path): string
    {
        $path = is_string($path) && $path !== ''
            ? $path
            : storage_path('scratch/crockenhill_orders_of_service_archive.md');

        return str_starts_with($path, '/') ? $path : base_path($path);
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function percentage(mixed $rate): string
    {
        return is_numeric($rate) ? number_format((float) $rate * 100, 1).'%' : 'n/a';
    }

    /** @return list<string> */
    private function songCanonicalKeys(): array
    {
        $keys = [];

        foreach (Song::query()->pluck('canonical_key') as $key) {
            if (is_string($key)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }
}
