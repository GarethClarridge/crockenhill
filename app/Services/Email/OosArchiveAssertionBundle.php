<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Data\OosArchiveEntry;
use App\Data\OosEmailItemExtractionResult;
use App\Data\OosEmailParseResult;
use App\Data\OosEmailServicePlan;
use App\Data\OosEmailSourceDocument;
use App\Enums\InboundEmailStatus;
use App\Enums\OosEmailContentScope;
use App\Enums\OosEmailParseDisposition;
use App\Enums\SermonService;
use App\Models\InboundEmail;
use App\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Process\Process;

class OosArchiveAssertionBundle
{
    public const FORMAT = 'crockenhill-oos-assertions';

    /**
     * Version 2 carries `ignored_lines`. A v1 bundle is refused rather than read: its parse
     * payload dropped the extractor's line dispositions, so re-validating it reports every
     * greeting and signature as an unclassified source line — a structural refusal that describes
     * the bundle format, not the document. Reading v1 leniently would turn that into a silent
     * pass, which is the worse of the two failures.
     */
    public const VERSION = 2;

    public const PROJECTOR_VERSION = 'email-plan-v2';

    public function __construct(
        private readonly OosEmailExtractionValidator $validator,
        private readonly InboundEmailImportService $importService,
        private readonly OosArchiveParseCacheBinding $cacheBinding,
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
            $binding = Arr::get($email->processing_metadata ?? [], OosArchiveParseCacheBinding::MetadataKey);

            if (! is_array($parsing) || ! is_array($binding)) {
                throw new RuntimeException("Archive entry {$entry->itemKey} has no matching validated parse payload.");
            }

            /**
             * The exported parse has to answer to the *current* curation, not
             * just to the current source bytes. Before HIR2 this compared the
             * input hash alone, so a re-curation that left the archive text
             * untouched shipped the decision the manifest had superseded.
             */
            if (($binding['version'] ?? null) !== OosArchiveParseCacheBinding::Version
                || ($binding['raw_cache_key_hash'] ?? null) !== $this->cacheBinding->rawCacheKeyHash($entry, $parserVersion)
                || ($binding['entry_authority_hash'] ?? null) !== $this->cacheBinding->entryAuthorityHash($entry)) {
                throw new RuntimeException(
                    "Archive entry {$entry->itemKey} was last resolved under a different source or curation authority; "
                    .'re-run the archive evaluation before exporting.'
                );
            }

            $sourceDocument = OosEmailSourceDocument::fromBody($entry->bodyPlain)->lineRecords();
            $payload = [
                'entry_identity' => $entry->syntheticMessageId,
                'curation_plan_hash' => $curationPlanHash,
                'input_hash' => $entry->inputHash,
                'entry' => [
                    'index' => $entry->index,
                    'item_key' => $entry->itemKey,
                    'date' => $entry->groundTruthDate,
                    'content_scope' => $entry->contentScope,
                    'services_present' => $entry->servicesPresent,
                    'item_line_counts' => $entry->itemLineCounts,
                    'curation' => $entry->curation,
                    'synthetic_message_id' => $entry->syntheticMessageId,
                    'source_key' => $entry->sourceKey,
                    'supersedes_source_key' => $entry->supersedesSourceKey,
                    'synthetic_received_at' => $entry->syntheticReceivedAt->toIso8601String(),
                ],
                'source_validation_proof' => CanonicalJson::hash([
                    'input_hash' => $entry->inputHash,
                    'curation_plan_hash' => $curationPlanHash,
                    'parser_version' => $parserVersion,
                    'validated_parse' => $parsing['disposition'] ?? null,
                    'source_document' => $sourceDocument,
                ]),
                'source_document' => $sourceDocument,
                'parser_version' => $parserVersion,
                'projector_version' => self::PROJECTOR_VERSION,
                'fingerprints' => $this->fingerprints(),
                'plan_identities' => [[
                    'plan_key' => $entry->servicesPresent[0].':'.$entry->groundTruthDate,
                    'source_key' => $entry->sourceKey,
                    'supersedes_source_key' => $entry->supersedesSourceKey,
                ]],
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
                    'ignored_lines',
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
                || ($payload['curation_plan_hash'] ?? null) !== $curationPlanHash
                || ($payload['parser_version'] ?? null) !== $parserVersion
                || ($payload['projector_version'] ?? null) !== self::PROJECTOR_VERSION
                || ($payload['fingerprints'] ?? null) !== $this->fingerprints()) {
                throw new RuntimeException("OoS assertion bundle fingerprint mismatch for entry {$entry->itemKey}.");
            }

            if (($payload['plan_identities'] ?? null) !== [[
                'plan_key' => $entry->servicesPresent[0].':'.$entry->groundTruthDate,
                'source_key' => $entry->sourceKey,
                'supersedes_source_key' => $entry->supersedesSourceKey,
            ]]) {
                throw new RuntimeException("OoS assertion bundle plan identity mismatch for entry {$entry->itemKey}.");
            }

            $parse = $payload['parse'] ?? null;
            $payloadHash = $payload['payload_hash'] ?? null;
            if (! is_string($payloadHash)
                || ! hash_equals(CanonicalJson::hash(Arr::except($payload, ['payload_hash'])), $payloadHash)
                || ! is_array($parse)
                || $this->containsDatabaseId($parse)) {
                throw new RuntimeException("OoS assertion bundle entry {$entry->itemKey} is invalid or contains a database ID.");
            }

            $reasons = $this->structuralReasons(
                OosEmailSourceDocument::fromBody($entry->bodyPlain),
                $parse,
            );
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
     * Production consumes a cryptographically bound normalized artifact, never
     * the raw email body it was derived from. Semantic source validation happens
     * at export/rehearsal time and its proof travels inside the payload.
     *
     * @param  array<string, mixed>  $bundle
     * @return array{valid:list<array{entry:OosArchiveEntry,payload:array<string,mixed>}>,invalid:list<array{entry:OosArchiveEntry,payload:array<string,mixed>,reasons:list<string>}>}
     */
    public function preflightPortable(array $bundle): array
    {
        $suppliedHash = $bundle['bundle_hash'] ?? null;
        $hashable = Arr::except($bundle, ['bundle_hash']);

        if (($bundle['format'] ?? null) !== self::FORMAT || ($bundle['version'] ?? null) !== self::VERSION
            || ! is_string($suppliedHash) || ! hash_equals(CanonicalJson::hash($hashable), $suppliedHash)) {
            throw new RuntimeException('OoS assertion bundle format, version or bundle hash is invalid.');
        }

        $valid = [];
        $invalid = [];
        $seen = [];
        $payloads = $bundle['entries'] ?? null;

        if (! is_array($payloads) || ! array_is_list($payloads)) {
            throw new RuntimeException('OoS assertion bundle entries must be a list.');
        }

        foreach ($payloads as $rawPayload) {
            $payload = $this->bundleEntry($rawPayload);
            $payloadHash = $payload['payload_hash'] ?? null;
            $entryData = $payload['entry'] ?? null;

            if (! is_string($payloadHash)
                || ! hash_equals(CanonicalJson::hash(Arr::except($payload, ['payload_hash'])), $payloadHash)
                || ! is_array($entryData)
                || ! is_string($payload['source_validation_proof'] ?? null)
                || $this->containsDatabaseId($payload['parse'] ?? null)) {
                throw new RuntimeException('OoS assertion bundle entry is invalid or contains a database ID.');
            }

            $entry = $this->portableEntry($entryData, $payload);
            $sourceDocument = $this->portableSourceDocument($payload['source_document'] ?? null);

            if (isset($seen[$entry->syntheticMessageId])) {
                throw new RuntimeException('OoS assertion bundle contains a duplicate entry identity.');
            }

            $seen[$entry->syntheticMessageId] = true;
            $proof = CanonicalJson::hash([
                'input_hash' => $entry->inputHash,
                'curation_plan_hash' => $payload['curation_plan_hash'] ?? null,
                'parser_version' => $payload['parser_version'] ?? null,
                'validated_parse' => data_get($payload, 'parse.disposition'),
                'source_document' => $sourceDocument->lineRecords(),
            ]);

            if (! hash_equals($proof, $payload['source_validation_proof'])) {
                throw new RuntimeException("OoS assertion bundle validation proof is invalid for {$entry->itemKey}.");
            }

            if (($payload['fingerprints'] ?? null) !== $this->fingerprints()) {
                throw new RuntimeException("OoS assertion bundle fingerprint mismatch for entry {$entry->itemKey}.");
            }

            $parse = $payload['parse'] ?? null;

            if (! is_array($parse)) {
                throw new RuntimeException("OoS assertion bundle entry {$entry->itemKey} has no parse result.");
            }

            $record = ['entry' => $entry, 'payload' => $payload];
            $reasons = $this->structuralReasons($sourceDocument, $parse);

            if ($reasons === []) {
                $valid[] = $record;
            } else {
                $invalid[] = [...$record, 'reasons' => $reasons];
            }
        }

        if ($valid === [] && $invalid === []) {
            throw new RuntimeException('OoS assertion bundle contains no approved entries.');
        }

        return ['valid' => $valid, 'invalid' => $invalid];
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  array<string, mixed>  $payload
     */
    private function portableEntry(array $entry, array $payload): OosArchiveEntry
    {
        if (! is_int($entry['index'] ?? null) || ! is_string($entry['item_key'] ?? null)
            || ! is_string($entry['date'] ?? null) || ! is_string($entry['content_scope'] ?? null)
            || ! is_array($entry['services_present'] ?? null) || ! is_array($entry['item_line_counts'] ?? null)
            || ! is_array($entry['curation'] ?? null) || ! is_string($entry['synthetic_message_id'] ?? null)
            || ! is_string($entry['source_key'] ?? null) || ! is_string($entry['synthetic_received_at'] ?? null)
            || ! is_string($payload['input_hash'] ?? null)) {
            throw new RuntimeException('OoS assertion bundle portable entry is incomplete.');
        }

        return new OosArchiveEntry(
            index: $entry['index'], itemKey: $entry['item_key'], subject: $entry['item_key'], bodyPlain: '',
            groundTruthDate: $entry['date'], contentScope: $entry['content_scope'], servicesPresent: $this->portableServices($entry['services_present']),
            itemLineCounts: $this->portableLineCounts($entry['item_line_counts']), curation: $this->portableCuration($entry['curation']), syntheticMessageId: $entry['synthetic_message_id'],
            sourceKey: $entry['source_key'], supersedesSourceKey: is_string($entry['supersedes_source_key'] ?? null) ? $entry['supersedes_source_key'] : null,
            inputHash: $payload['input_hash'], syntheticReceivedAt: CarbonImmutable::parse($entry['synthetic_received_at']),
        );
    }

    /** @return list<string> */
    private function portableServices(mixed $services): array
    {
        if (! is_array($services) || ! array_is_list($services)) {
            throw new RuntimeException('OoS assertion bundle services must be a list.');
        }

        $normalized = array_values(array_filter($services, is_string(...)));

        if (count($normalized) !== count($services) || $normalized === []) {
            throw new RuntimeException('OoS assertion bundle contains invalid services.');
        }

        return $normalized;
    }

    /** @return array<string, int> */
    private function portableLineCounts(mixed $counts): array
    {
        if (! is_array($counts)) {
            throw new RuntimeException('OoS assertion bundle line counts must be an object.');
        }

        $normalized = [];

        foreach ($counts as $key => $count) {
            if (! is_string($key) || ! is_int($count)) {
                throw new RuntimeException('OoS assertion bundle contains invalid line counts.');
            }

            $normalized[$key] = $count;
        }

        return $normalized;
    }

    /**
     * @return array{
     *     date_decision:string,
     *     date_decision_reason:?string,
     *     parse_decision:string,
     *     content_scope:string,
     *     partial_scope_reason:?string,
     *     payload:string,
     *     service_label:?string,
     *     title_override:?string,
     *     supersedes:?string,
     *     expected_item_count:?int,
     *     decided_by:?string,
     *     decided_at:?string,
     *     decision_rule_version:?string
     * }
     */
    private function portableCuration(mixed $curation): array
    {
        if (! is_array($curation)
            || ! is_string($curation['date_decision'] ?? null)
            || ! is_string($curation['parse_decision'] ?? null)
            || ! is_string($curation['content_scope'] ?? null)
            || ! is_string($curation['payload'] ?? null)
            || (($curation['expected_item_count'] ?? null) !== null && ! is_int($curation['expected_item_count']))) {
            throw new RuntimeException('OoS assertion bundle curation decision is invalid.');
        }

        return [
            'date_decision' => $curation['date_decision'],
            'date_decision_reason' => $this->nullableString($curation['date_decision_reason'] ?? null),
            'parse_decision' => $curation['parse_decision'],
            'content_scope' => $curation['content_scope'],
            'partial_scope_reason' => $this->nullableString($curation['partial_scope_reason'] ?? null),
            'payload' => $curation['payload'],
            'service_label' => $this->nullableString($curation['service_label'] ?? null),
            'title_override' => $this->nullableString($curation['title_override'] ?? null),
            'supersedes' => $this->nullableString($curation['supersedes'] ?? null),
            'expected_item_count' => is_int($curation['expected_item_count'] ?? null) ? $curation['expected_item_count'] : null,
            'decided_by' => $this->nullableString($curation['decided_by'] ?? null),
            'decided_at' => $this->nullableString($curation['decided_at'] ?? null),
            'decision_rule_version' => $this->nullableString($curation['decision_rule_version'] ?? null),
        ];
    }

    private function portableSourceDocument(mixed $records): OosEmailSourceDocument
    {
        if (! is_array($records) || ! array_is_list($records)) {
            throw new RuntimeException('OoS assertion bundle source document must be a list.');
        }

        $normalized = [];

        foreach ($records as $record) {
            if (! is_array($record) || ! is_int($record['line_id'] ?? null) || ! is_string($record['text'] ?? null)) {
                throw new RuntimeException('OoS assertion bundle source document contains an invalid line.');
            }

            $normalized[] = ['line_id' => $record['line_id'], 'text' => $record['text']];
        }

        return OosEmailSourceDocument::fromLineRecords($normalized);
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
                        'content_scope' => $entry->contentScope,
                        'curation_plan_hash' => $record['payload']['curation_plan_hash'] ?? null,
                        'portable_bundle' => true,
                        'plan_identities' => $record['payload']['plan_identities'],
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
        /**
         * This used to hash `OpenAiOosEmailItemExtractor.php`, the one file that held the legacy
         * prompt and schema together. That file is gone with the legacy path, and the semantic
         * equivalent is not one file but the whole annotate-compile surface, so the bundle now
         * records {@see OosParserSurfaceFingerprint}'s hash — the same figure every evaluation arm
         * declares. The key keeps its name because it answers the same question: which extraction
         * code produced these assertions.
         */
        $promptSchemaFingerprint = (new OosParserSurfaceFingerprint)->fingerprint()['hash'];

        return [
            'model_id' => (string) config('service-tracking.email_parsing.semantic.model'),
            'prompt_schema' => $promptSchemaFingerprint,
            'config' => CanonicalJson::hash(config('service-tracking.email_parsing')),
        ];
    }

    /**
     * @param  array<string, mixed>  $parse
     * @return list<string>
     */
    private function structuralReasons(OosEmailSourceDocument $sourceDocument, array $parse): array
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
                'content_scope' => $plan['content_scope'] ?? 'full',
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
            ignoredLines: $this->ignoredLines($parse['ignored_lines'] ?? null),
            provenanceComplete: true,
        );

        return $this->validator
            ->validate($sourceDocument, $extraction)
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
        if ($key !== null && preg_match('/(^|_)id(s)?$/', $key) === 1
            && ! str_contains($key, 'line_id')
            && ! in_array($key, [
                'synthetic_message_id',
                'entry_identity',
                'group_id',
                'service_group_id',
                'shared_service_group_ids',
            ], true)) {
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
                contentScope: is_string($plan['content_scope'] ?? null)
                    ? OosEmailContentScope::tryFrom($plan['content_scope']) ?? OosEmailContentScope::Unknown
                    : OosEmailContentScope::Full,
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
                'ignored_lines',
            ]),
            servicePlans: $plans,
            disposition: $this->disposition($parse['disposition'] ?? null),
            validationReasons: $this->strings($parse['validation_reasons'] ?? null),
            extractionAttempts: $this->arrays($parse['extraction_attempts'] ?? null),
            consensus: (bool) ($parse['consensus'] ?? false),
            ignoredLines: $this->ignoredLines($parse['ignored_lines'] ?? null),
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

