<?php

declare(strict_types=1);

namespace App\Services\Media\Video;

use App\Data\HistoricVideoCurationPlan;
use App\Enums\HistoricVideoCorroborationGrade;
use App\Enums\SermonService;
use App\Support\CanonicalJson;
use FilesystemIterator;
use Illuminate\Support\Carbon;
use JsonException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

class HistoricVideoCurationManifest
{
    private const FORMAT = 'crockenhill-historic-video-curation';

    private const VERSION = 4;

    private const SUPPORTED_EXTENSIONS = ['avi', 'mkv', 'mov', 'mp4', 'webm'];

    public function plan(string $rawDirectory, string $manifestPath): HistoricVideoCurationPlan
    {
        $rawRoot = realpath($rawDirectory);

        if (! is_string($rawRoot) || ! is_dir($rawRoot)) {
            throw new RuntimeException("Historic video directory does not exist: {$rawDirectory}");
        }

        $manifest = $this->manifest($manifestPath);
        $batchKey = $manifest['batch_key'];
        $entries = $manifest['entries'] ?? null;

        if (! is_array($entries) || ! array_is_list($entries)) {
            throw new RuntimeException('Historic video curation manifest entries must be a JSON list.');
        }

        $normalizedEntries = [];
        $seenKeys = [];
        $manifestPaths = [];

        foreach ($entries as $offset => $entry) {
            if (! is_array($entry)) {
                throw new RuntimeException("Historic video curation entry {$offset} must be an object.");
            }

            $normalized = $this->normalizeEntry($entry, $offset, $rawRoot);
            $key = $normalized['item_key'];

            if (isset($seenKeys[$key])) {
                throw new RuntimeException("Duplicate historic video manifest item key: {$key}");
            }

            $seenKeys[$key] = true;

            foreach ($normalized['files'] as $file) {
                if (isset($manifestPaths[$file['relative_path']])) {
                    throw new RuntimeException("Historic video manifest references a source file more than once: {$file['relative_path']}");
                }

                $manifestPaths[$file['relative_path']] = true;
            }

            $normalizedEntries[] = $normalized;
        }

        $this->validateDuplicateTargets($normalizedEntries, $seenKeys);
        $this->validateServiceIdentities($normalizedEntries);

        $unmanifested = array_values(array_diff(array_keys($this->inventory($rawRoot)), array_keys($manifestPaths)));

        if ($unmanifested !== []) {
            throw new RuntimeException('Historic video directory contains unmanifested files: '.implode(', ', $unmanifested));
        }

        $workItems = [];
        $exclusions = [];

        foreach ($normalizedEntries as $entry) {
            if ($entry['disposition'] !== 'include') {
                $exclusions[] = [
                    'item_key' => $entry['item_key'],
                    'exclusion_reason' => (string) $entry['exclusion_reason'],
                    'duplicate_of' => $entry['duplicate_of'],
                ];

                continue;
            }

            $files = [];
            $bytes = 0;

            foreach ($entry['files'] as $file) {
                $files[] = $this->verifiedPath($rawRoot, $file);
                $bytes += $file['byte_size'];
            }

            $date = Carbon::createFromFormat('Y-m-d', $entry['date'])?->startOfDay();

            if (! $date instanceof Carbon) {
                throw new RuntimeException("Invalid historic video manifest date: {$entry['date']}");
            }

            $workItems[] = [
                'manifest_item_key' => $entry['item_key'],
                'tag' => count($files) === 1 ? 'livestream' : 'concat',
                'label' => $entry['item_key'],
                'files' => $files,
                'source_files' => $entry['files'],
                'date' => $date,
                'service' => SermonService::from($entry['service']),
                'client_file_date' => $entry['client_file_date'] ?? $date->format('Y-m-d').' 12:00:00',
                'bytes' => $bytes,
                'manifest_concatenation' => $entry['concatenation'],
                'manifest_expected_occurrence_count' => $entry['expected_occurrence_count'],
                'manifest_corroboration' => HistoricVideoCorroborationGrade::from($entry['corroboration']),
                'editorial_facts' => $entry['editorial_facts'],
            ];
        }

        $manifestHash = CanonicalJson::hash([
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'batch_key' => $batchKey,
            'entries' => $normalizedEntries,
        ]);
        $counts = [
            'raw' => count($normalizedEntries),
            'include' => count($workItems),
            'exclude' => count($exclusions),
            'duplicate' => count(array_filter(
                $exclusions,
                static fn (array $exclusion): bool => $exclusion['duplicate_of'] !== null,
            )),
            ...$this->corroborationCounts($workItems),
        ];
        $planHash = CanonicalJson::hash([
            'format' => 'crockenhill-historic-video-import-plan',
            'version' => 1,
            'batch_key' => $batchKey,
            'manifest_hash' => $manifestHash,
            'counts' => $counts,
            'items' => array_map(static fn (array $item): array => [
                'item_key' => $item['manifest_item_key'],
                'date' => $item['date']->toDateString(),
                'service' => $item['service']->value,
                'concatenation' => $item['manifest_concatenation'],
                'expected_occurrence_count' => $item['manifest_expected_occurrence_count'],
                'corroboration' => $item['manifest_corroboration']->value,
                'editorial_facts' => $item['editorial_facts'],
                'files' => $item['source_files'],
            ], $workItems),
            'exclusions' => $exclusions,
        ]);

        return new HistoricVideoCurationPlan($manifestHash, $planHash, $workItems, $counts, $exclusions, $batchKey);
    }

