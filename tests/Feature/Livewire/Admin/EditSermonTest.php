<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Admin;

use App\Data\ThumbnailResult;
use App\Enums\PreacherSource;
use App\Enums\SermonContentType;
use App\Enums\SermonService;
use App\Enums\SermonVideoQualityStatus;
use App\Enums\SermonVideoVisibilityOverride;
use App\Jobs\AssessSermonVideoQuality;
use App\Livewire\Admin\Sermons\EditSermon;
use App\Livewire\Admin\Sermons\EditSermonThumbnails;
use App\Models\Preacher;
use App\Models\Sermon;
use App\Models\User;
use App\Services\Media\Thumbnail\ThumbnailGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EditSermonTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Sermon $sermon;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();

        $this->sermon = Sermon::factory()->create([
            'title' => 'Original Title',
            'slug' => 'original-title',
            'date' => '2025-06-15',
            'service' => SermonService::Morning->value,
            'preacher' => 'John Smith',
            'reference' => 'John 3:16',
            'series' => null,
            'summary' => null,
            'points' => null,
            'show_summary' => true,
            'show_points' => true,
        ]);
    }

    // -------------------------------------------------------------------------
    // Rendering & mount
    // -------------------------------------------------------------------------

    #[Test]
    public function it_renders_with_sermon_data_pre_populated(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(EditSermon::class, ['sermon' => $this->sermon])
            ->assertSet('form.title', 'Original Title')
            ->assertSet('form.slug', 'original-title')
            ->assertSet('form.preacher', 'John Smith')
            ->assertSet('form.reference', 'John 3:16')
            ->assertStatus(200);
    }

    // -------------------------------------------------------------------------
    // Slug auto-update
    // -------------------------------------------------------------------------

    #[Test]
    public function slug_can_be_updated_via_form(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(EditSermon::class, ['sermon' => $this->sermon])
            ->set('form.slug', 'custom-seo-slug')
            ->call('save');

        $this->assertEquals('custom-seo-slug', $this->sermon->fresh()->slug);
    }

    #[Test]
    public function generated_slug_tracks_title_until_slug_is_edited_manually(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(EditSermon::class, ['sermon' => $this->sermon])
            ->set('form.title', 'First Sermon Title')
            ->assertSet('form.slug', 'first-sermon-title')
            ->set('form.title', 'Second Sermon Title')
            ->assertSet('form.slug', 'second-sermon-title')
            ->set('form.slug', 'custom-sermon-slug')
            ->set('form.title', 'Third Sermon Title')
            ->assertSet('form.slug', 'custom-sermon-slug');
    }

    #[Test]
    public function it_renders_saved_thumbnail_candidates(): void
    {
        $this->actingAs($this->admin);

        $this->sermon->update([
            'video_file_path' => 'sermons/1/video.mp4',
            'thumbnail_file_path' => 'sermons/thumbnails/candidate-2-overlay.webp',
            'thumbnail_metadata' => [
                'selected_thumbnail_candidate_id' => 'candidate-2',
                'thumbnail_candidates' => [
                    [
                        'id' => 'candidate-1',
                        'timestamp' => 120.0,
                        'score' => 0.81,
                        'overlay_path' => 'sermons/thumbnails/candidate-1-overlay.webp',
                        'plain_path' => 'sermons/thumbnails/candidate-1-plain.webp',
                    ],
                    [
                        'id' => 'candidate-2',
                        'timestamp' => 240.0,
                        'score' => 0.93,
                        'overlay_path' => 'sermons/thumbnails/candidate-2-overlay.webp',
                        'plain_path' => 'sermons/thumbnails/candidate-2-plain.webp',
                    ],
                ],
            ],
        ]);

        Livewire::test(EditSermonThumbnails::class, ['sermon' => $this->sermon])
            ->assertSee('Thumbnail options')
            ->assertSee('Frame 1')
            ->assertSee('Frame 2')
            ->assertSee('The branded main thumbnail and card thumbnail are generated for the selected option.')
            ->assertSee('Selected');
    }

    // -------------------------------------------------------------------------
    // Save — happy path
    // -------------------------------------------------------------------------

    #[Test]
    public function it_saves_updated_sermon_fields(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(EditSermon::class, ['sermon' => $this->sermon])
            ->set('form.title', 'Updated Title')
            ->set('form.slug', 'updated-title')
            ->set('form.date', '2025-07-01')
            ->set('form.service', SermonService::Evening->value)
            ->set('form.preacherId', null)
            ->set('form.preacher', 'David Johnson')
            ->set('form.reference', 'Romans 8:28')
            ->call('save')
            ->assertDispatched('notify', type: 'success', message: 'Sermon updated');

        $this->sermon->refresh();
        $this->assertEquals('Updated Title', $this->sermon->title);
        $this->assertEquals('updated-title', $this->sermon->slug);
        $this->assertEquals('David Johnson', $this->sermon->preacher);
        $this->assertEquals('Romans 8:28', $this->sermon->reference);
    }

    #[Test]
    public function it_links_sermon_to_preacher_model_when_preacher_id_is_set(): void
    {
        $this->actingAs($this->admin);

        $preacher = Preacher::factory()->create([
            'name' => 'Mark Drury Admin Edit',
            'slug' => 'mark-drury-admin-edit',
        ]);

        Livewire::test(EditSermon::class, ['sermon' => $this->sermon])
            ->set('form.preacherId', $preacher->id)
            ->set('form.preacher', $preacher->name)
            ->call('save')
            ->assertDispatched('notify', type: 'success', message: 'Sermon updated');

        $this->sermon->refresh();
        $this->assertEquals($preacher->id, $this->sermon->preacher_id);
        $this->assertEquals(PreacherSource::Manual, $this->sermon->preacher_source);
        $this->assertFalse($this->sermon->needs_preacher_review);
    }

    #[Test]
    public function it_updates_the_selected_thumbnail_candidate_immediately(): void
    {
        $this->actingAs($this->admin);

        $this->sermon->update([
            'thumbnail_file_path' => 'sermons/thumbnails/candidate-1-overlay.webp',
            'thumbnail_metadata' => [
                'selected_thumbnail_candidate_id' => 'candidate-1',
                'plain_thumbnail_path' => 'sermons/thumbnails/candidate-1-plain.webp',
                'overlay_thumbnail_path' => 'sermons/thumbnails/candidate-1-overlay.webp',
                'thumbnail_candidates' => [
                    [
                        'id' => 'candidate-1',
                        'timestamp' => 120.0,
                        'score' => 0.81,
                        'overlay_path' => 'sermons/thumbnails/candidate-1-overlay.webp',
                        'plain_path' => 'sermons/thumbnails/candidate-1-plain.webp',
                    ],
                    [
                        'id' => 'candidate-2',
                        'timestamp' => 240.0,
                        'score' => 0.93,
                        'overlay_path' => 'sermons/thumbnails/candidate-2-overlay.webp',
                        'plain_path' => 'sermons/thumbnails/candidate-2-plain.webp',
                    ],
                ],
            ],
        ]);

        $mockService = $this->createMock(ThumbnailGenerationService::class);
        $mockService->expects($this->once())
            ->method('renderSelectedThumbnailCandidate')
            ->with($this->callback(fn (Sermon $model): bool => $model->is($this->sermon)), 'candidate-2')
            ->willReturn(ThumbnailResult::success(
                'sermons/thumbnails/candidate-2-overlay.webp',
                [
                    'selected_thumbnail_candidate_id' => 'candidate-2',
                    'plain_thumbnail_path' => 'sermons/thumbnails/candidate-2-plain.webp',
                    'overlay_thumbnail_path' => 'sermons/thumbnails/candidate-2-overlay.webp',
                    'thumbnail_candidates' => [
                        [
                            'id' => 'candidate-1',
                            'timestamp' => 120.0,
                            'score' => 0.81,
                            'overlay_path' => 'sermons/thumbnails/candidate-1-overlay.webp',
                            'plain_path' => 'sermons/thumbnails/candidate-1-plain.webp',
                        ],
                        [
                            'id' => 'candidate-2',
                            'timestamp' => 240.0,
                            'score' => 0.93,
                            'overlay_path' => 'sermons/thumbnails/candidate-2-overlay.webp',
                            'plain_path' => 'sermons/thumbnails/candidate-2-plain.webp',
                            'composition_mode' => 'layered_subject',
                            'foreground_extraction_method' => 'pixian_api',
                        ],
                    ],
                    'composition_mode' => 'layered_subject',
                    'foreground_extraction_method' => 'pixian_api',
                ],
            ));

        app()->instance(ThumbnailGenerationService::class, $mockService);

        Livewire::test(EditSermonThumbnails::class, ['sermon' => $this->sermon])
            ->call('selectThumbnailCandidate', 'candidate-2')
            ->assertDispatched('notify', type: 'success', message: 'Thumbnail updated');

        $this->sermon->refresh();
        $this->assertSame('sermons/thumbnails/candidate-2-overlay.webp', $this->sermon->thumbnail_file_path);
        $this->assertSame('candidate-2', $this->sermon->thumbnail_metadata?->selectedThumbnailCandidateId);
        $this->assertSame('sermons/thumbnails/candidate-2-plain.webp', $this->sermon->thumbnail_metadata?->plainThumbnailPath);
    }

    #[Test]
    public function it_renders_plain_only_candidates_and_generates_overlay_when_selected(): void
    {
        $this->actingAs($this->admin);

        $this->sermon->update([
            'thumbnail_file_path' => 'sermons/thumbnails/candidate-1-overlay.webp',
            'thumbnail_metadata' => [
                'selected_thumbnail_candidate_id' => 'candidate-1',
                'plain_thumbnail_path' => 'sermons/thumbnails/candidate-1-plain.webp',
                'overlay_thumbnail_path' => 'sermons/thumbnails/candidate-1-overlay.webp',
                'thumbnail_candidates' => [
                    [
                        'id' => 'candidate-1',
                        'timestamp' => 120.0,
                        'score' => 0.81,
                        'overlay_path' => 'sermons/thumbnails/candidate-1-overlay.webp',
                        'plain_path' => 'sermons/thumbnails/candidate-1-plain.webp',
                    ],
                    [
                        'id' => 'candidate-2',
                        'timestamp' => 240.0,
                        'score' => 0.93,
                        'plain_path' => 'sermons/thumbnails/candidate-2-plain.webp',
                    ],
                ],
            ],
        ]);

        $mockService = $this->createMock(ThumbnailGenerationService::class);
        $mockService->expects($this->once())
            ->method('renderSelectedThumbnailCandidate')
            ->with($this->callback(fn (Sermon $model): bool => $model->is($this->sermon)), 'candidate-2')
            ->willReturn(ThumbnailResult::success(
                'sermons/thumbnails/candidate-2-overlay.webp',
                [
                    'selected_thumbnail_candidate_id' => 'candidate-2',
                    'plain_thumbnail_path' => 'sermons/thumbnails/candidate-2-plain.webp',
                    'overlay_thumbnail_path' => 'sermons/thumbnails/candidate-2-overlay.webp',
                    'thumbnail_candidates' => [
                        [
                            'id' => 'candidate-1',
                            'timestamp' => 120.0,
                            'score' => 0.81,
                            'overlay_path' => 'sermons/thumbnails/candidate-1-overlay.webp',
                            'plain_path' => 'sermons/thumbnails/candidate-1-plain.webp',
                        ],
                        [
                            'id' => 'candidate-2',
                            'timestamp' => 240.0,
                            'score' => 0.93,
                            'overlay_path' => 'sermons/thumbnails/candidate-2-overlay.webp',
                            'plain_path' => 'sermons/thumbnails/candidate-2-plain.webp',
                            'composition_mode' => 'layered_subject',
                            'foreground_extraction_method' => 'pixian_api',
                        ],
                    ],
                    'composition_mode' => 'layered_subject',
                    'foreground_extraction_method' => 'pixian_api',
                ],
            ));

        app()->instance(ThumbnailGenerationService::class, $mockService);

        Livewire::test(EditSermonThumbnails::class, ['sermon' => $this->sermon])
            ->assertSee('Frame 2')
            ->call('selectThumbnailCandidate', 'candidate-2')
            ->assertDispatched('notify', type: 'success', message: 'Thumbnail updated');

        $this->sermon->refresh();
        $this->assertSame('sermons/thumbnails/candidate-2-overlay.webp', $this->sermon->thumbnail_file_path);
        $this->assertSame('candidate-2', $this->sermon->thumbnail_metadata?->selectedThumbnailCandidateId);
    }

    #[Test]
    public function it_regenerates_thumbnails_from_the_edit_screen(): void
    {
        $this->actingAs($this->admin);

        $this->sermon->update([
            'video_file_path' => 'sermons/1/video.mp4',
        ]);

        $mockService = $this->createMock(ThumbnailGenerationService::class);
        $mockService->expects($this->once())
            ->method('regenerateThumbnail')
            ->with($this->callback(fn (Sermon $model): bool => $model->is($this->sermon)))
            ->willReturn(ThumbnailResult::success(
                'sermons/thumbnails/candidate-3-overlay.webp',
                [
                    'plain_thumbnail_path' => 'sermons/thumbnails/candidate-3-plain.webp',
                    'overlay_thumbnail_path' => 'sermons/thumbnails/candidate-3-overlay.webp',
                    'selected_thumbnail_candidate_id' => 'candidate-3',
                    'thumbnail_candidates' => [
                        [
                            'id' => 'candidate-3',
                            'timestamp' => 360.0,
                            'score' => 0.95,
                            'overlay_path' => 'sermons/thumbnails/candidate-3-overlay.webp',
                            'plain_path' => 'sermons/thumbnails/candidate-3-plain.webp',
                        ],
                    ],
                ],
            ));

        app()->instance(ThumbnailGenerationService::class, $mockService);

        Livewire::test(EditSermonThumbnails::class, ['sermon' => $this->sermon])
            ->call('regenerateThumbnails')
            ->assertDispatched('notify', type: 'success', message: 'Thumbnails regenerated');

        $this->sermon->refresh();
        $this->assertSame('sermons/thumbnails/candidate-3-overlay.webp', $this->sermon->thumbnail_file_path);
        $this->assertSame('candidate-3', $this->sermon->thumbnail_metadata?->selectedThumbnailCandidateId);
    }

    #[Test]
    public function it_updates_video_visibility_override_from_the_edit_screen(): void
    {
        $this->actingAs($this->admin);

        $this->sermon->update([
            'video_file_path' => 'sermons/1/video.mp4',
            'video_quality_status' => SermonVideoQualityStatus::Rejected,
        ]);

        Livewire::test(EditSermon::class, ['sermon' => $this->sermon])
            ->assertSee('Video quality')
            ->call('setVideoVisibilityOverride', 'force_show')
            ->assertDispatched('notify', type: 'success', message: 'Video visibility override updated');

        $this->sermon->refresh();
        $this->assertSame(SermonVideoVisibilityOverride::ForceShow, $this->sermon->video_visibility_override);
    }

    #[Test]
    public function it_reruns_video_quality_assessment_from_the_edit_screen(): void
    {
        $this->actingAs($this->admin);
        Queue::fake();

        $this->sermon->update([
            'video_file_path' => 'sermons/1/video.mp4',
        ]);

        Livewire::test(EditSermon::class, ['sermon' => $this->sermon])
            ->call('rerunVideoQualityAssessment')
            ->assertDispatched('notify', type: 'success', message: 'Video quality assessment queued');

        Queue::assertPushedOn('video-processing', AssessSermonVideoQuality::class);
    }

    #[Test]
    public function it_refreshes_queued_video_quality_assessment_results(): void
    {
        $this->actingAs($this->admin);

        $this->sermon->update([
            'video_file_path' => 'sermons/1/video.mp4',
            'video_quality_status' => SermonVideoQualityStatus::Unassessed,
        ]);

        $component = Livewire::test(EditSermon::class, ['sermon' => $this->sermon]);

        $this->assertEquals(SermonVideoQualityStatus::Unassessed, $component->get('sermon')->video_quality_status);

        $this->sermon->update([
            'video_quality_status' => SermonVideoQualityStatus::Approved,
            'video_quality_assessed_at' => now(),
        ]);

        $component->call('refreshVideoQualityAssessment');

        // refreshVideoQualityAssessment() calls $this->sermon->refresh() so the model
        // reflects the updated DB state. The video quality card lives inside @island
        // which re-renders independently; assert on the model property instead of HTML.
        $this->assertEquals(SermonVideoQualityStatus::Approved, $component->get('sermon')->video_quality_status);
    }

    // -------------------------------------------------------------------------
    // Points management
    // -------------------------------------------------------------------------

    #[Test]
    public function it_can_add_a_point(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(EditSermon::class, ['sermon' => $this->sermon])
            ->call('addPoint')
            ->assertCount('form.points', 1);
    }

    #[Test]
    public function it_can_remove_a_point(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(EditSermon::class, ['sermon' => $this->sermon])
            ->call('addPoint')
            ->call('addPoint')
            ->assertCount('form.points', 2)
            ->call('removePoint', 0)
            ->assertCount('form.points', 1);
    }

    // -------------------------------------------------------------------------
    // Validation
    // -------------------------------------------------------------------------

    #[Test]
    public function it_validates_required_fields(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(EditSermon::class, ['sermon' => $this->sermon])
            ->set('form.title', '')
            ->set('form.slug', '')
            ->set('form.date', '')
            ->set('form.preacher', '')
            ->call('save')
            ->assertHasErrors(['form.title', 'form.slug', 'form.date', 'form.preacher']);
    }

    #[Test]
    public function it_validates_slug_uniqueness_against_other_sermons(): void
    {
        $this->actingAs($this->admin);

        $other = Sermon::factory()->create(['slug' => 'taken-slug']);

        Livewire::test(EditSermon::class, ['sermon' => $this->sermon])
            ->set('form.slug', 'taken-slug')
            ->call('save')
            ->assertHasErrors(['form.slug']);
    }

    #[Test]
    public function it_allows_saving_with_the_sermons_own_slug(): void
    {
        $this->actingAs($this->admin);

        // The unique rule should exclude the current sermon's own ID
        Livewire::test(EditSermon::class, ['sermon' => $this->sermon])
            ->set('form.slug', 'original-title')
            ->call('save')
            ->assertHasNoErrors(['slug']);
    }

    #[Test]
    public function it_adapts_the_edit_surface_for_childrens_talks(): void
    {
        $this->actingAs($this->admin);

        $talk = Sermon::factory()->create([
            'title' => 'Talk To Edit',
            'content_type' => SermonContentType::ChildrensTalk,
            'reference' => 'John 3:16',
            'show_summary' => true,
            'show_points' => true,
        ]);

        Livewire::test(EditSermon::class, ['sermon' => $talk])
            ->assertSee("Edit Children's Talk")
            ->assertSee('Speaker')
            ->assertSee("Children's talk notes")
            ->assertDontSee('Bible reference')
            ->assertDontSee('AI-generated content')
            ->assertDontSee('Display options');
    }

    #[Test]
    public function it_hides_thumbnail_regeneration_when_no_video_is_available(): void
    {
        $this->actingAs($this->admin);

        $this->sermon->update([
            'video_file_path' => null,
        ]);

        Livewire::test(EditSermonThumbnails::class, ['sermon' => $this->sermon])
            ->assertDontSee('Regenerate 5 options')
            ->assertSee('A video file is required before thumbnail options can be generated.');
    }

    #[Test]
    public function it_relies_on_route_middleware_for_access_control(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $sermon = Sermon::factory()->create();

        $this->actingAs($user);

        // Route middleware (auth, verified, admin) enforces access at the HTTP layer.
        // AdminLivewireAuthorizationTest covers this. Direct component mount is unrestricted.
        Livewire::test(EditSermon::class, ['sermon' => $sermon])
            ->assertOk();
    }
}
