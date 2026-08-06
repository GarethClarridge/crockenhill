<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Console\Commands\ImportOosArchiveCommand;
use App\Data\OosArchiveEntry;
use App\Data\OosEmailItemExtractionResult;
use App\Data\OosEmailParseResult;
use App\Data\OosEmailServicePlan;
use App\Data\OosEmailSourceDocument;
use App\Enums\InboundEmailStatus;
use App\Enums\OosEmailParseDisposition;
use App\Enums\SermonService;
use App\Models\InboundEmail;
use App\Support\CanonicalJson;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Process\Process;

class OosArchiveAssertionBundle
{
    public const FORMAT = 'crockenhill-oos-assertions';

    public const VERSION = 1;

    public const PROJECTOR_VERSION = 'email-plan-v1';

    public function __construct(
        private readonly OosEmailExtractionValidator $validator,
        private readonly InboundEmailImportService $importService,
    ) {}

    /**
     * @param  list<OosArchiveEntry>  $entries
     * @return array<string, mixed>
     */
    public function export(array $entries, string $curationPlanHash, string $parserVersion): array
    {
        $payloadEntries = [];

        foreach ($entries as $entry) {
            $email = InboundEmail::query()
                ->where('message_id', $entry->syntheticMessageId)
                ->firstOrFail();
            $parsing = Arr::get($email->processing_metadata ?? [], 'parsing');

            if (! is_array($parsing) || ($parsing['input_hash'] ?? null) !== $entry->inputHash) {
                throw new RuntimeException("Archive entry {$entry->itemKey} has no matching validated parse payload.");
            }

            $payload = [
                'entry_identity' => $entry->syntheticMessageId,
                'input_hash' => $entry->inputHash,
                'parser_version' => $parserVersion,
                'projector_version' => self::PROJECTOR_VERSION,
                'fingerprints' => $this->fingerprints(),
                'plans' => $parsing['service_plans'] ?? [],
                'parse' => Arr::only($parsing, [
                    'resolved_date',
                    'resolved_service',
                    'items',
                    'confidence_score',
                    'needs_review',
                    'should_import',
                    'disposition',
                    'validation_reasons',
                    'service_plans',
                    'extraction_attempts',
                    'consensus',
                ]),
                'validation' => [
                    'disposition' => $parsing['disposition'] ?? null,
                    'reasons' => $parsing['validation_reasons'] ?? [],
                    'consensus' => (bool) ($parsing['consensus'] ?? false),
                ],
            ];
            $payload['payload_hash'] = CanonicalJson::hash($payload);
            $payloadEntries[] = $payload;
        }

        if (count($payloadEntries) !== count($entries)) {
            throw new RuntimeException('Every approved archive entry must be represented in the assertion bundle.');
        }

        $bundle = [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            /**
             * §7.4: the bundle binds to the curation plan that authorised it, not to a source
             * artefact's bytes. The plan hash covers the manifest hash, the counts and every
             * include, so a bundle cannot be applied against a re-curated corpus.
             */
            'curation_plan_hash' => $curationPlanHash,
            'git_commit' => $this->gitCommit(),
            'entries' => $payloadEntries,
        ];
        $bundle['bundle_hash'] = CanonicalJson::hash($bundle);

        return $bundle;
    }

