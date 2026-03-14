<?php

namespace Tests\Feature\Api;

use App\Actions\ConfirmLivestreamSermonSegment;
use App\Enums\ApiTokenAbility;
use App\Enums\ProcessingStatus;
use App\Models\LivestreamSegment;
use App\Models\MediaProcessingLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ConfirmSegmentApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->admin = User::factory()->create([
            'is_admin' => true,
            'email_verified_at' => now(),
        ]);
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken('test', [ApiTokenAbility::MEDIA_PROCESS->value])
            ->plainTextToken;
    }

    private function makeLivestreamLogAwaitingReview(string $sourcePath = 'livestreams/2026/service.mp4'): MediaProcessingLog
    {
        Storage::disk('public')->put($sourcePath, 'fake-video');

        $log = MediaProcessingLog::factory()->livestream()->create([
            'source_file_path' => $sourcePath,
        ]);

        $log->markForManualReview(
            reasonCode: 'multiple_qualifying_speech_blocks',
            reasonMessage: 'Multiple speech blocks qualified.',
            speechSegments: [],
        );

        return $log->fresh();
    }

    // -------------------------------------------------------------------------
    // Authentication and authorisation
    // -------------------------------------------------------------------------

    #[Test]
    public function it_rejects_unauthenticated_requests(): void
    {
        $this->postJson('/api/media/processing/some-id/confirm-segment', ['segment_id' => 1])
            ->assertUnauthorized();
    }

    #[Test]
    public function it_rejects_token_without_media_process_ability(): void
    {
        $token = $this->admin->createToken('test', ['some:other'])->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/media/processing/some-id/confirm-segment', ['segment_id' => 1])
            ->assertForbidden();
    }

    #[Test]
    public function it_rejects_non_admin_users(): void
    {
        $user = User::factory()->create(['is_admin' => false, 'email_verified_at' => now()]);
        $token = $this->tokenFor($user);

        $this->withToken($token)
            ->postJson('/api/media/processing/some-id/confirm-segment', ['segment_id' => 1])
            ->assertForbidden();
    }

    // -------------------------------------------------------------------------
    // Validation
    // -------------------------------------------------------------------------

    #[Test]
    public function it_rejects_a_malformed_processing_id(): void
    {
        $token = $this->tokenFor($this->admin);

        $this->withToken($token)
            ->postJson('/api/media/processing/not-a-uuid/confirm-segment', ['segment_id' => 1])
            ->assertStatus(400)
            ->assertJsonFragment(['success' => false]);
    }

    #[Test]
    public function it_rejects_a_missing_segment_id(): void
    {
        Bus::fake();
        $log = $this->makeLivestreamLogAwaitingReview();
        $token = $this->tokenFor($this->admin);

        $this->withToken($token)
            ->postJson("/api/media/processing/{$log->processing_id}/confirm-segment", [])
            ->assertUnprocessable();
    }

    // -------------------------------------------------------------------------
    // Success
    // -------------------------------------------------------------------------

    #[Test]
    public function it_confirms_a_valid_speech_segment_and_returns_202(): void
    {
        Bus::fake();

        $log = $this->makeLivestreamLogAwaitingReview();
        $segment = LivestreamSegment::factory()->speech()->forProcessingLog($log->id)->create();
        $token = $this->tokenFor($this->admin);

        $response = $this->withToken($token)
            ->postJson("/api/media/processing/{$log->processing_id}/confirm-segment", [
                'segment_id' => $segment->id,
            ]);

        $response->assertStatus(202)
            ->assertJsonFragment(['success' => true])
            ->assertJsonStructure(['success', 'message', 'status_url']);

        $log->refresh();
        $this->assertSame(ProcessingStatus::PENDING, $log->status);
        $this->assertSame('manual_review_confirmed', $log->current_step);
        $this->assertSame($segment->id, $log->manuallyConfirmedSegmentId());
    }

    // -------------------------------------------------------------------------
    // Domain validation failures (delegated to action)
    // -------------------------------------------------------------------------

    #[Test]
    public function it_returns_422_for_an_unknown_processing_id(): void
    {
        $token = $this->tokenFor($this->admin);

        $this->withToken($token)
            ->postJson('/api/media/processing/00000000-0000-0000-0000-000000000000/confirm-segment', [
                'segment_id' => 1,
            ])
            ->assertUnprocessable()
            ->assertJsonFragment(['success' => false]);
    }

    #[Test]
    public function it_returns_422_for_an_invalid_segment_selection(): void
    {
        Bus::fake();
        $log = $this->makeLivestreamLogAwaitingReview();
        $songSegment = LivestreamSegment::factory()->song()->forProcessingLog($log->id)->create();
        $token = $this->tokenFor($this->admin);

        $this->withToken($token)
            ->postJson("/api/media/processing/{$log->processing_id}/confirm-segment", [
                'segment_id' => $songSegment->id,
            ])
            ->assertUnprocessable()
            ->assertJsonFragment(['success' => false]);
    }

    #[Test]
    public function it_returns_422_if_run_is_not_awaiting_review(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->completed()->create();
        $segment = LivestreamSegment::factory()->speech()->forProcessingLog($log->id)->create();
        $token = $this->tokenFor($this->admin);

        $this->withToken($token)
            ->postJson("/api/media/processing/{$log->processing_id}/confirm-segment", [
                'segment_id' => $segment->id,
            ])
            ->assertUnprocessable()
            ->assertJsonFragment(['success' => false]);
    }

    // -------------------------------------------------------------------------
    // Idempotency
    // -------------------------------------------------------------------------

    #[Test]
    public function it_rejects_a_second_confirmation_of_the_same_run(): void
    {
        Bus::fake();
        $log = $this->makeLivestreamLogAwaitingReview();
        $segment = LivestreamSegment::factory()->speech()->forProcessingLog($log->id)->create();
        $token = $this->tokenFor($this->admin);

        // First confirmation
        $this->withToken($token)
            ->postJson("/api/media/processing/{$log->processing_id}/confirm-segment", [
                'segment_id' => $segment->id,
            ])
            ->assertStatus(202);

        // Second attempt should be rejected
        $this->withToken($token)
            ->postJson("/api/media/processing/{$log->processing_id}/confirm-segment", [
                'segment_id' => $segment->id,
            ])
            ->assertUnprocessable();
    }

    // -------------------------------------------------------------------------
    // Action delegation
    // -------------------------------------------------------------------------

    #[Test]
    public function it_delegates_to_the_confirm_action(): void
    {
        $log = $this->makeLivestreamLogAwaitingReview();
        $segment = LivestreamSegment::factory()->speech()->forProcessingLog($log->id)->create();
        $token = $this->tokenFor($this->admin);

        $mock = $this->mock(ConfirmLivestreamSermonSegment::class);
        $mock->shouldReceive('execute')
            ->once()
            ->with($log->processing_id, $segment->id, \Mockery::type(User::class))
            ->andReturnNull();

        $this->withToken($token)
            ->postJson("/api/media/processing/{$log->processing_id}/confirm-segment", [
                'segment_id' => $segment->id,
            ])
            ->assertStatus(202);
    }
}
