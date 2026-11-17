<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Data\ServiceSectionMetadata;
use App\Enums\AudioProfile;
use App\Enums\ServiceSectionPublicationStatus;
use App\Enums\ServiceSectionSongMatchType;
use App\Enums\ServiceSectionType;
use App\Jobs\AutoPublishServiceSection;
use App\Jobs\PublishApprovedServiceSection;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Models\SongVideo;
use App\Services\ChurchService\SectionPublication\SectionPublicationHandlerFactory;
use App\Services\ChurchService\SectionPublication\SongPublicationReviewPolicy;
use App\Services\ChurchService\Structure\ServiceStructureValidator;
use App\Support\SectionReviewFlagPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AudioTimelineFixture;
use Tests\Support\CreatesAutomaticPublicationSong;
use Tests\Support\PublicationCandidateFixture;
use Tests\TestCase;

class AutoPublishServiceSectionTest extends TestCase
{
    use CreatesAutomaticPublicationSong;
    use RefreshDatabase;

    /** @return iterable<string, array{string, bool}> */
    public static function automaticStates(): iterable
    {
        foreach (['clean', 'inversion', 'low_confidence'] as $state) {
            yield $state => [$state, true];
        }
        foreach (['manual', 'hold', 'stale', 'missing', 'blocked', 'unmatched', 'unrecorded', 'talk', 'rejected'] as $state) {
            yield $state => [$state, false];
        }
        $flags = (new \ReflectionClass(ServiceStructureValidator::class))->getConstants();
        foreach ($flags as $name => $flag) {
            if (str_starts_with($name, 'FLAG_') && is_string($flag)
                && SectionReviewFlagPolicy::requiresManualReview(ServiceSectionType::Song, [$flag])) {
                yield $flag => ['flag:'.$flag, false];
            }
        }
        foreach (['inferred_song_match', 'song_musical_introduction_moved', 'short_song_clip', 'adjacent_same_song', 'unresolved_multiple_songs', 'unlocated_adjacent_song', 'uncorroborated_partial_recording', 'song_boundary_unobservable_gap', 'song_boundary_looped_gap', 'song_boundary_without_rms_corroboration', 'song_boundary_spoken_framing_below_floor', 'song_boundary_spoken_introduction', 'song_boundary_trailing_content', 'song_sound_untreated', 'song_loudness_missed', 'song_boundary_evidence_unreadable', 'song_boundary_evidence_unavailable', 'song_lyrics_outside_section', 'song_section_without_song', 'song_section_without_music', 'song_speech_under_loop', 'song_opening_missing', 'song_closing_missing', 'song_possible_quiet_introduction'] as $reason) {
            yield $reason => ['reason:'.$reason, false];
        }
    }