    /**
     * @param  array<string, mixed>  $bundle
     * @param  list<OosArchiveEntry>  $archiveEntries
     * @return array{valid:list<array{entry:OosArchiveEntry,payload:array<string,mixed>}>,invalid:list<array{entry:OosArchiveEntry,payload:array<string,mixed>,reasons:list<string>}>}
     */
    public function preflight(array $bundle, array $archiveEntries, string $curationPlanHash, string $parserVersion): array
    {
        $suppliedHash = $bundle['bundle_hash'] ?? null;
        $hashable = Arr::except($bundle, ['bundle_hash']);

        if (($bundle['format'] ?? null) !== self::FORMAT
            || ($bundle['version'] ?? null) !== self::VERSION
            || ! is_string($suppliedHash)
            || ! hash_equals(CanonicalJson::hash($hashable), $suppliedHash)) {
            throw new RuntimeException('OoS assertion bundle format, version or bundle hash is invalid.');
        }

        if (($bundle['curation_plan_hash'] ?? null) !== $curationPlanHash) {
            throw new RuntimeException('OoS assertion bundle was exported against a different curation plan.');
        }

        $entriesByIdentity = [];
        foreach ($archiveEntries as $entry) {
            $entriesByIdentity[$entry->syntheticMessageId] = $entry;
        }

        $payloads = $bundle['entries'] ?? null;
        if (! is_array($payloads) || count($payloads) !== count($entriesByIdentity)) {
            throw new RuntimeException('OoS assertion bundle does not represent every approved archive entry.');
        }

        $valid = [];
        $invalid = [];
        $seen = [];

        foreach ($payloads as $rawPayload) {
            $payload = $this->bundleEntry($rawPayload);

            $identity = $payload['entry_identity'] ?? null;
            if (! is_string($identity) || isset($seen[$identity]) || ! isset($entriesByIdentity[$identity])) {
                throw new RuntimeException('OoS assertion bundle contains an unknown or duplicate entry identity.');
            }

            $seen[$identity] = true;
            $entry = $entriesByIdentity[$identity];

            if (($payload['input_hash'] ?? null) !== $entry->inputHash
                || ($payload['parser_version'] ?? null) !== $parserVersion
                || ($payload['projector_version'] ?? null) !== self::PROJECTOR_VERSION
                || ($payload['fingerprints'] ?? null) !== $this->fingerprints()) {
                throw new RuntimeException("OoS assertion bundle fingerprint mismatch for entry {$entry->itemKey}.");
            }

            $parse = $payload['parse'] ?? null;
            $payloadHash = $payload['payload_hash'] ?? null;
            if (! is_string($payloadHash)
                || ! hash_equals(CanonicalJson::hash(Arr::except($payload, ['payload_hash'])), $payloadHash)
                || ! is_array($parse)
                || $this->containsDatabaseId($parse)) {
                throw new RuntimeException("OoS assertion bundle entry {$entry->itemKey} is invalid or contains a database ID.");
            }

            $reasons = $this->structuralReasons($entry, $parse);
            $record = ['entry' => $entry, 'payload' => $payload];

            if ($reasons === []) {
                $valid[] = $record;
            } else {
                $invalid[] = [...$record, 'reasons' => $reasons];
            }
        }

        return ['valid' => $valid, 'invalid' => $invalid];
    }

    /**
     * @param  array{valid:list<array{entry:OosArchiveEntry,payload:array<string,mixed>}>,invalid:list<array{entry:OosArchiveEntry,payload:array<string,mixed>,reasons:list<string>}>}  $preflight
     */
    public function stage(array $preflight): void
    {
        foreach ([...$preflight['valid'], ...$preflight['invalid']] as $record) {
            $entry = $record['entry'];
            $parse = $record['payload']['parse'];
            $reasons = $record['reasons'] ?? [];

            if ($reasons !== []) {
                $parse['disposition'] = OosEmailParseDisposition::InvalidExtraction->value;
                $parse['validation_reasons'] = $reasons;
                $parse['should_import'] = false;

                foreach ($parse['service_plans'] ?? [] as $index => $plan) {
                    if (is_array($plan)) {
                        $parse['service_plans'][$index]['disposition'] = OosEmailParseDisposition::InvalidExtraction->value;
                        $parse['service_plans'][$index]['validation_reasons'] = $reasons;
                        $parse['service_plans'][$index]['should_import'] = false;
                    }
                }
            }

            $email = InboundEmail::query()->firstOrNew(['message_id' => $entry->syntheticMessageId]);
            $email->fill([
                'from' => 'Order of Service Archive <archive@crockenhill.local>',
                'subject' => $entry->subject,
                'body_plain' => null,
                'body_html' => null,
                'received_at' => $entry->syntheticReceivedAt,
                'status' => $reasons === [] ? InboundEmailStatus::ArchiveEval : InboundEmailStatus::Pending,
                'processing_metadata' => [
                    'archive' => [
                        'item_key' => $entry->itemKey,
                        'entry_index' => $entry->index,
                        'input_hash' => $entry->inputHash,
                        'portable_bundle' => true,
                    ],
                    'parsing' => array_replace($parse, [
                        'input_hash' => $entry->inputHash,
                        'parser_version' => $record['payload']['parser_version'],
                    ]),
                ],
            ]);
            $email->save();
        }
    }

