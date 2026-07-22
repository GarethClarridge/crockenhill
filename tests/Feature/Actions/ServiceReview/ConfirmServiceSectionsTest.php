<?php

declare(strict_types=1);

namespace Tests\Feature\Actions\ServiceReview;

use App\Actions\ServiceReview\ConfirmServiceSections;
use App\Enums\SermonService;
use App\Enums\ServiceSectionSongMatchType;
use App\Enums\ServiceSectionType;
use App\Models\ChurchService;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ConfirmServiceSectionsTest extends TestCase
{
    use RefreshDatabase;

    private ConfirmServiceSections $action;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->action = app(ConfirmServiceSections::class);
        $this->admin = User::factory()->create(['is_admin' => true]);
    }

    #[Test]
    public function it_confirms_clearable_sections_and_lists_blocked_sections(): void
    {
        $service = ChurchService::factory()->create([
            'date' => '2026-06-07',
            'service' => SermonService::Morning,
        ]);

        $run = MediaProcessingLog::factory()->livestream()->create([
            'church_service_id' => $service->id,
            'extracted_date' => '2026-06-07',
            'extracted_service' => SermonService::Morning->value,
        ]);

        $clearable = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::Welcome->value,
            'needs_manual_review' => true,
            'metadata' => ['review_flags' => ['structure_low_confidence']],
        ]);

        $blocked = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::Song->value,
            'song_match_type' => ServiceSectionSongMatchType::Unmatched->value,
            'needs_manual_review' => true,
        ]);

        $result = $this->action->execute($service, $this->admin->id);

        $this->assertSame(1, $result['confirmed_count']);
        $this->assertSame(1, $result['skipped_reasons']['unmatched song'] ?? null);
        $this->assertFalse($clearable->fresh()?->needs_manual_review);
        $this->assertTrue($blocked->fresh()?->needs_manual_review);
    }
}
