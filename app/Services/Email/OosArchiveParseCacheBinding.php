<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Data\OosArchiveEntry;
use App\Support\CanonicalJson;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * What an archive parse may be reused from, and what authority it was resolved
 * under.
 *
 * The archive cache used to key a *resolved* parse on source bytes, parser
 * version and received date. None of those carry the curation plan, so a
 * re-curation that left the archive text untouched could not invalidate the
 * stored parse: `synchroniseEmail()` overwrote the entry's `archive` metadata
 * with the new plan, the plan hash the import quoted back still matched, and the
 * canonical import then consumed a decision the manifest had superseded. Full to
 * partial was the sharpest case — the approved outcome is evidence-only
 * retention with no canonical items, and the stale full scope projected them
 * anyway.
 *
 * HIR2 splits the two halves apart:
 *
 * - **raw extraction** is cacheable, because it depends only on the bytes the
 *   model saw. That is the expensive half and the one worth reusing.
 * - **identity, scope and supersession** are manifest-owned and are re-applied
 *   from the current entry on every run, whether or not a model call happened.
 *   {@see OosArchiveIdentityResolver} is the only place that happens.
 *
 * The per-entry authority hash below is not a cache key — nothing is reused or
 * discarded on account of it. It is the recorded answer to "which curation
 * decision was this run's output resolved under", so a report or an auditor can
 * see that an entry's authority moved even where its bytes did not.
 *
 * Delete alongside `ImportOosArchiveCommand` once the exact production import
 * and its retention window prove no further archive staging is required
 * (G9/WP10).
 */
class OosArchiveParseCacheBinding
{
    /** @var list<string> */
    /**
     * Version 1 is the raw-extraction cache.
     *
     * Version 0 is any pre-HIR2 metadata, which cached a resolved result. Those
     * rows are retained but never reusable: a resolved result cannot be turned
     * back into the model output it was derived from, so the first run under
     * this contract reparses once rather than guessing.
     */
    public const int Version = 1;

    /** Where the binding lives on an archive email's processing metadata. */
    public const string MetadataKey = 'archive_parse_cache';

    private bool $parserSurfaceCommitResolved = false;

    private ?string $resolvedParserSurfaceCommitSha = null;

    private readonly ?string $configuredParserSurfaceCommitSha;

    public function __construct(?string $parserSurfaceCommitSha = null)
    {
        $this->configuredParserSurfaceCommitSha = $parserSurfaceCommitSha;
    }

    /**
     * The reusable raw payload this stored binding offers, or null when it
     * offers none.
     *
     * @param  mixed  $stored  the `archive_parse_cache` block, whatever shape it is in
     * @return array<string, mixed>|null
     */
    public function reusableRawPayload(mixed $stored, OosArchiveEntry $entry, string $parserVersion): ?array
    {
        if (! is_array($stored) || ($stored['version'] ?? null) !== self::Version) {
            return null;
        }

        /**
         * Compared as a hash rather than as an array. MySQL's JSON type
         * normalises object keys by length, so the stored key comes back in a
         * different order than it went in and `===` on the two arrays is false
         * for two identical keys.
         */
        if (($stored['raw_cache_key_hash'] ?? null) !== $this->rawCacheKeyHash($entry, $parserVersion)) {
            return null;
        }

        $this->warnWhenParserSurfaceIsStale($stored, $entry, $parserVersion);

        $payload = $stored['raw_result'] ?? null;

        return is_array($payload) ? $payload : null;
    }

    public function rawCacheKeyHash(OosArchiveEntry $entry, string $parserVersion): string
    {
        return CanonicalJson::hash($this->rawCacheKey($entry, $parserVersion));
    }

    /**
     * The bytes, parser and received date the raw extraction depended on.
     *
     * The received date is part of the key because it is passed to the
     * extractor, so two runs of the same text at different dates are two
     * different model inputs.
     *
     * @return array{input_hash: string, parser_version: string, received_date: string}
     */
    public function rawCacheKey(OosArchiveEntry $entry, string $parserVersion): array
    {
        /**
         * The suffix is unconditional, and stays that way now the legacy path is deleted.
         *
         * Invariant 11 is that no result produced by the old nested-plan parser is ever
         * reinterpreted as an annotation result. Legacy cache rows outlive the legacy code — they
         * are database state, not a code path — so the namespace they were written under must
         * remain permanently unreachable rather than becoming reachable again the moment the
         * config key selecting it disappeared.
         */
        return [
            'input_hash' => $entry->inputHash,
            'parser_version' => "{$parserVersion}:semantic-annotations-v1",
            'received_date' => $entry->syntheticReceivedAt->toDateString(),
        ];
    }