    /**
     * A duplicate declaration is only meaningful if it points at another entry in
     * the same manifest and the duplicate itself is the copy being dropped.
     *
     * @param  list<array<string, mixed>>  $entries
     * @param  array<string, true>  $declaredKeys
     */
    private function validateDuplicateTargets(array $entries, array $declaredKeys): void
    {
        $byKey = collect($entries)->keyBy('item_key');

        foreach ($entries as $entry) {
            $duplicateOf = $entry['duplicate_of'];

            if ($duplicateOf === null) {
                continue;
            }

            if ($entry['disposition'] !== 'exclude') {
                throw new RuntimeException("Historic video entry {$entry['item_key']} declares duplicate_of but is not excluded.");
            }

            if ($duplicateOf === $entry['item_key']) {
                throw new RuntimeException("Historic video entry {$entry['item_key']} declares itself a duplicate.");
            }

            if (! isset($declaredKeys[$duplicateOf])) {
                throw new RuntimeException("Historic video entry {$entry['item_key']} duplicates undeclared item key {$duplicateOf}.");
            }

            $target = $byKey->get($duplicateOf);

            if (! is_array($target)
                || $target['disposition'] !== 'include'
                || $this->fileContentIdentity($target['files']) !== $this->fileContentIdentity($entry['files'])) {
                throw new RuntimeException("Historic video duplicate {$entry['item_key']} must name an included byte-identical target.");
            }
        }

        foreach ($entries as $entry) {
            $seen = [];
            $cursor = $entry;

            while ($cursor['duplicate_of'] !== null) {
                if (isset($seen[$cursor['item_key']])) {
                    throw new RuntimeException("Historic video duplicate chain for {$entry['item_key']} is cyclic.");
                }

                $seen[$cursor['item_key']] = true;
                $next = $byKey->get($cursor['duplicate_of']);

                if (! is_array($next)) {
                    throw new RuntimeException("Historic video duplicate chain for {$entry['item_key']} has no target.");
                }

                $cursor = $next;
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $files
     * @return list<array{sha256:mixed,byte_size:mixed}>
     */
    private function fileContentIdentity(array $files): array
    {
        return array_map(static fn (array $file): array => [
            'sha256' => $file['sha256'] ?? null,
            'byte_size' => $file['byte_size'] ?? null,
        ], $files);
    }

    /** @return array<string, mixed> */
    private function manifest(string $path): array
    {
        $bytes = file_get_contents($path);

        if (! is_string($bytes)) {
            throw new RuntimeException("Historic video curation manifest does not exist: {$path}");
        }

        try {
            $manifest = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Historic video curation manifest is not valid JSON.', previous: $exception);
        }

        if (! is_array($manifest) || ! $this->hasExactKeys($manifest, ['format', 'version', 'batch_key', 'entries'])
            || $manifest['format'] !== self::FORMAT || $manifest['version'] !== self::VERSION
            || ! is_string($manifest['batch_key']) || trim($manifest['batch_key']) === '') {
            throw new RuntimeException('Unsupported historic video curation manifest format or version.');
        }

        $manifest['batch_key'] = trim($manifest['batch_key']);

        return $manifest;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array{
     *     item_key:string,
     *     disposition:string,
     *     exclusion_reason:?string,
     *     duplicate_of:?string,
     *     date:string,
     *     service:string,
     *     concatenation:string,
     *     client_file_date:?string,
     *     expected_occurrence_count:int,
     *     corroboration:string,
     *     decision:array<string, mixed>,
     *     editorial_facts:array<string, string|null>,
     *     files:list<array{relative_path:string,sha256:string,byte_size:int}>
     * }
     */
    private function normalizeEntry(array $entry, int $offset, string $rawRoot): array
    {
        if (! $this->hasExactKeys($entry, [
            'item_key',
            'source_kind',
            'disposition',
            'exclusion_reason',
            'duplicate_of',
            'date',
            'service',
            'concatenation',
            'client_file_date',
            'expected_occurrence_count',
            'corroboration',
            'decision',
            'editorial_facts',
            'files',
        ])) {
            throw new RuntimeException("Historic video entry {$offset} has unknown or missing schema fields.");
        }

        $itemKey = $this->requiredString($entry, 'item_key', $offset);
        $disposition = $this->requiredString($entry, 'disposition', $offset);

        if (! in_array($disposition, ['include', 'exclude'], true)) {
            throw new RuntimeException("Invalid historic video disposition for {$itemKey}.");
        }

        $sourceKind = $this->requiredString($entry, 'source_kind', $offset);

        if ($sourceKind !== 'livestream') {
            throw new RuntimeException("Unsupported historic video source kind for {$itemKey}.");
        }

        $date = $this->requiredString($entry, 'date', $offset);
        $service = $this->requiredString($entry, 'service', $offset);

        $parsedDate = Carbon::createFromFormat('!Y-m-d', $date);
        $dateErrors = Carbon::getLastErrors();

        if (preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $date) !== 1
            || ! $parsedDate instanceof Carbon
            || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))
            || SermonService::tryFrom($service) === null) {
            throw new RuntimeException("Historic video entry {$itemKey} must declare an explicit valid date and service.");
        }

        $concatenation = $this->requiredString($entry, 'concatenation', $offset);

        if (! in_array($concatenation, ['single', 'lossless', 'reencoded'], true)) {
            throw new RuntimeException("Invalid historic video concatenation decision for {$itemKey}.");
        }

        $expectedCount = $entry['expected_occurrence_count'] ?? null;

        if (! is_int($expectedCount) || $expectedCount < 0) {
            throw new RuntimeException("Historic video entry {$itemKey} requires expected_occurrence_count.");
        }

        $corroboration = HistoricVideoCorroborationGrade::tryFrom((string) ($entry['corroboration'] ?? ''));

        if (! $corroboration instanceof HistoricVideoCorroborationGrade) {
            throw new RuntimeException("Historic video entry {$itemKey} requires a known corroboration grade.");
        }

        $exclusionReason = $this->nullableString($entry, 'exclusion_reason');

        if ($disposition === 'exclude' && $exclusionReason === null) {
            throw new RuntimeException("Historic video entry {$itemKey} is excluded without a reason.");
        }

        $decision = $entry['decision'] ?? null;

        if (! is_array($decision)) {
            throw new RuntimeException("Historic video entry {$itemKey} requires an authorised decision.");
        }

        $hasRule = $this->hasExactKeys($decision, ['approved_rule_version'])
            && is_string($decision['approved_rule_version'])
            && trim($decision['approved_rule_version']) !== '';
        $hasPerson = $this->hasExactKeys($decision, ['author', 'decided_at'])
            && is_string($decision['author'])
            && trim($decision['author']) !== ''
            && is_string($decision['decided_at'])
            && Carbon::parse($decision['decided_at'])->toIso8601String() === $decision['decided_at'];

        if (! $hasRule && ! $hasPerson) {
            throw new RuntimeException("Historic video entry {$itemKey} requires an exact authorised decision shape.");
        }

        $editorialFacts = $entry['editorial_facts'] ?? null;

        if (! is_array($editorialFacts) || ! $this->hasExactKeys($editorialFacts, [
            'occasion',
            'title',
            'speaker',
            'scripture_reference',
            'series',
        ])) {
            throw new RuntimeException("Historic video entry {$itemKey} requires exact portable editorial facts.");
        }

        foreach ($editorialFacts as $field => $value) {
            if ($value !== null && (! is_string($value) || trim($value) === '')) {
                throw new RuntimeException("Historic video entry {$itemKey} has an invalid editorial fact: {$field}.");
            }

            $editorialFacts[$field] = is_string($value) ? trim($value) : null;
        }

        $files = $entry['files'] ?? null;

        if (! is_array($files) || ! array_is_list($files) || $files === []) {
            throw new RuntimeException("Historic video entry {$itemKey} requires source files.");
        }

        if (($concatenation === 'single') !== (count($files) === 1)) {
            throw new RuntimeException("Historic video entry {$itemKey} has a contradictory concatenation decision.");
        }

        // A multi-file service is fragmented by definition, and a single-file one
        // cannot be. Tying the grade to the file list stops a curated entry from
        // claiming whole-service corroboration it does not have.
        if (($corroboration === HistoricVideoCorroborationGrade::Fragmented) !== (count($files) > 1)) {
            throw new RuntimeException("Historic video entry {$itemKey} has a corroboration grade contradicting its source files.");
        }

        // The declared segment count is the operator's independent statement of
        // how many recordings this service produced, so a files list that has been
        // truncated — or padded — during curation fails here rather than silently
        // dispatching a partial service.
        if ($disposition === 'include' && $expectedCount !== count($files)) {
            throw new RuntimeException(
                "Historic video entry {$itemKey} expects {$expectedCount} occurrences but declares ".count($files).' source files.',
            );
        }

        $normalizedFiles = [];

        foreach ($files as $file) {
            if (! is_array($file) || ! $this->hasExactKeys($file, ['relative_path', 'sha256', 'byte_size'])) {
                throw new RuntimeException("Historic video entry {$itemKey} has an invalid source file.");
            }

            $relativePath = $this->requiredString($file, 'relative_path', $offset);
            $this->validateRelativePath($relativePath, $rawRoot);
            $extension = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));

            if (! in_array($extension, self::SUPPORTED_EXTENSIONS, true)) {
                throw new RuntimeException("Historic video entry {$itemKey} has an unsupported source extension.");
            }

            $hash = strtolower($this->requiredString($file, 'sha256', $offset));
            $size = $file['byte_size'] ?? null;

            if (preg_match('/\A[0-9a-f]{64}\z/', $hash) !== 1 || ! is_int($size) || $size < 1) {
                throw new RuntimeException("Historic video entry {$itemKey} has an invalid source hash or byte size.");
            }

            $normalizedFiles[] = ['relative_path' => $relativePath, 'sha256' => $hash, 'byte_size' => $size];
        }

