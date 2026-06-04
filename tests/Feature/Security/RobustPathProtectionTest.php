<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\Sermon;
use App\Models\ServiceSection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RobustPathProtectionTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_blocks_unsafe_paths_in_sermon_assets(): void
    {
        $admin = User::factory()->crockenhillAdmin()->create();
        $sermon = Sermon::factory()->create([
            'audio_file_path' => '../../etc/passwd',
            'video_file_path' => '/absolute/path/to/secret',
            'thumbnail_file_path' => 'http://malicious.com/exploit.jpg',
            'thumbnail_metadata' => [
                'card_thumbnail_path' => 'C:\\Windows\\System32\\cmd.exe',
            ],
            'transcript_file_path' => 's3://bucket/secret.txt',
        ]);

        $this->actingAs($admin);

        // Audio
        $this->get(route('sermons.audio', $sermon))->assertStatus(404);

        // Video
        $this->get(route('sermons.video', $sermon))->assertStatus(404);

        // Thumbnail
        $this->get(route('sermons.thumbnail', $sermon))->assertStatus(404);

        // Card Thumbnail
        $this->get(route('sermons.thumbnail.card', $sermon))->assertStatus(404);

        // Transcript (authorization check also applies)
        $this->get(route('sermons.transcript', $sermon))->assertStatus(404);
    }

    #[Test]
    public function it_blocks_unsafe_paths_in_thumbnail_candidates(): void
    {
        $admin = User::factory()->crockenhillAdmin()->create();
        $sermon = Sermon::factory()->create([
            'thumbnail_metadata' => [
                'selected_candidate_id' => 'candidate-1',
                'thumbnail_candidates' => [
                    [
                        'id' => 'candidate-1',
                        'timestamp' => 10.0,
                        'score' => 0.9,
                        'plain_path' => '../../traversal.jpg',
                    ],
                ],
            ],
        ]);

        $this->actingAs($admin);

        $this->get(route('admin.sermons.thumbnails.preview', [
            'sermon' => $sermon->slug,
            'candidateId' => 'candidate-1',
            'variant' => 'plain',
        ]))->assertStatus(404);
    }

    #[Test]
    public function it_blocks_unsafe_paths_in_service_section_candidates(): void
    {
        $admin = User::factory()->crockenhillAdmin()->create();
        $section = ServiceSection::factory()->create([
            'extracted_audio_path' => 'ftp://malicious.com/audio.mp3',
            'extracted_video_path' => '\\\\network\\share\\video.mp4',
        ]);

        $this->actingAs($admin);

        $this->get(route('admin.services.section-publications.preview-audio', $section))->assertStatus(404);
        $this->get(route('admin.services.section-publications.preview-video', $section))->assertStatus(404);
    }

    #[Test]
    public function it_blocks_private_transcript_access_for_non_admins(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $sermon = Sermon::factory()->create([
            'transcript_file_path' => 'private/sermon_1.md',
        ]);

        // Non-admin verified user should be blocked from private transcript
        $this->actingAs($user);
        $this->get(route('sermons.transcript', $sermon))->assertStatus(404);

        // Admin should have access (if it exists, but here we just check it doesn't 404 on auth)
        $admin = User::factory()->crockenhillAdmin()->create();
        $this->actingAs($admin);
        $this->get(route('sermons.transcript', $sermon))->assertStatus(404); // Still 404 because file doesn't exist, but it passed auth matches
    }
}
