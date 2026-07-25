<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Preacher;
use App\Models\SpeakerProfile;
use App\Services\Preacher\PreacherResolutionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;
use RuntimeException;
use Throwable;

/**
 * Import a speaker profile bundle produced by `speaker-profiles:export`.
 *
 * Preachers are matched by slug first, then resolved by name through
 * {@see PreacherResolutionService} so aliases and casing variants land on the canonical
 * record. Profiles upsert on (preacher, provider, model_version), making the command safe
 * to re-run.
 */
class ImportSpeakerProfilesCommand extends Command
{
    private const FORMAT = 'crockenhill.speaker-profiles';

    private const SUPPORTED_VERSIONS = [1];

    protected $signature = 'speaker-profiles:import
                            {file : Path to the JSON bundle produced by speaker-profiles:export}
                            {--dry-run : Report what would change without writing}
                            {--deactivate-existing : Deactivate all current profiles before importing}';

    protected $description = 'Import speaker profile centroids from a portable JSON bundle';

    public function handle(PreacherResolutionService $preacherResolution): int
    {
        $dryRun = (bool) $this->option('dry-run');

        try {
            $profiles = $this->readBundle();
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($dryRun) {
            $this->info('DRY RUN — no database changes will be made.');
        }

        $created = 0;
        $updated = 0;
        $deactivated = 0;
        $rows = [];

        try {
            DB::transaction(function () use (
                $profiles,
                $preacherResolution,
                $dryRun,
                &$created,
                &$updated,
                &$deactivated,
                &$rows
            ): void {
                if ($this->option('deactivate-existing')) {
                    $deactivated = SpeakerProfile::query()->where('is_active', true)->count();

                    if (! $dryRun) {
                        SpeakerProfile::query()->where('is_active', true)->update(['is_active' => false]);
                    }
                }

                foreach ($profiles as $profile) {
                    $preacher = $this->resolvePreacher($profile, $preacherResolution, $dryRun);

                    $existing = $preacher === null ? null : SpeakerProfile::query()
                        ->where('preacher_id', $preacher->id)
                        ->where('provider', $profile['provider'])
                        ->where('model_version', $profile['model_version'])
                        ->first();

                    $action = $existing === null ? 'create' : 'update';

                    if (! $dryRun && $preacher !== null) {
                        SpeakerProfile::query()->updateOrCreate(
                            [
                                'preacher_id' => $preacher->id,
                                'provider' => $profile['provider'],
                                'model_version' => $profile['model_version'],
                            ],
                            [
                                'centroid_embedding' => $profile['centroid_embedding'],
                                'sample_count' => $profile['sample_count'],
                                'quality_score' => $profile['quality_score'],
                                'accept_threshold' => $profile['accept_threshold'],
                                'margin_threshold' => $profile['margin_threshold'],
                                'is_active' => $profile['is_active'],
                            ]
                        );
                    }

                    if ($action === 'create') {
                        $created++;
                    } else {
                        $updated++;
                    }

                    $rows[] = [
                        $profile['preacher_name'],
                        $profile['provider'].' '.$profile['model_version'],
                        count($profile['centroid_embedding']),
                        $this->formatDateRange($profile['sample_date_range']),
                        $action,
                    ];
                }
            });
        } catch (Throwable $exception) {
            $this->error('Import failed and was rolled back: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->table(['Preacher', 'Provider', 'Dims', 'Sample dates', 'Action'], $rows);

        if ($deactivated > 0) {
            $this->line("Existing profiles deactivated: {$deactivated}");
        }

        $this->info(sprintf(
            '%s %d profile(s): %d created, %d updated.',
            $dryRun ? 'Would import' : 'Imported',
            count($profiles),
            $created,
            $updated
        ));

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    private function resolvePreacher(
        array $profile,
        PreacherResolutionService $preacherResolution,
        bool $dryRun
    ): ?Preacher {
        $slug = is_string($profile['preacher_slug'] ?? null) ? $profile['preacher_slug'] : null;

        if ($slug !== null) {
            $bySlug = Preacher::query()->where('slug', $slug)->first();

            if ($bySlug !== null) {
                return $bySlug;
            }
        }

        // A dry run must not create preacher records as a side effect of reporting.
        if ($dryRun) {
            return Preacher::query()->where('name', $profile['preacher_name'])->first();
        }

        return $preacherResolution->resolve((string) $profile['preacher_name']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function readBundle(): array
    {
        $file = (string) $this->argument('file');

        if (! File::exists($file)) {
            throw new RuntimeException("Bundle not found: {$file}");
        }

        $decoded = json_decode(File::get($file), true);

        if (! is_array($decoded)) {
            throw new RuntimeException('Bundle is not valid JSON.');
        }

        if (($decoded['format'] ?? null) !== self::FORMAT) {
            throw new RuntimeException('Bundle format is not '.self::FORMAT.'.');
        }

        if (! in_array($decoded['version'] ?? null, self::SUPPORTED_VERSIONS, true)) {
            throw new RuntimeException('Unsupported bundle version.');
        }

        $validator = Validator::make($decoded, [
            'profiles' => ['required', 'array', 'min:1'],
            'profiles.*.preacher_name' => ['required', 'string', 'max:255'],
            'profiles.*.preacher_slug' => ['nullable', 'string', 'max:255'],
            'profiles.*.provider' => ['required', 'string', 'max:50'],
            'profiles.*.model_version' => ['required', 'string', 'max:50'],
            'profiles.*.centroid_embedding' => ['required', 'array', 'min:1'],
            'profiles.*.centroid_embedding.*' => ['required', 'numeric'],
            'profiles.*.sample_count' => ['nullable', 'integer', 'min:0'],
            'profiles.*.quality_score' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'profiles.*.accept_threshold' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'profiles.*.margin_threshold' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'profiles.*.is_active' => ['nullable', 'boolean'],
        ]);

        if ($validator->fails()) {
            throw new RuntimeException('Bundle is invalid: '.$validator->errors()->first());
        }

        $profiles = [];

        foreach ($decoded['profiles'] as $profile) {
            $embedding = array_values(array_map('floatval', $profile['centroid_embedding']));

            if ($this->isZeroVector($embedding)) {
                $this->warn("Skipping {$profile['preacher_name']}: centroid is a zero vector (never trained).");

                continue;
            }

            $profiles[] = [
                'preacher_name' => $profile['preacher_name'],
                'preacher_slug' => $profile['preacher_slug'] ?? null,
                'provider' => $profile['provider'],
                'model_version' => $profile['model_version'],
                'centroid_embedding' => $embedding,
                'sample_count' => (int) ($profile['sample_count'] ?? 0),
                'quality_score' => $this->nullableFloat($profile['quality_score'] ?? null),
                'accept_threshold' => $this->nullableFloat($profile['accept_threshold'] ?? null),
                'margin_threshold' => $this->nullableFloat($profile['margin_threshold'] ?? null),
                'is_active' => (bool) ($profile['is_active'] ?? true),
                'sample_date_range' => is_array($profile['sample_date_range'] ?? null)
                    ? $profile['sample_date_range']
                    : ['first' => null, 'last' => null, 'count' => 0],
            ];
        }

        if ($profiles === []) {
            throw new RuntimeException('Bundle contained no usable profiles.');
        }

        return $profiles;
    }

    /**
     * A zero-filled centroid is the placeholder written before training; importing one
     * would create a profile that scores 0.0 against everything.
     *
     * @param  list<float>  $embedding
     */
    private function isZeroVector(array $embedding): bool
    {
        foreach ($embedding as $value) {
            if (abs($value) > 1e-12) {
                return false;
            }
        }

        return true;
    }

    private function nullableFloat(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * @param  array<string, mixed>  $range
     */
    private function formatDateRange(array $range): string
    {
        $first = $range['first'] ?? null;
        $last = $range['last'] ?? null;

        if (! is_string($first) || ! is_string($last)) {
            return '(unknown)';
        }

        return substr($first, 0, 7).' .. '.substr($last, 0, 7);
    }
}
