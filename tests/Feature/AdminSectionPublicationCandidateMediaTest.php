<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ServiceSectionPublicationStatus;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Models\User;
use App\Queries\ServiceReviewDashboardQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdminSectionPublicationCandidateMediaTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'is_admin' => true,
            'email_verified_at' => now(),
        ]);
    }

    #[Test]
    public function extracted_private_candidate_media_is_not_publicly_retrievable_from_a_raw_storage_url(): void
    {
        Storage::fake('local');

        $run = MediaProcessingLog::factory()->livestream()->create();
        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'publication_status' => ServiceSectionPublicationStatus::PENDING_APPROVAL->value,
            'extracted_audio_path' => 'private/section-publications/700/audio.mp3',
        ]);

        Storage::disk('local')->put((string) $section->extracted_audio_path, 'candidate-audio');

        $rawUrl = Storage::disk('local')->url((string) $section->extracted_audio_path);
        $rawPath = (string) (parse_url($rawUrl, PHP_URL_PATH) ?: $rawUrl);

        $this->get($rawPath)->assertNotFound();
    }

    #[Test]
    public function authenticated_admins_can_preview_candidate_media_via_the_guarded_dashboard_path(): void
    {
        Storage::fake('local');

        $run = MediaProcessingLog::factory()->livestream()->create();
        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'publication_status' => ServiceSectionPublicationStatus::PENDING_APPROVAL->value,
            'extracted_audio_path' => 'private/section-publications/701/audio.mp3',
            'extracted_video_path' => 'private/section-publications/701/video.mp4',
        ]);

        Storage::disk('local')->put((string) $section->extracted_audio_path, 'candidate-audio');
        Storage::disk('local')->put((string) $section->extracted_video_path, 'candidate-video');

        $this->actingAs($this->admin);

        $query = app(ServiceReviewDashboardQuery::class);
        $groups = $query->reviewGroups();

        $audioUrl = (string) $groups[0]['sections'][0]['audio_url'];
        $videoUrl = (string) $groups[0]['sections'][0]['video_url'];

        $audioResponse = $this->get((string) parse_url($audioUrl, PHP_URL_PATH))
            ->assertOk()
            ->assertHeader('Content-Type', 'audio/mpeg');
        $this->assertStringContainsString('private', (string) $audioResponse->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', (string) $audioResponse->headers->get('Cache-Control'));

        $videoResponse = $this->get((string) parse_url($videoUrl, PHP_URL_PATH))
            ->assertOk()
            ->assertHeader('Content-Type', 'video/mp4');
        $this->assertStringContainsString('private', (string) $videoResponse->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', (string) $videoResponse->headers->get('Cache-Control'));
    }
}
