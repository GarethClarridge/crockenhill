<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Data\OosArchiveEntry;
use App\Data\OosEmailParseResult;
use App\Enums\InboundEmailStatus;
use App\Enums\SermonService;
use App\Models\InboundEmail;
use App\Services\Email\InboundEmailImportService;
use App\Services\Email\OosArchiveAssertionBundle;
use App\Services\Email\OosArchiveEvaluator;
use App\Services\Email\OosArchiveMarkdownParser;
use App\Services\Email\OosEmailParserService;
use App\Services\Song\SongTitleResolver;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Process the historic order-of-service archive as if the current email pipeline had handled it
 * at the time. Each archive entry becomes an ordinary synthetic inbound email and takes one of
 * three routes:
 *
 * - **blocked** — the markdown contradicts itself about the date. The email stays
 *   {@see InboundEmailStatus::ArchiveEval} and is reported, because no human can act on it
 *   either until the archive text is corrected.
 * - **held for review** — the ground truth does not corroborate the parse. The email becomes
 *   Pending and appears in the review inbox, where the existing edit-and-approve workbench
 *   handles it exactly as it would a live email.
 * - **imported** — the entry runs through {@see InboundEmailImportService::import()}, which
 *   merges into an existing service or creates one. Whether it imports unattended is decided by
 *   the live auto-import bar, not by this command; anything below it stays Pending in the inbox.
 */
class ImportOosArchiveCommand extends Command
{
    /** Bump when the parsing pipeline changes shape (v6: structural provenance validation via OosEmailExtractionValidator — source-line grounding, corrective retry and per-plan dispositions, none of which a v5 parse carries) to invalidate cached parses. */
    private const PARSER_VERSION = 'archive-v6';

    /**
     * Archive-text problems: the entry's own date cannot be trusted, so nobody — human or
     * pipeline — can act on it until the markdown is corrected. The email stays ArchiveEval.
     *
     * @var list<string>
     */
    private const BLOCKING_FLAGS = [
        'weekday_mismatch',
        'date_discrepancy',
        'source_date_discrepancy',
        'multi_date',
    ];

    protected $signature = 'oos:import-archive
                            {path? : Archive markdown path}
                            {--dry-run : Split and validate only, without database or extractor access}
                            {--import : Import eligible entries through the live email pipeline}
                            {--fresh-parse : Ignore cached parse results}
                            {--limit= : Maximum entries to process}
                            {--date=* : Include only these ground-truth dates}
                            {--from= : Include dates on or after this date}
                            {--to= : Include dates on or before this date}
                            {--export-bundle= : Export normalized assertions to a private bundle}
                            {--import-bundle= : Preflight and stage a private assertion bundle}
                            {--apply-bundle= : Apply an already-staged private assertion bundle}
                            {--report= : JSON report path}';

    protected $description = 'Evaluate and conservatively import the order-of-service email archive';

