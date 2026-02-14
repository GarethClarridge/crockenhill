<?php

namespace App\Console\Commands;

use App\Enums\PreacherSource;
use App\Models\Preacher;
use App\Models\PreacherAlias;
use App\Models\Sermon;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class PreacherCutoverCommand extends Command
{
    protected $signature = 'preachers:cutover
        {--dry-run : Preview changes without executing}
        {--alias-file= : Path to optional JSON alias mapping file}';

    protected $description = 'Backfill canonical Preacher records from legacy sermon preacher strings';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->info('DRY RUN — no changes will be made.');
        }

        // Load optional alias mapping
        $aliasMap = $this->loadAliasMap();

        // Step 1: Read distinct preacher strings
        $rawNames = Sermon::whereNotNull('preacher')
            ->where('preacher', '!=', '')
            ->distinct()
            ->pluck('preacher');

        $this->info("Found {$rawNames->count()} distinct preacher strings.");

        // Step 2: Normalize and group variants
        $canonicalMap = $this->buildCanonicalMap($rawNames, $aliasMap);

        $this->info('Canonical preachers to create/verify: '.count($canonicalMap));

        // Step 3: Ensure "Visiting speaker" exists
        $canonicalMap['visiting speaker'] = $canonicalMap['visiting speaker'] ?? 'Visiting Speaker';

        $preachersCreated = 0;
        $aliasesCreated = 0;
        $sermonsLinked = 0;
        $sermonsDefaulted = 0;

        // Step 4: Create Preacher and alias records
        foreach ($canonicalMap as $normalizedKey => $canonicalName) {
            if ($dryRun) {
                $this->line("  Would create/verify preacher: {$canonicalName} (key: {$normalizedKey})");

                continue;
            }

            $preacher = Preacher::firstOrCreate(
                ['slug' => Str::slug($canonicalName)],
                ['name' => $canonicalName, 'is_active' => true]
            );

            if ($preacher->wasRecentlyCreated) {
                $preachersCreated++;
            }

            // Create alias for the normalized key
            $alias = PreacherAlias::firstOrCreate(
                ['alias' => $normalizedKey],
                ['preacher_id' => $preacher->id]
            );

            if ($alias->wasRecentlyCreated) {
                $aliasesCreated++;
            }
        }

        // Step 5: Also create aliases for all raw variants
        if (! $dryRun) {
            foreach ($rawNames as $rawName) {
                $normalizedKey = $this->normalizeName($rawName);
                $canonicalName = $canonicalMap[$normalizedKey] ?? null;

                if (! $canonicalName) {
                    continue;
                }

                $preacher = Preacher::where('slug', Str::slug($canonicalName))->first();

                if (! $preacher) {
                    continue;
                }

                $rawAlias = strtolower(trim($rawName));
                if ($rawAlias !== $normalizedKey) {
                    PreacherAlias::firstOrCreate(
                        ['alias' => $rawAlias],
                        ['preacher_id' => $preacher->id]
                    );
                }
            }
        }

        // Step 6: Link sermons to preacher records
        $visitingPreacher = $dryRun ? null : Preacher::where('slug', 'visiting-speaker')->first();

        $sermons = Sermon::whereNull('preacher_id')->get();
        $this->info("Sermons to link: {$sermons->count()}");

        foreach ($sermons as $sermon) {
            $preacherString = trim($sermon->preacher ?? '');

            if ($dryRun) {
                if (empty($preacherString)) {
                    $this->line("  Would default sermon #{$sermon->id} to Visiting Speaker");
                    $sermonsDefaulted++;
                } else {
                    $this->line("  Would link sermon #{$sermon->id} ({$preacherString})");
                }
                $sermonsLinked++;

                continue;
            }

            if (empty($preacherString)) {
                // Default to Visiting Speaker
                $sermon->update([
                    'preacher' => 'Visiting Speaker',
                    'preacher_id' => $visitingPreacher?->id,
                    'preacher_source' => PreacherSource::DEFAULT->value,
                    'needs_preacher_review' => true,
                ]);
                $sermonsDefaulted++;
                $sermonsLinked++;

                continue;
            }

            // Look up via alias
            $alias = PreacherAlias::where('alias', strtolower($preacherString))->first();
            $preacher = $alias?->preacher;

            if (! $preacher) {
                // Try by slug
                $preacher = Preacher::where('slug', Str::slug($preacherString))->first();
            }

            if ($preacher) {
                $sermon->update([
                    'preacher_id' => $preacher->id,
                    'preacher_source' => PreacherSource::MANUAL->value,
                    'needs_preacher_review' => false,
                ]);
                $sermonsLinked++;
            } else {
                // Unknown — default
                $sermon->update([
                    'preacher' => 'Visiting Speaker',
                    'preacher_id' => $visitingPreacher?->id,
                    'preacher_source' => PreacherSource::DEFAULT->value,
                    'needs_preacher_review' => true,
                ]);
                $sermonsDefaulted++;
                $sermonsLinked++;
            }
        }

        // Summary
        $this->newLine();
        $this->info('Summary:');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Preachers created', $dryRun ? count($canonicalMap) : $preachersCreated],
                ['Aliases created', $dryRun ? '(dry run)' : $aliasesCreated],
                ['Sermons linked', $sermonsLinked],
                ['Sermons defaulted to Visiting Speaker', $sermonsDefaulted],
            ]
        );

        return self::SUCCESS;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, string>  $rawNames
     * @param  array<string, string>  $aliasMap
     * @return array<string, string>
     */
    private function buildCanonicalMap($rawNames, array $aliasMap): array
    {
        $canonicalMap = [];

        foreach ($rawNames as $rawName) {
            $normalized = $this->normalizeName($rawName);

            // Check alias map first
            if (isset($aliasMap[$normalized])) {
                $canonicalMap[$normalized] = $aliasMap[$normalized];

                continue;
            }

            if (isset($aliasMap[strtolower(trim($rawName))])) {
                $canonicalMap[$normalized] = $aliasMap[strtolower(trim($rawName))];

                continue;
            }

            // Use the normalized form as canonical
            if (! isset($canonicalMap[$normalized])) {
                $canonicalMap[$normalized] = Str::title(trim($rawName));
            }
        }

        return $canonicalMap;
    }

    private function normalizeName(string $name): string
    {
        $name = trim($name);
        $name = preg_replace('/\s+/', ' ', $name) ?? $name;

        return strtolower($name);
    }

    /**
     * @return array<string, string>
     */
    private function loadAliasMap(): array
    {
        $aliasFile = $this->option('alias-file');

        if (! $aliasFile || ! file_exists($aliasFile)) {
            return [];
        }

        $contents = file_get_contents($aliasFile);

        if ($contents === false) {
            $this->warn("Could not read alias file: {$aliasFile}");

            return [];
        }

        $decoded = json_decode($contents, true);

        if (! is_array($decoded)) {
            $this->warn("Invalid JSON in alias file: {$aliasFile}");

            return [];
        }

        // Normalize keys to lowercase
        $map = [];
        foreach ($decoded as $variant => $canonical) {
            $map[strtolower(trim($variant))] = $canonical;
        }

        return $map;
    }
}
