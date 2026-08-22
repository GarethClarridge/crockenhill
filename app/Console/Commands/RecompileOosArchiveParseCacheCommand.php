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
 * Delete alongside the historic-import archive commands once no further cache replay is required.
 */
class RecompileOosArchiveParseCacheCommand extends Command
{
    protected $signature = 'oos:recompile-archive-parse-cache
                            {--item-key=* : Archive item keys to replay (required)}
                            {--dry-run : Report what would change without writing}';

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
        $rows = [];
        $changed = 0;
        $unchanged = 0;

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
            $beforeRuleCodes = data_get($before, 'extraction_attempts.0.final_rule_codes', []);

            $replayed = $this->replay($inboundEmail, $initialAnnotations);
            $afterPayload = $importService->encodeParseResult($replayed);
            $afterItems = count($afterPayload['items'] ?? []);
            $afterRuleCodes = data_get($afterPayload, 'extraction_attempts.0.final_rule_codes', []);

            $rowChanged = CanonicalJson::hash($before) !== CanonicalJson::hash($afterPayload);

            $rows[] = [
                $itemKey,
                $beforeItems,
                $afterItems,
                implode(',', $beforeRuleCodes) ?: '—',
                implode(',', $afterRuleCodes) ?: '—',
                $rowChanged ? 'changed' : 'unchanged',
            ];

            if (! $rowChanged) {
                $unchanged++;

                continue;
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

        return self::SUCCESS;
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
