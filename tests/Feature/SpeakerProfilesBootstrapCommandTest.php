<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\SpeakerIdentificationInterface;
use App\Data\SpeakerEmbeddingResult;
use App\Enums\SampleSource;
use App\Enums\SermonContentType;
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

    public function test_profile_is_left_inactive_when_every_extraction_fails(): void
    {
        config([
            'media-processing.speaker_identification.provider' => 'resemblyzer',
            'media-processing.speaker_identification.model_version' => 'v1.0',
        ]);

        $preacher = Preacher::factory()->create(['slug' => 'extraction-always-fails']);
        Sermon::factory()->count(2)->withPreacher($preacher)->withAudio()->create();

        $mockService = $this->createMock(SpeakerIdentificationInterface::class);
        $mockService->method('extractEmbedding')
            ->willReturn(SpeakerEmbeddingResult::failed('no audio'));

        $this->instance(SpeakerIdentificationInterface::class, $mockService);

        $this->artisan("speaker-profiles:bootstrap --preacher={$preacher->slug} --min-sermons=2 --max-sermons=2")
            ->assertSuccessful();

        $profile = SpeakerProfile::where('preacher_id', $preacher->id)->first();

        // The row exists but must never be active: its centroid is the zero-vector
        // placeholder, and cosine similarity against a zero-norm vector is 0.0 — so an
        // active one advertises a preacher as identifiable who can never be identified.
        // Production carried 21 profiles in exactly this state.
        $this->assertNotNull($profile);
        $this->assertFalse($profile->is_active);
        $this->assertEquals(0, $profile->sample_count);
    }

    public function test_profile_is_activated_once_a_real_centroid_exists(): void
    {
        config([
            'media-processing.speaker_identification.provider' => 'resemblyzer',
            'media-processing.speaker_identification.model_version' => 'v1.0',
        ]);

        $preacher = Preacher::factory()->create(['slug' => 'extraction-succeeds']);
        Sermon::factory()->count(2)->withPreacher($preacher)->withAudio()->create();

        $mockService = $this->createMock(SpeakerIdentificationInterface::class);
        $mockService->method('extractEmbedding')
            ->willReturn(SpeakerEmbeddingResult::success(array_fill(0, 256, 0.1), 60.0));
        $mockService->method('updateProfile')
            ->willReturnCallback(function (SpeakerProfile $profile, array $approved): SpeakerProfile {
                $profile->update(['centroid_embedding' => $approved[0], 'sample_count' => count($approved)]);

                return $profile->fresh() ?? $profile;
            });

        $this->instance(SpeakerIdentificationInterface::class, $mockService);

        $this->artisan("speaker-profiles:bootstrap --preacher={$preacher->slug} --min-sermons=2 --max-sermons=2")
            ->assertSuccessful();

        $this->assertTrue(SpeakerProfile::where('preacher_id', $preacher->id)->first()?->is_active);
    }

    public function test_samples_are_spread_across_history_not_taken_from_the_newest_window(): void
    {
        config([
            'media-processing.speaker_identification.provider' => 'resemblyzer',
            'media-processing.speaker_identification.model_version' => 'v1.0',
        ]);

        $preacher = Preacher::factory()->create(['slug' => 'spread-across-history']);

        foreach (range(2014, 2025) as $year) {
            Sermon::factory()->withPreacher($preacher)->withAudio()->create([
                'date' => "{$year}-06-01",
            ]);
        }

        $mockService = $this->createMock(SpeakerIdentificationInterface::class);
        $mockService->method('extractEmbedding')
            ->willReturn(SpeakerEmbeddingResult::success(array_fill(0, 256, 0.1), 60.0));
        $mockService->method('updateProfile')
            ->willReturnCallback(fn (SpeakerProfile $profile): SpeakerProfile => $profile);

        $this->instance(SpeakerIdentificationInterface::class, $mockService);

        $this->artisan("speaker-profiles:bootstrap --preacher={$preacher->slug} --min-sermons=3 --max-sermons=4")
            ->assertSuccessful();

        $profile = SpeakerProfile::where('preacher_id', $preacher->id)->first();
        $years = SpeakerSample::where('speaker_profile_id', $profile->id)
            ->with('sermon')
            ->get()
            ->map(fn (SpeakerSample $sample): int => (int) $sample->sermon?->date?->format('Y'))
            ->sort()
            ->values();

        // Taking the newest four would give 2022-2025, a four-year window. A profile built
        // that way describes one recording setup rather than a person: production's Mark
        // Drury centroid spans six weeks, and his own 2013 preaching scores 0.764 against
        // it — below the 0.75 accept threshold.
        $this->assertCount(4, $years);
        $this->assertGreaterThanOrEqual(8, $years->last() - $years->first());
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

    /**
     * `sermons` is polymorphic, so a children's talk is a real Sermon row with its own
     * audio and preacher. A voice profile wants one person talking uninterrupted; a
     * children's talk is call and response with children answering, and only the opening
     * `extraction_duration` seconds are embedded — the part most likely to hold other
     * voices. `orderByDesc('date')` also puts newly published children's talks first, so
     * without this filter Phase 8 would have degraded every profile as it went.
     */
    public function test_bootstrap_never_samples_a_childrens_talk(): void
    {
        config([
            'media-processing.speaker_identification.provider' => 'resemblyzer',
            'media-processing.speaker_identification.model_version' => 'v1.0',
        ]);

        $preacher = Preacher::factory()->create([
            'name' => 'Content Type Filter Test',
            'slug' => 'content-type-filter-test',
        ]);

        $sermons = Sermon::factory()->count(2)->withPreacher($preacher)->withAudio()
            ->sequence(['date' => '2025-01-05'], ['date' => '2025-02-09'])
            ->create();

        // Dated latest, so orderByDesc('date') reaches it first, and --max-sermons=3 leaves
        // room for all three: only the content_type filter keeps it out.
        $childrensTalk = Sermon::factory()->withPreacher($preacher)->withAudio()->create([
            'content_type' => SermonContentType::ChildrensTalk,
            'date' => '2026-08-09',
        ]);

        $mockService = $this->createMock(SpeakerIdentificationInterface::class);
        $mockService->method('extractEmbedding')
            ->willReturn(SpeakerEmbeddingResult::success(array_fill(0, 256, 0.1), 60.0));
        $mockService->method('updateProfile')
            ->willReturnCallback(fn (SpeakerProfile $profile): SpeakerProfile => $profile);
        $this->instance(SpeakerIdentificationInterface::class, $mockService);

        $this->artisan("speaker-profiles:bootstrap --preacher={$preacher->slug} --min-sermons=2 --max-sermons=3")
            ->assertSuccessful();

        $sampledSermonIds = SpeakerSample::query()->pluck('sermon_id')->all();

        $this->assertCount(2, $sampledSermonIds);
        $this->assertContains($sermons[0]->id, $sampledSermonIds);
        $this->assertContains($sermons[1]->id, $sampledSermonIds);
        $this->assertNotContains($childrensTalk->id, $sampledSermonIds);
    }
}
