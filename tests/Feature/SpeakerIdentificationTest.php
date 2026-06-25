<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\SpeakerIdentificationInterface;
use App\Data\SpeakerEmbeddingResult;
use App\Data\SpeakerMatchResult;
use App\Enums\PreacherSource;
use App\Enums\SampleSource;
use App\Jobs\IdentifySpeaker;
use App\Models\MediaProcessingLog;
use App\Models\Preacher;
use App\Models\Sermon;
use App\Models\SpeakerProfile;
use App\Models\SpeakerSample;
use App\Services\Preacher\NullSpeakerIdentificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use OpenAI\Exceptions\TransporterException;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Client\ClientExceptionInterface;
use Tests\TestCase;

class SpeakerIdentificationTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // Model relationship tests
    // =========================================================================

    #[Test]
    public function test_speaker_profile_belongs_to_preacher(): void
    {
        $preacher = Preacher::factory()->create();
        $profile = SpeakerProfile::factory()->create(['preacher_id' => $preacher->id]);

        $this->assertTrue($profile->preacher->is($preacher));
    }

    #[Test]
    public function test_speaker_profile_has_many_samples(): void
    {
        $profile = SpeakerProfile::factory()->create();
        SpeakerSample::factory()->count(3)->create(['speaker_profile_id' => $profile->id]);

        $this->assertCount(3, $profile->samples);
    }

    #[Test]
    public function test_preacher_has_many_speaker_profiles(): void
    {
        $preacher = Preacher::factory()->create();
        SpeakerProfile::factory()->create([
            'preacher_id' => $preacher->id,
            'provider' => 'provider1',
        ]);
        SpeakerProfile::factory()->create([
            'preacher_id' => $preacher->id,
            'provider' => 'provider2',
        ]);

        $this->assertCount(2, $preacher->speakerProfiles);
    }

    #[Test]
    public function test_speaker_profile_active_scope(): void
    {
        SpeakerProfile::factory()->create(['is_active' => true]);
        SpeakerProfile::factory()->inactive()->create();

        $activeProfiles = SpeakerProfile::active()->get();

        $this->assertCount(1, $activeProfiles);
        $this->assertTrue($activeProfiles->first()->is_active);
    }

    #[Test]
    public function test_speaker_profile_cascades_on_preacher_delete(): void
    {
        $preacher = Preacher::factory()->create();
        $profile = SpeakerProfile::factory()->create(['preacher_id' => $preacher->id]);
        SpeakerSample::factory()->count(2)->create(['speaker_profile_id' => $profile->id]);

        $preacher->delete();

        $this->assertDatabaseMissing('speaker_profiles', ['id' => $profile->id]);
        $this->assertDatabaseMissing('speaker_samples', ['speaker_profile_id' => $profile->id]);
    }

    #[Test]
    public function test_speaker_sample_source_enum_cast(): void
    {
        $sample = SpeakerSample::factory()->backfill()->create();

        $this->assertInstanceOf(SampleSource::class, $sample->source);
        $this->assertEquals(SampleSource::Backfill, $sample->source);
    }

    #[Test]
    public function test_effective_threshold_uses_profile_override(): void
    {
        $profile = SpeakerProfile::factory()->create([
            'accept_threshold' => 0.85,
            'margin_threshold' => 0.15,
        ]);

        $this->assertEquals(0.85, $profile->getEffectiveAcceptThreshold());
        $this->assertEquals(0.15, $profile->getEffectiveMarginThreshold());
    }

    #[Test]
    public function test_effective_threshold_falls_back_to_config(): void
    {
        config([
            'media-processing.speaker_identification.accept_threshold' => 0.75,
            'media-processing.speaker_identification.margin_threshold' => 0.10,
        ]);

        $profile = SpeakerProfile::factory()->create([
            'accept_threshold' => null,
            'margin_threshold' => null,
        ]);

        $this->assertEquals(0.75, $profile->getEffectiveAcceptThreshold());
        $this->assertEquals(0.10, $profile->getEffectiveMarginThreshold());
    }

    // =========================================================================
    // NullSpeakerIdentificationService tests
    // =========================================================================

    #[Test]
    public function test_null_service_returns_failed_embedding(): void
    {
        $service = new NullSpeakerIdentificationService;
        $result = $service->extractEmbedding('/fake/path.mp3');

        $this->assertFalse($result->success);
        $this->assertNull($result->embedding);
        $this->assertNotEmpty($result->errorMessage);
    }

    #[Test]
    public function test_null_service_returns_no_match(): void
    {
        $service = new NullSpeakerIdentificationService;
        $result = $service->identify('/fake/path.mp3', new Collection);

        $this->assertFalse($result->matched);
        $this->assertNotEmpty($result->reason);
    }

    #[Test]
    public function test_null_service_update_profile_returns_unchanged(): void
    {
        $profile = SpeakerProfile::factory()->create(['sample_count' => 5]);
        $service = new NullSpeakerIdentificationService;

        $returned = $service->updateProfile($profile, []);

        $this->assertEquals($profile->id, $returned->id);
        $this->assertEquals(5, $returned->sample_count);
    }

    // =========================================================================
    // SpeakerEmbeddingResult DTO tests
    // =========================================================================

    #[Test]
    public function test_speaker_embedding_result_success_factory(): void
    {
        $embedding = array_fill(0, 256, 0.5);
        $result = SpeakerEmbeddingResult::success($embedding, 60.0);

        $this->assertTrue($result->success);
        $this->assertEquals($embedding, $result->embedding);
        $this->assertEquals(60.0, $result->durationUsed);
        $this->assertNull($result->errorMessage);
    }

    #[Test]
    public function test_speaker_embedding_result_failed_factory(): void
    {
        $result = SpeakerEmbeddingResult::failed('Something went wrong');

        $this->assertFalse($result->success);
        $this->assertNull($result->embedding);
        $this->assertEquals('Something went wrong', $result->errorMessage);
    }

    // =========================================================================
    // SpeakerMatchResult DTO tests
    // =========================================================================

    #[Test]
    public function test_speaker_match_result_no_profiles_factory(): void
    {
        $result = SpeakerMatchResult::noProfiles();

        $this->assertFalse($result->matched);
        $this->assertEquals('No active speaker profiles', $result->reason);
    }

    #[Test]
    public function test_speaker_match_result_error_factory(): void
    {
        $result = SpeakerMatchResult::error('Extraction failed');

        $this->assertFalse($result->matched);
        $this->assertTrue($result->errored);
        $this->assertEquals('Extraction failed', $result->reason);
    }

    #[Test]
    public function test_speaker_match_result_to_log_array(): void
    {
        $result = SpeakerMatchResult::noMatch(0.6, 0.5, [], 'Score too low');

        $log = $result->toLogArray();

        $this->assertArrayHasKey('matched', $log);
        $this->assertArrayHasKey('errored', $log);
        $this->assertArrayHasKey('top_score', $log);
        $this->assertArrayHasKey('reason', $log);
        $this->assertFalse($log['matched']);
        $this->assertFalse($log['errored']);
        $this->assertEquals(0.6, $log['top_score']);
    }

    // =========================================================================
    // IdentifySpeaker job gate tests
    // =========================================================================

    #[Test]
    public function test_identify_speaker_skips_when_disabled(): void
    {
        config(['media-processing.speaker_identification.enabled' => false]);

        $log = MediaProcessingLog::factory()->audio()->pending()->create([
            'sermon_id' => Sermon::factory()->create()->id,
        ]);

        $this->instance(SpeakerIdentificationInterface::class, new NullSpeakerIdentificationService);

        (new IdentifySpeaker($log))->handle(app(SpeakerIdentificationInterface::class));

        $log->refresh();
        $metadata = $log->processing_metadata;

        $this->assertEquals('skipped', $metadata['speaker_identification']['outcome'] ?? null);
    }

    #[Test]
    public function test_identify_speaker_skips_when_no_sermon_record(): void
    {
        config(['media-processing.speaker_identification.enabled' => true]);

        $log = MediaProcessingLog::factory()->audio()->pending()->create(['sermon_id' => null]);

        $this->instance(SpeakerIdentificationInterface::class, new NullSpeakerIdentificationService);

        (new IdentifySpeaker($log))->handle(app(SpeakerIdentificationInterface::class));

        $log->refresh();

        $this->assertEquals('skipped', $log->processing_metadata['speaker_identification']['outcome'] ?? null);
    }

    #[Test]
    public function test_identify_speaker_skips_when_preacher_source_is_id3(): void
    {
        config(['media-processing.speaker_identification.enabled' => true]);

        $sermon = Sermon::factory()->create([
            'preacher_source' => PreacherSource::Id3->value,
        ]);

        $log = MediaProcessingLog::factory()->audio()->pending()->create(['sermon_id' => $sermon->id]);

        $this->instance(SpeakerIdentificationInterface::class, new NullSpeakerIdentificationService);

        (new IdentifySpeaker($log))->handle(app(SpeakerIdentificationInterface::class));

        $log->refresh();

        $this->assertEquals('skipped_id3', $log->processing_metadata['speaker_identification']['outcome'] ?? null);
    }

    #[Test]
    public function test_identify_speaker_skips_when_audio_too_short(): void
    {
        config([
            'media-processing.speaker_identification.enabled' => true,
            'media-processing.speaker_identification.min_duration' => 30,
        ]);

        $sermon = Sermon::factory()->create([
            'preacher_source' => PreacherSource::Default->value,
            'duration' => 20.0,
        ]);

        $log = MediaProcessingLog::factory()->audio()->pending()->create(['sermon_id' => $sermon->id]);

        $this->instance(SpeakerIdentificationInterface::class, new NullSpeakerIdentificationService);

        (new IdentifySpeaker($log))->handle(app(SpeakerIdentificationInterface::class));

        $log->refresh();

        $this->assertEquals('skipped_short', $log->processing_metadata['speaker_identification']['outcome'] ?? null);
    }

    #[Test]
    public function test_identify_speaker_skips_when_no_active_profiles(): void
    {
        config(['media-processing.speaker_identification.enabled' => true]);

        $sermon = Sermon::factory()->create([
            'preacher_source' => PreacherSource::Default->value,
            'duration' => 300.0,
        ]);

        $log = MediaProcessingLog::factory()->audio()->pending()->create([
            'sermon_id' => $sermon->id,
            'source_file_path' => 'sermons/audio/test.mp3',
        ]);

        $this->instance(SpeakerIdentificationInterface::class, new NullSpeakerIdentificationService);

        (new IdentifySpeaker($log))->handle(app(SpeakerIdentificationInterface::class));

        $log->refresh();

        $this->assertEquals('skipped_no_profiles', $log->processing_metadata['speaker_identification']['outcome'] ?? null);
    }

    // =========================================================================
    // IdentifySpeaker job mode tests (using mock service)
    // =========================================================================

    #[Test]
    public function test_identify_speaker_enforce_mode_assigns_preacher(): void
    {
        config([
            'media-processing.speaker_identification.enabled' => true,
            'media-processing.speaker_identification.mode' => 'enforce',
        ]);

        $preacher = Preacher::factory()->create([
            'name' => 'Mark Drury Enforce Test',
            'slug' => 'mark-drury-enforce-test',
        ]);
        $profile = SpeakerProfile::factory()->create(['preacher_id' => $preacher->id, 'is_active' => true]);
        $sermon = Sermon::factory()->create([
            'preacher_source' => PreacherSource::Default->value,
            'preacher_confidence' => null,
            'needs_preacher_review' => true,
            'duration' => 300.0,
        ]);

        $log = MediaProcessingLog::factory()->audio()->pending()->create([
            'sermon_id' => $sermon->id,
            'source_file_path' => 'sermons/audio/test.mp3',
        ]);

        $matchResult = SpeakerMatchResult::matched($profile->load('preacher'), 0.90, 0.70, [$profile->id => 0.90]);

        $mockService = $this->createStub(SpeakerIdentificationInterface::class);
        $mockService->method('identify')->willReturn($matchResult);

        $this->instance(SpeakerIdentificationInterface::class, $mockService);

        (new IdentifySpeaker($log))->handle($mockService);

        $sermon->refresh();

        $this->assertEquals($preacher->id, $sermon->preacher_id);
        $this->assertEquals(PreacherSource::SpeakerModel, $sermon->preacher_source);
        $this->assertEquals(0.90, $sermon->preacher_confidence);
        $this->assertFalse($sermon->needs_preacher_review);
        $this->assertEquals('Mark Drury Enforce Test', $sermon->preacher);
    }

    #[Test]
    public function test_identify_speaker_shadow_mode_does_not_change_preacher(): void
    {
        config([
            'media-processing.speaker_identification.enabled' => true,
            'media-processing.speaker_identification.mode' => 'shadow',
        ]);

        $visitingPreacher = Preacher::factory()->create([
            'name' => 'Visiting Speaker Shadow Mode',
            'slug' => 'visiting-speaker-shadow-mode',
        ]);
        $matchedPreacher = Preacher::factory()->create([
            'name' => 'Mark Drury Shadow Match',
            'slug' => 'mark-drury-shadow-match',
        ]);
        $profile = SpeakerProfile::factory()->create(['preacher_id' => $matchedPreacher->id, 'is_active' => true]);

        $sermon = Sermon::factory()->create([
            'preacher_id' => $visitingPreacher->id,
            'preacher' => 'Visiting Speaker Shadow Mode',
            'preacher_source' => PreacherSource::Default->value,
            'duration' => 300.0,
        ]);

        $log = MediaProcessingLog::factory()->audio()->pending()->create([
            'sermon_id' => $sermon->id,
            'source_file_path' => 'sermons/audio/test.mp3',
        ]);

        $matchResult = SpeakerMatchResult::matched($profile->load('preacher'), 0.88, 0.70, [$profile->id => 0.88]);

        $mockService = $this->createStub(SpeakerIdentificationInterface::class);
        $mockService->method('identify')->willReturn($matchResult);

        (new IdentifySpeaker($log))->handle($mockService);

        $sermon->refresh();

        // Preacher assignment unchanged in shadow mode
        $this->assertEquals($visitingPreacher->id, $sermon->preacher_id);
        $this->assertEquals('Visiting Speaker Shadow Mode', $sermon->preacher);
        $this->assertEquals(PreacherSource::Default, $sermon->preacher_source);
        // Confidence is stored for observability
        $this->assertEquals(0.88, $sermon->preacher_confidence);
    }

    #[Test]
    public function test_identify_speaker_no_match_logs_result(): void
    {
        config([
            'media-processing.speaker_identification.enabled' => true,
            'media-processing.speaker_identification.mode' => 'enforce',
        ]);

        $preacher = Preacher::factory()->create();
        SpeakerProfile::factory()->create(['preacher_id' => $preacher->id, 'is_active' => true]);

        $sermon = Sermon::factory()->create([
            'preacher_source' => PreacherSource::Default->value,
            'preacher_id' => $preacher->id,
            'duration' => 300.0,
        ]);

        $log = MediaProcessingLog::factory()->audio()->pending()->create([
            'sermon_id' => $sermon->id,
            'source_file_path' => 'sermons/audio/test.mp3',
        ]);

        $noMatchResult = SpeakerMatchResult::noMatch(0.60, 0.55, [], 'Score below threshold');

        $mockService = $this->createStub(SpeakerIdentificationInterface::class);
        $mockService->method('identify')->willReturn($noMatchResult);

        $originalPreacherId = $sermon->preacher_id;

        (new IdentifySpeaker($log))->handle($mockService);

        $sermon->refresh();
        $log->refresh();

        // No match in enforce mode falls back to Visiting Speaker and review queue.
        $this->assertNotEquals($originalPreacherId, $sermon->preacher_id);
        $this->assertEquals('Visiting Speaker', $sermon->preacher);
        $this->assertEquals(PreacherSource::Default, $sermon->preacher_source);
        $this->assertEquals(0.60, $sermon->preacher_confidence);
        $this->assertTrue($sermon->needs_preacher_review);

        // Decision logged.
        $this->assertEquals('no_match', $log->processing_metadata['speaker_identification']['outcome'] ?? null);
    }

    #[Test]
    public function test_identify_speaker_error_result_is_logged_as_error(): void
    {
        config([
            'media-processing.speaker_identification.enabled' => true,
            'media-processing.speaker_identification.mode' => 'enforce',
        ]);

        $preacher = Preacher::factory()->create([
            'name' => 'Visiting Speaker Error Mode',
            'slug' => 'visiting-speaker-error-mode',
        ]);
        SpeakerProfile::factory()->create(['preacher_id' => $preacher->id, 'is_active' => true]);

        $sermon = Sermon::factory()->create([
            'preacher_id' => $preacher->id,
            'preacher' => $preacher->name,
            'preacher_source' => PreacherSource::Default->value,
            'duration' => 300.0,
            'needs_preacher_review' => true,
        ]);

        $log = MediaProcessingLog::factory()->audio()->pending()->create([
            'sermon_id' => $sermon->id,
            'source_file_path' => 'sermons/audio/test.mp3',
        ]);

        $errorResult = SpeakerMatchResult::error('Embedding extraction failed');

        $mockService = $this->createStub(SpeakerIdentificationInterface::class);
        $mockService->method('identify')->willReturn($errorResult);

        (new IdentifySpeaker($log))->handle($mockService);

        $sermon->refresh();
        $log->refresh();

        $this->assertEquals($preacher->id, $sermon->preacher_id);
        $this->assertEquals(PreacherSource::Default, $sermon->preacher_source);
        $this->assertEquals('error', $log->processing_metadata['speaker_identification']['outcome'] ?? null);
    }

    #[Test]
    public function test_identify_speaker_filters_profiles_by_provider_and_model_version(): void
    {
        config([
            'media-processing.speaker_identification.enabled' => true,
            'media-processing.speaker_identification.provider' => 'resemblyzer',
            'media-processing.speaker_identification.model_version' => 'v1.0',
        ]);

        $preacher = Preacher::factory()->create();
        SpeakerProfile::factory()->create([
            'preacher_id' => $preacher->id,
            'provider' => 'resemblyzer',
            'model_version' => 'v2.0',
            'is_active' => true,
        ]);

        $sermon = Sermon::factory()->create([
            'preacher_source' => PreacherSource::Default->value,
            'duration' => 300.0,
        ]);

        $log = MediaProcessingLog::factory()->audio()->pending()->create([
            'sermon_id' => $sermon->id,
            'source_file_path' => 'sermons/audio/test.mp3',
        ]);

        $this->instance(SpeakerIdentificationInterface::class, new NullSpeakerIdentificationService);

        (new IdentifySpeaker($log))->handle(app(SpeakerIdentificationInterface::class));

        $log->refresh();
        $this->assertEquals('skipped_no_profiles', $log->processing_metadata['speaker_identification']['outcome'] ?? null);
    }

    // =========================================================================
    // Non-blocking tests (critical)
    // =========================================================================

    #[Test]
    public function test_identify_speaker_non_blocking_on_deterministic_exception(): void
    {
        config([
            'media-processing.speaker_identification.enabled' => true,
            'media-processing.speaker_identification.mode' => 'enforce',
        ]);

        $preacher = Preacher::factory()->create();
        SpeakerProfile::factory()->create(['preacher_id' => $preacher->id, 'is_active' => true]);

        $sermon = Sermon::factory()->create([
            'preacher_source' => PreacherSource::Default->value,
            'duration' => 300.0,
        ]);

        $log = MediaProcessingLog::factory()->audio()->pending()->create([
            'sermon_id' => $sermon->id,
            'source_file_path' => 'sermons/audio/test.mp3',
        ]);

        $mockService = $this->createStub(SpeakerIdentificationInterface::class);
        // RuntimeException is a deterministic/unknown error — must not be re-thrown
        $mockService->method('identify')->willThrowException(new \RuntimeException('Service unavailable'));

        // Should NOT throw — deterministic exceptions are caught so the pipeline chain continues
        (new IdentifySpeaker($log))->handle($mockService);

        $log->refresh();

        $this->assertEquals('error', $log->processing_metadata['speaker_identification']['outcome'] ?? null);
        // Processing log must NOT be marked as failed
        $this->assertNotEquals('failed', $log->status->value ?? $log->status);
    }

    #[Test]
    public function test_identify_speaker_rethrows_transient_connection_exception(): void
    {
        config([
            'media-processing.speaker_identification.enabled' => true,
            'media-processing.speaker_identification.mode' => 'enforce',
        ]);

        $preacher = Preacher::factory()->create();
        SpeakerProfile::factory()->create(['preacher_id' => $preacher->id, 'is_active' => true]);

        $sermon = Sermon::factory()->create([
            'preacher_source' => PreacherSource::Default->value,
            'duration' => 300.0,
        ]);

        $log = MediaProcessingLog::factory()->audio()->pending()->create([
            'sermon_id' => $sermon->id,
            'source_file_path' => 'sermons/audio/test.mp3',
        ]);

        $mockService = $this->createStub(SpeakerIdentificationInterface::class);
        $mockService->method('identify')->willThrowException(new ConnectionException('Connection timed out'));

        // Should re-throw — transient failures must propagate so the queue can retry
        $this->expectException(ConnectionException::class);
        (new IdentifySpeaker($log))->handle($mockService);
    }

    #[Test]
    public function test_identify_speaker_rethrows_transient_openai_transporter_exception(): void
    {
        config([
            'media-processing.speaker_identification.enabled' => true,
            'media-processing.speaker_identification.mode' => 'enforce',
        ]);

        $preacher = Preacher::factory()->create();
        SpeakerProfile::factory()->create(['preacher_id' => $preacher->id, 'is_active' => true]);

        $sermon = Sermon::factory()->create([
            'preacher_source' => PreacherSource::Default->value,
            'duration' => 300.0,
        ]);

        $log = MediaProcessingLog::factory()->audio()->pending()->create([
            'sermon_id' => $sermon->id,
            'source_file_path' => 'sermons/audio/test.mp3',
        ]);

        $clientException = new class('Connection reset by peer') extends \Exception implements ClientExceptionInterface {};
        $transporterException = new TransporterException($clientException);

        $mockService = $this->createStub(SpeakerIdentificationInterface::class);
        $mockService->method('identify')->willThrowException($transporterException);

        // Should re-throw — TransporterException wraps network-level failures and must be retried
        $this->expectException(TransporterException::class);
        (new IdentifySpeaker($log))->handle($mockService);
    }

    #[Test]
    public function test_identify_speaker_failed_callback_records_permanent_failure(): void
    {
        $log = MediaProcessingLog::factory()->audio()->pending()->create([
            'source_file_path' => 'sermons/audio/test.mp3',
        ]);

        $job = new IdentifySpeaker($log);
        $job->failed(new ConnectionException('All retries exhausted'));

        $log->refresh();

        $this->assertEquals('failed', $log->processing_metadata['speaker_identification']['outcome'] ?? null);
        $this->assertStringContainsString('All retries exhausted', $log->processing_metadata['speaker_identification']['reason'] ?? '');
        // Processing log must NOT be marked as failed — speaker identification is non-blocking
        $this->assertNotEquals('failed', $log->status->value ?? $log->status);
    }
}