    #[Test]
    #[DataProvider('automaticStates')]
    public function fresh_and_pending_sections_receive_the_same_automatic_judgement(string $state, bool $advances): void
    {
        Storage::fake('local');
        Storage::fake('public');
        Queue::fake();
        config(['media-processing.storage.sermon_disk' => 'public']);
        if (str_starts_with($state, 'reason:')) {
            $policy = $this->createMock(SongPublicationReviewPolicy::class);
            $policy->method('assess')->willReturn([
                'reasons' => [['kind' => substr($state, 7), 'detail' => 'Fresh assessment, absent from stored metadata.']],
                'boundary_evidence' => [],
            ]);
            $this->app->instance(SongPublicationReviewPolicy::class, $policy);
        }
        foreach ([ServiceSectionPublicationStatus::NotApplicable, ServiceSectionPublicationStatus::PendingApproval] as $status) {
            $section = $this->automaticSong();
            $metadata = $section->metadata->toArray();
            $attributes = ['publication_status' => $status];
            if ($state === 'low_confidence') {
                $attributes['confidence'] = 0.1;
            } elseif ($state === 'manual') {
                $attributes['needs_manual_review'] = true;
            } elseif ($state === 'rejected') {
                $attributes['publication_status'] = ServiceSectionPublicationStatus::Rejected;
            } elseif ($state === 'hold' || $state === 'inversion' || str_starts_with($state, 'flag:')) {
                $metadata['review_flags'] = [$state === 'hold' ? 'content_defect_hold' : ($state === 'inversion' ? 'structure_oos_cross_type_inversion' : substr($state, 5))];
            } elseif ($state === 'stale') {
                $metadata['publication_candidate_extraction']['media_processing'] = 'old';
            } elseif ($state === 'missing') {
                Storage::disk('public')->delete($section->extracted_video_path);
            } elseif ($state === 'blocked') {
                $metadata['publication_candidate_extraction_blocked'] = ['reason' => 'cut_plan_invalid'];
            } elseif ($state === 'unmatched') {
                $attributes['song_match_type'] = ServiceSectionSongMatchType::Unmatched;
            } elseif ($state === 'unrecorded') {
                $path = 'test-automatic/'.$section->media_processing_log_id.'.timeline.json';
                Storage::disk('local')->put($path, AudioTimelineFixture::json([[0, 300, 0.0, 0.95]], 300));
                $section->processingLog->update(['audio_timeline_path' => $path]);
            } elseif ($state === 'talk') {
                $attributes['section_type'] = ServiceSectionType::ShortTalk;
                $attributes['confidence'] = 1.0;
            }
            $attributes['metadata'] = ServiceSectionMetadata::fromArray($metadata);
            $section->update($attributes);
            (new AutoPublishServiceSection($section->id))->handle(app(SectionPublicationHandlerFactory::class));
            $expected = $advances
                ? ($status === ServiceSectionPublicationStatus::PendingApproval ? ServiceSectionPublicationStatus::Approved : ServiceSectionPublicationStatus::Published)
                : ($state === 'rejected' ? ServiceSectionPublicationStatus::Rejected : $status);
            $this->assertSame($expected, $section->fresh()->publication_status, $state.' / '.$status->value);
            if (! $advances) {
                $this->assertFalse(SongVideo::query()->where('service_section_id', $section->id)->exists());
                if ($state !== 'missing') {
                    Storage::disk('public')->assertExists($section->extracted_video_path);
                }
            }
        }
    }

    #[Test]
    public function a_new_trailing_reason_holds_an_apparently_clean_pending_song_like_2072(): void
    {
        [$section] = $this->publishableSongSection();
        $section->update(['publication_status' => ServiceSectionPublicationStatus::PendingApproval]);
        $this->storeAutomaticSongEvidence($section, [
            ['start' => 100.0, 'end' => 180.0, 'text' => 'The song begins.'],
            ['start' => 181.0, 'end' => 245.0, 'text' => 'The song continues.'],
            ['start' => 260.0, 'end' => 300.0, 'text' => 'Let us pray together now.'],
        ]);
        Storage::disk('local')->put('test-automatic/'.$section->media_processing_log_id.'.rms', "pts_time:245\nlavfi.astats.Overall.RMS_level=-20\npts_time:252\nlavfi.astats.Overall.RMS_level=-20\npts_time:260\nlavfi.astats.Overall.RMS_level=-20");
        $this->assertArrayNotHasKey('song_publication_review', $section->metadata->toArray());
        (new AutoPublishServiceSection($section->id))->handle(app(SectionPublicationHandlerFactory::class));
        $this->assertSame(ServiceSectionPublicationStatus::PendingApproval, $section->fresh()->publication_status);
        $this->assertContains('song_boundary_trailing_content', array_column($section->fresh()->metadata->raw['song_publication_review']['reasons'], 'kind'));
        Storage::disk('public')->assertExists($section->extracted_video_path);
    }