        return [
            'item_key' => $itemKey,
            'disposition' => $disposition,
            'exclusion_reason' => $exclusionReason,
            'duplicate_of' => $this->nullableString($entry, 'duplicate_of'),
            'date' => $date,
            'service' => $service,
            'concatenation' => $concatenation,
            'client_file_date' => $this->nullableString($entry, 'client_file_date'),
            'expected_occurrence_count' => $expectedCount,
            'corroboration' => $corroboration->value,
            'decision' => $decision,
            'editorial_facts' => $editorialFacts,
            'files' => $normalizedFiles,
        ];
    }

    /**
     * Grade totals ride in the plan counts so the evidence strength of an
     * approved corpus is hash-covered alongside its membership, rather than
     * being recomputed later from a report nobody signed.
     *
     * @param  list<array<string, mixed>>  $workItems
     * @return array<string, int>
     */
    private function corroborationCounts(array $workItems): array
    {
        $counts = [];

        foreach (HistoricVideoCorroborationGrade::cases() as $grade) {
            $counts["corroboration_{$grade->value}"] = count(array_filter(
                $workItems,
                static fn (array $item): bool => $item['manifest_corroboration'] === $grade,
            ));
        }

        return $counts;
    }

    /** @param list<array<string, mixed>> $entries */
    private function validateServiceIdentities(array $entries): void
    {
        $identities = [];

        foreach ($entries as $entry) {
            if (($entry['disposition'] ?? null) !== 'include') {
                continue;
            }

            $identity = "{$entry['date']}|{$entry['service']}";

            if (isset($identities[$identity])) {
                throw new RuntimeException(
                    "Historic video manifest contains multiple included services for {$identity}; curate one service or exclude the unresolved collision.",
                );
            }

            $identities[$identity] = true;
        }
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @param  list<string>  $expected
     */
    private function hasExactKeys(array $payload, array $expected): bool
    {
        $keys = array_keys($payload);
        sort($keys);
        sort($expected);

        return $keys === $expected;
    }

    /**
     * The completeness sweep exists to catch a recording nobody curated, so it
     * only considers files that could be one. The corpus lives on a removable
     * drive mounted by macOS and Windows, which both scatter their own metadata
     * through it (`.DS_Store`, `._` AppleDouble forks, `.Spotlight-V100`,
     * `Thumbs.db`); demanding a manifest entry for those would make every plan
     * unrunnable against the real drive.
     *
     * @return array<string, true>
     */
    private function inventory(string $rawRoot): array
    {
        $inventory = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($rawRoot, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo || ! $file->isFile()) {
                continue;
            }

            $relativePath = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($rawRoot) + 1));

            if (! $this->isCandidateRecording($relativePath)) {
                continue;
            }

            $this->validateRelativePath($relativePath, $rawRoot);
            $inventory[$relativePath] = true;
        }

        ksort($inventory, SORT_STRING);

        return $inventory;
    }

    private function isCandidateRecording(string $relativePath): bool
    {
        foreach (explode('/', $relativePath) as $segment) {
            if (str_starts_with($segment, '.')) {
                return false;
            }
        }

        return in_array(
            strtolower(pathinfo($relativePath, PATHINFO_EXTENSION)),
            self::SUPPORTED_EXTENSIONS,
            true,
        );
    }

    /** @param array{relative_path:string,sha256:string,byte_size:int} $file */
    private function verifiedPath(string $rawRoot, array $file): string
    {
        $path = "{$rawRoot}/{$file['relative_path']}";

        if (! is_file($path) || $this->containsSymlink($rawRoot, $file['relative_path'])) {
            throw new RuntimeException("Historic video source is missing or symlinked: {$file['relative_path']}");
        }

        $realPath = realpath($path);

        if (! is_string($realPath) || ! $this->isWithinRoot($realPath, $rawRoot)) {
            throw new RuntimeException("Historic video source escapes its root: {$file['relative_path']}");
        }

        $size = filesize($realPath);
        $hash = hash_file('sha256', $realPath);

        if ($size !== $file['byte_size'] || ! is_string($hash) || ! hash_equals($file['sha256'], $hash)) {
            throw new RuntimeException("Historic video source changed: {$file['relative_path']}");
        }

        return $realPath;
    }

    private function validateRelativePath(string $relativePath, string $rawRoot): void
    {
        if ($relativePath === '' || str_starts_with($relativePath, '/') || str_contains($relativePath, '\\') || in_array('..', explode('/', $relativePath), true)) {
            throw new RuntimeException("Unsafe historic video manifest path: {$relativePath}");
        }

        $resolved = realpath("{$rawRoot}/{$relativePath}");

        if (is_string($resolved) && ! $this->isWithinRoot($resolved, $rawRoot)) {
            throw new RuntimeException("Historic video manifest path escapes its root: {$relativePath}");
        }
    }

    private function containsSymlink(string $rawRoot, string $relativePath): bool
    {
        $path = $rawRoot;

        foreach (explode('/', $relativePath) as $segment) {
            $path .= DIRECTORY_SEPARATOR.$segment;

            if (is_link($path)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $entry */
    private function requiredString(array $entry, string $key, int $offset): string
    {
        $value = $entry[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException("Historic video curation entry {$offset} requires {$key}.");
        }

        return trim($value);
    }

    /** @param array<string, mixed> $entry */
    private function nullableString(array $entry, string $key): ?string
    {
        $value = $entry[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function isWithinRoot(string $path, string $root): bool
    {
        return $path !== $root && str_starts_with($path, $root.DIRECTORY_SEPARATOR);
    }
}
