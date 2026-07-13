<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Contracts\SpeakerIdentificationInterface;
use App\Enums\SampleSource;
use App\Models\Preacher;
use App\Models\Sermon;
use App\Models\SpeakerProfile;
use App\Models\SpeakerSample;
use App\Services\Preacher\NullSpeakerIdentificationService;
use App\Services\Sermon\SermonStorageService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class BootstrapSpeakerProfilesCommand extends Command
{
    protected $signature = 'speaker-profiles:bootstrap
        {--dry-run : Preview which profiles/samples would be created}
        {--preacher= : Restrict to a specific preacher slug or id}
        {--min-sermons=5 : Minimum sermon count required to bootstrap a profile}
        {--max-sermons=10 : Maximum sermons to sample per preacher}
        {--model-version= : Override model version stored on created profiles}';

    protected $description = 'Bootstrap speaker profiles from historical sermon audio';

    public function handle(SpeakerIdentificationInterface $speakerService, SermonStorageService $storageService): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $minSermons = max(1, (int) $this->option('min-sermons'));
        $maxSermons = max($minSermons, (int) $this->option('max-sermons'));

        $provider = (string) config('media-processing.speaker_identification.provider', 'null');
        $modelVersion = (string) ($this->option('model-version')
            ?: config('media-processing.speaker_identification.model_version', 'v1.0'));

        if ($provider === '' || $provider === 'null') {
            if (! $dryRun) {
                $this->error('Speaker provider is not configured. Set SPEAKER_IDENTIFICATION_PROVIDER (e.g. resemblyzer).');

                return self::FAILURE;
            }

            $provider = 'resemblyzer';
            $this->warn('Provider not configured; using "resemblyzer" for dry-run reporting only.');
        }

        if ($modelVersion === '') {
            if (! $dryRun) {
                $this->error('Speaker model version is empty. Set SPEAKER_MODEL_VERSION or pass --model-version.');

                return self::FAILURE;
            }

            $modelVersion = 'v1.0';
            $this->warn('Model version not configured; using "v1.0" for dry-run reporting only.');
        }

        if (! $dryRun && $speakerService instanceof NullSpeakerIdentificationService) {
            $this->error('Active speaker service is NullSpeakerIdentificationService. Configure provider and clear config cache.');

            return self::FAILURE;
        }

        $preachers = $this->resolvePreachers((string) ($this->option('preacher') ?? ''));

        if ($preachers->isEmpty()) {
            $this->warn('No matching preachers found.');

            return self::SUCCESS;
        }

        $metrics = [
            'preachers_considered' => $preachers->count(),
            'preachers_skipped' => 0,
            'profiles_created' => 0,
            'profiles_updated' => 0,
            'samples_extracted' => 0,
            'samples_failed' => 0,
        ];

        if ($dryRun) {
            $this->info('DRY RUN — no database changes will be made.');
        }

        $this->line("Provider: {$provider}");
        $this->line("Model version: {$modelVersion}");
        $this->line("Min sermons: {$minSermons}");
        $this->line("Max sermons: {$maxSermons}");
        $this->newLine();

        foreach ($preachers as $preacher) {
            $sermons = $this->candidateSermons($preacher->id, $maxSermons);

            if ($sermons->count() < $minSermons) {
                $metrics['preachers_skipped']++;
                $this->line("Skipping {$preacher->name}: only {$sermons->count()} eligible sermons.");

                continue;
            }

            if ($dryRun) {
                $this->line("Would bootstrap {$preacher->name} with {$sermons->count()} sermon samples.");

                continue;
            }

            $profile = SpeakerProfile::query()->firstOrCreate(
                [
                    'preacher_id' => $preacher->id,
                    'provider' => $provider,
                    'model_version' => $modelVersion,
                ],
                [
                    'centroid_embedding' => array_fill(0, 256, 0.0),
                    'sample_count' => 0,
                    'is_active' => true,
                ]
            );

            if ($profile->wasRecentlyCreated) {
                $metrics['profiles_created']++;
            }

            $this->line("Bootstrapping {$preacher->name} ({$sermons->count()} sermons)...");

            foreach ($sermons as $sermon) {
                $fileInfo = $storageService->getSermonFileInfo($sermon);
                $result = $speakerService->extractEmbedding($fileInfo['path'], $fileInfo['disk']);

                if (! $result->success || ! is_array($result->embedding)) {
                    $metrics['samples_failed']++;
                    $reason = $result->errorMessage ?? 'Unknown extraction error';
                    $this->warn("  Failed sermon #{$sermon->id}: {$reason}");

                    continue;
                }

                SpeakerSample::query()->updateOrCreate(
                    [
                        'speaker_profile_id' => $profile->id,
                        'sermon_id' => $sermon->id,
                        'source' => SampleSource::Backfill->value,
                    ],
                    [
                        'media_processing_log_id' => null,
                        'embedding' => $result->embedding,
                        'duration_seconds' => $result->durationUsed ?? 0.0,
                        'quality_score' => null,
                        'approved' => true,
                    ]
                );

                $metrics['samples_extracted']++;
            }

            $approvedEmbeddings = SpeakerSample::query()
                ->where('speaker_profile_id', $profile->id)
                ->where('approved', true)
                ->pluck('embedding')
                ->map(function ($embedding): array {
                    if (! is_array($embedding)) {
                        return [];
                    }

                    return array_values(array_map('floatval', $embedding));
                })
                ->filter(fn (array $embedding) => $embedding !== [])
                ->values()
                ->all();
            $approvedEmbeddings = array_values($approvedEmbeddings);

            if ($approvedEmbeddings === []) {
                $this->warn("  No approved embeddings available to update profile for {$preacher->name}.");

                continue;
            }

            $speakerService->updateProfile($profile, $approvedEmbeddings);
            $metrics['profiles_updated']++;
        }

        $this->newLine();
        $this->info('Summary');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Preachers considered', $metrics['preachers_considered']],
                ['Preachers skipped (below min sermons)', $metrics['preachers_skipped']],
                ['Profiles created', $dryRun ? '(dry run)' : $metrics['profiles_created']],
                ['Profiles updated', $dryRun ? '(dry run)' : $metrics['profiles_updated']],
                ['Samples extracted', $dryRun ? '(dry run)' : $metrics['samples_extracted']],
                ['Samples failed', $dryRun ? '(dry run)' : $metrics['samples_failed']],
            ]
        );

        if ($metrics['preachers_considered'] > 0
            && $metrics['preachers_skipped'] === $metrics['preachers_considered']) {
            $this->newLine();
            $this->warn("All preachers were below the minimum sermon threshold ({$minSermons}).");
            $this->line('Tip: rerun with a lower threshold, e.g. --min-sermons=1 for local seed data.');
        }

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, Preacher>
     */
    private function resolvePreachers(string $preacherFilter): Collection
    {
        $query = Preacher::query()->orderBy('name');

        if ($preacherFilter !== '') {
            if (ctype_digit($preacherFilter)) {
                $query->whereKey((int) $preacherFilter);
            } else {
                $query->where('slug', $preacherFilter);
            }
        }

        return $query->get();
    }

    /**
     * @return Collection<int, Sermon>
     */
    private function candidateSermons(int $preacherId, int $maxSermons): Collection
    {
        return Sermon::query()
            ->where('preacher_id', $preacherId)
            ->whereNotNull('audio_file_path')
            ->where('audio_file_path', '!=', '')
            ->orderByDesc('date')
            ->limit($maxSermons)
            ->get(['id', 'audio_file_path', 'date']);
    }
}
