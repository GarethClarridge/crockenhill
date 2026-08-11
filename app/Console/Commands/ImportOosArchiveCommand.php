<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Data\OosArchiveEntry;
use App\Data\OosCurationPlan;
use App\Data\OosEmailParseResult;
use App\Enums\InboundEmailStatus;
use App\Enums\SermonService;
use App\Models\InboundEmail;
use App\Services\Email\InboundEmailImportService;
use App\Services\Email\OosArchiveAssertionBundle;
use App\Services\Email\OosArchiveEvaluator;
use App\Services\Email\OosCurationEntryFactory;
use App\Services\Email\OosCurationManifest;
use App\Services\Email\OosEmailParserService;
use App\Services\Import\HistoricImportProductionGuard;
use App\Services\Import\UnevidencedCanonicalItemGuard;
use App\Services\Song\SongTitleResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use RuntimeException;
use Throwable;

/**
 * Process the approved §7.5 Email order-of-service corpus as if the current email pipeline had
 * handled it at the time. Each approved manifest include becomes an ordinary synthetic inbound
 * email and takes one of two routes:
 *
 * - **held for review** — the manifest does not corroborate the parse, or the source asserts only
 *   part of an order. The email becomes Pending and appears in the review inbox, where the
 *   existing edit-and-approve workbench handles it exactly as it would a live email.
 * - **imported** — the entry runs through {@see InboundEmailImportService::import()}, which merges
 *   into an existing service or creates one. Whether it imports unattended is decided by the live
 *   auto-import bar, not by this command; anything below it stays Pending in the inbox.
 *
 * **There is no third, blocked route, and that is the point of the manifest.** Its predecessor
 * read one aggregate markdown file, split it on `### ` headings and inferred each entry's date and
 * service from the heading text — so an entry whose text contradicted itself had to be parked in a
 * report line nobody could act on. §7.3 makes the approved manifest mutation authority instead:
 * {@see OosCurationManifest::validateIncludesForDryRun()} compares every approved entry's resolved
 * identity against its payload's own frontmatter before anything is touched, and a `strict`
 * disagreement fails the whole run. A contradiction is now loud and up front rather than quiet and
 * per-entry, and `manifest-authoritative` is the recorded adjudication of one.
 */
class ImportOosArchiveCommand extends Command
{
    /** Bump when the parsing pipeline changes shape (v7: entries come from the §7.5 curation manifest rather than the superseded aggregate archive, so identity, completeness and the input hash all have different provenance) to invalidate cached parses. */
    private const ParserVersion = 'archive-v7';

    private const DefaultVerbatimRoot = 'scratch/oos-verbatim';

    private const DefaultFormattedRoot = 'scratch/oos';

    protected $signature = 'oos:import-archive
                            {--manifest= : Path to the approved crockenhill-oos-curation manifest}
                            {--verbatim= : Verbatim corpus root (default storage/scratch/oos-verbatim)}
                            {--formatted= : Formatted corpus root (default storage/scratch/oos)}
                            {--dry-run : Reconcile the manifest against the corpus, without database or extractor access}
                            {--evaluate : Evaluate assertions without importing canonical services}
                            {--import : Import eligible entries through the live email pipeline}
                            {--plan-hash= : Exact plan_hash emitted by the dry run; required with --import}
                            {--accept-unevidenced-items : Stage even where curated identities already hold items no source explains (§13.5 F2)}
                            {--fresh-parse : Ignore cached parse results}
                            {--limit= : Maximum entries to process}
                            {--date=* : Include only these resolved dates}
                            {--from= : Include dates on or after this date}
                            {--to= : Include dates on or before this date}
                            {--export-bundle= : Export normalized assertions to a private bundle}
                            {--import-bundle= : Preflight and stage a private assertion bundle}
                            {--apply-bundle= : Apply an already-staged private assertion bundle}
                            {--report= : JSON report path}';

