<?php

declare(strict_types=1);

namespace App\Services\HistoricMedia;

use RuntimeException;

class HistoricProcessingMetadataSerializer
{
    private const PORTABLE_KEYS = [
        'historic_import',
        'rms_log_path',
        'service_artifacts',
        'service_structure',
        'service_structure_result',
        'service_transcript_path',
        'service_transcript_unobservable_windows',
        'raw_service_transcript_path',
        'compressed_service_audio_path',
        'processing_fingerprint',
    ];

    /**
     * Local-only keys the export drops rather than guards.
     *
     * `extracted_audio_path` and `extracted_segment_path` are staging-relative
     * working copies of paths the section and run records already carry through
     * the asset roles, so the metadata copy is runtime state. Left unlisted they
     * hit the path branch of `guardUnknownKey`, which is what made every pilot
     * run fail portable inventory.
     *
     * `service_structure_proposal` is a rejected structure proposal kept for
     * local review. It names local order-of-service item keys and describes a
     * revision that was never applied, so it is local review state; it is listed
     * here rather than left to the generic `proposal` guard, which still fails
     * closed for any proposal key nobody has classified.
     */
    private const RUNTIME_KEYS = [
        'historic_promotion',
        'attempt_count',
        'enhanced_audio_file_path',
        'extracted_audio_path',
        'extracted_segment_path',
        'job_id',
        'owner_user_id',
        'queue_name',
        'retry_state',
        'service_structure_proposal',
        'source_file_path',
    ];

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    public function serialize(array $metadata): array
    {
        $portable = [];

        foreach ($metadata as $key => $value) {
            if (in_array($key, self::RUNTIME_KEYS, true)) {
                continue;
            }

            if (! in_array($key, self::PORTABLE_KEYS, true)) {
                $this->guardUnknownKey($key, $value);

                continue;
            }

            if ($key === 'historic_import' && is_array($value)) {
                $value = $this->portableHistoricImport($value);
            }

            if ($key === 'service_structure' && is_array($value)) {
                $value = $this->portableServiceStructure($value);
            }

            $this->guardPortableValue($key, $value);
            $portable[$key] = $value;
        }

        ksort($portable);

        return $portable;
    }

    /**
     * Structure sections carry `oos_item_id`, the local primary key of the
     * order-of-service item a section was aligned to. That identity means
     * nothing at the destination — the same service is a different row there —
     * so it is dropped rather than carried.
     *
     * Only this one known-local key is removed. Anything else identity-bearing
     * that appears in `service_structure` later still reaches `guardPortableValue`
     * and fails the export closed, which is the behaviour that surfaced this
     * field in the first place.
     *
     * @param  array<string, mixed>  $structure
     * @return array<string, mixed>
     */
    private function portableServiceStructure(array $structure): array
    {
        if (! is_array($structure['sections'] ?? null)) {
            return $structure;
        }

        $sections = [];

        foreach ($structure['sections'] as $section) {
            if (is_array($section)) {
                unset($section['oos_item_id']);
            }

            $sections[] = $section;
        }

        $structure['sections'] = $sections;

        return $structure;
    }