    #[Test]
    public function an_old_candidate_waits_for_a_fresh_cut_like_3771_3773_and_3775(): void
    {
        [$section] = $this->publishableSongSection();
        $metadata = $section->metadata->toArray();
        $metadata['publication_candidate_extraction']['media_processing'] = 'older-processing';
        $section->update(['publication_status' => ServiceSectionPublicationStatus::PendingApproval, 'metadata' => ServiceSectionMetadata::fromArray($metadata)]);
        (new AutoPublishServiceSection($section->id))->handle(app(SectionPublicationHandlerFactory::class));
        $this->assertSame(ServiceSectionPublicationStatus::PendingApproval, $section->fresh()->publication_status);
        $this->assertFalse(SongVideo::query()->where('service_section_id', $section->id)->exists());
        $metadata['publication_candidate_extraction'] = PublicationCandidateFixture::current(AudioProfile::Music);
        $section->update(['metadata' => ServiceSectionMetadata::fromArray($metadata)]);
        Queue::fake();
        (new AutoPublishServiceSection($section->id))->handle(app(SectionPublicationHandlerFactory::class));
        $this->assertSame(ServiceSectionPublicationStatus::Approved, $section->fresh()->publication_status);
    }

    #[Test]
    public function duplicate_automatic_and_approved_jobs_create_one_song_video(): void
    {
        [$section] = $this->publishableSongSection();
        $section->update(['publication_status' => ServiceSectionPublicationStatus::PendingApproval]);
        Queue::fake();
        $job = new AutoPublishServiceSection($section->id);
        $job->handle(app(SectionPublicationHandlerFactory::class));
        $job->handle(app(SectionPublicationHandlerFactory::class));
        $approvedJob = new PublishApprovedServiceSection($section->id);
        $approvedJob->handle(app(SectionPublicationHandlerFactory::class));
        $approvedJob->handle(app(SectionPublicationHandlerFactory::class));
        $this->assertSame(1, SongVideo::query()->where('service_section_id', $section->id)->count());
        $this->assertSame(ServiceSectionPublicationStatus::Published, $section->fresh()->publication_status);
    }

    /** @return array<string, array{string}> */
    public static function lateChanges(): array
    {
        return array_combine(['manual', 'flag', 'hold', 'reason', 'stale', 'rejected'], array_map(static fn (string $state): array => [$state], ['manual', 'flag', 'hold', 'reason', 'stale', 'rejected']));
    }

    #[Test]
    #[DataProvider('lateChanges')]
    public function a_hold_added_after_dispatch_is_read_under_the_row_lock(string $state): void
    {
        [$section] = $this->publishableSongSection();
        $section->update(['publication_status' => ServiceSectionPublicationStatus::PendingApproval]);
        $job = new AutoPublishServiceSection($section->id);
        $locked = false;
        DB::listen(function ($query) use (&$locked): void {
            if (str_contains($query->sql, 'service_sections') && str_contains($query->sql, 'for update')) {
                $locked = DB::transactionLevel() > 0;
            }
        });
        $metadata = $section->metadata->toArray();
        $attributes = [];
        if ($state === 'manual') {
            $attributes['needs_manual_review'] = true;
        } elseif ($state === 'flag' || $state === 'hold') {
            $metadata['review_flags'] = [$state === 'hold' ? 'content_defect_hold' : 'structure_micro_section'];
        } elseif ($state === 'reason') {
            $metadata['publication_candidate_extraction']['audio_treatment']['loudness_misses'] = ['Fresh loudness miss.'];
        } elseif ($state === 'stale') {
            $metadata['publication_candidate_extraction']['media_processing'] = 'old';
        } else {
            $attributes['publication_status'] = ServiceSectionPublicationStatus::Rejected;
        }
        $attributes['metadata'] = ServiceSectionMetadata::fromArray($metadata);
        $section->fresh()->update($attributes);
        $job->handle(app(SectionPublicationHandlerFactory::class));
        $this->assertTrue($locked);
        $this->assertSame($state === 'rejected' ? ServiceSectionPublicationStatus::Rejected : ServiceSectionPublicationStatus::PendingApproval, $section->fresh()->publication_status);
        $this->assertFalse(SongVideo::query()->where('service_section_id', $section->id)->exists());
    }

