<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Sermon;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonPrivateAssetTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_serves_private_audio_file_directly(): void
    {
        Storage::fake('local');

        $sermon = Sermon::factory()->create([
            'slug' => 'private-audio-sermon',
            'audio_file_path' => 'private/sermons/audio/test.mp3',
        ]);

        Storage::disk('local')->put('private/sermons/audio/test.mp3', 'fake mp3 content');

        $admin = User::factory()->crockenhillAdmin()->create();
        $response = $this->actingAs($admin)->get("/christ/sermons/{$sermon->slug}/audio");

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'audio/mpeg');
        // We verify that no-store is present, which is our security requirement for private assets
        $response->assertHeaderContains('Cache-Control', 'no-store');
    }

    #[Test]
    public function it_serves_private_thumbnail_webp_directly(): void
    {
        Storage::fake('local');

        $sermon = Sermon::factory()->create([
            'slug' => 'private-webp-sermon',
            'thumbnail_file_path' => 'private/thumbnails/test.webp',
        ]);

        Storage::disk('local')->put('private/thumbnails/test.webp', 'fake webp content');

        $admin = User::factory()->crockenhillAdmin()->create();
        $response = $this->actingAs($admin)->get("/christ/sermons/{$sermon->slug}/thumbnail");

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'image/webp');
        $response->assertHeaderContains('Cache-Control', 'no-store');
    }

    #[Test]
    public function it_serves_private_thumbnail_jpg_directly(): void
    {
        Storage::fake('local');

        $sermon = Sermon::factory()->create([
            'slug' => 'private-jpg-sermon',
            'thumbnail_file_path' => 'private/thumbnails/test.jpg',
        ]);

        Storage::disk('local')->put('private/thumbnails/test.jpg', 'fake jpg content');

        $admin = User::factory()->crockenhillAdmin()->create();
        $response = $this->actingAs($admin)->get("/christ/sermons/{$sermon->slug}/thumbnail");

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'image/jpeg');
    }

    #[Test]
    public function it_serves_private_card_thumbnail_directly(): void
    {
        Storage::fake('local');

        $sermon = Sermon::factory()->create([
            'slug' => 'private-card-sermon',
            'thumbnail_metadata' => [
                'card_thumbnail_path' => 'private/thumbnails/card.png',
            ],
        ]);

        Storage::disk('local')->put('private/thumbnails/card.png', 'fake png content');

        $admin = User::factory()->crockenhillAdmin()->create();
        $response = $this->actingAs($admin)->get("/christ/sermons/{$sermon->slug}/thumbnail/card");

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'image/png');
    }
}
