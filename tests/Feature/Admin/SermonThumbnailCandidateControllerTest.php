<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Sermon;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonThumbnailCandidateControllerTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function admin_can_view_thumbnail_candidate_overlay(): void
    {
        Storage::fake('public');
        $admin = User::factory()->admin()->create();
        $sermon = Sermon::factory()->create([
            'thumbnail_metadata' => [
                'thumbnail_candidates' => [
                    [
                        'id' => 'candidate-1',
                        'timestamp' => 120.5,
                        'score' => 0.95,
                        'plain_path' => 'sermons/thumbnails/candidate-1-plain.webp',
                        'overlay_path' => 'sermons/thumbnails/candidate-1-overlay.webp',
                    ],
                ],
            ],
        ]);

        Storage::disk('public')->put('sermons/thumbnails/candidate-1-overlay.webp', 'fake-overlay-content');

        $response = $this->actingAs($admin)
            ->get("/admin/sermons/{$sermon->slug}/thumbnails/candidate-1/overlay");

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'image/webp');

        ob_start();
        $response->sendContent();
        $content = ob_get_clean();

        $this->assertEquals('fake-overlay-content', $content);
    }

    #[Test]
    public function admin_can_view_thumbnail_candidate_card(): void
    {
        Storage::fake('public');
        $admin = User::factory()->admin()->create();
        $sermon = Sermon::factory()->create([
            'thumbnail_metadata' => [
                'thumbnail_candidates' => [
                    [
                        'id' => 'candidate-2',
                        'timestamp' => 150.0,
                        'score' => 0.88,
                        'plain_path' => 'sermons/thumbnails/candidate-2-plain.webp',
                        'card_path' => 'sermons/thumbnails/candidate-2-card.webp',
                    ],
                ],
            ],
        ]);

        Storage::disk('public')->put('sermons/thumbnails/candidate-2-card.webp', 'fake-card-content');

        $response = $this->actingAs($admin)
            ->get("/admin/sermons/{$sermon->slug}/thumbnails/candidate-2/card");

        $response->assertStatus(200);

        ob_start();
        $response->sendContent();
        $content = ob_get_clean();

        $this->assertEquals('fake-card-content', $content);
    }

    #[Test]
    public function admin_can_view_thumbnail_candidate_plain(): void
    {
        Storage::fake('public');
        $admin = User::factory()->admin()->create();
        $sermon = Sermon::factory()->create([
            'thumbnail_metadata' => [
                'thumbnail_candidates' => [
                    [
                        'id' => 'candidate-3',
                        'timestamp' => 200.0,
                        'score' => 0.99,
                        'plain_path' => 'sermons/thumbnails/candidate-3-plain.webp',
                    ],
                ],
            ],
        ]);

        Storage::disk('public')->put('sermons/thumbnails/candidate-3-plain.webp', 'fake-plain-content');

        $response = $this->actingAs($admin)
            ->get("/admin/sermons/{$sermon->slug}/thumbnails/candidate-3/plain");

        $response->assertStatus(200);

        ob_start();
        $response->sendContent();
        $content = ob_get_clean();

        $this->assertEquals('fake-plain-content', $content);
    }

    #[Test]
    public function non_admin_cannot_view_thumbnail_candidate(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $sermon = Sermon::factory()->create();

        $response = $this->actingAs($user)
            ->get("/admin/sermons/{$sermon->slug}/thumbnails/candidate-1/overlay");

        $response->assertStatus(403);
    }

    #[Test]
    public function guest_cannot_view_thumbnail_candidate(): void
    {
        $sermon = Sermon::factory()->create();

        $response = $this->get("/admin/sermons/{$sermon->slug}/thumbnails/candidate-1/overlay");

        $response->assertRedirect('/login');
    }

    #[Test]
    public function it_returns_404_for_invalid_candidate_id_format(): void
    {
        $admin = User::factory()->admin()->create();
        $sermon = Sermon::factory()->create();

        $response = $this->actingAs($admin)
            ->get("/admin/sermons/{$sermon->slug}/thumbnails/invalid-id/overlay");

        $response->assertStatus(404);
    }

    #[Test]
    public function it_returns_404_when_candidate_not_found_in_metadata(): void
    {
        $admin = User::factory()->admin()->create();
        $sermon = Sermon::factory()->create([
            'thumbnail_metadata' => [
                'thumbnail_candidates' => [
                    [
                        'id' => 'candidate-1',
                        'timestamp' => 120.5,
                        'score' => 0.95,
                        'plain_path' => 'sermons/thumbnails/candidate-1-plain.webp',
                    ],
                ],
            ],
        ]);

        $response = $this->actingAs($admin)
            ->get("/admin/sermons/{$sermon->slug}/thumbnails/candidate-999/overlay");

        $response->assertStatus(404);
    }

    #[Test]
    public function it_returns_404_when_variant_path_is_missing_in_metadata(): void
    {
        $admin = User::factory()->admin()->create();
        $sermon = Sermon::factory()->create([
            'thumbnail_metadata' => [
                'thumbnail_candidates' => [
                    [
                        'id' => 'candidate-1',
                        'timestamp' => 120.5,
                        'score' => 0.95,
                        'plain_path' => 'sermons/thumbnails/candidate-1-plain.webp',
                        // overlay_path is missing
                    ],
                ],
            ],
        ]);

        $response = $this->actingAs($admin)
            ->get("/admin/sermons/{$sermon->slug}/thumbnails/candidate-1/overlay");

        $response->assertStatus(404);
    }

    #[Test]
    public function it_returns_404_for_path_traversal_attempt(): void
    {
        $admin = User::factory()->admin()->create();
        $sermon = Sermon::factory()->create([
            'thumbnail_metadata' => [
                'thumbnail_candidates' => [
                    [
                        'id' => 'candidate-1',
                        'timestamp' => 120.5,
                        'score' => 0.95,
                        'plain_path' => 'sermons/thumbnails/../../secrets.webp',
                    ],
                ],
            ],
        ]);

        $response = $this->actingAs($admin)
            ->get("/admin/sermons/{$sermon->slug}/thumbnails/candidate-1/plain");

        $response->assertStatus(404);
    }

    #[Test]
    public function it_returns_404_when_file_missing_on_disk(): void
    {
        Storage::fake('public');
        $admin = User::factory()->admin()->create();
        $sermon = Sermon::factory()->create([
            'thumbnail_metadata' => [
                'thumbnail_candidates' => [
                    [
                        'id' => 'candidate-1',
                        'timestamp' => 120.5,
                        'score' => 0.95,
                        'plain_path' => 'sermons/thumbnails/missing.webp',
                    ],
                ],
            ],
        ]);

        // File is NOT put on the disk

        $response = $this->actingAs($admin)
            ->get("/admin/sermons/{$sermon->slug}/thumbnails/candidate-1/plain");

        $response->assertStatus(404);
    }

    #[Test]
    public function it_detects_correct_content_type_for_webp(): void
    {
        Storage::fake('public');
        $admin = User::factory()->admin()->create();
        $sermon = Sermon::factory()->create([
            'thumbnail_metadata' => [
                'thumbnail_candidates' => [
                    [
                        'id' => 'candidate-1',
                        'timestamp' => 120.5,
                        'score' => 0.95,
                        'plain_path' => 'sermons/thumbnails/image.webp',
                    ],
                ],
            ],
        ]);

        Storage::disk('public')->put('sermons/thumbnails/image.webp', 'fake-webp');

        $response = $this->actingAs($admin)
            ->get("/admin/sermons/{$sermon->slug}/thumbnails/candidate-1/plain");

        $response->assertHeader('Content-Type', 'image/webp');
    }

    #[Test]
    public function it_detects_correct_content_type_for_png(): void
    {
        Storage::fake('public');
        $admin = User::factory()->admin()->create();
        $sermon = Sermon::factory()->create([
            'thumbnail_metadata' => [
                'thumbnail_candidates' => [
                    [
                        'id' => 'candidate-1',
                        'timestamp' => 120.5,
                        'score' => 0.95,
                        'plain_path' => 'sermons/thumbnails/image.png',
                    ],
                ],
            ],
        ]);

        Storage::disk('public')->put('sermons/thumbnails/image.png', 'fake-png');

        $response = $this->actingAs($admin)
            ->get("/admin/sermons/{$sermon->slug}/thumbnails/candidate-1/plain");

        $response->assertHeader('Content-Type', 'image/png');
    }

    #[Test]
    public function it_detects_correct_content_type_for_jpeg(): void
    {
        Storage::fake('public');
        $admin = User::factory()->admin()->create();
        $sermon = Sermon::factory()->create([
            'thumbnail_metadata' => [
                'thumbnail_candidates' => [
                    [
                        'id' => 'candidate-1',
                        'timestamp' => 120.5,
                        'score' => 0.95,
                        'plain_path' => 'sermons/thumbnails/image.jpg',
                    ],
                ],
            ],
        ]);

        Storage::disk('public')->put('sermons/thumbnails/image.jpg', 'fake-jpg');

        $response = $this->actingAs($admin)
            ->get("/admin/sermons/{$sermon->slug}/thumbnails/candidate-1/plain");

        $response->assertHeader('Content-Type', 'image/jpeg');
    }
}
