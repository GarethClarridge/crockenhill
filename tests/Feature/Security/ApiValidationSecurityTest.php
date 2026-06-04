<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Enums\ApiTokenAbility;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ApiValidationSecurityTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function sermon_api_index_rejects_out_of_range_per_page(): void
    {
        // Currently it silently clamps to 100, which results in 200 OK.
        // We want it to return 422 Unprocessable Entity.
        $response = $this->getJson('/api/sermons?per_page=999');

        // This will currently fail (asserting 422, but getting 200)
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['per_page']);
    }

    #[Test]
    public function sermon_api_index_rejects_invalid_sort_field(): void
    {
        // Currently it silently defaults to 'date', which results in 200 OK.
        // We want it to return 422 Unprocessable Entity.
        $response = $this->getJson('/api/sermons?sort=unsupported_field');

        // This will currently fail (asserting 422, but getting 200)
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['sort']);
    }

    #[Test]
    public function media_processing_status_rejects_out_of_range_log_limit(): void
    {
        $user = User::factory()->admin()->create();
        $token = $user->createToken('test', [ApiTokenAbility::MEDIA_PROCESS->value])->plainTextToken;

        $processingId = '00000000-0000-4000-a000-000000000000';

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/media/processing/{$processingId}/status?log_limit=999");

        // This will currently fail (asserting 422, but getting 404/200 depending on if ID exists,
        // but it won't be 422 because it's not validated currently)
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['log_limit']);
    }
}