    #[Test]
    public function a_clean_pending_song_advances_through_automatic_approval(): void
    {
        Queue::fake();
        [$section] = $this->publishableSongSection();
        $section->update(['publication_status' => ServiceSectionPublicationStatus::PendingApproval]);

        (new AutoPublishServiceSection($section->id))->handle(app(SectionPublicationHandlerFactory::class));

        $this->assertSame(ServiceSectionPublicationStatus::Approved, $section->fresh()->publication_status);
        $this->assertSame('automatic', $section->fresh()->metadata->raw['publication']['batch_approvals'][0]['source']);
        Queue::assertPushed(PublishApprovedServiceSection::class, 1);
    }

    #[Test]
    public function it_auto_publishes_a_song_section(): void
    {
        [$section] = $this->publishableSongSection();
        $songId = $section->churchServiceItem->song_id;

        (new AutoPublishServiceSection($section->id))->handle(
            app(SectionPublicationHandlerFactory::class)
        );

        $section->refresh();
        $this->assertEquals(ServiceSectionPublicationStatus::Published, $section->publication_status);
        $this->assertNotNull($section->published_at);

        $songVideo = SongVideo::query()->where('service_section_id', $section->id)->first();
        $this->assertNotNull($songVideo);
        $this->assertEquals($songId, $songVideo->song_id);
    }

    #[Test]
    public function it_withholds_a_section_held_for_review_after_the_job_was_queued(): void
    {
        // The window this closes: preparation judged the section eligible and
        // queued the job, then the 2026-09-09 repetition screen held it. The
        // transition table does not help — `not_applicable` to `published` is
        // allowed, and 157 of the 194 held sections sit in exactly that state.
        [$section] = $this->publishableSongSection();
        $section->forceFill(['needs_manual_review' => true])->save();

        (new AutoPublishServiceSection($section->id))->handle(
            app(SectionPublicationHandlerFactory::class)
        );

        $section->refresh();
        $this->assertEquals(ServiceSectionPublicationStatus::NotApplicable, $section->publication_status);
        $this->assertNull(SongVideo::query()->where('service_section_id', $section->id)->first());
    }

    #[Test]
    public function it_withholds_a_section_whose_run_was_retired_after_the_job_was_queued(): void
    {
        // A retired run's result is withdrawn, so publishing from it would
        // republish a conclusion the import has already taken back.
        [$section, $processingLog] = $this->publishableSongSection();
        $processingLog->forceFill(['superseded_at' => now()])->save();

        (new AutoPublishServiceSection($section->id))->handle(
            app(SectionPublicationHandlerFactory::class)
        );

        $section->refresh();
        $this->assertEquals(ServiceSectionPublicationStatus::NotApplicable, $section->publication_status);
        $this->assertNull(SongVideo::query()->where('service_section_id', $section->id)->first());
    }

    #[Test]
    public function it_withholds_a_section_that_has_lost_its_handler_eligibility(): void
    {
        // A song whose match was withdrawn to unmatched is no longer eligible —
        // inferred still is — and the queued job has no way to know unless it
        // asks again.
        [$section] = $this->publishableSongSection();
        $section->forceFill(['song_match_type' => ServiceSectionSongMatchType::Unmatched->value])->save();

        (new AutoPublishServiceSection($section->id))->handle(
            app(SectionPublicationHandlerFactory::class)
        );

        $section->refresh();
        $this->assertEquals(ServiceSectionPublicationStatus::NotApplicable, $section->publication_status);
        $this->assertNull(SongVideo::query()->where('service_section_id', $section->id)->first());
    }

