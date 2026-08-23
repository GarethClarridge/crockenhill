<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Contracts\OosSemanticAnnotator;
use App\Data\OosEmailParseResult;
use App\Data\OosEmailSourceDocument;
use App\Data\OosSemanticAnnotationResult;
use App\Models\InboundEmail;
use App\Services\ChurchService\ServiceItemTitleCleaner;
use App\Services\Email\ExistingEmailImportLookup;
use App\Services\Email\InboundEmailImportService;
use App\Services\Email\OosArchiveParseCacheBinding;
use App\Services\Email\OosEmailExtractionValidator;
use App\Services\Email\OosEmailParserService;
use App\Services\Email\OosSemanticParserCandidate;
use App\Support\CanonicalJson;
use Illuminate\Console\Command;

/**
 * Replay a cached archive parse through the current parser code, from the model's own annotations
 * it already banked, without making a single model call.
 *
 * `oos:import-archive --cache-only` reuses `archive_parse_cache.raw_result` verbatim — it never
 * re-derives from `extraction_attempts[0].initial_annotations`, so a validator or compiler fix
 * changes nothing about an already-cached source until it is re-parsed for real money or replayed
 * here. The substitution is narrow and auditable: only {@see OosSemanticAnnotator}, the part that
 * calls the model, is stubbed to return the banked `initial_annotations`; validation, repair,
 * compilation and encoding all run as the live code would, so a replay is exactly what a fresh
 * parse would have produced if the model's answer were unchanged and only the parser's judgement of
 * it moved.
 *
 * Found 2026-08-22 (IC3 item 14 follow-up): `OosSemanticAnnotationValidator::validateContinuation()`
 * rejected a continuation targeting a boundary-also-item line, and the compiler's all-or-nothing
 * failure discarded whole documents over that one false positive. Fixed in the validator; this
 * command is how the fix reaches sources already sitting in the rehearsal cache.
 *
 * Refuses to write anything for a source whose replay does not change (a repair-rejected finding
 * that survives the fix, or a document this fix does not touch) — this is a diagnostic-and-repair
 * tool, not a blind rewrite. `--dry-run` reports without writing at all.
 *
 * It also refuses to write a replay that comes out *worse* than the cache it would replace, which
 * is the failure mode "changed" alone cannot see. Because the repairer is deliberately omitted
 * below, every source whose findings were originally cleared by a repair call replays as a
 * failure: found 2026-08-23, a full-corpus sweep reported 26 of 554 sources failing
 * `shared_boundary_role_invalid` or `item_semantics_incomplete` that are healthy in the cache —
 * 299 items across services that parse cleanly. All 26 hash differently from their cached result,
 * so nothing but `--dry-run` stood between that sweep and overwriting them all with zero-item
 * failures. A regression is reported, never written, and makes the command exit non-zero;
 * `--allow-regression` is the deliberate override for the case where the worse result is the
 * honest one.
 *
 * Delete alongside the historic-import archive commands once no further cache replay is required.
 */
class RecompileOosArchiveParseCacheCommand extends Command
{
    protected $signature = 'oos:recompile-archive-parse-cache
                            {--item-key=* : Archive item keys to replay (required)}
                            {--dry-run : Report what would change without writing}
                            {--allow-regression : Write even when the replay loses items or gains rule codes}';

    protected $description = 'Replay cached archive parses through the current parser code, from banked model annotations, with no model call';

