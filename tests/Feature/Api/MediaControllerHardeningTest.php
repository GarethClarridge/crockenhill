<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Actions\ConfirmLivestreamSermonSegment;
use App\Exceptions\SafeInvalidArgumentException;
use App\Models\LivestreamSegment;
use App\Models\MediaProcessingLog;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MediaControllerHardeningTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function it_returns_raw_message_for_safe_invalid_argument_exception(): void
    {
        /** @var User $user */
        $user = User::factory()->admin()->create();
        $processingId = '00000000-0000-0000-0000-000000000000';

        // Create processing log first to satisfy foreign key
        /** @var MediaProcessingLog $log */
        $log = MediaProcessingLog::factory()->create();
        /** @var LivestreamSegment $segment */
        $segment = LivestreamSegment::factory()->create(['media_processing_log_id' => $log->id]);
        $segmentId = $segment->id;

        /** @var \Mockery\MockInterface&\App\Actions\ConfirmLivestreamSermonSegment $actionMock */
        $actionMock = $this->mock(ConfirmLivestreamSermonSegment::class);
        /** @var \Mockery\Expectation $expectation */
        $expectation = $actionMock->shouldReceive('execute');
        $expectation->andThrow(new SafeInvalidArgumentException('This is a safe message.'));

        $response = $this->actingAs($user)
            ->postJson(route('api.media.processing.confirm-segment', ['processingId' => $processingId]), [
                'segment_id' => $segmentId,
            ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'This is a safe message.',
            ]);
    }

    #[Test]
    public function it_returns_generic_message_for_unsafe_invalid_argument_exception(): void
    {
        /** @var User $user */
        $user = User::factory()->admin()->create();
        $processingId = '00000000-0000-0000-0000-000000000000';

        // Create processing log first to satisfy foreign key
        /** @var MediaProcessingLog $log */
        $log = MediaProcessingLog::factory()->create();
        /** @var LivestreamSegment $segment */
        $segment = LivestreamSegment::factory()->create(['media_processing_log_id' => $log->id]);
        $segmentId = $segment->id;

        /** @var \Mockery\MockInterface&\App\Actions\ConfirmLivestreamSermonSegment $actionMock */
        $actionMock = $this->mock(ConfirmLivestreamSermonSegment::class);
        /** @var \Mockery\Expectation $expectation */
        $expectation = $actionMock->shouldReceive('execute');
        $expectation->andThrow(new \InvalidArgumentException('This is an unsafe internal error message.'));

        $response = $this->actingAs($user)
            ->postJson(route('api.media.processing.confirm-segment', ['processingId' => $processingId]), [
                'segment_id' => $segmentId,
            ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'Confirmation failed due to an internal error.',
            ]);

        $response->assertJsonMissing(['message' => 'This is an unsafe internal error message.']);
    }
}
