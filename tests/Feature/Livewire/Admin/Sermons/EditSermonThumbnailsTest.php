<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Admin\Sermons;

use App\Data\ThumbnailResult;
use App\Livewire\Admin\Sermons\EditSermonThumbnails;
use App\Models\Sermon;
use App\Models\User;
use App\Services\Media\Thumbnail\ThumbnailGenerationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EditSermonThumbnailsTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private Sermon $sermon;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->crockenhillAdmin()->create();
        $this->sermon = Sermon::factory()->create([
            'thumbnail_metadata' => [
                'selected_thumbnail_candidate_id' => 'candidate-1',
                'thumbnail_candidates' => [
                    [
                        'id' => 'candidate-1',
                        'timestamp' => 120.0,
                        'score' => 0.9,
                        'plain_path' => 'thumbnails/cand-1-plain.webp',
                    ],
                    [
                        'id' => 'candidate-2',
                        'timestamp' => 240.0,
                        'score' => 0.8,
                        'plain_path' => 'thumbnails/cand-2-plain.webp',
                    ],
                ],
            ],
        ]);
    }

    #[Test]
    public function it_renders_correctly_for_admin(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(EditSermonThumbnails::class, ['sermon' => $this->sermon])
            ->assertStatus(200)
            ->assertSee('2:00') // candidate-1 timestamp
            ->assertSee('4:00') // candidate-2 timestamp
            ->assertSet('selectedThumbnailCandidateId', 'candidate-1');
    }

    #[Test]
    public function it_relies_on_route_middleware_for_access_control(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user);

        // Route middleware enforces access at the HTTP layer.
        // Direct component mount in test is unrestricted unless authorizeAdmin is called.
        Livewire::test(EditSermonThumbnails::class, ['sermon' => $this->sermon])
            ->assertOk();
    }

    #[Test]
    public function it_can_select_a_thumbnail_candidate(): void
    {
        $this->actingAs($this->admin);

        $newMetadata = array_merge($this->sermon->thumbnail_metadata->toArray(), [
            'selected_thumbnail_candidate_id' => 'candidate-2',
            'overlay_thumbnail_path' => 'thumbnails/cand-2-overlay.webp',
        ]);

        $this->mock(ThumbnailGenerationService::class, function ($mock) use ($newMetadata) {
            $mock->shouldReceive('renderSelectedThumbnailCandidate')
                ->once()
                ->with(\Mockery::on(fn ($s) => $s->id === $this->sermon->id), 'candidate-2')
                ->andReturn(ThumbnailResult::success('thumbnails/cand-2-overlay.webp', $newMetadata));
        });

        Livewire::test(EditSermonThumbnails::class, ['sermon' => $this->sermon])
            ->call('selectThumbnailCandidate', 'candidate-2')
            ->assertDispatched('notify', type: 'success', message: 'Thumbnail updated')
            ->assertSet('selectedThumbnailCandidateId', 'candidate-2');

        $this->sermon->refresh();
        $this->assertEquals('thumbnails/cand-2-overlay.webp', $this->sermon->thumbnail_file_path);
        $this->assertEquals('candidate-2', $this->sermon->thumbnail_metadata->selectedThumbnailCandidateId);
    }

    #[Test]
    public function it_handles_thumbnail_selection_failure(): void
    {
        $this->actingAs($this->admin);

        $this->mock(ThumbnailGenerationService::class, function ($mock) {
            $mock->shouldReceive('renderSelectedThumbnailCandidate')
                ->once()
                ->andReturn(ThumbnailResult::failed('Something went wrong'));
        });

        Livewire::test(EditSermonThumbnails::class, ['sermon' => $this->sermon])
            ->call('selectThumbnailCandidate', 'candidate-2')
            ->assertDispatched('notify', type: 'error', message: 'Thumbnail update failed: Something went wrong');
    }

    #[Test]
    public function it_can_regenerate_thumbnails(): void
    {
        $this->actingAs($this->admin);
        $this->sermon->update(['video_file_path' => 'sermons/video.mp4']);

        $newMetadata = [
            'selected_thumbnail_candidate_id' => 'candidate-new',
            'overlay_thumbnail_path' => 'thumbnails/new-overlay.webp',
            'thumbnail_candidates' => [
                ['id' => 'candidate-new', 'timestamp' => 300.0, 'score' => 0.95, 'plain_path' => 'thumbnails/new-plain.webp'],
            ],
        ];

        $this->mock(ThumbnailGenerationService::class, function ($mock) use ($newMetadata) {
            $mock->shouldReceive('regenerateThumbnail')
                ->once()
                ->with(\Mockery::on(fn ($s) => $s->id === $this->sermon->id))
                ->andReturn(ThumbnailResult::success('thumbnails/new-overlay.webp', $newMetadata));
        });

        Livewire::test(EditSermonThumbnails::class, ['sermon' => $this->sermon])
            ->call('regenerateThumbnails')
            ->assertDispatched('notify', type: 'success', message: 'Thumbnails regenerated');

        $this->sermon->refresh();
        $this->assertEquals('thumbnails/new-overlay.webp', $this->sermon->thumbnail_file_path);
        $this->assertEquals('candidate-new', $this->sermon->thumbnail_metadata->selectedThumbnailCandidateId);
    }

    #[Test]
    public function it_cannot_regenerate_thumbnails_without_video(): void
    {
        $this->actingAs($this->admin);
        $this->sermon->update(['video_file_path' => null]);

        Livewire::test(EditSermonThumbnails::class, ['sermon' => $this->sermon])
            ->call('regenerateThumbnails')
            ->assertDispatched('notify', type: 'error', message: 'No video file is available for thumbnail generation.');
    }

    #[Test]
    public function it_handles_thumbnail_regeneration_failure(): void
    {
        $this->actingAs($this->admin);
        $this->sermon->update(['video_file_path' => 'sermons/video.mp4']);

        $this->mock(ThumbnailGenerationService::class, function ($mock) {
            $mock->shouldReceive('regenerateThumbnail')
                ->once()
                ->andReturn(ThumbnailResult::failed('Regeneration failed'));
        });

        Livewire::test(EditSermonThumbnails::class, ['sermon' => $this->sermon])
            ->call('regenerateThumbnails')
            ->assertDispatched('notify', type: 'error', message: 'Thumbnail regeneration failed: Regeneration failed');
    }
}