    public function handle(
        InboundEmailImportService $importService,
    ): int {
        $itemKeys = array_values(array_filter((array) $this->option('item-key'), 'is_string'));

        if ($itemKeys === []) {
            $this->error('At least one --item-key= is required.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $allowRegression = (bool) $this->option('allow-regression');
        $rows = [];
        $changed = 0;
        $unchanged = 0;
        $regressed = 0;

        foreach ($itemKeys as $itemKey) {
            $inboundEmail = InboundEmail::query()
                ->whereRaw("JSON_EXTRACT(processing_metadata, '$.archive.item_key') = ?", [$itemKey])
                ->first();

            if (! $inboundEmail instanceof InboundEmail) {
                $this->error("No inbound email carries archive item_key {$itemKey}.");

                return self::FAILURE;
            }

            $stored = data_get($inboundEmail->processing_metadata, OosArchiveParseCacheBinding::MetadataKey);
            $initialAnnotations = data_get($stored, 'raw_result.extraction_attempts.0.initial_annotations');

            if (! is_array($initialAnnotations)) {
                $this->error("{$itemKey}: cached raw_result carries no extraction_attempts[0].initial_annotations to replay from.");

                return self::FAILURE;
            }

            $before = data_get($stored, 'raw_result');
            $beforeItems = is_array($before['items'] ?? null) ? count($before['items']) : 0;
            $beforeRuleCodes = $this->ruleCodes(data_get($before, 'extraction_attempts.0.final_rule_codes'));

            $replayed = $this->replay($inboundEmail, $initialAnnotations);
            $afterPayload = $importService->encodeParseResult($replayed);
            $afterItems = count($afterPayload['items'] ?? []);
            $afterRuleCodes = $this->ruleCodes(data_get($afterPayload, 'extraction_attempts.0.final_rule_codes'));

            $rowChanged = CanonicalJson::hash($before) !== CanonicalJson::hash($afterPayload);
            $rowRegressed = $rowChanged && $this->isRegression($beforeItems, $afterItems, $beforeRuleCodes, $afterRuleCodes);

            $rows[] = [
                $itemKey,
                $beforeItems,
                $afterItems,
                implode(',', $beforeRuleCodes) ?: '—',
                implode(',', $afterRuleCodes) ?: '—',
                match (true) {
                    $rowRegressed && $allowRegression => 'regressed (forced)',
                    $rowRegressed => 'REGRESSED — not written',
                    $rowChanged => 'changed',
                    default => 'unchanged',
                },
            ];

            if (! $rowChanged) {
                $unchanged++;

                continue;
            }

            if ($rowRegressed) {
                $regressed++;

                if (! $allowRegression) {
                    continue;
                }
            }

            $changed++;

            if ($dryRun) {
                continue;
            }

            $binding = $stored;
            $binding['raw_result'] = $afterPayload;
            $binding['raw_result_hash'] = CanonicalJson::hash($afterPayload);

            $metadata = $inboundEmail->processing_metadata;
            $metadata[OosArchiveParseCacheBinding::MetadataKey] = $binding;
            $inboundEmail->processing_metadata = $metadata;
            $inboundEmail->save();
        }

        $this->table(['Item key', 'Items before', 'Items after', 'Rule codes before', 'Rule codes after', 'Result'], $rows);
        $this->info(
            $dryRun
                ? "Dry run: {$changed} would change, {$unchanged} unchanged. No model call made, nothing written."
                : "{$changed} written, {$unchanged} unchanged. No model call made."
        );

        if ($regressed === 0) {
            return self::SUCCESS;
        }

        if ($allowRegression) {
            $this->warn("{$regressed} replayed worse than the cache and were written anyway (--allow-regression).");

            return self::SUCCESS;
        }

        $this->error(
            "{$regressed} replayed worse than the cache and were not written. A source whose findings "
            .'were originally cleared by a repair call always replays as a failure here, because this '
            .'command omits the repairer — check its cached final_rule_codes before reading one of these '
            .'as a parser defect. Pass --allow-regression to write them anyway.'
        );

        return self::FAILURE;
    }

    /** @return list<string> */
    private function ruleCodes(mixed $codes): array
    {
        return is_array($codes) ? array_values(array_filter($codes, 'is_string')) : [];
    }

    /**
     * Is the replayed result strictly worse than the cache it would replace?
     *
     * Losing items is the visible half. Gaining a rule code matters even when the item count holds,
     * because a code the cache does not carry means this replay found a document the live parser
     * had already settled — the reverse of what a fix under test should do.
     *
     * @param  list<string>  $beforeRuleCodes
     * @param  list<string>  $afterRuleCodes
     */
    private function isRegression(int $beforeItems, int $afterItems, array $beforeRuleCodes, array $afterRuleCodes): bool
    {
        return $afterItems < $beforeItems || array_diff($afterRuleCodes, $beforeRuleCodes) !== [];
    }

    /** @param array<string, mixed> $initialAnnotationsPayload */
    private function replay(
        InboundEmail $inboundEmail,
        array $initialAnnotationsPayload,
    ): OosEmailParseResult {
        $annotations = OosSemanticAnnotationResult::fromArray($initialAnnotationsPayload);

        $stubAnnotator = new class($annotations) implements OosSemanticAnnotator
        {
            public function __construct(private readonly OosSemanticAnnotationResult $result) {}

            public function annotate(OosEmailSourceDocument $source): OosSemanticAnnotationResult
            {
                return $this->result;
            }
        };

        /**
         * The repairer is deliberately omitted (null): a repair this command's replay needs would
         * mean the fix under test still leaves a finding after the initial annotation, which is
         * exactly the "unchanged" case the caller should see and investigate, not paper over with a
         * repair attempt this tool never verifies against a real model.
         */
        $candidate = app()->makeWith(OosSemanticParserCandidate::class, [
            'annotator' => $stubAnnotator,
            'repairer' => null,
        ]);

        $emailParser = new OosEmailParserService(
            app(ExistingEmailImportLookup::class),
            app(ServiceItemTitleCleaner::class),
            $candidate,
            app(OosEmailExtractionValidator::class),
        );

        return $emailParser->parse($inboundEmail);
    }
}
