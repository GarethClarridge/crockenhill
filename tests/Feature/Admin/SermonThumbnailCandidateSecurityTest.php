<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Sermon;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonThumbnailCandidateSecurityTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function unauthenticated_users_are_redirected_to_login(): void
    {
        /** @var Sermon $sermon */
        $sermon = Sermon::factory()->create();

        $response = $this->get(route('admin.sermons.thumbnails.preview', [
            'sermon' => $sermon->slug,
            'candidateId' => 'candidate-1',
            'variant' => 'plain',
        ]));

        $response->assertRedirect('/login');
    }

    #[Test]
    public function non_admin_authenticated_users_receive_403(): void
    {
        /** @var User $user */
        $user = User::factory()->create(['is_admin' => false]);
        /** @var Sermon $sermon */
        $sermon = Sermon::factory()->create();

        $response = $this->actingAs($user)->get(route('admin.sermons.thumbnails.preview', [
            'sermon' => $sermon->slug,
            'candidateId' => 'candidate-1',
            'variant' => 'plain',
        ]));

        $response->assertStatus(403);
    }

    #[Test]
    public function admin_can_access_valid_thumbnail_candidate(): void
    {
        Storage::fake('public');
        /** @var User $admin */
        $admin = User::factory()->create(['is_admin' => true]);

        $candidatePath = 'thumbnails/candidate-1.webp';
        Storage::disk('public')->put($candidatePath, 'fake image content');

        /** @var Sermon $sermon */
        $sermon = Sermon::factory()->create([
            'thumbnail_metadata' => [
                'thumbnail_candidates' => [
                    [
                        'id' => 'candidate-1',
                        'plain_path' => $candidatePath,
                        'score' => 0.9,
                        'timestamp' => 10.0,
                    ],
                ],
            ],
        ]);

        $response = $this->actingAs($admin)->get(route('admin.sermons.thumbnails.preview', [
            'sermon' => $sermon->slug,
            'candidateId' => 'candidate-1',
            'variant' => 'plain',
        ]));

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'image/webp');
        $this->assertEquals('fake image content', $response->streamedContent());
    }

    #[Test]
    public function invalid_candidate_id_pattern_returns_404(): void
    {
        /** @var User $admin */
        $admin = User::factory()->create(['is_admin' => true]);
        /** @var Sermon $sermon */
        $sermon = Sermon::factory()->create();

        // Testing path traversal attempt in candidateId
        $response = $this->actingAs($admin)->get(route('admin.sermons.thumbnails.preview', [
            'sermon' => $sermon->slug,
            'candidateId' => 'candidate-../../../etc/passwd',
            'variant' => 'plain',
        ]));

        $response->assertStatus(404);
    }

    #[Test]
    public function unsafe_resolved_path_returns_404(): void
    {
        /** @var User $admin */
        $admin = User::factory()->create(['is_admin' => true]);

        // Simulating a case where a candidate exists but its path is malicious
        /** @var Sermon $sermon */
        $sermon = Sermon::factory()->create([
            'thumbnail_metadata' => [
                'thumbnail_candidates' => [
                    [
                        'id' => 'candidate-1',
                        'plain_path' => '/etc/passwd',
                        'score' => 0.9,
                        'timestamp' => 10.0,
                    ],
                ],
            ],
        ]);

        $response = $this->actingAs($admin)->get(route('admin.sermons.thumbnails.preview', [
            'sermon' => $sermon->slug,
            'candidateId' => 'candidate-1',
            'variant' => 'plain',
        ]));

        $response->assertStatus(404);
    }

    #[Test]
    public function nonexistent_candidate_returns_404(): void
    {
        /** @var User $admin */
        $admin = User::factory()->create(['is_admin' => true]);
        /** @var Sermon $sermon */
        $sermon = Sermon::factory()->create();

        $response = $this->actingAs($admin)->get(route('admin.sermons.thumbnails.preview', [
            'sermon' => $sermon->slug,
            'candidateId' => 'candidate-999',
            'variant' => 'plain',
        ]));

        $response->assertStatus(404);
    }
}
