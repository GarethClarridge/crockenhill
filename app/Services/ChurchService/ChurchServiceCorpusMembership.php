<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Enums\ChurchServiceSource;
use App\Models\ChurchServiceSourceRecord;
use App\Support\CanonicalJson;
use App\Support\ChurchServiceSourceKey;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Certifies the exact source revisions a historic proposal census is allowed to
 * describe. Counts are deliberately absent from this contract: an approved
 * source item either has its exact active revision and projection, or it does
 * not belong to the certified corpus.
 */
class ChurchServiceCorpusMembership
{
    public const string Format = 'crockenhill-historic-corpus-membership';

    public const int Version = 1;

    /**
     * @param  array<string, mixed>|null  $membership
     * @return array<string, mixed>
     */
    public function certify(?array $membership, int $policyVersion): array
    {
        if ($membership === null) {
            return ['approved' => false, 'items' => [], 'blockers' => ['membership_unapproved']];
        }

        $items = $this->validatedItems($membership);
        $expectedKeys = [];
        $results = [];
        $blockers = [];

        foreach ($items as $item) {
            $key = $this->key($item);
            $expectedKeys[$key] = true;
            $records = ChurchServiceSourceRecord::query()
                ->with('churchService')
                ->where('source', $item['source'])
                ->where('source_key_hash', ChurchServiceSourceKey::identity($item['source_key']))
                ->get();
            $matching = $records->filter(
                fn (ChurchServiceSourceRecord $record): bool => $record->batch_hash === $item['batch_hash'],
            );
            $issues = $this->issues($item, $matching, $records, $policyVersion);

            if ($issues !== []) {
                $blockers = [...$blockers, ...$issues];
            }

            $results[] = [
                'source' => $item['source'],
                'batch_hash' => $item['batch_hash'],
                'source_key' => $item['source_key'],
                'identity' => $item['identity'],
                'issues' => $issues,
            ];
        }

        $actual = ChurchServiceSourceRecord::query()
            ->whereIn('batch_hash', array_values(array_unique(array_column($items, 'batch_hash'))))
            ->get(['source', 'batch_hash', 'source_key']);

        foreach ($actual as $record) {
            if (! is_string($record->batch_hash)) {
                continue;
            }

            $key = $this->key([
                'source' => $record->source->value,
                'batch_hash' => $record->batch_hash,
                'source_key' => $record->source_key,
            ]);

            if (! isset($expectedKeys[$key])) {
                $blockers[] = 'unexpected_source_item';
            }
        }

        return [
            'approved' => true,
            'membership_hash' => $membership['membership_hash'],
            'source_kinds' => array_values(array_unique(array_column($items, 'source'))),
            'items' => $results,
            'blockers' => array_values(array_unique($blockers)),
        ];
    }

    /** @return array<string, mixed> */
    public function fromFile(string $path): array
    {
        $contents = file_get_contents($path);

        if (! is_string($contents)) {
            throw new RuntimeException('The historic corpus membership file could not be read.');
        }

        $membership = json_decode($contents, true);

        if (! is_array($membership)) {
            throw new RuntimeException('The historic corpus membership file contains invalid JSON.');
        }

        $this->validatedItems($membership);

        return $membership;
    }

    /**
     * @param  array<string, mixed>  $membership
     * @return list<array{source:string,batch_hash:string,source_key:string,input_hash:string,processing_fingerprint:array<string,mixed>,identity:array{date:string,service:string}}>
     */
    private function validatedItems(array $membership): array
    {
        $hash = $membership['membership_hash'] ?? null;
        $hashable = $membership;
        unset($hashable['membership_hash']);

        if (($membership['format'] ?? null) !== self::Format
            || ($membership['version'] ?? null) !== self::Version
            || ! is_string($hash)
            || ! hash_equals(CanonicalJson::hash($hashable), $hash)
            || ! is_array($membership['items'] ?? null)
            || $membership['items'] === []) {
            throw new RuntimeException('Historic corpus membership format, contents or hash is invalid.');
        }

        $items = [];
        $seen = [];

        foreach ($membership['items'] as $item) {
            if (! is_array($item)
                || ! is_string($item['source'] ?? null)
                || ! ChurchServiceSource::tryFrom($item['source']) instanceof ChurchServiceSource
                || ! is_string($item['batch_hash'] ?? null)
                || ! is_string($item['source_key'] ?? null)
                || ! is_string($item['input_hash'] ?? null)
                || ! is_array($item['processing_fingerprint'] ?? null)
                || ! is_array($item['identity'] ?? null)
                || ! is_string($item['identity']['date'] ?? null)
                || ! is_string($item['identity']['service'] ?? null)) {
                throw new RuntimeException('Historic corpus membership contains an invalid source item.');
            }

            $key = $this->key($item);
            if (isset($seen[$key])) {
                throw new RuntimeException('Historic corpus membership contains a duplicate source item.');
            }

            $seen[$key] = true;
            $items[] = [
                'source' => $item['source'],
                'batch_hash' => $item['batch_hash'],
                'source_key' => ChurchServiceSourceKey::canonical($item['source_key']),
                'input_hash' => $item['input_hash'],
                'processing_fingerprint' => $item['processing_fingerprint'],
                'identity' => [
                    'date' => $item['identity']['date'],
                    'service' => $item['identity']['service'],
                ],
            ];
        }

        return $items;
    }

    /**
     * @param  array{source:string,batch_hash:string,source_key:string,input_hash:string,processing_fingerprint:array<string,mixed>,identity:array{date:string,service:string}}  $expected
     * @param  Collection<int, ChurchServiceSourceRecord>  $matching
     * @param  Collection<int, ChurchServiceSourceRecord>  $allRevisions
     * @return list<string>
     */
    private function issues(array $expected, Collection $matching, Collection $allRevisions, int $policyVersion): array
    {
        if ($matching->count() !== 1) {
            return [$matching->isEmpty() ? 'missing_source_item' : 'ambiguous_source_item'];
        }

        $record = $matching->firstOrFail();
        $issues = [];
        $service = $record->churchService;

        if ($service === null) {
            return ['source_item_identity_mismatch'];
        }

        if ($allRevisions->contains(fn (ChurchServiceSourceRecord $revision): bool => $revision->supersedes_id === $record->id)) {
            $issues[] = 'source_item_not_active_leaf';
        }

        if ($record->input_hash !== $expected['input_hash']) {
            $issues[] = 'source_item_input_hash_mismatch';
        }

        if (CanonicalJson::hash($record->processing_fingerprint) !== CanonicalJson::hash($expected['processing_fingerprint'])) {
            $issues[] = 'source_item_fingerprint_mismatch';
        }

        if ($service->date->toDateString() !== $expected['identity']['date']
            || $service->service->value !== $expected['identity']['service']) {
            $issues[] = 'source_item_identity_mismatch';
        }

        if ($service->projection_policy_version !== $policyVersion) {
            $issues[] = 'source_item_projection_stale';
        }

        return $issues;
    }

    /** @param array{source:string,batch_hash:string,source_key:string} $item */
    private function key(array $item): string
    {
        return "{$item['source']}\0{$item['batch_hash']}\0".ChurchServiceSourceKey::identity($item['source_key']);
    }
}