    /**
     * Malformed entries are refused, not skipped. Everywhere else in this class a bad shape means
     * an untrustworthy bundle, and dropping an ignored line quietly would resurface as a source
     * line the extraction never accounted for — blaming the document for a transport defect.
     *
     * @return list<array{line_id:int,reason:string}>
     */
    private function ignoredLines(mixed $ignoredLines): array
    {
        if ($ignoredLines === null) {
            return [];
        }

        if (! is_array($ignoredLines) || ! array_is_list($ignoredLines)) {
            throw new RuntimeException('OoS assertion payload ignored lines must be a list.');
        }

        $lines = [];

        foreach ($ignoredLines as $ignoredLine) {
            if (! is_array($ignoredLine) || ! is_int($ignoredLine['line_id'] ?? null)
                || ! is_string($ignoredLine['reason'] ?? null)) {
                throw new RuntimeException('OoS assertion payload has an invalid ignored line.');
            }

            $lines[] = ['line_id' => $ignoredLine['line_id'], 'reason' => $ignoredLine['reason']];
        }

        return $lines;
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
         * Full sources may carry both of a Sunday's orders. Partial sources are narrower: the
         * manifest only authorizes its curated service slots as incomplete evidence.
         */
        foreach ($parseResult->servicePlans as $plan) {
            if ($plan->date === $entry->groundTruthDate
                && $plan->service !== null
                && $plan->items !== []
                && ($entry->assertsFullOrder()
                    || in_array($plan->service->value, $entry->servicesPresent, true))) {
                $keys[] = $plan->key();
            }
        }

        return array_values(array_unique($keys));
    }
}
