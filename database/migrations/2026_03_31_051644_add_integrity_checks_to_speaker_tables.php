<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const PROFILE_QUALITY_CHECK = 'speaker_profiles_quality_score_check';

    private const PROFILE_ACCEPT_CHECK = 'speaker_profiles_accept_threshold_check';

    private const PROFILE_MARGIN_CHECK = 'speaker_profiles_margin_threshold_check';

    private const SAMPLE_QUALITY_CHECK = 'speaker_samples_quality_score_check';

    private const SAMPLE_DURATION_CHECK = 'speaker_samples_duration_check';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Add CHECK constraints (MySQL only as they are enforced at DB level)
        if (DB::getDriverName() === 'mysql') {
            DB::statement(sprintf(
                'ALTER TABLE speaker_profiles ADD CONSTRAINT %s CHECK (quality_score >= 0 AND quality_score <= 1)',
                self::PROFILE_QUALITY_CHECK
            ));
            DB::statement(sprintf(
                'ALTER TABLE speaker_profiles ADD CONSTRAINT %s CHECK (accept_threshold >= 0 AND accept_threshold <= 1)',
                self::PROFILE_ACCEPT_CHECK
            ));
            DB::statement(sprintf(
                'ALTER TABLE speaker_profiles ADD CONSTRAINT %s CHECK (margin_threshold >= 0 AND margin_threshold <= 1)',
                self::PROFILE_MARGIN_CHECK
            ));

            DB::statement(sprintf(
                'ALTER TABLE speaker_samples ADD CONSTRAINT %s CHECK (quality_score >= 0 AND quality_score <= 1)',
                self::SAMPLE_QUALITY_CHECK
            ));
            DB::statement(sprintf(
                'ALTER TABLE speaker_samples ADD CONSTRAINT %s CHECK (duration_seconds >= 0)',
                self::SAMPLE_DURATION_CHECK
            ));
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement(sprintf('ALTER TABLE speaker_profiles DROP CHECK %s', self::PROFILE_QUALITY_CHECK));
            DB::statement(sprintf('ALTER TABLE speaker_profiles DROP CHECK %s', self::PROFILE_ACCEPT_CHECK));
            DB::statement(sprintf('ALTER TABLE speaker_profiles DROP CHECK %s', self::PROFILE_MARGIN_CHECK));
            DB::statement(sprintf('ALTER TABLE speaker_samples DROP CHECK %s', self::SAMPLE_QUALITY_CHECK));
            DB::statement(sprintf('ALTER TABLE speaker_samples DROP CHECK %s', self::SAMPLE_DURATION_CHECK));
        }
    }
};