    public function handle(
        OosArchiveMarkdownParser $archiveParser,
        OosEmailParserService $emailParser,
        InboundEmailImportService $importService,
        OosArchiveEvaluator $evaluator,
        OosArchiveAssertionBundle $assertionBundle,
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
        $archiveHash = hash('sha256', $markdown);

        try {
            if ($this->stringOption('import-bundle') !== null || $this->stringOption('apply-bundle') !== null) {
                $bundlePath = $this->stringOption('import-bundle') ?? $this->stringOption('apply-bundle');
                $bundle = $this->readBundle((string) $bundlePath);
                $preflight = $assertionBundle->preflight($bundle, $entries, $archiveHash, self::PARSER_VERSION);

                if ($this->stringOption('import-bundle') !== null) {
                    $assertionBundle->stage($preflight);
                    $this->info(sprintf(
                        'Staged %d valid and %d review-held OoS assertion entries.',
                        count($preflight['valid']),
                        count($preflight['invalid']),
                    ));
                } else {
                    $assertionBundle->apply($preflight);
                    $this->info(sprintf('Applied %d OoS assertion entries.', count($preflight['valid'])));
                }

                return self::SUCCESS;
            }
        } catch (Throwable $throwable) {
            $this->error($throwable->getMessage());

            return self::FAILURE;
        }
        $dryRun = (bool) $this->option('dry-run');
        $shouldImport = (bool) $this->option('import') && ! $dryRun;
        $songTitleResolver = $dryRun ? null : SongTitleResolver::fromDatabase();
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
                $eligiblePlanKeys = $this->groundTruthPlanKeys($entry, $parseResult);
                $blockingReasons = $this->blockingReasons($entry);
                $reviewReasons = $blockingReasons === []
                    ? $this->reviewReasons($entry, $parseResult, $sourceUpdatedAfterImport, $eligiblePlanKeys)
                    : [];
                $gateReasons = array_values(array_unique([...$blockingReasons, ...$reviewReasons]));
                $importError = null;

                if ($blockingReasons !== []) {
                    $disposition = 'blocked';
                } elseif ($reviewReasons !== []) {
                    // The archive text is sound but its ground truth does not corroborate the
                    // parse: hand the entry to the review inbox rather than importing it.
                    $disposition = 'held_for_review';
                    $this->releaseToInbox($inboundEmail, $shouldImport);
                } elseif (! $shouldImport) {
                    $disposition = 'eligible';
                } else {
                    $this->releaseToInbox($inboundEmail, $shouldImport);
                    $importResult = $importService->import(
                        $inboundEmail,
                        $parseResult,
                        onlyPlanKeys: $eligiblePlanKeys,
                    );

                    if ($importResult->hasFailures()) {
                        // A plan failure inside import() is caught and recorded as a Failed
                        // outcome, not thrown — never let it masquerade as a clean import.
                        $disposition = 'import_failed';
                        $importError = implode('; ', array_map(
                            static fn ($plan): string => "{$plan->planKey}: ".($plan->message ?? 'import failed'),
                            $importResult->failed(),
                        ));
                        $this->warn("Import failure for #{$entry->index}: {$importError}");
                    } else {
                        $disposition = match (true) {
                            $importResult->created() !== [] => 'created',
                            $importResult->merged() !== [] => 'merged',
                            // Below the auto-import bar, or a structure merge staged for review:
                            // the email stays Pending and the inbox picks it up.
                            default => 'held_for_review',
                        };
                    }
                }

                $result = $evaluator->evaluate(
                    $entry,
                    $parseResult,
                    $disposition,
                    $gateReasons,
                    $songTitleResolver,
                    $importError,
                    $eligiblePlanKeys,
                );

                if ($sourceUpdatedAfterImport) {
                    $result['flags'][] = 'source_updated_after_import';
                }

                $result['parse_flags'] = $this->parseFlags($entry, $parseResult);

                $results[] = $result;
            } catch (Throwable $throwable) {
                $this->recordFailure($entry, $throwable);
                $results[] = $evaluator->evaluate(
                    $entry,
                    null,
                    'failed',
                    ['processing_failure'],
                    $songTitleResolver,
                    $throwable->getMessage(),
                );
            }
        }

        $report = [
            'generated_at' => now()->toIso8601String(),
            'mode' => $dryRun ? 'dry_run' : ($shouldImport ? 'import' : 'evaluate'),
            'pipeline_mode' => 'multi_service',
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

        try {
            $exportPath = $this->stringOption('export-bundle');
            if ($exportPath !== null) {
                $bundle = $assertionBundle->export($entries, $archiveHash, self::PARSER_VERSION);
                $this->writeJson($this->privateScratchPath($exportPath), $bundle);
            }
        } catch (Throwable $throwable) {
            $this->error("Could not export assertion bundle: {$throwable->getMessage()}");

            return self::FAILURE;
        }

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
     * Reasons the archive markdown itself has to be corrected first. Nothing downstream can act
     * on an entry whose date is contradicted, so it never leaves ArchiveEval.
     *
     * @return list<string>
     */
    private function blockingReasons(OosArchiveEntry $entry): array
    {
        $reasons = [];

        foreach (self::BLOCKING_FLAGS as $flag) {
            if (in_array($flag, $entry->flags, true)) {
                $reasons[] = $flag;
            }
        }

        return array_values(array_unique($reasons));
    }

    /**
     * Reasons to hand the entry to a human rather than import it unattended. Unlike the blocking
     * reasons these say nothing against the archive text — `unverified_service_ground_truth` only
     * means the markdown entry carries no `####` service sub-headings — so the entry becomes an
     * ordinary Pending email and the existing edit-and-approve workbench takes it from there.
     *
     * @param  list<string>  $eligiblePlanKeys
     * @return list<string>
     */
    private function reviewReasons(
        OosArchiveEntry $entry,
        OosEmailParseResult $parseResult,
        bool $sourceUpdatedAfterImport,
        array $eligiblePlanKeys,
    ): array {
        $reasons = [];

        if ($entry->labelQuality !== 'full') {
            $reasons[] = 'unverified_service_ground_truth';
        }

        if ($entry->servicesPresent !== []
            && $parseResult->service instanceof SermonService
            && ! in_array($parseResult->service->value, $entry->servicesPresent, true)) {
            $reasons[] = 'service_not_in_ground_truth';
        }

        if ($sourceUpdatedAfterImport) {
            $reasons[] = 'source_updated_after_import';
        }

        // No plan the ground truth corroborates — either the entry records no services at all or
        // no parsed plan matches the ones it does. Importing would write a service the archive
        // does not evidence, and import() rejects an empty plan list outright.
        if ($eligiblePlanKeys === []) {
            $reasons[] = 'no_corroborated_plan';
        }

        return array_values(array_unique($reasons));
    }

    /**
     * Parse-quality signals that no longer gate the import. The live pipeline already handles
     * each of them by holding the plan and leaving the email in the inbox, so the archive must
     * not park the entry over them — but the evaluation report still reports them.
     *
     * @return list<string>
     */
    private function parseFlags(OosArchiveEntry $entry, OosEmailParseResult $parseResult): array
    {
        $flags = [];

        if ($parseResult->date === null || ! $this->isValidDate($parseResult->date) || $parseResult->date !== $entry->groundTruthDate) {
            $flags[] = 'date_mismatch';
        }

        if (! $parseResult->service instanceof SermonService) {
            $flags[] = 'invalid_service';
        }

        if ($parseResult->items === []) {
            $flags[] = 'empty_items';
        }

        $threshold = (float) config('service-tracking.email_parsing.auto_import_threshold', 0.90);
        if ($parseResult->confidenceScore < $threshold) {
            $flags[] = 'low_confidence';
        }

        return array_values(array_unique($flags));
    }

    /**
     * Keys of the parsed plans the entry's ground truth corroborates: its date and one of its
     * recorded services, with items. The multi-service parser can produce a second plan the
     * ground truth does not support — an invented service — and this keeps it out of the import
     * without also deciding confidence, which is the live auto-import bar's job.
     *
     * An empty result means nothing is corroborated, including for an entry that records no
     * services at all; {@see self::reviewReasons()} turns that into a review, so the import is
     * never called with a plan list it would reject.
     *
     * @return list<string>
     */
    private function groundTruthPlanKeys(OosArchiveEntry $entry, OosEmailParseResult $parseResult): array
    {
        $keys = [];

        foreach ($parseResult->servicePlans as $plan) {
            if ($plan->date === $entry->groundTruthDate
                && $plan->service instanceof SermonService
                && in_array($plan->service->value, $entry->servicesPresent, true)
                && $plan->items !== []) {
                $keys[] = $plan->key();
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * Take the synthetic email out of the archive-only ArchiveEval status so it behaves like any
     * other inbound email — reachable from the review inbox, approvable, editable.
     *
     * Only an `--import` run does this: an evaluation run is meant to produce a report for
     * private inspection, not to drop eighty entries into the operator's inbox.
     *
     * An already-processed email is pushed back to Pending as well, because
     * {@see InboundEmailImportService::recordImportOutcome()} only ever promotes: without this a
     * re-run whose re-parse now holds every plan — a changed source, or an extraction the
     * validator has since learnt to reject — would stay silently Processed and unreachable. The
     * promotion back to Processed happens inside the import when every plan resolves.
     */
    private function releaseToInbox(InboundEmail $inboundEmail, bool $shouldImport): void
    {
        if (! $shouldImport || $inboundEmail->status === InboundEmailStatus::Pending) {
            return;
        }

        $inboundEmail->status = InboundEmailStatus::Pending;
        $inboundEmail->save();
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

        $byType = $aggregate['song_link_hit_rate']['by_type'] ?? [];
        if ($byType !== []) {
            $rows = [];
            foreach ($byType as $matchType => $count) {
                $rows[] = [(string) $matchType, (string) $count];
            }

            $this->table(['Song match type', 'Count'], $rows);
        }
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

    /** @return array<string, mixed> */
    private function readBundle(string $path): array
    {
        $resolved = $this->privateScratchPath($path);
        $payload = json_decode((string) file_get_contents($resolved), true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($payload)) {
            throw new \RuntimeException('OoS assertion bundle must contain a JSON object.');
        }

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    private function writeJson(string $path, array $payload): void
    {
        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new \RuntimeException("Could not create bundle directory: {$directory}");
        }

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        if (file_put_contents($path, $json."\n") === false) {
            throw new \RuntimeException("Could not write bundle file: {$path}");
        }
    }

    private function privateScratchPath(string $path): string
    {
        $resolved = $this->resolvePath($path);
        $scratch = realpath(storage_path('scratch')) ?: storage_path('scratch');
        $directory = realpath(dirname($resolved)) ?: dirname($resolved);

        if ($directory !== $scratch && ! str_starts_with($directory, $scratch.DIRECTORY_SEPARATOR)) {
            throw new \RuntimeException('OoS assertion bundles must stay under storage/scratch.');
        }

        return $resolved;
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
}
