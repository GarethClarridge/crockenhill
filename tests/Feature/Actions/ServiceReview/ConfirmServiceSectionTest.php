<?php

declare(strict_types=1);

namespace Tests\Feature\Actions\ServiceReview;

use App\Actions\ServiceReview\ConfirmServiceSection;
use App\Enums\ServiceSectionSongMatchType;
use App\Enums\ServiceSectionType;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ConfirmServiceSectionTest extends TestCase
{
    use RefreshDatabase;

    private ConfirmServiceSection $action;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->action = app(ConfirmServiceSection::class);
        $this->admin = User::factory()->create(['is_admin' => true]);
    }

    #[Test]
    public function it_clears_review_state_and_records_confirmation_audit_metadata(): void
    {
        $run = MediaProcessingLog::factory()->livestream()->create();

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'needs_manual_review' => true,
            'metadata' => [
                'review_reason' => 'structure_low_confidence',
                'review_flags' => ['structure_low_confidence'],
                'manual_review' => ['previous' => 'value'],
            ],
        ]);

        $this->action->execute($section, $this->admin->id);

        $fresh = $section->fresh();
        $metadata = $fresh?->metadata?->toArray() ?? [];

        $this->assertFalse($fresh?->needs_manual_review);
        $this->assertArrayNotHasKey('review_reason', $metadata);
        $this->assertArrayNotHasKey('review_flags', $metadata);
        $this->assertSame('value', $metadata['manual_review']['previous'] ?? null);
        $this->assertSame($this->admin->id, $metadata['manual_review']['confirmed_by_user_id'] ?? null);
        $this->assertNotEmpty($metadata['manual_review']['confirmed_at'] ?? null);
    }

    #[Test]
    public function it_promotes_an_inferred_song_match_when_the_operator_confirms_it(): void
    {
        $run = MediaProcessingLog::factory()->livestream()->create();

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::Song->value,
            'song_match_type' => ServiceSectionSongMatchType::Inferred->value,
            'needs_manual_review' => true,
            'metadata' => ['review_flags' => ['song_alignment_inferred']],
        ]);

        $this->action->execute($section, $this->admin->id);

        $fresh = $section->fresh();
        $metadata = $fresh?->metadata?->toArray() ?? [];

        $this->assertSame(ServiceSectionSongMatchType::Confirmed, $fresh?->song_match_type);
        $this->assertNotEmpty($metadata['manual_review']['song_match_reviewed_at'] ?? null);
        $this->assertSame($this->admin->id, $metadata['manual_review']['song_match_reviewed_by_user_id'] ?? null);
    }

    #[Test]
    public function it_records_an_unmatched_song_match_as_reviewed_without_falsely_linking_a_song(): void
    {
        $run = MediaProcessingLog::factory()->livestream()->create();

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::Song->value,
            'song_match_type' => ServiceSectionSongMatchType::Unmatched->value,
            'needs_manual_review' => true,
        ]);

        $this->action->execute($section, $this->admin->id);

        $fresh = $section->fresh();
        $metadata = $fresh?->metadata?->toArray() ?? [];

        $this->assertFalse($fresh?->needs_manual_review);
        $this->assertSame(ServiceSectionSongMatchType::Unmatched, $fresh?->song_match_type);
        $this->assertNotEmpty($metadata['manual_review']['song_match_reviewed_at'] ?? null);
    }
}
