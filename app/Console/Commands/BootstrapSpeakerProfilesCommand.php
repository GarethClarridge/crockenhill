<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Contracts\SpeakerIdentificationInterface;
use App\Enums\SampleSource;
use App\Enums\SermonContentType;
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
                    // Created INACTIVE and only activated once a real centroid exists.
                    // A profile whose extractions all fail keeps this placeholder zero
                    // vector, and `cosineSimilarity` returns 0.0 for a zero-norm vector — so
                    // an active one is a profile that can never match, advertising a preacher
                    // as identifiable when they are not. Production carried 21 of these.
                    'centroid_embedding' => array_fill(0, 256, 0.0),
                    'sample_count' => 0,
                    'is_active' => false,
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

            // Activation is earned, not assumed: only now does the profile hold a centroid
            // computed from real audio rather than the zero-vector placeholder above.
            if (! $profile->is_active) {
                $profile->update(['is_active' => true]);
            }

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
        $candidates = Sermon::query()
            ->where('preacher_id', $preacherId)
            // `sermons` is polymorphic: a children's talk is a real Sermon row with its own
            // audio. A voice profile wants the cleanest available example of one person
            // talking, and a sermon is ~36 minutes of exactly that, where a children's talk
            // is 2-8 minutes of call and response with children answering. Only the first
            // `extraction_duration` seconds are embedded, so it is the purity of that window
            // that matters rather than the total length — and a children's talk's opening
            // minute is the part most likely to contain other voices.
            //
            // Nothing is contaminated today, but only because the three existing
            // children's-talk rows have no audio yet. `orderByDesc('date')` takes the newest
            // records, and newly published children's talks are the newest, so this would
            // have started silently degrading every profile the moment Phase 8 published one
            // with audio — across ~33% of 414 identities.
            ->where('content_type', SermonContentType::Sermon)
            ->whereNotNull('audio_file_path')
            ->where('audio_file_path', '!=', '')
            ->orderByDesc('date')
            ->get(['id', 'audio_file_path', 'date']);

        return $this->spreadAcrossHistory($candidates, $maxSermons);
    }

    /**
     * Take an evenly spaced sample across a preacher's whole recorded history.
     *
     * This used to be `orderByDesc('date')->limit($maxSermons)` — the newest N — which can
     * only ever describe a preacher as they sounded in one short window. Production's four
     * real profiles were all built that way and span four months; Mark Drury's covers six
     * weeks of late 2025. Measured consequence: his own 2013 preaching scores 0.764 against
     * his own profile, below the 0.75 accept threshold, while a stranger recorded in the
     * same era as the profile reaches 0.778. A centroid built from one window is closer to a
     * description of the recording chain than of the person.
     *
     * Spreading the sample does not make a Resemblyzer centroid channel-invariant — that is
     * an encoder property and was measured separately — but it stops the profile encoding
     * one month's microphone as if it were an identity.
     *
     * @param  Collection<int, Sermon>  $candidates  newest first
     * @return Collection<int, Sermon>
     */
    private function spreadAcrossHistory(Collection $candidates, int $maxSermons): Collection
    {
        $total = $candidates->count();

        if ($total <= $maxSermons) {
            return $candidates;
        }

        // Index by position, not by `only()`: on an Eloquent collection `only()` selects by
        // model primary key, so passing offsets to it silently returns nothing.
        $ordered = $candidates->values()->all();
        $step = $total / $maxSermons;
        $picked = [];

        foreach (range(0, $maxSermons - 1) as $slot) {
            $picked[] = $ordered[(int) floor($slot * $step)];
        }

        return new Collection($picked);
    }
}