    protected $description = 'Evaluate and conservatively import the approved order-of-service email corpus';

    public function handle(
        OosCurationManifest $curationManifest,
        OosCurationEntryFactory $entryFactory,
        OosEmailParserService $emailParser,
        InboundEmailImportService $importService,
        OosArchiveEvaluator $evaluator,
        OosArchiveAssertionBundle $assertionBundle,
        HistoricImportProductionGuard $productionGuard,
        UnevidencedCanonicalItemGuard $unevidencedItemGuard,
    ): int {
        $mode = $this->mode();

        if ($mode === null) {
            return self::FAILURE;
        }

        if (in_array($mode, ['stage_assertions', 'apply_assertions'], true)) {
            try {
                return $this->runPortableBundleMode($assertionBundle, $productionGuard);
            } catch (Throwable $throwable) {
                $this->error($throwable->getMessage());

                return self::FAILURE;
            }
        }

        if ($this->hasAdHocFilters() && ! in_array($mode, ['reconcile', 'evaluate'], true)) {
            $this->error('Ad-hoc filters are only allowed for reconcile or evaluate mode; definitive operations require the complete approved corpus.');

            return self::FAILURE;
        }

        $this->line("Mode: {$mode}");
        $this->line('Mutation scope: '.match ($mode) {
            'reconcile' => 'none',
            'evaluate' => 'archive evaluation records only',
            'import' => 'approved canonical Email evidence',
            'export_assertions' => 'private assertion artifact only',
            'stage_assertions' => 'private staged assertion records only',
            'apply_assertions' => 'approved canonical assertion evidence',
            default => throw new RuntimeException("Unknown OoS archive mode: {$mode}"),
        });

        $manifestPath = $this->stringOption('manifest');

        if ($manifestPath === null) {
            $this->error('An approved curation manifest is required with --manifest=. The corpus has no default authority.');

            return self::FAILURE;
        }

        $verbatimRoot = $this->resolvePath($this->stringOption('verbatim') ?? storage_path(self::DefaultVerbatimRoot));
        $formattedRoot = $this->resolvePath($this->stringOption('formatted') ?? storage_path(self::DefaultFormattedRoot));

        try {
            $plan = $curationManifest->plan($verbatimRoot, $formattedRoot, $this->resolvePath($manifestPath));

            /**
             * Deterministic and free, so it runs in every mode rather than only under --dry-run:
             * no entry is parsed, released or imported until every approved payload's own
             * frontmatter has been reconciled against the identity the manifest assigned it.
             */
            $snapshots = $curationManifest->snapshots($verbatimRoot, $formattedRoot, $plan);
            $adjudicated = $curationManifest->validateSnapshotsForDryRun($plan, $snapshots);
            $allEntries = $entryFactory->entries($plan, $snapshots);
        } catch (Throwable $throwable) {
            $this->error($throwable->getMessage());

            return self::FAILURE;
        }

        $entries = $this->filteredEntries($allEntries);

        try {
            if (in_array($mode, ['stage_assertions', 'apply_assertions'], true)) {
                return $this->runBundleMode($assertionBundle, $entries, $plan, $productionGuard);
            }
        } catch (Throwable $throwable) {
            $this->error($throwable->getMessage());

            return self::FAILURE;
        }

        $dryRun = $mode === 'reconcile';
        $shouldImport = $mode === 'import';

        /**
         * Only `--import` writes canonical services, so only `--import` is the
         * production-once operation G8 gates. Evaluation mode still writes
         * `InboundEmail` rows and parse caches — see the class docblock — but it
         * creates no service and releases nothing to the review inbox, which is
         * the boundary that makes staging a rehearsal activity rather than an
         * import.
         */
        if ($shouldImport) {
            $refusal = $productionGuard->refusalFor('oos:import-archive --import');

            if ($refusal !== null) {
                $this->error($refusal);

                return self::FAILURE;
            }
        }

        /**
         * §13.5 step 3 stages into a *clean* rehearsal database. Staging over an
         * earlier evidence-free import of the same corpus turns every affected
         * service into an `unnormalized_legacy_items` proposal, and the §9.4 census
         * then measures that import rather than the projector. Checked after the
         * production guard so an operator pointed at production hears the more
         * serious refusal first, and only for the entries this run would stage.
         */
        if ($shouldImport && ! $this->option('accept-unevidenced-items')) {
            $refusal = $unevidencedItemGuard->refusalFor(
                'oos:import-archive --import',
                array_map(
                    static fn (array $include): array => [$include['resolved_date'], $include['resolved_service']],
                    $plan->includes,
                ),
            );

            if ($refusal !== null) {
                $this->error($refusal);

                return self::FAILURE;
            }
        }

        if ($shouldImport && ! $this->planHashMatches($plan)) {
            $this->error('The supplied --plan-hash does not match the current curation plan. Re-run --dry-run and use the plan_hash it reports.');

            return self::FAILURE;
        }

        $songTitleResolver = $dryRun ? null : SongTitleResolver::fromDatabase();
        $results = [];

        /**
         * Source keys this run has given a source record, so a superseding entry can
         * tell whether the predecessor it declares actually exists yet.
         *
         * @var array<string, true> $importedSourceKeys
         */
        $importedSourceKeys = [];

        foreach ($entries as $entry) {
            $this->line("Evaluating #{$entry->index}: {$entry->itemKey}");

            if ($dryRun) {
                $results[] = $evaluator->evaluate($entry, null, 'dry_run', ['dry_run']);

                continue;
            }

            try {
                [$inboundEmail, $sourceUpdatedAfterImport] = $this->synchroniseEmail($entry, $plan);
                $parseResult = $this->parseResult($entry, $inboundEmail, $emailParser, $importService);
                $eligiblePlanKeys = $this->importablePlanKeys($entry, $parseResult);
                $reviewReasons = $this->reviewReasons(
                    $entry,
                    $parseResult,
                    $sourceUpdatedAfterImport,
                    $eligiblePlanKeys,
                    $shouldImport ? $importedSourceKeys : null,
                );
                $importError = null;

                if ($reviewReasons !== []) {
                    // The manifest is sound but does not corroborate the parse: hand the entry to
                    // the review inbox rather than importing it.
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
                            static fn ($failed): string => "{$failed->planKey}: ".($failed->message ?? 'import failed'),
                            $importResult->failed(),
                        ));
                        $this->warn("Import failure for {$entry->itemKey}: {$importError}");
                    } else {
                        $disposition = match (true) {
                            $importResult->created() !== [] => 'created',
                            $importResult->merged() !== [] => 'merged',
                            // Below the auto-import bar, or a structure merge staged for review:
                            // the email stays Pending and the inbox picks it up.
                            default => 'held_for_review',
                        };

                        if ($disposition === 'created' || $disposition === 'merged') {
                            // This entry now has a source record a later entry may supersede.
                            $importedSourceKeys[$entry->sourceKey] = true;
                        }
                    }
                }

