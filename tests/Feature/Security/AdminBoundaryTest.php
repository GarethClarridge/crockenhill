<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdminBoundaryTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function unverified_admins_are_blocked_from_media_processing_apis(): void
    {
        $admin = User::factory()->create([
            'is_admin' => true,
            'email_verified_at' => null,
        ]);

        $this->actingAs($admin)
            ->postJson(route('api.media.upload', ['type' => 'audio']))
            ->assertStatus(403);

        $this->actingAs($admin)
            ->getJson(route('api.media.processing.status', ['processingId' => 'some-id']))
            ->assertStatus(403);
    }

    #[Test]
    public function unverified_admins_are_blocked_from_service_tracking_apis(): void
    {
        $admin = User::factory()->create([
            'is_admin' => true,
            'email_verified_at' => null,
        ]);

        $this->actingAs($admin)
            ->postJson(route('api.services.openlp.store'))
            ->assertStatus(403);
    }
}
