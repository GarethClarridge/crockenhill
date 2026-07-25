<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\SpeakerProfile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

/**
 * Export speaker profiles as a portable bundle for transfer between environments.
 *
 * Only the profile centroids are exported. Identification reads nothing else from a
 * profile, so `speaker_samples` — which carries a `sermon_id` foreign key that does not
 * survive a move between environments — is deliberately left behind. Preachers travel by
 * name and slug so no local primary key crosses the boundary.
 *
 * The resulting file contains voice fingerprints; treat it as private.
 */
class ExportSpeakerProfilesCommand extends Command
{
    private const FORMAT = 'crockenhill.speaker-profiles';

    /**
     * Bump when the bundle shape changes so the importer can reject unknown formats.
     */
    private const FORMAT_VERSION = 1;

    protected $signature = 'speaker-profiles:export
                            {--output= : Path to write the JSON bundle to}
                            {--include-inactive : Include profiles where is_active is false}
                            {--provider= : Restrict to a single provider (e.g. resemblyzer)}';

    protected $description = 'Export speaker profile centroids as a portable JSON bundle';

    public function handle(): int
    {
        try {
            $output = $this->stringOption('output');

            if ($output === null) {
                throw new RuntimeException('The --output option is required.');
            }

            $profiles = $this->profiles();

            if ($profiles === []) {
                throw new RuntimeException('No matching speaker profiles found; nothing was written.');
            }

            $this->writeBundle($output, $profiles);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Speaker profiles exported.');
        $this->line('Profiles: '.count($profiles));
        $this->line("Path: {$output}");
        $this->newLine();

        $this->table(
            ['Preacher', 'Provider', 'Version', 'Dims', 'Samples', 'Sample dates'],
            array_map(
                fn (array $profile): array => [
                    $profile['preacher_name'],
                    $profile['provider'],
                    $profile['model_version'],
                    count($profile['centroid_embedding']),
                    $profile['sample_count'],
                    $this->formatDateRange($profile['sample_date_range']),
                ],
                $profiles
            )
        );

        return self::SUCCESS;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function profiles(): array
    {
        $query = SpeakerProfile::query()->with('preacher')->orderBy('id');

        if (! $this->option('include-inactive')) {
            $query->active();
        }

        $provider = $this->stringOption('provider');

        if ($provider !== null) {
            $query->where('provider', $provider);
        }

        $profiles = [];

        foreach ($query->get() as $profile) {
            $preacher = $profile->preacher;

            if ($preacher === null) {
                $this->warn("Skipping profile #{$profile->id}: no preacher record.");

                continue;
            }

            $profiles[] = [
                'preacher_name' => $preacher->name,
                'preacher_slug' => $preacher->slug,
                'provider' => $profile->provider,
                'model_version' => $profile->model_version,
                'centroid_embedding' => array_values(array_map('floatval', $profile->centroid_embedding)),
                'sample_count' => $profile->sample_count,
                'quality_score' => $profile->quality_score,
                'accept_threshold' => $profile->accept_threshold,
                'margin_threshold' => $profile->margin_threshold,
                'is_active' => $profile->is_active,
                'sample_date_range' => $this->sampleDateRange($profile),
            ];
        }

        return $profiles;
    }

    /**
     * Report the span of sermon dates a profile was trained on.
     *
     * A centroid is only as good as the era it was built from, so this travels with the
     * bundle to make a stale profile obvious on the receiving side.
     *
     * @return array{first:?string, last:?string, count:int}
     */
    private function sampleDateRange(SpeakerProfile $profile): array
    {
        $range = DB::table('speaker_samples')
            ->join('sermons', 'sermons.id', '=', 'speaker_samples.sermon_id')
            ->where('speaker_samples.speaker_profile_id', $profile->id)
            ->selectRaw('MIN(sermons.date) AS first_date, MAX(sermons.date) AS last_date, COUNT(*) AS row_count')
            ->first();

        return [
            'first' => isset($range->first_date) ? (string) $range->first_date : null,
            'last' => isset($range->last_date) ? (string) $range->last_date : null,
            'count' => (int) ($range->row_count ?? 0),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $profiles
     */
    private function writeBundle(string $output, array $profiles): void
    {
        $directory = dirname($output);

        if (! File::isDirectory($directory)) {
            File::makeDirectory($directory, 0755, true);
        }

        $bundle = [
            'format' => self::FORMAT,
            'version' => self::FORMAT_VERSION,
            'exported_at' => now()->toIso8601String(),
            'profiles' => $profiles,
        ];

        $json = json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        if (File::put($output, $json) === false) {
            throw new RuntimeException("Unable to write bundle to {$output}.");
        }

        if (! chmod($output, 0600)) {
            throw new RuntimeException("Unable to restrict speaker profile bundle permissions: {$output}");
        }
    }

    /**
     * @param  array{first:?string, last:?string, count:int}  $range
     */
    private function formatDateRange(array $range): string
    {
        if ($range['first'] === null || $range['last'] === null) {
            return '(no samples)';
        }

        return substr($range['first'], 0, 7).' .. '.substr($range['last'], 0, 7);
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }
}
