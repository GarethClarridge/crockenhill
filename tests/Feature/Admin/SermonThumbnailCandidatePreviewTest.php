<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Sermon;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonThumbnailCandidatePreviewTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function admin_can_preview_a_public_thumbnail_candidate(): void
    {
        Storage::fake('public');

        $admin = User::factory()->admin()->create();
        $sermon = Sermon::factory()->create([
            'slug' => 'preview-sermon',
            'thumbnail_metadata' => [
                'thumbnail_candidates' => [
                    [
                        'id' => 'candidate-1',
                        'timestamp' => 180.0,
                        'score' => 0.84,
                        'overlay_path' => 'sermons/thumbnails/candidate-1-overlay.webp',
                        'plain_path' => 'sermons/thumbnails/candidate-1-plain.webp',
                    ],
                ],
            ],
        ]);

        Storage::disk('public')->put('sermons/thumbnails/candidate-1-overlay.webp', 'overlay content');

        $response = $this->actingAs($admin)->get(route('admin.sermons.thumbnails.preview', [
            'sermon' => $sermon->slug,
            'candidateId' => 'candidate-1',
            'variant' => 'overlay',
        ]));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/webp');
    }

    #[Test]
    public function admin_can_preview_a_stored_thumbnail_candidate(): void
    {
        Storage::fake('public');

        $admin = User::factory()->admin()->create();
        $sermon = Sermon::factory()->create([
            'slug' => 'private-preview-sermon',
            'thumbnail_metadata' => [
                'thumbnail_candidates' => [
                    [
                        'id' => 'candidate-2',
                        'timestamp' => 240.0,
                        'score' => 0.91,
                        'card_path' => 'sermons/thumbnails/candidate-2-card.webp',
                        'plain_path' => 'sermons/thumbnails/candidate-2-plain.webp',
                    ],
                ],
            ],
        ]);

        Storage::disk('public')->put('sermons/thumbnails/candidate-2-card.webp', 'card content');

        $response = $this->actingAs($admin)->get(route('admin.sermons.thumbnails.preview', [
            'sermon' => $sermon->slug,
            'candidateId' => 'candidate-2',
            'variant' => 'card',
        ]));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/webp');
    }

    #[Test]
    public function admin_can_preview_a_plain_only_thumbnail_candidate_via_card_fallback(): void
    {
        Storage::fake('public');

        $admin = User::factory()->admin()->create();
        $sermon = Sermon::factory()->create([
            'slug' => 'plain-only-preview-sermon',
            'thumbnail_metadata' => [
                'thumbnail_candidates' => [
                    [
                        'id' => 'candidate-3',
                        'timestamp' => 300.0,
                        'score' => 0.88,
                        'plain_path' => 'sermons/thumbnails/candidate-3-plain.webp',
                    ],
                ],
            ],
        ]);

        Storage::disk('public')->put('sermons/thumbnails/candidate-3-plain.webp', 'plain content');

        $response = $this->actingAs($admin)->get(route('admin.sermons.thumbnails.preview', [
            'sermon' => $sermon->slug,
            'candidateId' => 'candidate-3',
            'variant' => 'card',
        ]));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/webp');
    }

    #[Test]
    public function guests_cannot_preview_thumbnail_candidates(): void
    {
        $sermon = Sermon::factory()->create([
            'slug' => 'guest-preview-sermon',
            'thumbnail_metadata' => [
                'thumbnail_candidates' => [
                    [
                        'id' => 'candidate-1',
                        'timestamp' => 180.0,
                        'score' => 0.84,
                        'overlay_path' => 'sermons/thumbnails/candidate-1-overlay.webp',
                        'plain_path' => 'sermons/thumbnails/candidate-1-plain.webp',
                    ],
                ],
            ],
        ]);

        $response = $this->get(route('admin.sermons.thumbnails.preview', [
            'sermon' => $sermon->slug,
            'candidateId' => 'candidate-1',
            'variant' => 'overlay',
        ]));

        $response->assertRedirect(route('login'));
    }

    #[Test]
    public function it_returns_not_found_for_unknown_thumbnail_candidates(): void
    {
        $admin = User::factory()->admin()->create();
        $sermon = Sermon::factory()->create([
            'slug' => 'missing-preview-sermon',
            'thumbnail_metadata' => [
                'thumbnail_candidates' => [],
            ],
        ]);

        $response = $this->actingAs($admin)->get(route('admin.sermons.thumbnails.preview', [
            'sermon' => $sermon->slug,
            'candidateId' => 'missing',
            'variant' => 'overlay',
        ]));

        $response->assertNotFound();
    }
}