    /**
     * @param  array{valid:list<array{entry:OosArchiveEntry,payload:array<string,mixed>}>,invalid:list<array{entry:OosArchiveEntry,payload:array<string,mixed>,reasons:list<string>}>}  $preflight
     */
    public function apply(array $preflight): void
    {
        if ($preflight['invalid'] !== []) {
            throw new RuntimeException('Structurally invalid shipped entries must be reviewed before bundle apply.');
        }

        DB::transaction(function () use ($preflight): void {
            foreach ($preflight['valid'] as $record) {
                $entry = $record['entry'];
                $email = InboundEmail::query()
                    ->where('message_id', $entry->syntheticMessageId)
                    ->lockForUpdate()
                    ->firstOrFail();
                $parseResult = $this->parseResultFromPayload($record['payload']);

                $email->status = InboundEmailStatus::Pending;
                $email->save();
                $result = $this->importService->import(
                    $email,
                    $parseResult,
                    onlyPlanKeys: $this->eligiblePlanKeys($entry, $parseResult),
                    sourceInputHash: (string) $record['payload']['input_hash'],
                );

                if ($result->hasFailures()) {
                    throw new RuntimeException("OoS assertion apply failed for entry {$entry->itemKey}.");
                }
            }
        });
    }

    /** @return array<string, string> */
    private function fingerprints(): array
    {
        $promptSchemaFingerprint = hash_file(
            'sha256',
            app_path('Services/Email/OpenAiOosEmailItemExtractor.php'),
        );

        if (! is_string($promptSchemaFingerprint)) {
            throw new RuntimeException('Could not fingerprint the OoS extraction prompt and schema.');
        }

        return [
            'model_id' => (string) config('service-tracking.email_parsing.model'),
            'prompt_schema' => $promptSchemaFingerprint,
            'config' => CanonicalJson::hash(config('service-tracking.email_parsing')),
        ];
    }

    /**
     * @param  array<string, mixed>  $parse
     * @return list<string>
     */
    private function structuralReasons(OosArchiveEntry $entry, array $parse): array
    {
        $services = [];

        foreach ($parse['service_plans'] ?? [] as $plan) {
            if (! is_array($plan)) {
                continue;
            }

            $provenance = is_array($plan['source_provenance'] ?? null) ? $plan['source_provenance'] : [];
            $items = [];

            foreach ($plan['items'] ?? [] as $index => $item) {
                if (! is_array($item)) {
                    continue;
                }

                $itemProvenance = $provenance['items'][$index] ?? [];
                $items[] = [
                    'type' => (string) ($item['type'] ?? 'other'),
                    'title' => (string) ($item['title'] ?? ''),
                    'source_line_ids' => $itemProvenance['source_line_ids'] ?? [],
                    'continuation' => (bool) ($itemProvenance['continuation'] ?? false),
                ];
            }

            $services[] = [
                'service' => $plan['service'] ?? null,
                'date' => $plan['date'] ?? null,
                'service_evidence_line_ids' => $provenance['service_evidence_line_ids'] ?? [],
                'items' => $items,
                'confidence' => (float) ($plan['confidence'] ?? 0),
            ];
        }

        $extraction = new OosEmailItemExtractionResult(
            items: [],
            confidence: (float) ($parse['confidence_score'] ?? 0),
            services: $services,
            serviceCount: count($services),
            provenanceComplete: true,
        );

        return $this->validator
            ->validate(OosEmailSourceDocument::fromBody($entry->bodyPlain), $extraction)
            ->allReasons();
    }

    /** @return array<string, mixed> */
    private function bundleEntry(mixed $payload): array
    {
        if (! is_array($payload)) {
            throw new RuntimeException('OoS assertion bundle contains an invalid entry payload.');
        }

        $entry = [];

        foreach ($payload as $key => $value) {
            if (! is_string($key)) {
                throw new RuntimeException('OoS assertion bundle entry keys must be strings.');
            }

            $entry[$key] = $value;
        }

        return $entry;
    }

