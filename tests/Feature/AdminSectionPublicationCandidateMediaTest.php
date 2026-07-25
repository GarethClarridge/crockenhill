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

    /** The disk phpunit.xml configures as the sermon disk, where candidates live. */
    private const string CANDIDATE_DISK = 'public';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'is_admin' => true,
            'email_verified_at' => now(),
        ]);
    }

    /**
     * Candidates live on the sermon disk now, so the bytes are reachable by
     * anyone holding the key. What the review dashboard must never do is hand
     * that key out: every link it renders goes through the admin-guarded
     * preview route, which is where authorisation happens.
     */
    #[Test]
    public function the_review_dashboard_links_candidate_media_only_through_the_guarded_route(): void
    {
        Storage::fake(self::CANDIDATE_DISK);

        $run = MediaProcessingLog::factory()->livestream()->create();
        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'publication_status' => ServiceSectionPublicationStatus::PendingApproval->value,
            'extracted_audio_path' => 'section-publications/700-abcdef0123456789/audio.mp3',
            'extracted_video_path' => 'section-publications/700-abcdef0123456789/video.mp4',
        ]);

        Storage::disk(self::CANDIDATE_DISK)->put((string) $section->extracted_audio_path, 'candidate-audio');
        Storage::disk(self::CANDIDATE_DISK)->put((string) $section->extracted_video_path, 'candidate-video');

        $this->actingAs($this->admin);

        $sectionData = app(ServiceReviewDashboardQuery::class)->reviewGroups()[0]['sections'][0];

        $this->assertSame(
            route('admin.services.section-publications.preview-audio', $section),
            (string) $sectionData['audio_url'],
        );
        $this->assertSame(
            route('admin.services.section-publications.preview-video', $section),
            (string) $sectionData['video_url'],
        );

        // A guest hitting either link is bounced, never served.
        auth()->logout();
        $this->get((string) $sectionData['audio_url'])->assertRedirect(route('login'));
    }

    #[Test]
    public function authenticated_admins_can_preview_candidate_media_via_the_guarded_dashboard_path(): void
    {
        Storage::fake(self::CANDIDATE_DISK);

        $run = MediaProcessingLog::factory()->livestream()->create();
        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'publication_status' => ServiceSectionPublicationStatus::PendingApproval->value,
            'extracted_audio_path' => 'section-publications/701-abcdef0123456789/audio.mp3',
            'extracted_video_path' => 'section-publications/701-abcdef0123456789/video.mp4',
        ]);

        Storage::disk(self::CANDIDATE_DISK)->put((string) $section->extracted_audio_path, 'candidate-audio');
        Storage::disk(self::CANDIDATE_DISK)->put((string) $section->extracted_video_path, 'candidate-video');

        $this->actingAs($this->admin);

        $sectionData = app(ServiceReviewDashboardQuery::class)->reviewGroups()[0]['sections'][0];

        $audioResponse = $this->get((string) parse_url((string) $sectionData['audio_url'], PHP_URL_PATH));
        $audioResponse->assertRedirect(Storage::disk(self::CANDIDATE_DISK)->url((string) $section->extracted_audio_path));
        $this->assertStringContainsString('private', (string) $audioResponse->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', (string) $audioResponse->headers->get('Cache-Control'));

        $videoResponse = $this->get((string) parse_url((string) $sectionData['video_url'], PHP_URL_PATH));
        $videoResponse->assertRedirect(Storage::disk(self::CANDIDATE_DISK)->url((string) $section->extracted_video_path));
    }
}
