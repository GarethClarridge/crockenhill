<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AudioProfile;
use App\Models\MediaProcessingLog;
use App\Services\Media\Audio\AudioTreatmentSettings;
use Illuminate\Console\Command;

/**
 * Set one recording's own audio treatment (§6.3.3): hum removal for a recording
 * that hums, de-essing for a sibilant speaker, and so on.
 *
 * Writes `processing_metadata.audio_treatment_overrides.{profile}`, which the next
 * cut of that run uses. Candidates and the stored sermon record the settings they
 * were cut with, so changing them makes the run's candidates re-cut on their next
 * preparation; nothing is re-extracted here.
 */
class SetAudioTreatmentCommand extends Command
{
    protected $signature = 'media:audio-treatment
                            {run : The processing run id}
                            {profile : speech or music}
                            {--set=* : key=value, e.g. --set=hum_hz=50 --set="de_ess=i=0.5:m=0.5:f=0.5"}
                            {--unset=* : Keys to return to the profile default}
                            {--clear : Remove every override for this profile}';

    protected $description = "Set or clear one recording's audio treatment overrides";

    public function handle(): int
    {
        $run = MediaProcessingLog::find((int) $this->argument('run'));
        $profile = AudioProfile::tryFrom((string) $this->argument('profile'));

        if (! $run instanceof MediaProcessingLog || $profile === null) {
            $this->error('Unknown run, or profile is not one of: '.implode(', ', AudioProfile::values()));

            return self::FAILURE;
        }

        $known = array_keys((array) config('media-processing.audio_treatment.'.$profile->value, []));
        $changes = [];

        foreach ((array) $this->option('set') as $pair) {
            [$key, $value] = array_pad(explode('=', (string) $pair, 2), 2, null);
            if (! in_array($key, $known, true) || $value === null || $value === '') {
                $this->error("Not a {$profile->value} setting: {$pair}. Settings: ".implode(', ', $known));

                return self::FAILURE;
            }
            $changes[$key] = is_numeric($value) ? (float) $value : $value;
        }

        $unset = array_map('strval', (array) $this->option('unset'));
        $clear = (bool) $this->option('clear');

        $run->writeProcessingMetadata(function (array $metadata) use ($profile, $changes, $unset, $clear): array {
            $current = $clear ? [] : (array) ($metadata['audio_treatment_overrides'][$profile->value] ?? []);
            $next = array_diff_key(array_replace($current, $changes), array_flip($unset));

            if ($next === []) {
                unset($metadata['audio_treatment_overrides'][$profile->value]);
            } else {
                $metadata['audio_treatment_overrides'][$profile->value] = $next;
            }

            if (($metadata['audio_treatment_overrides'] ?? null) === []) {
                unset($metadata['audio_treatment_overrides']);
            }

            return $metadata;
        });

        $effective = AudioTreatmentSettings::for($profile, AudioTreatmentSettings::overridesFor($run->refresh(), $profile));
        $this->info("Run {$run->id} {$profile->value} treatment now:");
        $this->table(['Setting', 'Value'], collect($effective->toArray())->except('profile')->map(
            static fn (mixed $value, string $key): array => [$key, match (true) {
                $value === null, $value === [] => 'off',
                is_array($value) => implode('; ', array_map(static fn (array $rule): string => "pauses above {$rule['above']}: {$rule['denoise']}", $value)),
                default => (string) $value,
            }],
        )->values()->all());

        return self::SUCCESS;
    }
}