    private function containsDatabaseId(mixed $value, ?string $key = null): bool
    {
        if ($key !== null && preg_match('/(^|_)id(s)?$/', $key) === 1 && ! str_contains($key, 'line_id')) {
            return $value !== null;
        }

        if (! is_array($value)) {
            return false;
        }

        foreach ($value as $childKey => $child) {
            if ($this->containsDatabaseId($child, is_string($childKey) ? $childKey : null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function parseResultFromPayload(array $payload): OosEmailParseResult
    {
        $parse = $payload['parse'] ?? null;

        if (! is_array($parse)) {
            throw new RuntimeException('OoS assertion payload has no parse result.');
        }

        $plans = [];

        foreach ($parse['service_plans'] ?? [] as $plan) {
            if (! is_array($plan)) {
                throw new RuntimeException('OoS assertion payload has an invalid service plan.');
            }

            $plans[] = new OosEmailServicePlan(
                service: SermonService::tryFrom((string) ($plan['service'] ?? '')),
                date: $this->nullableString($plan['date'] ?? null),
                items: $this->items($plan['items'] ?? null),
                confidence: is_numeric($plan['confidence'] ?? null) ? (float) $plan['confidence'] : 0.0,
                needsReview: (bool) ($plan['needs_review'] ?? false),
                shouldImport: (bool) ($plan['should_import'] ?? false),
                disposition: $this->disposition($plan['disposition'] ?? null),
                validationReasons: $this->strings($plan['validation_reasons'] ?? null),
                sourceProvenance: is_array($plan['source_provenance'] ?? null) ? $plan['source_provenance'] : [],
            );
        }

        return new OosEmailParseResult(
            date: $this->nullableString($parse['resolved_date'] ?? null),
            service: SermonService::tryFrom((string) ($parse['resolved_service'] ?? '')),
            items: $this->items($parse['items'] ?? null),
            confidenceScore: is_numeric($parse['confidence_score'] ?? null) ? (float) $parse['confidence_score'] : 0.0,
            needsReview: (bool) ($parse['needs_review'] ?? false),
            shouldImport: (bool) ($parse['should_import'] ?? false),
            // The approved payload carries the parse's own provenance, and a replay
            // has to record what a live import would have recorded. The identity and
            // item fields are dropped because they are represented above.
            importMetadata: Arr::except($parse, [
                'resolved_date',
                'resolved_service',
                'items',
                'needs_review',
                'should_import',
                'service_plans',
            ]),
            servicePlans: $plans,
            disposition: $this->disposition($parse['disposition'] ?? null),
            validationReasons: $this->strings($parse['validation_reasons'] ?? null),
            extractionAttempts: $this->arrays($parse['extraction_attempts'] ?? null),
            consensus: (bool) ($parse['consensus'] ?? false),
        );
    }

    private function disposition(mixed $value): OosEmailParseDisposition
    {
        return is_string($value)
            ? OosEmailParseDisposition::tryFrom($value) ?? OosEmailParseDisposition::ReviewRequired
            : OosEmailParseDisposition::ReviewRequired;
    }

    /**
     * @return list<array{position:int,type:string,title:string,source_title:?string,openlp_search_title:?string,metadata:?array<string,mixed>}>
     */
    private function items(mixed $items): array
    {
        if (! is_array($items) || ! array_is_list($items)) {
            throw new RuntimeException('OoS assertion payload items must be a list.');
        }

        $normalized = [];

        foreach ($items as $item) {
            if (! is_array($item)
                || ! is_int($item['position'] ?? null)
                || ! is_string($item['type'] ?? null)
                || ! is_string($item['title'] ?? null)) {
                throw new RuntimeException('OoS assertion payload contains an invalid service item.');
            }

            $metadata = $item['metadata'] ?? null;

            if ($metadata !== null && ! is_array($metadata)) {
                throw new RuntimeException('OoS assertion payload item metadata must be an object or null.');
            }

            $normalized[] = [
                'position' => $item['position'],
                'type' => $item['type'],
                'title' => $item['title'],
                'source_title' => $this->nullableString($item['source_title'] ?? null),
                'openlp_search_title' => $this->nullableString($item['openlp_search_title'] ?? null),
                'metadata' => $metadata,
            ];
        }

        return $normalized;
    }

    /** @return list<string> */
    private function strings(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_filter($values, is_string(...)));
    }

    /** @return list<array<string, mixed>> */
    private function arrays(mixed $values): array
    {
        if (! is_array($values) || ! array_is_list($values)) {
            return [];
        }

        return array_values(array_filter($values, is_array(...)));
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function gitCommit(): string
    {
        $process = new Process(['git', 'rev-parse', 'HEAD'], base_path());
        $process->run();

        return $process->isSuccessful() ? trim($process->getOutput()) : 'unknown';
    }

    /**
     * @return list<string>
     */
    private function eligiblePlanKeys(OosArchiveEntry $entry, OosEmailParseResult $parseResult): array
    {
        $keys = [];

        /**
         * Gated on the manifest's resolved date, not its resolved service: one email routinely
         * carries both that Sunday's orders and the live pipeline imports both. See
         * {@see ImportOosArchiveCommand::importablePlanKeys()} for why the
         * manifest is authority over source identity rather than over service count.
         */
        foreach ($parseResult->servicePlans as $plan) {
            if ($plan->date === $entry->groundTruthDate
                && $plan->service !== null
                && $plan->items !== []) {
                $keys[] = $plan->key();
            }
        }

        return array_values(array_unique($keys));
    }
}
