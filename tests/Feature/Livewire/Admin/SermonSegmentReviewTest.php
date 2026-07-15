<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Admin;

use App\Actions\ConfirmLivestreamSermonSegment;
use App\Livewire\Admin\SermonSegmentReview;
use App\Models\LivestreamSegment;
use App\Models\MediaProcessingLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonSegmentReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['media-processing.storage.temp_disk' => 'local']);

        $this->admin = User::factory()->create([
            'is_admin' => true,
            'email_verified_at' => now(),
        ]);
    }

    #[Test]
    public function it_renders_and_confirms_without_service_tracking(): void
    {
        config(['service-tracking.enabled' => false]);

        $run = MediaProcessingLog::factory()->livestream()->manualReviewRequired()->create();
        Storage::disk('local')->put((string) $run->source_file_path, 'video');

        $segment = LivestreamSegment::factory()->speech()->create([
            'media_processing_log_id' => $run->id,
            'segment_index' => 0,
        ]);

        $this->mock(ConfirmLivestreamSermonSegment::class, function ($mock) use ($run, $segment): void {
            $mock->shouldReceive('execute')
                ->once()
                ->with($run->processing_id, $segment->id, \Mockery::type(User::class));
        });

        Livewire::actingAs($this->admin)
            ->test(SermonSegmentReview::class, ['processingLog' => $run])
            ->assertSee('Choose sermon segment')
            ->assertSee('This is the sermon')
            ->call('confirmSegment', $segment->id)
            ->assertDispatched('notify', type: 'success');
    }

    #[Test]
    public function its_route_rejects_non_segmentation_runs(): void
    {
        $run = MediaProcessingLog::factory()->audio()->failed()->create();

        $this->actingAs($this->admin)
            ->get(route('admin.recordings.sermon-segment', $run->processing_id))
            ->assertNotFound();
    }

    #[Test]
    public function its_route_is_admin_only(): void
    {
        $run = MediaProcessingLog::factory()->livestream()->manualReviewRequired()->create();
        $member = User::factory()->create([
            'is_admin' => false,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($member)
            ->get(route('admin.recordings.sermon-segment', $run->processing_id))
            ->assertForbidden();
    }
}
