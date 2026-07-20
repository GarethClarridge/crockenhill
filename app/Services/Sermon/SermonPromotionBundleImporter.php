<?php

declare(strict_types=1);

namespace App\Services\Sermon;

use App\Enums\SermonContentType;
use App\Models\MediaProcessingLog;
use App\Models\Preacher;
use App\Models\PreacherAlias;
use App\Models\Sermon;
use App\Models\SermonProcessingStep;
use App\Services\Processing\SermonIdentitySyncService;
use App\Services\Scripture\SermonScriptureFilterIndexService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class SermonPromotionBundleImporter
{
    public function __construct(
        private readonly SermonPromotionAssets $assets,
        private readonly SermonPromotionBundleFiles $files,
        private readonly SermonPromotionBundleValidator $validator,
        private readonly SermonScriptureFilterIndexService $scriptureFilters,
        private readonly SermonIdentitySyncService $identitySync,
    ) {}

    /**
     * @return array{
     *     applied: bool,
     *     verified_hashes: bool,
     *     counts: array{already_present: int, create: int, conflict: int, created: int},
     *     entries: list<array{local_id: int, classification: 'already_present'|'create'|'conflict', reason: string, existing_sermon_id: int|null}>
     * }
     */
    public function import(string $path, bool $verifyHashes = false, bool $apply = false): array
    {
        $bundle = $this->validator->decodeAndValidate($this->files->read($path));
        /** @var list<array<string, mixed>> $bundleEntries */
        $bundleEntries = $bundle['sermons'];
        $preflightEntries = $this->preflight($bundleEntries, $verifyHashes);
        $counts = $this->counts($preflightEntries);

        if ($counts['conflict'] > 0 || ! $apply) {
            return [
                'applied' => false,
                'verified_hashes' => $verifyHashes,
                'counts' => [...$counts, 'created' => 0],
                'entries' => $preflightEntries,
            ];
        }

        $created = DB::transaction(function () use ($bundleEntries, $preflightEntries): int {
            $created = 0;

            foreach ($bundleEntries as $index => $bundleEntry) {
                if ($preflightEntries[$index]['classification'] === 'already_present') {
                    continue;
                }

                $currentClassification = $this->classifyExistingSermon($bundleEntry);

                if ($currentClassification['classification'] !== 'create') {
                    throw new RuntimeException('Promotion preflight changed before apply; no records were committed.');
                }

                $this->persistEntry($bundleEntry);
                $created++;
            }

            return $created;
        });

        return [
            'applied' => true,
            'verified_hashes' => $verifyHashes,
            'counts' => [...$counts, 'created' => $created],
            'entries' => $preflightEntries,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $bundleEntries
     * @return list<array{local_id: int, classification: 'already_present'|'create'|'conflict', reason: string, existing_sermon_id: int|null}>
     */
    private function preflight(array $bundleEntries, bool $verifyHashes): array
    {
        $bundleConflictReasons = $this->bundleConflictReasons($bundleEntries);
        $results = [];

        foreach ($bundleEntries as $index => $bundleEntry) {
            $localId = (int) $bundleEntry['local_id'];
            $reason = $bundleConflictReasons[$index] ?? null;

            if ($reason !== null) {
                $results[] = $this->conflict($localId, $reason);

                continue;
            }

            try {
                /** @var list<array{kind: string, path: string, size: int, sha256: string}> $assetManifest */
                $assetManifest = $bundleEntry['assets'];
                $this->assets->verify($assetManifest, $verifyHashes);
                $this->guardScriptureFilters($bundleEntry);
            } catch (Throwable $exception) {
                $results[] = $this->conflict($localId, $exception->getMessage());

                continue;
            }

            $classification = $this->classifyExistingSermon($bundleEntry);

            if ($classification['classification'] === 'create') {
                $preacherConflict = $this->preacherConflict($bundleEntry);

                if ($preacherConflict !== null) {
                    $results[] = $this->conflict($localId, $preacherConflict);

                    continue;
                }
            }

            $results[] = [
                'local_id' => $localId,
                'classification' => $classification['classification'],
                'reason' => $classification['reason'],
                'existing_sermon_id' => $classification['existing_sermon_id'],
            ];
        }

        return $results;
    }

    /**
     * @param  array<string, mixed>  $bundleEntry
     * @return array{classification: 'already_present'|'create'|'conflict', reason: string, existing_sermon_id: int|null}
     */
    private function classifyExistingSermon(array $bundleEntry): array
    {
        /** @var array<string, mixed> $sermonData */
        $sermonData = $bundleEntry['sermon'];
        /** @var array<string, mixed> $provenance */
        $provenance = $bundleEntry['provenance'];
        /** @var list<array{kind: string, path: string, size: int, sha256: string}> $assetManifest */
        $assetManifest = $bundleEntry['assets'];

        $assetPaths = array_values(array_unique(array_column($assetManifest, 'path')));
        $assetSermonIds = $this->sermonIdsForAssetPaths($assetPaths);
        $hashLogs = MediaProcessingLog::query()
            ->where('file_hash', $provenance['file_hash'])
            ->get(['sermon_id']);
        $hashSermonIds = $hashLogs
            ->pluck('sermon_id')
            ->filter(fn (mixed $id): bool => is_numeric($id))
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
        $processingLog = MediaProcessingLog::query()
            ->where('processing_id', $provenance['processing_id'])
            ->first(['sermon_id']);
        $slugSermon = Sermon::query()
            ->where('slug', $sermonData['slug'])
            ->first(['id']);
        $anchorIds = array_values(array_unique([...$assetSermonIds, ...$hashSermonIds]));

        if ($hashLogs->contains(fn (MediaProcessingLog $log): bool => $log->sermon_id === null)) {
            return $this->classificationConflict('Source hash is already used by unlinked processing provenance.');
        }

        if (count($anchorIds) > 1) {
            return $this->classificationConflict('Strong asset/hash identities point to different production sermons.');
        }

        if ($anchorIds === []) {
            if ($processingLog instanceof MediaProcessingLog) {
                return $this->classificationConflict('Processing UUID already belongs to production without a matching asset/hash identity.');
            }

            if ($slugSermon instanceof Sermon) {
                return $this->classificationConflict('Sermon slug already belongs to production without a matching asset/hash identity.');
            }

            return [
                'classification' => 'create',
                'reason' => 'No strong production identity match was found.',
                'existing_sermon_id' => null,
            ];
        }

        $existingSermonId = $anchorIds[0];
        $existingSermon = Sermon::query()->find($existingSermonId, ['id', 'content_type']);

        if (! $existingSermon instanceof Sermon || $existingSermon->content_type !== SermonContentType::Sermon) {
            return $this->classificationConflict('Strong identity points to a non-sermon production record.');
        }

        if ($processingLog instanceof MediaProcessingLog && $processingLog->sermon_id !== $existingSermonId) {
            return $this->classificationConflict('Processing UUID points to a different production sermon.');
        }

        if ($slugSermon instanceof Sermon && $slugSermon->id !== $existingSermonId) {
            return $this->classificationConflict('Sermon slug points to a different production sermon.');
        }

        return [
            'classification' => 'already_present',
            'reason' => 'Exact source hash or canonical asset path matches production.',
            'existing_sermon_id' => $existingSermonId,
        ];
    }

    /**
     * @param  list<string>  $assetPaths
     * @return list<int>
     */
    private function sermonIdsForAssetPaths(array $assetPaths): array
    {
        $pathLookup = array_fill_keys($assetPaths, true);
        $sermonIds = [];

        $sermons = Sermon::query()
            ->select([
                'id',
                'audio_file_path',
                'video_file_path',
                'transcript_file_path',
                'thumbnail_file_path',
                'thumbnail_metadata',
            ])
            ->orderBy('id')
            ->cursor();

        foreach ($sermons as $sermon) {
            foreach ($this->assets->pathsForSermon($sermon) as $path) {
                if (isset($pathLookup[$path])) {
                    $sermonIds[$sermon->id] = true;
                }
            }
        }

        return array_map('intval', array_keys($sermonIds));
    }

    /**
     * @param  array<string, mixed>  $bundleEntry
     */
    private function preacherConflict(array $bundleEntry): ?string
    {
        /** @var array{name: string, slug: string, aliases: list<string>} $preacherData */
        $preacherData = $bundleEntry['preacher'];
        $matches = $this->matchingPreachers($preacherData);

        return count($matches) > 1
            ? 'Preacher slug, name, or aliases point to different canonical preachers.'
            : null;
    }

    /**
     * @param  array{name: string, slug: string, aliases: list<string>}  $preacherData
     * @return array<int, Preacher>
     */
    private function matchingPreachers(array $preacherData): array
    {
        /** @var array<int, Preacher> $matches */
        $matches = Preacher::query()
            ->where('slug', $preacherData['slug'])
            ->orWhere('name', $preacherData['name'])
            ->get()
            ->keyBy('id')
            ->all();
        $aliasValues = array_values(array_unique([
            Str::lower(Str::squish($preacherData['name'])),
            ...$preacherData['aliases'],
        ]));
        $aliases = PreacherAlias::query()
            ->with('preacher')
            ->whereIn('alias', $aliasValues)
            ->get();

        foreach ($aliases as $alias) {
            if ($alias->preacher instanceof Preacher) {
                $matches[$alias->preacher->id] = $alias->preacher;
            }
        }

        return $matches;
    }

    /**
     * @param  list<array<string, mixed>>  $bundleEntries
     * @return array<int, string>
     */
    private function bundleConflictReasons(array $bundleEntries): array
    {
        $reasons = [];
        $assetOwners = [];
        $plannedSlugOwners = [];
        $plannedNameOwners = [];
        $plannedAliasOwners = [];

        foreach ($bundleEntries as $index => $entry) {
            /** @var list<array{kind: string, path: string, size: int, sha256: string}> $assetManifest */
            $assetManifest = $entry['assets'];

            foreach ($assetManifest as $asset) {
                $owner = $assetOwners[$asset['path']] ?? null;

                if (is_int($owner) && $owner !== $index) {
                    $reasons[$owner] = 'Canonical asset path is shared by multiple bundle entries.';
                    $reasons[$index] = 'Canonical asset path is shared by multiple bundle entries.';
                }

                $assetOwners[$asset['path']] = $index;
            }

            /** @var array{name: string, slug: string, aliases: list<string>} $preacher */
            $preacher = $entry['preacher'];
            $preacherKey = $preacher['slug'].'|'.Str::lower(Str::squish($preacher['name']));
            $normalizedName = Str::lower(Str::squish($preacher['name']));

            foreach ([
                [$plannedSlugOwners, $preacher['slug']],
                [$plannedNameOwners, $normalizedName],
            ] as [$owners, $value]) {
                $owner = $owners[$value] ?? null;

                if (is_array($owner) && $owner['key'] !== $preacherKey) {
                    $reasons[$owner['index']] = 'Bundle preacher natural identities conflict.';
                    $reasons[$index] = 'Bundle preacher natural identities conflict.';
                }
            }

            $plannedSlugOwners[$preacher['slug']] = ['index' => $index, 'key' => $preacherKey];
            $plannedNameOwners[$normalizedName] = ['index' => $index, 'key' => $preacherKey];

            foreach ([$normalizedName, ...$preacher['aliases']] as $alias) {
                $owner = $plannedAliasOwners[$alias] ?? null;

                if (is_array($owner) && $owner['key'] !== $preacherKey) {
                    $reasons[$owner['index']] = 'Bundle preacher aliases conflict.';
                    $reasons[$index] = 'Bundle preacher aliases conflict.';
                }

                $plannedAliasOwners[$alias] = ['index' => $index, 'key' => $preacherKey];
            }
        }

        return $reasons;
    }

    /**
     * @param  array<string, mixed>  $bundleEntry
     */
    private function guardScriptureFilters(array $bundleEntry): void
    {
        /** @var array<string, mixed> $sermonData */
        $sermonData = $bundleEntry['sermon'];
        /** @var list<array{bible_book: string, bible_chapter: int}> $bundledFilters */
        $bundledFilters = $bundleEntry['scripture_filters'];
        $expectedFilters = $this->scriptureFilters->entriesForReference($sermonData['reference']);

        if ($this->normalizedFilters($bundledFilters) !== $this->normalizedFilters($expectedFilters)) {
            throw new RuntimeException('Bundled scripture filters do not match the current reference parser.');
        }
    }

    /**
     * @param  array<string, mixed>  $bundleEntry
     */
    private function persistEntry(array $bundleEntry): void
    {
        /** @var array<string, mixed> $sermonData */
        $sermonData = $bundleEntry['sermon'];
        /** @var array{name: string, slug: string, aliases: list<string>} $preacherData */
        $preacherData = $bundleEntry['preacher'];
        /** @var array<string, mixed> $provenance */
        $provenance = $bundleEntry['provenance'];
        /** @var list<array{bible_book: string, bible_chapter: int}> $filters */
        $filters = $bundleEntry['scripture_filters'];

        $preacher = $this->resolveOrCreatePreacher($preacherData);
        $scripturePassage = $this->identitySync->findExistingScripturePassage($sermonData['reference']);

        $sermon = new Sermon;
        $sermon->forceFill([
            ...$sermonData,
            'preacher' => $preacher->name,
            'preacher_id' => $preacher->id,
            'scripture_passage_id' => $scripturePassage?->id,
            'livestream_processing_id' => null,
            'download_count' => 0,
        ]);
        $sermon->save();

        /** @var list<array<string, mixed>> $steps */
        $steps = $provenance['steps'];
        unset($provenance['steps']);

        $processingLog = new MediaProcessingLog;
        $processingLog->forceFill([
            ...$provenance,
            'sermon_id' => $sermon->id,
            'owner_user_id' => null,
            'church_service_id' => null,
            'queue_name' => null,
            'job_id' => null,
            'attempt_count' => null,
            'dedup_key' => null,
        ]);
        $processingLog->save();

        foreach ($steps as $stepData) {
            $step = new SermonProcessingStep;
            $step->forceFill([
                ...$stepData,
                'processing_id' => $processingLog->processing_id,
            ]);
            $step->save();
        }

        $this->scriptureFilters->syncForSermon($sermon, $filters);
    }

    /**
     * @param  array{name: string, slug: string, aliases: list<string>}  $preacherData
     */
    private function resolveOrCreatePreacher(array $preacherData): Preacher
    {
        $matches = $this->matchingPreachers($preacherData);

        if (count($matches) > 1) {
            throw new RuntimeException('Preacher identity changed after preflight.');
        }

        $preacher = array_values($matches)[0] ?? null;

        if ($preacher instanceof Preacher) {
            return $preacher;
        }

        $preacher = Preacher::query()->create([
            'name' => $preacherData['name'],
            'slug' => $preacherData['slug'],
            'is_active' => true,
        ]);

        $aliases = array_values(array_unique(array_filter(
            $preacherData['aliases'],
            fn (string $alias): bool => $alias !== Str::lower(Str::squish($preacher->name)),
        )));

        if ($aliases !== []) {
            $preacher->aliases()->createMany(array_map(
                static fn (string $alias): array => ['alias' => $alias],
                $aliases,
            ));
        }

        return $preacher;
    }

    /**
     * @param  list<array{local_id: int, classification: 'already_present'|'create'|'conflict', reason: string, existing_sermon_id: int|null}>  $entries
     * @return array{already_present: int, create: int, conflict: int}
     */
    private function counts(array $entries): array
    {
        $counts = ['already_present' => 0, 'create' => 0, 'conflict' => 0];

        foreach ($entries as $entry) {
            $counts[$entry['classification']]++;
        }

        return $counts;
    }

    /**
     * @return array{local_id: int, classification: 'conflict', reason: string, existing_sermon_id: null}
     */
    private function conflict(int $localId, string $reason): array
    {
        return [
            'local_id' => $localId,
            'classification' => 'conflict',
            'reason' => $reason,
            'existing_sermon_id' => null,
        ];
    }

    /**
     * @return array{classification: 'conflict', reason: string, existing_sermon_id: null}
     */
    private function classificationConflict(string $reason): array
    {
        return [
            'classification' => 'conflict',
            'reason' => $reason,
            'existing_sermon_id' => null,
        ];
    }

    /**
     * @param  array<int, array{bible_book: string, bible_chapter: int}>  $filters
     * @return list<array{bible_book: string, bible_chapter: int}>
     */
    private function normalizedFilters(array $filters): array
    {
        usort($filters, static fn (array $left, array $right): int => [
            $left['bible_book'],
            $left['bible_chapter'],
        ] <=> [
            $right['bible_book'],
            $right['bible_chapter'],
        ]);

        return $filters;
    }
}
