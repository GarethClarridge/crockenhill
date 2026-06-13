<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Actions\ConfirmLivestreamSermonSegment;
use App\Contracts\ProvidesSafeMessage;
use App\Models\LivestreamSegment;
use App\Models\MediaProcessingLog;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MediaControllerHardeningTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function it_returns_generic_message_for_unsafe_invalid_argument_exception(): void
    {
        $user = User::factory()->admin()->create();
        $this->actingAs($user);

        $log = MediaProcessingLog::factory()->livestream()->manualReviewRequired()->create();
        $segment = LivestreamSegment::factory()->forProcessingLog($log->id)->speech()->create();

        $mockAction = Mockery::mock(ConfirmLivestreamSermonSegment::class);
        $mockAction->shouldReceive('execute')
            ->andThrow(new \InvalidArgumentException('Sensitive internal detail'));

        $this->app->instance(ConfirmLivestreamSermonSegment::class, $mockAction);

        $response = $this->postJson(route('api.media.processing.confirm-segment', ['processingId' => $log->processing_id]), [
            'segment_id' => $segment->id
        ]);

        $response->assertStatus(422);
        $response->assertJson([
            'success' => false,
            'message' => 'Invalid request parameters.'
        ]);
        $response->assertJsonMissing(['message' => 'Sensitive internal detail']);
    }

    #[Test]
    public function it_returns_safe_message_for_safe_invalid_argument_exception(): void
    {
        $user = User::factory()->admin()->create();
        $this->actingAs($user);

        $log = MediaProcessingLog::factory()->livestream()->manualReviewRequired()->create();
        $segment = LivestreamSegment::factory()->forProcessingLog($log->id)->speech()->create();

        $safeException = new class('Safe business error') extends \InvalidArgumentException implements ProvidesSafeMessage {
            public function getSafeMessage(): string { return $this->getMessage(); }
        };

        $mockAction = Mockery::mock(ConfirmLivestreamSermonSegment::class);
        $mockAction->shouldReceive('execute')
            ->andThrow($safeException);

        $this->app->instance(ConfirmLivestreamSermonSegment::class, $mockAction);

        $response = $this->postJson(route('api.media.processing.confirm-segment', ['processingId' => $log->processing_id]), [
            'segment_id' => $segment->id
        ]);

        $response->assertStatus(422);
        $response->assertJson([
            'success' => false,
            'message' => 'Safe business error'
        ]);
    }
}
