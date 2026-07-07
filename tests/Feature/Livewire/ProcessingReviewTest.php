<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Actions\ConfirmLivestreamSermonSegment;
use App\Enums\ProcessingStatus;
use App\Livewire\Admin\ChurchServices\ProcessingReview;
use App\Mail\ManualReviewRequired;
use App\Models\LivestreamSegment;
use App\Models\MediaProcessingLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\BuildsTestScenarios;

class ProcessingReviewTest extends TestCase
{
    use BuildsTestScenarios;
    use RefreshDatabase;

    private User $admin;

    private User $nonAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->admin = $this->createVerifiedAdmin();

        $this->nonAdmin = User::factory()->create([
            'is_admin' => false,
            'email_verified_at' => now(),
        ]);
    }

    // ── ProcessingReview detail tests ───────────────────────────────────────
    // The list page is retired (P5): its queue lives in the review inbox, with
    // the entries pinned by ReviewInboxTest. Only the orphan-run detail page
    // remains here.

    #[Test]
    public function review_detail_renders_segments_and_reason(): void
    {
        $this->actingAs($this->admin);

        $log = MediaProcessingLog::factory()->livestream()->create([
            'source_file_path' => 'livestreams/2026/review-detail.mp4',
            'status' => ProcessingStatus::Failed,
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
        Storage::disk('local')->put('livestreams/2026/review-detail.mp4', 'fake-video');

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
            ->assertSee('Available')
            ->assertSee('This is the sermon');
    }

    #[Test]
    public function review_detail_renders_the_rejected_structure_proposal(): void
    {
        $this->actingAs($this->admin);

        $log = $this->makeLogAwaitingReview();
        $metadata = $log->processing_metadata?->toArray() ?? [];
        $metadata['service_structure_proposal'] = [
            'generated_at' => now()->toIso8601String(),
            'model' => 'mock',
            'passed_validation' => false,
            'hard_failures' => [
                ['code' => 'multiple_sermons', 'message' => 'The structure contains 2 sermon sections; a service has at most one.'],
            ],
            'unmatched_oos_item_ids' => [],
            'sections' => [
                [
                    'section_type' => 'sermon',
                    'section_order' => 1,
                    'title' => 'The faithfulness of God',
                    'start_time' => 600.0,
                    'end_time' => 2200.0,
                    'confidence' => 0.97,
                    'metadata' => ['review_flags' => []],
                ],
                [
                    'section_type' => 'song',
                    'section_order' => 2,
                    'title' => 'Praise my soul',
                    'start_time' => 2210.0,
                    'end_time' => 2400.0,
                    'confidence' => 0.9,
                    'metadata' => ['review_flags' => ['structure_low_confidence']],
                ],
            ],
        ];
        $log->forceFill(['processing_metadata' => $metadata])->save();

        Livewire::test(ProcessingReview::class, ['processingLog' => $log])
            ->assertSee('Detected Structure (failed validation)')
            ->assertSee('The structure contains 2 sermon sections; a service has at most one.')
            ->assertSee('The faithfulness of God')
            ->assertSee('10:00–36:40')
            ->assertSee('structure_low_confidence');
    }

    #[Test]
    public function review_detail_omits_the_proposal_panel_when_none_was_recorded(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(ProcessingReview::class, ['processingLog' => $this->makeLogAwaitingReview()])
            ->assertDontSee('Detected Structure (failed validation)');
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
            ->assertRedirect(route('admin.services.inbox', ['filter' => 'segments']));
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

        $this->get(route('admin.services.processing.review', $log))
            ->assertForbidden();
    }

    #[Test]
    public function review_detail_aborts_for_non_livestream_log(): void
    {
        $this->actingAs($this->admin);

        $audioLog = MediaProcessingLog::factory()->audio()->create([
            'status' => ProcessingStatus::Failed,
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
            'status' => ProcessingStatus::Failed,
            'current_step' => 'manual_review_required',
        ]);

        $mail = new ManualReviewRequired($log->processing_id, 'Test reason');
        $rendered = $mail->render();

        $expectedUrl = route('admin.services.processing.review', $log);
        $this->assertStringContainsString($expectedUrl, $rendered);
    }

    #[Test]
    public function manual_review_email_falls_back_to_the_inbox_url_when_log_not_found(): void
    {
        $mail = new ManualReviewRequired('nonexistent-id', 'Test reason');
        $rendered = $mail->render();

        $expectedUrl = route('admin.services.inbox', ['filter' => 'segments']);
        $this->assertStringContainsString($expectedUrl, $rendered);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function makeLogAwaitingReview(): MediaProcessingLog
    {
        return MediaProcessingLog::factory()->livestream()->create([
            'status' => ProcessingStatus::Failed,
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