    /**
     * Every manifest decision that changes what a given raw extraction resolves
     * to.
     *
     * Recorded rather than compared, so that adding a field here cannot silently
     * discard a cache; it changes what the reports say an entry was resolved
     * under.
     */
    public function entryAuthorityHash(OosArchiveEntry $entry): string
    {
        return CanonicalJson::hash([
            'item_key' => $entry->itemKey,
            'ground_truth_date' => $entry->groundTruthDate,
            'services_present' => $entry->servicesPresent,
            'content_scope' => $entry->contentScope,
            'partial_scope_reason' => $entry->curation['partial_scope_reason'] ?? null,
            'parse_decision' => $entry->curation['parse_decision'],
            'service_assignments' => $entry->curation['service_assignments'],
            'date_decision' => $entry->curation['date_decision'],
            'date_decision_reason' => $entry->curation['date_decision_reason'] ?? null,
            'service_label' => $entry->curation['service_label'] ?? null,
            'title_override' => $entry->curation['title_override'] ?? null,
            'expected_item_count' => $entry->curation['expected_item_count'] ?? null,
            'source_key' => $entry->sourceKey,
            'synthetic_message_id' => $entry->syntheticMessageId,
            'supersedes_source_key' => $entry->supersedesSourceKey,
        ]);
    }

    /**
     * The block written back to the email after a resolve.
     *
     * @param  array<string, mixed>  $rawPayload  the encoded raw extractor result
     * @param  array<string, mixed>  $resolvedPayload  the encoded result the manifest resolved it to
     * @return array<string, mixed>
     */
    public function binding(
        OosArchiveEntry $entry,
        string $parserVersion,
        string $planHash,
        array $rawPayload,
        array $resolvedPayload,
        bool $extractionReused,
    ): array {
        return [
            'version' => self::Version,
            'raw_cache_key' => $this->rawCacheKey($entry, $parserVersion),
            'raw_cache_key_hash' => $this->rawCacheKeyHash($entry, $parserVersion),
            'raw_result_hash' => CanonicalJson::hash($rawPayload),
            'raw_result' => $rawPayload,
            'parser_surface_commit' => $this->parserSurfaceCommitSha(),
            'entry_authority_hash' => $this->entryAuthorityHash($entry),
            'curation_plan_hash' => $planHash,
            'resolved_result_hash' => CanonicalJson::hash($resolvedPayload),
            'extraction_reused' => $extractionReused,
            'resolved_at' => now()->toIso8601String(),
        ];
    }

    /**
     * The binding without its cached payload, for reports and per-entry
     * evidence. The raw result itself is an implementation detail of the reuse
     * decision, and repeating a whole parse in a report helps nobody read it.
     *
     * @return array<string, mixed>|null
     */
    public function evidence(mixed $binding): ?array
    {
        if (! is_array($binding)) {
            return null;
        }

        unset($binding['raw_result']);

        return $binding;
    }

    /** @param array<string, mixed> $stored */
    private function warnWhenParserSurfaceIsStale(array $stored, OosArchiveEntry $entry, string $parserVersion): void
    {
        $storedCommit = $stored['parser_surface_commit'] ?? null;
        $currentCommit = $this->parserSurfaceCommitSha();

        if (! is_string($storedCommit) || ! is_string($currentCommit) || $storedCommit === $currentCommit) {
            return;
        }

        Log::warning('OoS archive raw parse cache was produced by a stale parser surface', [
            'entry_key' => $entry->itemKey,
            'parser_version' => $parserVersion,
            'stored_parser_surface_commit' => $storedCommit,
            'current_parser_surface_commit' => $currentCommit,
        ]);
    }

    private function parserSurfaceCommitSha(): ?string
    {
        if ($this->configuredParserSurfaceCommitSha !== null) {
            return $this->configuredParserSurfaceCommitSha;
        }

        if ($this->parserSurfaceCommitResolved) {
            return $this->resolvedParserSurfaceCommitSha;
        }

        $this->parserSurfaceCommitResolved = true;

        try {
            $process = new Process([
                'git',
                'log',
                '-1',
                '--format=%H',
                '--',
                ...OosParserSurfaceFingerprint::Files,
            ], base_path());
            $process->setTimeout(2);
            $process->run();

            if (! $process->isSuccessful()) {
                return null;
            }

            $commit = trim($process->getOutput());

            if (preg_match('/^[a-f0-9]{40}$/', $commit) !== 1) {
                return null;
            }

            return $this->resolvedParserSurfaceCommitSha = $commit;
        } catch (Throwable) {
            return null;
        }
    }
}
