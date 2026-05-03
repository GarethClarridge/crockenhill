<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\Sermon;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RobustPathProtectionTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function it_blocks_uri_schemes_in_audio_paths(): void
    {
        Storage::fake('public');
        $sermon = Sermon::factory()->create([
            'audio_file_path' => 'http://malicious.com/shell.php',
        ]);

        $response = $this->get(route('sermons.audio', ['sermon' => $sermon->slug]));

        $response->assertStatus(404);
    }

    #[Test]
    public function it_blocks_uri_schemes_in_video_paths(): void
    {
        Storage::fake('public');
        $sermon = Sermon::factory()->create([
            'video_file_path' => 'https://attacker.com/payload.mp4',
        ]);

        $response = $this->get(route('sermons.video', ['sermon' => $sermon->slug]));

        $response->assertStatus(404);
    }

    #[Test]
    public function it_blocks_uri_schemes_in_thumbnail_paths(): void
    {
        Storage::fake('public');
        $sermon = Sermon::factory()->create([
            'thumbnail_file_path' => 'ftp://evil.com/thumb.jpg',
        ]);

        $response = $this->get(route('sermons.thumbnail', ['sermon' => $sermon->slug]));

        $response->assertStatus(404);
    }

    #[Test]
    public function it_blocks_absolute_paths_in_transcript_reader(): void
    {
        Storage::fake('public');
        $sermon = Sermon::factory()->create([
            'transcript_file_path' => '/etc/passwd',
        ]);

        // The transcript serving route uses SermonTranscriptReader
        $response = $this->get(route('sermons.transcript', ['sermon' => $sermon->slug]));

        $response->assertStatus(404);
    }

    #[Test]
    public function it_blocks_uri_schemes_in_transcript_reader(): void
    {
        Storage::fake('public');
        $sermon = Sermon::factory()->create([
            'transcript_file_path' => 's3://bucket/secret.txt',
        ]);

        $response = $this->get(route('sermons.transcript', ['sermon' => $sermon->slug]));

        $response->assertStatus(404);
    }

    #[Test]
    public function it_blocks_malicious_thumbnail_candidates(): void
    {
        Storage::fake('public');
        $admin = User::factory()->admin()->create();
        $sermon = Sermon::factory()->create();

        $response = $this->actingAs($admin)->get(route('admin.sermons.thumbnails.preview', [
            'sermon' => $sermon->slug,
            'candidateId' => 'candidate-1',
            'variant' => 'plain',
        ]));

        $response->assertStatus(404);
    }
}