    #[Test]
    public function it_withholds_a_section_that_came_to_require_approval_and_leaves_its_candidate_in_place(): void
    {
        // Repair pass 2026-10-10, run 1223: preparation queued the job, the section then
        // went to `pending_approval`, and the handler moved each candidate to the song path
        // before the transition table refused. Three failed jobs, three rows naming a file
        // that was no longer there.
        [$section] = $this->publishableSongSection();
        $section->forceFill(['publication_status' => ServiceSectionPublicationStatus::PendingApproval->value, 'end_time' => 120.0, 'duration' => 20.0])->save();

        (new AutoPublishServiceSection($section->id))->handle(
            app(SectionPublicationHandlerFactory::class)
        );

        $section->refresh();
        $this->assertEquals(ServiceSectionPublicationStatus::PendingApproval, $section->publication_status);
        $this->assertSame('section-publications/guard/video.mp4', $section->extracted_video_path);
        Storage::disk('public')->assertExists('section-publications/guard/video.mp4');
        $this->assertNull(SongVideo::query()->where('service_section_id', $section->id)->first());
    }

    #[Test]
    public function it_still_publishes_a_section_that_is_genuinely_unchanged(): void
    {
        // The guard must not become a refusal to publish anything: these are the
        // same questions preparation asked, and a section that still answers
        // them the same way publishes exactly as before.
        [$section] = $this->publishableSongSection();

        (new AutoPublishServiceSection($section->id))->handle(
            app(SectionPublicationHandlerFactory::class)
        );

        $section->refresh();
        $this->assertEquals(ServiceSectionPublicationStatus::Published, $section->publication_status);
        $this->assertNotNull(SongVideo::query()->where('service_section_id', $section->id)->first());
    }

    /** @return array{0: ServiceSection, 1: MediaProcessingLog} */
    private function publishableSongSection(): array
    {
        Storage::fake('local');
        Storage::fake('public');
        config(['media-processing.storage.sermon_disk' => 'public']);
        $section = $this->automaticSong();
        Storage::disk('public')->move($section->extracted_video_path, 'section-publications/guard/video.mp4');
        $section->update([
            'publication_status' => ServiceSectionPublicationStatus::NotApplicable,
            'extracted_video_path' => 'section-publications/guard/video.mp4',
        ]);

        return [$section->fresh(), $section->processingLog];
    }

    #[Test]
    public function it_skips_already_published_sections(): void
    {
        $section = ServiceSection::factory()->create([
            'section_type' => ServiceSectionType::Song->value,
            'publication_status' => ServiceSectionPublicationStatus::Published->value,
        ]);

        $initialCount = SongVideo::count();

        (new AutoPublishServiceSection($section->id))->handle(
            app(SectionPublicationHandlerFactory::class)
        );

        $this->assertEquals($initialCount, SongVideo::count());
    }

    #[Test]
    public function it_skips_when_publishing_is_disabled(): void
    {
        config(['media-processing.section_publishing.enabled' => false]);

        $section = ServiceSection::factory()->create([
            'section_type' => ServiceSectionType::Song->value,
            'publication_status' => ServiceSectionPublicationStatus::NotApplicable->value,
        ]);

        (new AutoPublishServiceSection($section->id))->handle(
            app(SectionPublicationHandlerFactory::class)
        );

        $section->refresh();
        $this->assertEquals(ServiceSectionPublicationStatus::NotApplicable, $section->publication_status);
    }

    #[Test]
    public function it_throws_when_no_handler_registered(): void
    {
        config(['media-processing.section_publishing.handlers' => []]);

        $section = ServiceSection::factory()->create([
            'section_type' => ServiceSectionType::Song->value,
            'publication_status' => ServiceSectionPublicationStatus::NotApplicable->value,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No publication handler registered');

        (new AutoPublishServiceSection($section->id))->handle(
            app(SectionPublicationHandlerFactory::class)
        );
    }

    #[Test]
    public function it_handles_missing_section_gracefully(): void
    {
        // A missing section id is a no-op rather than an error.
        $this->expectNotToPerformAssertions();

        (new AutoPublishServiceSection(99999))->handle(
            app(SectionPublicationHandlerFactory::class)
        );
    }
}
