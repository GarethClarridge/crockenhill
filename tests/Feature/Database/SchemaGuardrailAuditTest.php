<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Enums\SampleSource;
use App\Models\MediaProcessingLog;
use App\Models\Preacher;
use App\Models\Sermon;
use App\Models\SpeakerProfile;
use App\Models\SpeakerSample;
use App\Support\SchemaGuardrailAudit;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SchemaGuardrailAuditTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_reports_a_clean_schema_state_when_no_guardrail_violations_exist(): void
    {
        SpeakerProfile::factory()->create();
        SpeakerSample::factory()->create();
        MediaProcessingLog::factory()->create();

        $report = app(SchemaGuardrailAudit::class)->report();

        $this->assertSame([], $report['speaker_profile_duplicates']);
        $this->assertSame([], $report['speaker_sample_duplicates']);
        $this->assertSame(0, $report['orphaned_media_processing_logs']);
        $this->assertSame([], $report['invalid_service_section_statuses']);
        $this->assertSame([], $report['invalid_service_section_publication_statuses']);
        $this->assertSame(0, $report['legacy_livestream_processing_log_rows']);
    }

    #[Test]
    public function it_detects_duplicate_speaker_identity_rows_and_orphaned_processing_logs(): void
    {
        $preacher = Preacher::factory()->create();

        if (Schema::hasIndex('speaker_profiles', 'speaker_profiles_preacher_provider_version_unique')) {
            Schema::table('speaker_profiles', function (Blueprint $table): void {
                $table->dropUnique('speaker_profiles_preacher_provider_version_unique');
            });
        }

        SpeakerProfile::factory()->create([
            'preacher_id' => $preacher->id,
            'provider' => 'resemblyzer',
            'model_version' => 'v1.0',
        ]);
        SpeakerProfile::factory()->create([
            'preacher_id' => $preacher->id,
            'provider' => 'resemblyzer',
            'model_version' => 'v1.0',
        ]);

        if (Schema::hasIndex('speaker_samples', 'speaker_samples_profile_sermon_source_unique')) {
            Schema::table('speaker_samples', function (Blueprint $table): void {
                $table->dropUnique('speaker_samples_profile_sermon_source_unique');
            });
        }

        $profile = SpeakerProfile::query()->firstOrFail();
        $sermon = Sermon::factory()->create();

        SpeakerSample::factory()->create([
            'speaker_profile_id' => $profile->id,
            'sermon_id' => $sermon->id,
            'source' => SampleSource::Backfill->value,
        ]);
        SpeakerSample::factory()->create([
            'speaker_profile_id' => $profile->id,
            'sermon_id' => $sermon->id,
            'source' => SampleSource::Backfill->value,
        ]);

        $orphanedSermon = Sermon::factory()->create();
        MediaProcessingLog::factory()->withSermon($orphanedSermon)->create();

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        DB::table('sermons')->where('id', $orphanedSermon->id)->delete();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $report = app(SchemaGuardrailAudit::class)->report();

        $this->assertCount(1, $report['speaker_profile_duplicates']);
        $this->assertSame(2, $report['speaker_profile_duplicates'][0]['duplicate_count']);

        $this->assertCount(1, $report['speaker_sample_duplicates']);
        $this->assertSame(2, $report['speaker_sample_duplicates'][0]['duplicate_count']);

        $this->assertSame(1, $report['orphaned_media_processing_logs']);
    }
}
