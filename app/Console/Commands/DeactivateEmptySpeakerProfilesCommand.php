<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\SpeakerProfile;
use Illuminate\Console\Command;

/**
 * Deactivate speaker profiles whose centroid cannot match anything.
 *
 * `BootstrapSpeakerProfilesCommand` used to create a profile active, seeded with a
 * zero-vector placeholder, before attempting any extraction. When every extraction for a
 * preacher failed the row survived in that state: `is_active = true`, 256 zeros.
 *
 * Such a profile is inert rather than harmful — `ResemblyzerSpeakerIdentificationService`
 * returns 0.0 for a zero-norm vector, so it sorts below every real candidate and never
 * displaces one or distorts the margin. What it does do is lie: the preacher is advertised
 * as identifiable and can never be identified, and any count of "active profiles" is wrong.
 * Production carried 21 of these against 4 real ones.
 *
 * The bootstrap command now creates profiles inactive and activates them only once a real
 * centroid exists, so this command cleans up rows created before that fix.
 */
class DeactivateEmptySpeakerProfilesCommand extends Command
{
    protected $signature = 'speaker-profiles:deactivate-empty
                            {--apply : Apply changes (the default is a dry run)}';

    protected $description = 'Deactivate active speaker profiles whose centroid is all zeros';

    public function handle(): int
    {
        $dryRun = ! (bool) $this->option('apply');

        if ($dryRun) {
            $this->info('DRY RUN — no database changes will be made.');
        }

        $empty = SpeakerProfile::query()
            ->where('is_active', true)
            ->with('preacher')
            ->get()
            ->filter(fn (SpeakerProfile $profile): bool => $this->centroidIsEmpty($profile));

        if ($empty->isEmpty()) {
            $this->info('No active profiles have an empty centroid.');

            return self::SUCCESS;
        }

        $this->table(
            ['Profile', 'Preacher', 'Provider', 'Samples'],
            $empty->map(fn (SpeakerProfile $profile): array => [
                $profile->id,
                $profile->preacher->name ?? '(unknown)',
                $profile->provider.' '.$profile->model_version,
                $profile->sample_count,
            ])->all()
        );

        if (! $dryRun) {
            SpeakerProfile::query()->whereIn('id', $empty->pluck('id'))->update(['is_active' => false]);
        }

        $this->info(sprintf(
            '%s %d profile(s).',
            $dryRun ? 'Would deactivate' : 'Deactivated',
            $empty->count()
        ));

        return self::SUCCESS;
    }

    /**
     * Whether the stored centroid carries no signal at all.
     *
     * An empty vector counts alongside an all-zero one for the same reason: cosine
     * similarity against a zero-norm vector is 0.0, so the profile can never clear the
     * accept threshold and is active in name only.
     */
    private function centroidIsEmpty(SpeakerProfile $profile): bool
    {
        $centroid = $profile->centroid_embedding;

        if ($centroid === []) {
            return true;
        }

        // Cast before comparing: the column is a JSON array and whole numbers decode as
        // int `0`, not float `0.0`, so a strict comparison against 0.0 never matches and
        // every zero vector would read as populated. The `float` PHPDoc describes the
        // intent, not what json_decode returns.
        foreach ($centroid as $value) {
            if ((float) $value !== 0.0) {
                return false;
            }
        }

        return true;
    }
}
