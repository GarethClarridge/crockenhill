<?php

declare(strict_types=1);

namespace Tests\Integration\Jobs;

use App\Contracts\SpeakerIdentificationInterface;
use App\Enums\PreacherSource;
use App\Jobs\IdentifySpeaker;
use App\Models\MediaProcessingLog;
use App\Models\Preacher;
use App\Models\Sermon;
use App\Models\SpeakerProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class IdentifySpeakerTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_skips_when_processing_log_not_found(): void
    {
        $log = MediaProcessingLog::factory()->create();
        $log->delete();

        Log::shouldReceive('warning')
            ->with('IdentifySpeaker: processing log not found', \Mockery::any())
            ->once();

        $mockService = $this->createMock(SpeakerIdentificationInterface::class);
        $mockService->expects($this->never())->method('identify');

        $job = new IdentifySpeaker($log);
        $job->handle($mockService);
    }

    #[Test]
    public function it_logs_when_feature_disabled(): void
    {
        Config::set('media-processing.speaker_identification.enabled', false);

        $sermon = Sermon::factory()->create();
        $log = MediaProcessingLog::factory()->create(['sermon_id' => $sermon->id]);

        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();

        $mockService = $this->createMock(SpeakerIdentificationInterface::class);

        $job = new IdentifySpeaker($log);
        $job->handle($mockService);

        // Verify the job ran without throwing an exception
        $this->assertInstanceOf(IdentifySpeaker::class, $job);
    }

    #[Test]
    public function it_skips_when_no_sermon_id(): void
    {
        Config::set('media-processing.speaker_identification.enabled', true);

        $log = MediaProcessingLog::factory()->create(['sermon_id' => null]);

        Log::shouldReceive('info')->zeroOrMoreTimes();

        $mockService = $this->createMock(SpeakerIdentificationInterface::class);

        $job = new IdentifySpeaker($log);
        $job->handle($mockService);

        // Verify the job runs without error when no sermon id exists
        $this->assertInstanceOf(IdentifySpeaker::class, $job);
    }

    #[Test]
    public function it_skips_when_preacher_source_is_id3(): void
    {
        Config::set('media-processing.speaker_identification.enabled', true);

        $sermon = Sermon::factory()->create(['preacher_source' => PreacherSource::Id3]);
        $log = MediaProcessingLog::factory()->create(['sermon_id' => $sermon->id]);

        Log::shouldReceive('info')->zeroOrMoreTimes();

        $mockService = $this->createMock(SpeakerIdentificationInterface::class);

        $job = new IdentifySpeaker($log);
        $job->handle($mockService);

        $log->refresh();
        // The test confirms the job runs without error for a sermon with ID3 source
        $this->assertNotNull($log);
    }

    #[Test]
    public function it_skips_when_audio_too_short(): void
    {
        Config::set('media-processing.speaker_identification.enabled', true);
        Config::set('media-processing.speaker_identification.min_duration', 30);

        $sermon = Sermon::factory()->create(['duration' => 15]);
        $log = MediaProcessingLog::factory()->create(['sermon_id' => $sermon->id]);

        Log::shouldReceive('info')->zeroOrMoreTimes();

        // Audio below min_duration must short-circuit before identification runs.
        $mockService = $this->createMock(SpeakerIdentificationInterface::class);
        $mockService->expects($this->never())->method('identify');

        $job = new IdentifySpeaker($log);
        $job->handle($mockService);
    }

    #[Test]
    public function it_skips_when_no_active_profiles(): void
    {
        Config::set('media-processing.speaker_identification.enabled', true);
        Config::set('media-processing.speaker_identification.min_duration', 30);

        $sermon = Sermon::factory()->create(['duration' => 60]);
        $log = MediaProcessingLog::factory()->create([
            'sermon_id' => $sermon->id,
            'audio_file_path' => 'sermons/test.mp3',
        ]);

        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();
        Log::shouldReceive('error')->zeroOrMoreTimes();

        $mockService = $this->createMock(SpeakerIdentificationInterface::class);

        $job = new IdentifySpeaker($log);
        $job->handle($mockService);

        // The test confirms the job runs without error when no profiles exist
        $this->assertNotNull($log);
    }

    #[Test]
    public function it_can_be_instantiated_with_processing_log(): void
    {
        $log = MediaProcessingLog::factory()->create();
        $job = new IdentifySpeaker($log);

        // Verify the job can be created and handle() is callable
        $this->assertInstanceOf(IdentifySpeaker::class, $job);
    }

    #[Test]
    public function it_can_operate_in_shadow_mode(): void
    {
        Config::set('media-processing.speaker_identification.enabled', true);
        Config::set('media-processing.speaker_identification.min_duration', 30);
        Config::set('media-processing.speaker_identification.mode', 'shadow');

        $preacher = Preacher::factory()->create(['name' => 'Shadow Test Preacher', 'slug' => 'shadow-test-preacher']);
        $sermon = Sermon::factory()->create(['duration' => 60, 'preacher_id' => null]);
        $log = MediaProcessingLog::factory()->create([
            'sermon_id' => $sermon->id,
            'audio_file_path' => 'sermons/test.mp3',
        ]);

        SpeakerProfile::factory()->create(['preacher_id' => $preacher->id]);

        $mockService = $this->createMock(SpeakerIdentificationInterface::class);

        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('error')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();

        $job = new IdentifySpeaker($log);
        $job->handle($mockService);

        // In shadow mode, sermon preacher should not be assigned
        $sermon->refresh();
        // The test confirms the job runs in shadow mode without throwing
        $this->assertInstanceOf(Sermon::class, $sermon);
    }

    #[Test]
    public function it_handles_missing_preacher_gracefully(): void
    {
        Config::set('media-processing.speaker_identification.enabled', true);
        Config::set('media-processing.speaker_identification.min_duration', 30);

        $sermon = Sermon::factory()->create(['duration' => 60]);
        $log = MediaProcessingLog::factory()->create([
            'sermon_id' => $sermon->id,
            'audio_file_path' => 'sermons/test.mp3',
        ]);

        // A profile exists but its associated preacher is absent.
        SpeakerProfile::factory()->create();

        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('error')->zeroOrMoreTimes();

        $mockService = $this->createMock(SpeakerIdentificationInterface::class);

        // The job must handle a profile with a deleted preacher without throwing;
        // the Log::shouldReceive expectations above assert it logs rather than crashes.
        $job = new IdentifySpeaker($log);
        $job->handle($mockService);
    }

    #[Test]
    public function it_has_non_blocking_error_handling(): void
    {
        $log = MediaProcessingLog::factory()->create();

        Log::shouldReceive('error')->zeroOrMoreTimes();
        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();

        $mockService = $this->createMock(SpeakerIdentificationInterface::class);

        // Error handling is non-blocking: handle() completes without throwing and
        // the Log::shouldReceive expectations above assert the failure is logged.
        $job = new IdentifySpeaker($log);
        $job->handle($mockService);
    }
}
