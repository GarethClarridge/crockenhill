<?php

declare(strict_types=1);

namespace App\Services\Preacher;

use App\Enums\PreacherSource;
use App\Models\Preacher;
use App\Models\PreacherAlias;
use App\Models\Sermon;
use Illuminate\Support\Str;

/**
 * @phpstan-type PreacherCutoverSummary array{
 *     distinct_names:int,
 *     preachers_created:int,
 *     aliases_created:int,
 *     sermons_linked:int,
 *     sermons_defaulted:int
 * }
 */
class PreacherCutoverService
{
    public function __construct(
        private readonly PreacherResolutionService $preacherResolutionService,
    ) {}

    /**
     * @param  array<string, string>  $aliasMap
     * @return array{
     *     dry_run: bool,
     *     summary: PreacherCutoverSummary
     * }
     */
    public function run(bool $dryRun = false, array $aliasMap = []): array
    {
        $normalizedAliasMap = $this->normalizeAliasMap($aliasMap);
        $preacherCache = [];

        $rawNames = Sermon::query()
            ->whereNotNull('preacher')
            ->pluck('preacher')
            ->filter(fn (mixed $name): bool => $this->normalizeName((string) $name) !== '')
            ->unique()
            ->values();

        $summary = [
            'distinct_names' => $rawNames->count(),
            'preachers_created' => 0,
            'aliases_created' => 0,
            'sermons_linked' => 0,
            'sermons_defaulted' => 0,
        ];

        foreach ($rawNames as $rawName) {
            $canonicalName = $this->canonicalNameForRawName($rawName, $normalizedAliasMap);

            if ($dryRun) {
                $summary['preachers_created'] += Preacher::query()->where('name', $canonicalName)->exists() ? 0 : 1;
                $summary['aliases_created'] += PreacherAlias::query()->where('alias', $this->normalizeName($rawName))->exists() ? 0 : 1;

                continue;
            }

            $preacherExists = Preacher::query()->where('name', $canonicalName)->exists();
            $preacher = $this->resolveCachedPreacher($canonicalName, $preacherCache);

            if (! $preacherExists) {
                $summary['preachers_created']++;
            }

            $rawAlias = $this->normalizeName($rawName);
            $alias = PreacherAlias::withoutEvents(fn () => PreacherAlias::query()->firstOrCreate(
                ['alias' => $rawAlias],
                ['preacher_id' => $preacher->id],
            ));

            if ($alias->wasRecentlyCreated) {
                $summary['aliases_created']++;
            }
        }

        $visitingSpeaker = $dryRun ? null : $this->resolveCachedPreacher('', $preacherCache);

        foreach (Sermon::query()->whereNull('preacher_id')->get() as $sermon) {
            $rawPreacher = $this->preacherResolutionService->normalizeWhitespace((string) $sermon->preacher);

            if ($rawPreacher === '') {
                $summary['sermons_linked']++;
                $summary['sermons_defaulted']++;

                if (! $dryRun) {
                    $sermon->update([
                        'preacher' => 'Visiting Speaker',
                        'preacher_id' => $visitingSpeaker?->id,
                        'preacher_source' => PreacherSource::Default->value,
                        'needs_preacher_review' => true,
                    ]);
                }

                continue;
            }

            $canonicalName = $this->canonicalNameForRawName($rawPreacher, $normalizedAliasMap);

            if (! $dryRun) {
                $preacher = $this->resolveCachedPreacher($canonicalName, $preacherCache);

                if ($this->normalizeName($rawPreacher) !== $this->normalizeName($canonicalName)) {
                    PreacherAlias::withoutEvents(fn () => PreacherAlias::query()->firstOrCreate(
                        ['alias' => $this->normalizeName($rawPreacher)],
                        ['preacher_id' => $preacher->id],
                    ));
                }

                $sermon->update([
                    'preacher_id' => $preacher->id,
                    'preacher_source' => PreacherSource::Manual->value,
                    'needs_preacher_review' => false,
                ]);
            }

            $summary['sermons_linked']++;
        }

        return [
            'dry_run' => $dryRun,
            'summary' => $summary,
        ];
    }

    /**
     * @param  array<string, string>  $aliasMap
     * @return array<string, string>
     */
    private function normalizeAliasMap(array $aliasMap): array
    {
        $normalized = [];

        foreach ($aliasMap as $variant => $canonical) {
            $key = $this->normalizeName((string) $variant);
            $canonicalName = $this->preacherResolutionService->normalizeWhitespace((string) $canonical);

            if ($key === '' || $canonicalName === '') {
                continue;
            }

            $normalized[$key] = $canonicalName;
        }

        return $normalized;
    }

    /**
     * @param  array<string, string>  $aliasMap
     */
    private function canonicalNameForRawName(string $rawName, array $aliasMap): string
    {
        $normalized = $this->normalizeName($rawName);

        return $aliasMap[$normalized] ?? Str::title($this->preacherResolutionService->normalizeWhitespace($rawName));
    }

    private function normalizeName(string $name): string
    {
        return $this->preacherResolutionService->normalizeAlias($name);
    }

    /**
     * @param  array<string, Preacher>  $preacherCache
     */
    private function resolveCachedPreacher(string $canonicalName, array &$preacherCache): Preacher
    {
        $cacheKey = $canonicalName === '' ? 'visiting-speaker' : $canonicalName;

        if (! isset($preacherCache[$cacheKey])) {
            $preacherCache[$cacheKey] = $this->preacherResolutionService->resolve($canonicalName);
        }

        return $preacherCache[$cacheKey];
    }
}