    /**
     * @param  array<string, mixed>  $historicImport
     * @return array<string, mixed>
     */
    private function portableHistoricImport(array $historicImport): array
    {
        $sources = [];

        foreach ($historicImport['sources'] ?? [] as $source) {
            if (! is_array($source)) {
                continue;
            }

            $sources[] = [
                'sha256' => $source['sha256'] ?? null,
                'size' => $source['size'] ?? null,
            ];
        }

        return array_filter([
            'tag' => $historicImport['tag'] ?? null,
            'concatenation' => $historicImport['concatenation'] ?? null,
            'codec_fingerprint' => $historicImport['codec_fingerprint'] ?? null,
            'sources' => $sources,
            'editorial_facts' => $this->portableEditorialFacts($historicImport['editorial_facts'] ?? null),
            'scripture_passage_outcomes' => $this->portableScripturePassageOutcomes(
                $historicImport['scripture_passage_outcomes'] ?? null
            ),
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * F59 settles a publication that resolved no passage on an approved terminal
     * absence, and HistoricProcessingResultInventory reads that settlement from
     * here to emit `scripture_passage_outcome`.
     *
     * This allow-list dropped it, so an export replaced the curator's decision
     * with nothing. Before HIR3 the destination read the omission as approval
     * and applied the publication with no Scripture relationship at all; after
     * it, the apply refuses. Either way the settlement has to travel, so it does.
     *
     * The block is keyed by publication slug, which is portable, and only the
     * two decision fields are carried.
     *
     * @return array<string, array<string, string>>|null
     */
    private function portableScripturePassageOutcomes(mixed $outcomes): ?array
    {
        if (! is_array($outcomes)) {
            return null;
        }

        $portable = [];

        foreach ($outcomes as $slug => $outcome) {
            if (! is_string($slug) || ! is_array($outcome)) {
                continue;
            }

            $settled = [];

            foreach (['status', 'reason'] as $field) {
                $value = $outcome[$field] ?? null;

                if (is_string($value) && $value !== '') {
                    $settled[$field] = $value;
                }
            }

            if ($settled !== []) {
                $portable[$slug] = $settled;
            }
        }

        ksort($portable);

        return $portable === [] ? null : $portable;
    }

    /**
     * F44 requires the curated occasion/title/speaker/scripture/series facts to
     * survive the one-time import. Title, speaker, scripture and series reach the
     * destination on the sermon itself, but `occasion` has no column anywhere, so
     * without carrying the block the curated fact is destroyed at this boundary.
     *
     * @return array<string, string>|null
     */
    private function portableEditorialFacts(mixed $editorialFacts): ?array
    {
        if (! is_array($editorialFacts)) {
            return null;
        }

        $portable = [];

        foreach (['occasion', 'title', 'speaker', 'scripture_reference', 'series'] as $field) {
            $value = $editorialFacts[$field] ?? null;

            if (is_string($value) && $value !== '') {
                $portable[$field] = $value;
            }
        }

        return $portable === [] ? null : $portable;
    }

    private function guardUnknownKey(string $key, mixed $value): void
    {
        if (
            HistoricProcessingResultFieldClassifier::isIdentityKey($key)
            || HistoricProcessingResultFieldClassifier::isPathKey($key)
            || preg_match('/(^|_)(token|secret|key)$/i', $key) === 1
            || str_contains(mb_strtolower($key), 'proposal')
            || str_contains(mb_strtolower($key), 'retry')
            || $this->containsAbsolutePath($value)
        ) {
            throw new RuntimeException("Unsupported ID-bearing, path or runtime processing metadata: {$key}.");
        }
    }

    private function guardPortableValue(string $key, mixed $value): void
    {
        if ($this->containsAbsolutePath($value)) {
            throw new RuntimeException("Portable processing metadata contains an absolute path in {$key}.");
        }

        if ($this->containsForbiddenNestedKey($value, $key === 'service_artifacts')) {
            throw new RuntimeException("Portable processing metadata contains a local identity or runtime field in {$key}.");
        }
    }

    private function containsAbsolutePath(mixed $value): bool
    {
        if (is_string($value)) {
            return str_starts_with($value, '/')
                || preg_match('/\A[A-Za-z]:[\\\\\\/]/', $value) === 1
                || str_starts_with($value, 'file://');
        }

        if (! is_array($value)) {
            return false;
        }

        foreach ($value as $nested) {
            if ($this->containsAbsolutePath($nested)) {
                return true;
            }
        }

        return false;
    }

    private function containsForbiddenNestedKey(mixed $value, bool $allowArtifactPath = false): bool
    {
        if (! is_array($value)) {
            return false;
        }

        foreach ($value as $key => $nested) {
            if (is_string($key) && (
                HistoricProcessingResultFieldClassifier::isIdentityKey($key)
                || in_array($key, self::RUNTIME_KEYS, true)
                || str_contains(mb_strtolower($key), 'proposal')
                || str_contains(mb_strtolower($key), 'retry')
                || (
                    HistoricProcessingResultFieldClassifier::isPathKey($key)
                    && ! ($allowArtifactPath && $key === 'path')
                )
            )) {
                return true;
            }

            if ($this->containsForbiddenNestedKey($nested, $allowArtifactPath)) {
                return true;
            }
        }

        return false;
    }
}
