<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\SpeakerIdentificationInterface;
use App\Data\SpeakerEmbeddingResult;
use App\Enums\SampleSource;
use App\Models\Preacher;
use App\Models\Sermon;
use App\Models\SpeakerProfile;
use App\Models\SpeakerSample;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SpeakerProfilesBootstrapCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_bootstrap_creates_profile_and_backfill_samples(): void
    {
        config([
            'media-processing.speaker_identification.provider' => 'resemblyzer',
            'media-processing.speaker_identification.model_version' => 'v1.0',
        ]);

        $preacher = Preacher::factory()->create([
            'name' => 'Mark Drury Bootstrap Test',
            'slug' => 'mark-drury-bootstrap-test',
        ]);
        Sermon::factory()->count(2)->withPreacher($preacher)->withAudio()->create();

        $embedding = array_fill(0, 256, 0.1);

        $mockService = $this->createMock(SpeakerIdentificationInterface::class);
        $mockService->method('extractEmbedding')
            ->willReturn(SpeakerEmbeddingResult::success($embedding, 60.0));
        $mockService->method('updateProfile')
            ->willReturnCallback(function (SpeakerProfile $profile, array $approvedEmbeddings): SpeakerProfile {
                $profile->update([
                    'centroid_embedding' => $approvedEmbeddings[0],
                    'sample_count' => count($approvedEmbeddings),
                ]);

                return $profile->fresh() ?? $profile;
            });

        $this->instance(SpeakerIdentificationInterface::class, $mockService);

        $this->artisan("speaker-profiles:bootstrap --preacher={$preacher->slug} --min-sermons=2 --max-sermons=2")
            ->assertSuccessful();

        $profile = SpeakerProfile::where('preacher_id', $preacher->id)->first();
        $this->assertNotNull($profile);
        $this->assertEquals('resemblyzer', $profile->provider);
        $this->assertEquals('v1.0', $profile->model_version);
        $this->assertEquals(2, $profile->sample_count);

        $samples = SpeakerSample::where('speaker_profile_id', $profile->id)->get();
        $this->assertCount(2, $samples);
        $this->assertTrue($samples->every(fn (SpeakerSample $sample) => $sample->approved));
        $this->assertTrue($samples->every(fn (SpeakerSample $sample) => $sample->source === SampleSource::Backfill));
    }

    public function test_bootstrap_is_idempotent_for_profile_and_samples(): void
    {
        config([
            'media-processing.speaker_identification.provider' => 'resemblyzer',
            'media-processing.speaker_identification.model_version' => 'v1.0',
        ]);

        $preacher = Preacher::factory()->create([
            'name' => 'Mark Drury Bootstrap Idempotent',
            'slug' => 'mark-drury-bootstrap-idempotent',
        ]);
        Sermon::factory()->count(2)->withPreacher($preacher)->withAudio()->create();

        $embedding = array_fill(0, 256, 0.2);

        $mockService = $this->createMock(SpeakerIdentificationInterface::class);
        $mockService->method('extractEmbedding')
            ->willReturn(SpeakerEmbeddingResult::success($embedding, 60.0));
        $mockService->method('updateProfile')
            ->willReturnCallback(function (SpeakerProfile $profile, array $approvedEmbeddings): SpeakerProfile {
                $profile->update([
                    'centroid_embedding' => $approvedEmbeddings[0],
                    'sample_count' => count($approvedEmbeddings),
                ]);

                return $profile->fresh() ?? $profile;
            });

        $this->instance(SpeakerIdentificationInterface::class, $mockService);

        $this->artisan("speaker-profiles:bootstrap --preacher={$preacher->slug} --min-sermons=2 --max-sermons=2")
            ->assertSuccessful();
        $this->artisan("speaker-profiles:bootstrap --preacher={$preacher->slug} --min-sermons=2 --max-sermons=2")
            ->assertSuccessful();

        $profile = SpeakerProfile::where('preacher_id', $preacher->id)->first();

        $this->assertNotNull($profile);
        $this->assertEquals(1, SpeakerProfile::where('preacher_id', $preacher->id)->count());
        $this->assertEquals(2, SpeakerSample::where('speaker_profile_id', $profile->id)->count());
    }

    public function test_bootstrap_resolves_canonical_sermon_paths_via_storage_service(): void
    {
        config([
            'media-processing.speaker_identification.provider' => 'resemblyzer',
            'media-processing.speaker_identification.model_version' => 'v1.0',
        ]);

        $preacher = Preacher::factory()->create([
            'name' => 'Mark Drury Bootstrap Legacy',
            'slug' => 'mark-drury-bootstrap-legacy',
        ]);

        Sermon::factory()->count(2)->withPreacher($preacher)->create([
            'audio_file_path' => 'legacy/sermons/my-sermon.mp3',
        ]);

        $embedding = array_fill(0, 256, 0.1);

        $resolvedPaths = [];

        $mockService = $this->createMock(SpeakerIdentificationInterface::class);
        $mockService->method('extractEmbedding')
            ->willReturnCallback(function (string $path, string $disk) use (&$resolvedPaths, $embedding): SpeakerEmbeddingResult {
                $resolvedPaths[] = [$path, $disk];

                return SpeakerEmbeddingResult::success($embedding, 60.0);
            });
        $mockService->method('updateProfile')
            ->willReturnCallback(function (SpeakerProfile $profile, array $approvedEmbeddings): SpeakerProfile {
                $profile->update([
                    'centroid_embedding' => $approvedEmbeddings[0],
                    'sample_count' => count($approvedEmbeddings),
                ]);

                return $profile->fresh() ?? $profile;
            });

        $this->instance(SpeakerIdentificationInterface::class, $mockService);

        $this->artisan("speaker-profiles:bootstrap --preacher={$preacher->slug} --min-sermons=2 --max-sermons=2")
            ->assertSuccessful();

        $profile = SpeakerProfile::where('preacher_id', $preacher->id)->first();
        $this->assertNotNull($profile);
        $this->assertEquals(2, $profile->sample_count);
        $this->assertSame(
            [
                ['legacy/sermons/my-sermon.mp3', 'public'],
                ['legacy/sermons/my-sermon.mp3', 'public'],
            ],
            $resolvedPaths
        );
    }

    public function test_bootstrap_dry_run_makes_no_changes(): void
    {
        config([
            'media-processing.speaker_identification.provider' => 'resemblyzer',
            'media-processing.speaker_identification.model_version' => 'v1.0',
        ]);

        $preacher = Preacher::factory()->create();
        Sermon::factory()->count(3)->withPreacher($preacher)->create();

        $mockService = $this->createMock(SpeakerIdentificationInterface::class);
        $mockService->expects($this->never())->method('extractEmbedding');
        $mockService->expects($this->never())->method('updateProfile');
        $this->instance(SpeakerIdentificationInterface::class, $mockService);

        $this->artisan("speaker-profiles:bootstrap --dry-run --preacher={$preacher->slug} --min-sermons=2 --max-sermons=3")
            ->assertSuccessful();

        $this->assertEquals(0, SpeakerProfile::where('preacher_id', $preacher->id)->count());
        $this->assertEquals(
            0,
            SpeakerSample::query()
                ->whereHas('speakerProfile', fn ($query) => $query->where('preacher_id', $preacher->id))
                ->count()
        );
    }
}