                $result = $evaluator->evaluate(
                    $entry,
                    $parseResult,
                    $disposition,
                    $reviewReasons,
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
            'mode' => $mode,
            'pipeline_mode' => 'multi_service',
            'parser_version' => self::ParserVersion,
            'corpus' => [
                'verbatim_root' => $verbatimRoot,
                'formatted_root' => $formattedRoot,
                'manifest_path' => $this->resolvePath($manifestPath),
            ],
            'curation_plan' => $plan->report(),
            /**
             * Identity disagreements an operator has already ruled on via
             * `parse_decision: manifest-authoritative`. Empty is the normal state; a non-empty
             * list is the audit trail for every place the manifest overrode its own source.
             */
            'adjudicated_identity_disagreements' => $adjudicated,
            'approved_entry_count' => count($allEntries),
            'selected_entry_count' => count($entries),
            'cohorts' => [
                'full' => count(array_filter($allEntries, static fn (OosArchiveEntry $entry): bool => $entry->assertsFullOrder())),
                'partial' => count(array_filter($allEntries, static fn (OosArchiveEntry $entry): bool => ! $entry->assertsFullOrder())),
            ],
            'entries' => $results,
            'aggregate' => $evaluator->aggregate($results),
        ];

        try {
            $exportPath = $mode === 'export_assertions' ? $this->stringOption('export-bundle') : null;
            if ($exportPath !== null) {
                $bundle = $assertionBundle->export($entries, $plan->planHash, self::ParserVersion);
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

        if ($this->hasUnsettledResults($mode, $results, $allEntries, $entries)) {
            $this->error('Approved OoS corpus closeout is incomplete; no definitive operation can report success.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function mode(): ?string
    {
        $selected = [];

        foreach ([
            'reconcile' => (bool) $this->option('dry-run'),
            'evaluate' => (bool) $this->option('evaluate'),
            'import' => (bool) $this->option('import'),
            'export_assertions' => $this->stringOption('export-bundle') !== null,
            'stage_assertions' => $this->stringOption('import-bundle') !== null,
            'apply_assertions' => $this->stringOption('apply-bundle') !== null,
        ] as $mode => $enabled) {
            if ($enabled) {
                $selected[] = $mode;
            }
        }

        if (count($selected) === 1) {
            return $selected[0];
        }

        $this->error('Choose exactly one mode: --dry-run, --evaluate, --import, --export-bundle, --import-bundle, or --apply-bundle.');

        return null;
    }

    private function hasAdHocFilters(): bool
    {
        return $this->stringOption('limit') !== null
            || $this->stringOption('from') !== null
            || $this->stringOption('to') !== null
            || array_filter((array) $this->option('date'), 'is_string') !== [];
    }

    /**
     * @param  list<array<string, mixed>>  $results
     * @param  list<OosArchiveEntry>  $allEntries
     * @param  list<OosArchiveEntry>  $entries
     */
    private function hasUnsettledResults(string $mode, array $results, array $allEntries, array $entries): bool
    {
        if (! in_array($mode, ['import', 'apply_assertions'], true)) {
            return false;
        }

        if (count($entries) !== count($allEntries)) {
            return true;
        }

        return collect($results)->contains(static fn (array $result): bool => in_array(
            $result['disposition'] ?? null,
            ['failed', 'import_failed', 'held_for_review'],
            true,
        ));
    }

    /**
     * @param  list<OosArchiveEntry>  $entries
     */
    private function runBundleMode(
        OosArchiveAssertionBundle $assertionBundle,
        array $entries,
        OosCurationPlan $plan,
        HistoricImportProductionGuard $productionGuard,
    ): int {
        $isApply = $this->stringOption('apply-bundle') !== null;
        $refusal = $productionGuard->refusalFor(
            'oos:import-archive '.($isApply ? '--apply-bundle' : '--import-bundle'),
        );

        if ($refusal !== null) {
            $this->error($refusal);

            return self::FAILURE;
        }

        $bundlePath = $this->stringOption('import-bundle') ?? $this->stringOption('apply-bundle');
        $bundle = $this->readBundle((string) $bundlePath);
        $preflight = $assertionBundle->preflight($bundle, $entries, $plan->planHash, self::ParserVersion);

        if ($this->stringOption('import-bundle') !== null) {
            $assertionBundle->stage($preflight);
            $this->info(sprintf(
                'Staged %d valid and %d review-held OoS assertion entries.',
                count($preflight['valid']),
                count($preflight['invalid']),
            ));

            return self::SUCCESS;
        }

        $assertionBundle->apply($preflight);
        $this->info(sprintf('Applied %d OoS assertion entries.', count($preflight['valid'])));

        return self::SUCCESS;
    }

    private function runPortableBundleMode(
        OosArchiveAssertionBundle $assertionBundle,
        HistoricImportProductionGuard $productionGuard,
    ): int {
        $isApply = $this->stringOption('apply-bundle') !== null;
        $refusal = $productionGuard->refusalFor('oos:import-archive '.($isApply ? '--apply-bundle' : '--import-bundle'));

        if ($refusal !== null) {
            $this->error($refusal);

            return self::FAILURE;
        }

        $path = $this->stringOption($isApply ? 'apply-bundle' : 'import-bundle');
        $preflight = $assertionBundle->preflightPortable($this->readBundle((string) $path));

        if (! $isApply) {
            $assertionBundle->stage($preflight);

            return self::SUCCESS;
        }

        $assertionBundle->apply($preflight);

        return self::SUCCESS;
    }

    /**
     * §7.4: an import binds to the exact plan the operator reviewed, so a manifest edited between
     * the dry run and the apply cannot slip through on the strength of the earlier reading.
     */
    private function planHashMatches(OosCurationPlan $plan): bool
    {
        $supplied = $this->stringOption('plan-hash');

        return $supplied !== null && hash_equals($plan->planHash, $supplied);
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

        $entries = array_values(array_filter($entries, static function (OosArchiveEntry $entry) use ($dates, $from, $to): bool {
            if ($dates !== [] && ! in_array($entry->groundTruthDate, $dates, true)) {
                return false;
            }

            if ($from !== null && $entry->groundTruthDate < $from) {
                return false;
            }

            return ! ($to !== null && $entry->groundTruthDate > $to);
        }));

        $limit = $this->stringOption('limit');

        return $limit !== null && (int) $limit >= 0
            ? array_slice($entries, 0, (int) $limit)
            : $entries;
    }

    /**
     * @return array{InboundEmail, bool}
     */
    private function synchroniseEmail(OosArchiveEntry $entry, OosCurationPlan $plan): array
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
            $inboundEmail->received_at = Carbon::instance($entry->syntheticReceivedAt);
        }

        $metadata = is_array($inboundEmail->processing_metadata) ? $inboundEmail->processing_metadata : [];
        $metadata['archive'] = $this->archiveMetadata($entry, $plan);

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
            && ($parsing['parser_version'] ?? null) === self::ParserVersion;

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
        $metadata['parsing']['parser_version'] = self::ParserVersion;
        $inboundEmail->processing_metadata = $metadata;
        $inboundEmail->save();

        return $parseResult;
    }

    /**
     * Reasons to hand the entry to a human rather than import it unattended. None of them says
     * anything against the manifest — a `partial` content scope is a curated fact about the
     * source, not a defect in it — so the entry becomes an ordinary Pending email and the
     * existing edit-and-approve workbench takes it from there.
     *
     * @param  list<string>  $eligiblePlanKeys
     * @param  array<string, true>|null  $importedSourceKeys  Source keys already given a source
     *                                                        record by this run; null outside
     *                                                        import mode, where no canonical
     *                                                        state exists for a predecessor to
     *                                                        be absent from.
     * @return list<string>
     */
    private function reviewReasons(
        OosArchiveEntry $entry,
        OosEmailParseResult $parseResult,
        bool $sourceUpdatedAfterImport,
        array $eligiblePlanKeys,
        ?array $importedSourceKeys = null,
    ): array {
        $reasons = [];

        /**
         * A correction chain is admitted or held as a unit.
         *
         * The confidence gate and the supersession contract were independent, and
         * the 2026-08-11 staging run found where that breaks: `2026-03-15-am`
         * parsed at 0.85 and was held, its correction parsed at 0.92 and imported,
         * and `IngestChurchServiceSourceRevision` then refused the correction
         * because the predecessor it supersedes had no source record. The entry was
         * lost and F32's closeout refused the whole corpus on account of it.
         *
         * Holding the successor keeps the chain reviewable as the single editorial
         * decision it actually is, and never imports a correction whose base the
         * operator has not accepted. The alternative — importing the predecessor
         * regardless of confidence — would let the gate be bypassed by attaching a
         * correction to it.
         */
        if ($importedSourceKeys !== null
            && $entry->supersedesSourceKey !== null
            && ! isset($importedSourceKeys[$entry->supersedesSourceKey])) {
            $reasons[] = 'superseded_predecessor_not_imported';
        }

        /**
         * §8.4: a partial order's silence is not disagreement, so it must never import
         * unattended as though it were the whole service.
         */
        if (! $entry->assertsFullOrder()) {
            $reasons[] = 'partial_source_scope';
        }

        /**
         * The manifest says which service this document is, and the parse found no order for it
         * anywhere — not merely that some *other* service also appears, which is the ordinary
         * shape of a Sunday email carrying both orders. A genuine identity disagreement, so a
         * human looks before anything is written.
         */
        if ($parseResult->servicePlans !== [] && ! $this->parseCoversCuratedService($entry, $parseResult)) {
            $reasons[] = 'curated_service_not_parsed';
        }

        if ($sourceUpdatedAfterImport) {
            $reasons[] = 'source_updated_after_import';
        }

        // No plan the manifest corroborates — no parsed plan matches its resolved date and
        // service. Importing would write a service the manifest does not approve, and import()
        // rejects an empty plan list outright.
        if ($eligiblePlanKeys === []) {
            $reasons[] = 'no_corroborated_plan';
        }

        return array_values(array_unique($reasons));
    }

    private function parseCoversCuratedService(OosArchiveEntry $entry, OosEmailParseResult $parseResult): bool
    {
        foreach ($parseResult->servicePlans as $plan) {
            if ($plan->service instanceof SermonService
                && in_array($plan->service->value, $entry->servicesPresent, true)) {
                return true;
            }
        }

        return false;
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

        if ($parseResult->date !== $entry->groundTruthDate) {
            $flags[] = 'date_mismatch';
        }

        if (! $parseResult->service instanceof SermonService) {
            $flags[] = 'invalid_service';
        }

        if ($parseResult->items === []) {
            $flags[] = 'empty_items';
        }

        /**
         * The email carries an order for a service beyond the one the manifest names it by — the
         * ordinary shape of a Sunday email holding both that morning's and that evening's orders.
         * It imports through the same path a live email would, so this is not a loss; it is
         * curation feedback, telling the operator that `resolved_service` describes only part of
         * what the document contains.
         */
        foreach ($parseResult->servicePlans as $plan) {
            if ($plan->service instanceof SermonService
                && $plan->items !== []
                && ! in_array($plan->service->value, $entry->servicesPresent, true)) {
                $flags[] = 'service_beyond_manifest';
            }
        }

        $threshold = (float) config('service-tracking.email_parsing.auto_import_threshold', 0.90);
        if ($parseResult->confidenceScore < $threshold) {
            $flags[] = 'low_confidence';
        }

        return array_values(array_unique($flags));
    }

    /**
     * Keys of the parsed plans this entry may import: those on the manifest's resolved date, with
     * items.
     *
     * **Deliberately not filtered by the manifest's resolved service.** One email routinely
     * carries both that Sunday's morning and evening orders, and the live pipeline already handles
     * it — `OosEmailParserService` returns a plan per service and `import()` creates one
     * `ChurchService` for each. Restricting the archive to the manifest's single
     * `resolved_service` would make it lose the second order on emails the live path imports in
     * full, which is a difference in behaviour between an email received today and the same email
     * received in 2019.
     *
     * The division of authority is the same one §7.5 draws for item counts: the manifest is
     * authority over the *source's identity* — which document this is and which date it belongs
     * to — while how many services the document describes is parse content, produced by an LLM.
     * Gating on parse content is what §7.5 refuses. The date gate stays because it is a manifest
     * decision and deterministic; confidence stays the live auto-import bar's job.
     *
     * An empty result means nothing is importable; {@see self::reviewReasons()} turns that into a
     * review, so import() is never called with a plan list it would reject.
     *
     * @return list<string>
     */
    private function importablePlanKeys(OosArchiveEntry $entry, OosEmailParseResult $parseResult): array
    {
        $keys = [];

        foreach ($parseResult->servicePlans as $plan) {
            if ($plan->date === $entry->groundTruthDate
                && $plan->service instanceof SermonService
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
     * private inspection, not to drop hundreds of entries into the operator's inbox.
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
    private function archiveMetadata(OosArchiveEntry $entry, OosCurationPlan $plan): array
    {
        return [
            'item_key' => $entry->itemKey,
            'entry_index' => $entry->index,
            'ground_truth_date' => $entry->groundTruthDate,
            'content_scope' => $entry->contentScope,
            'services_present' => $entry->servicesPresent,
            'item_line_counts' => $entry->itemLineCounts,
            'plan_identities' => [[
                'plan_key' => $entry->servicesPresent[0].':'.$entry->groundTruthDate,
                'source_key' => $entry->sourceKey,
                'supersedes_source_key' => $entry->supersedesSourceKey,
            ]],
            'curation' => $entry->curation,
            'curation_plan_hash' => $plan->planHash,
            'batch_key' => $plan->batchKey,
            'input_hash' => $entry->inputHash,
            'flags' => [],
        ];
    }

    /** @param array<string, mixed> $report */
    private function renderSummary(array $report): void
    {
        $aggregate = $report['aggregate'];
        $itemCounts = $aggregate['item_count_reconciliation'];

        $this->table(['Metric', 'Value'], [
            ['Approved entries', (string) $report['approved_entry_count']],
            ['Selected entries', (string) $report['selected_entry_count']],
            ['Full / partial', "{$report['cohorts']['full']} / {$report['cohorts']['partial']}"],
            ['Adjudicated identity disagreements', (string) count($report['adjudicated_identity_disagreements'])],
            ['Date accuracy', $this->percentage($aggregate['date_accuracy']['all']['rate'])],
            ['Morning recall', $this->percentage($aggregate['service_metrics']['morning']['recall'])],
            ['Evening recall', $this->percentage($aggregate['service_metrics']['evening']['recall'])],
            ['Auto-import precision', $this->percentage($aggregate['auto_import_precision']['rate'])],
            ['Item counts reconciled', "{$itemCounts['matched']} / {$itemCounts['checked']}"],
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
            throw new RuntimeException("Could not create report directory: {$directory}");
        }

        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        if (file_put_contents($path, $json."\n") === false) {
            throw new RuntimeException("Could not write report file: {$path}");
        }

        return $path;
    }

    /** @return array<string, mixed> */
    private function readBundle(string $path): array
    {
        $resolved = $this->privateScratchPath($path);
        $payload = json_decode((string) file_get_contents($resolved), true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($payload)) {
            throw new RuntimeException('OoS assertion bundle must contain a JSON object.');
        }

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    private function writeJson(string $path, array $payload): void
    {
        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException("Could not create bundle directory: {$directory}");
        }

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        if (file_put_contents($path, $json."\n") === false) {
            throw new RuntimeException("Could not write bundle file: {$path}");
        }
    }

    private function privateScratchPath(string $path): string
    {
        $resolved = $this->resolvePath($path);
        $scratch = realpath(storage_path('scratch')) ?: storage_path('scratch');
        $directory = realpath(dirname($resolved)) ?: dirname($resolved);

        if ($directory !== $scratch && ! str_starts_with($directory, $scratch.DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('OoS assertion bundles must stay under storage/scratch.');
        }

        return $resolved;
    }

    private function resolvePath(string $path): string
    {
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
