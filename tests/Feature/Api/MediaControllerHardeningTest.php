<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class MediaControllerHardeningTest extends TestCase
{
    use DatabaseTransactions;

    public function test_it_rejects_malformed_processing_id_with_incorrect_length(): void
    {
        $user = User::factory()->admin()->create();
        $this->actingAs($user, 'sanctum');

        // Too short
        $response = $this->getJson(route('api.media.processing.status', ['processingId' => 'too-short']));
        $response->assertStatus(400);

        // Too long
        $response = $this->getJson(route('api.media.processing.status', ['processingId' => str_repeat('a', 37)]));
        $response->assertStatus(400);
    }

    public function test_it_enforces_max_length_on_video_processing_mode(): void
    {
        $user = User::factory()->admin()->create();
        $this->actingAs($user, 'sanctum');

        $response = $this->postJson(route('api.media.upload', ['type' => 'video']), [
            'video_processing_mode' => str_repeat('a', 21),
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['video_processing_mode']);
    }

    public function test_it_enforces_max_length_on_include_logs_parameter(): void
    {
        $user = User::factory()->admin()->create();
        $this->actingAs($user, 'sanctum');

        $processingId = Str::uuid()->toString();

        $response = $this->getJson(route('api.media.processing.status', [
            'processingId' => $processingId,
            'include_logs' => str_repeat('t', 11),
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['include_logs']);
    }
}
