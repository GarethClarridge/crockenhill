<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Actions\ConfirmLivestreamSermonSegment;
use App\Enums\ProcessingStatus;
use App\Livewire\Admin\ChurchServices\ProcessingReview;
use App\Livewire\Admin\ChurchServices\ProcessingReviewList;
use App\Models\LivestreamSegment;
use App\Models\MediaProcessingLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProcessingReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $nonAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'is_admin' => true,
            'email_verified_at' => now(),
        ]);

        $this->nonAdmin = User::factory()->create([
            'is_admin' => false,
            'email_verified_at' => now(),
        ]);
    }

    // ── ProcessingReviewList tests ──────────────────────────────────────────

    #[Test]
    public function review_list_shows_flagged_livestream_runs_only(): void
    {
        $this->actingAs($this->admin);

        $flagged = MediaProcessingLog::factory()->livestream()->create([
            'status' => ProcessingStatus::FAILED,
            'current_step' => 'manual_review_required',
            'processing_metadata' => [
                'manual_review' => [
                    'status' => 'required',
                    'reason_code' => 'multiple_qualifying_speech_blocks',
                    'reason_message' => 'Ambiguous blocks.',
                    'flagged_at' => now()->toIso8601String(),
                    'speech_segments' => [],
                ],
            ],
        ]);

        // A completed run — should not appear
        MediaProcessingLog::factory()->livestream()->completed()->create();

        // A non-livestream failed run — should not appear
        MediaProcessingLog::factory()->audio()->create([
            'status' => ProcessingStatus::FAILED,
            'current_step' => 'manual_review_required',
        ]);

        Livewire::test(ProcessingReviewList::class)
            ->assertSee($flagged->processing_id)
            ->assertSee('Awaiting review')
            ->assertDontSee('No livestream runs are awaiting manual review');
    }

    #[Test]
    public function review_list_shows_empty_state_when_no_flagged_runs(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(ProcessingReviewList::class)
            ->assertSee('No livestream runs are awaiting manual review');
    }

    #[Test]
    public function review_list_requires_admin(): void
    {
        $this->actingAs($this->nonAdmin);

        Livewire::test(ProcessingReviewList::class)
            ->assertForbidden();
    }

    #[Test]
    public function review_list_route_is_accessible_to_admin(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.services.processing.review.index'))
            ->assertOk()
            ->assertSeeLivewire(ProcessingReviewList::class);
    }

    // ── ProcessingReview detail tests ───────────────────────────────────────

    #[Test]
    public function review_detail_renders_segments_and_reason(): void
    {
        $this->actingAs($this->admin);

        $log = MediaProcessingLog::factory()->livestream()->create([
            'status' => ProcessingStatus::FAILED,
            'current_step' => 'manual_review_required',
            'processing_metadata' => [
                'manual_review' => [
                    'status' => 'required',
                    'reason_code' => 'multiple_qualifying_speech_blocks',
                    'reason_message' => 'Two speech blocks were roughly equal in length.',
                    'flagged_at' => now()->toIso8601String(),
                    'speech_segments' => [],
                ],
            ],
        ]);

        $speechSegment = LivestreamSegment::factory()->speech()->create([
            'media_processing_log_id' => $log->id,
            'segment_index' => 0,
            'start_time' => 0.0,
            'end_time' => 1200.0,
            'duration' => 1200.0,
            'is_sermon_candidate' => true,
        ]);

        LivestreamSegment::factory()->create([
            'media_processing_log_id' => $log->id,
            'segment_index' => 1,
            'classification' => 'song',
            'start_time' => 1200.0,
            'end_time' => 1500.0,
            'duration' => 300.0,
        ]);

        Livewire::test(ProcessingReview::class, ['processingLog' => $log])
            ->assertSee($log->processing_id)
            ->assertSee('Two speech blocks were roughly equal in length.')
            ->assertSee('Speech')
            ->assertSee('Song')
            ->assertSee('Candidate')
            ->assertSee('This is the sermon');
    }

    #[Test]
    public function review_detail_shows_confirm_button_only_on_speech_segments(): void
    {
        $this->actingAs($this->admin);

        $log = $this->makeLogAwaitingReview();

        LivestreamSegment::factory()->speech()->create([
            'media_processing_log_id' => $log->id,
            'segment_index' => 0,
        ]);

        LivestreamSegment::factory()->create([
            'media_processing_log_id' => $log->id,
            'segment_index' => 1,
            'classification' => 'song',
        ]);

        $component = Livewire::test(ProcessingReview::class, ['processingLog' => $log]);

        // "This is the sermon" appears once (for the speech segment only)
        $this->assertEquals(1, substr_count($component->html(), 'This is the sermon'));
    }

    #[Test]
    public function review_detail_dispatches_confirm_and_redirects_to_queue(): void
    {
        $this->actingAs($this->admin);

        $log = $this->makeLogAwaitingReview();

        $speechSegment = LivestreamSegment::factory()->speech()->create([
            'media_processing_log_id' => $log->id,
            'segment_index' => 0,
        ]);

        $this->mock(ConfirmLivestreamSermonSegment::class, function ($mock) use ($log, $speechSegment): void {
            $mock->shouldReceive('execute')
                ->once()
                ->with($log->processing_id, $speechSegment->id, \Mockery::type(User::class));
        });

        Livewire::test(ProcessingReview::class, ['processingLog' => $log])
            ->call('confirmSegment', $speechSegment->id)
            ->assertRedirect(route('admin.services.processing.review.index'));
    }

    #[Test]
    public function review_detail_shows_error_when_confirmation_fails(): void
    {
        $this->actingAs($this->admin);

        $log = $this->makeLogAwaitingReview();

        $speechSegment = LivestreamSegment::factory()->speech()->create([
            'media_processing_log_id' => $log->id,
            'segment_index' => 0,
        ]);

        $this->mock(ConfirmLivestreamSermonSegment::class, function ($mock): void {
            $mock->shouldReceive('execute')
                ->once()
                ->andThrow(new \InvalidArgumentException('The source video file is no longer available.'));
        });

        Livewire::test(ProcessingReview::class, ['processingLog' => $log])
            ->call('confirmSegment', $speechSegment->id)
            ->assertDispatched('notify', type: 'error', message: 'The source video file is no longer available.')
            ->assertNoRedirect();
    }

    #[Test]
    public function review_detail_requires_admin(): void
    {
        $this->actingAs($this->nonAdmin);

        $log = $this->makeLogAwaitingReview();

        Livewire::test(ProcessingReview::class, ['processingLog' => $log])
            ->assertForbidden();
    }

    #[Test]
    public function review_detail_aborts_for_non_livestream_log(): void
    {
        $this->actingAs($this->admin);

        $audioLog = MediaProcessingLog::factory()->audio()->create([
            'status' => ProcessingStatus::FAILED,
            'current_step' => 'manual_review_required',
        ]);

        Livewire::test(ProcessingReview::class, ['processingLog' => $audioLog])
            ->assertStatus(404);
    }

    #[Test]
    public function review_detail_route_is_accessible_to_admin(): void
    {
        $this->actingAs($this->admin);

        $log = $this->makeLogAwaitingReview();

        $this->get(route('admin.services.processing.review', $log))
            ->assertOk()
            ->assertSeeLivewire(ProcessingReview::class);
    }

    // ── Mail tests ───────────────────────────────────────────────────────────

    #[Test]
    public function manual_review_email_contains_correct_review_url(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->create([
            'status' => ProcessingStatus::FAILED,
            'current_step' => 'manual_review_required',
        ]);

        $mail = new \App\Mail\ManualReviewRequired($log->processing_id, 'Test reason');
        $rendered = $mail->render();

        $expectedUrl = route('admin.services.processing.review', $log);
        $this->assertStringContainsString($expectedUrl, $rendered);
    }

    #[Test]
    public function manual_review_email_falls_back_to_queue_url_when_log_not_found(): void
    {
        $mail = new \App\Mail\ManualReviewRequired('nonexistent-id', 'Test reason');
        $rendered = $mail->render();

        $expectedUrl = route('admin.services.processing.review.index');
        $this->assertStringContainsString($expectedUrl, $rendered);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function makeLogAwaitingReview(): MediaProcessingLog
    {
        return MediaProcessingLog::factory()->livestream()->create([
            'status' => ProcessingStatus::FAILED,
            'current_step' => 'manual_review_required',
            'processing_metadata' => [
                'manual_review' => [
                    'status' => 'required',
                    'reason_code' => 'multiple_qualifying_speech_blocks',
                    'reason_message' => 'Ambiguous blocks.',
                    'flagged_at' => now()->toIso8601String(),
                    'speech_segments' => [],
                ],
            ],
        ]);
    }
}
