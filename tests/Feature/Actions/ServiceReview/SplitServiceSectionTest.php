<?php

declare(strict_types=1);

namespace Tests\Feature\Actions\ServiceReview;

use App\Actions\HoldSectionForContentReview;
use App\Actions\ServiceReview\SplitServiceSection;
use App\Enums\ContentHoldCheck;
use App\Enums\ServiceSectionPublicationStatus;
use App\Enums\ServiceSectionType;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class SplitServiceSectionTest extends TestCase
{
    use RefreshDatabase;

    private SplitServiceSection $action;

    private User $admin;

    private MediaProcessingLog $log;

    protected function setUp(): void
    {
        parent::setUp();

        $this->action = app(SplitServiceSection::class);
        $this->admin = User::factory()->admin()->create();
        $this->log = MediaProcessingLog::factory()->livestream()->create();
    }

    /**
     * Run 1311: three people's testimonies detected as one talk. Splitting at the change of
     * speaker gives each part its own section of the same type, in order.
     */
    #[Test]
    public function it_splits_a_section_into_two_of_the_same_type_at_the_chosen_time(): void
    {
        $before = $this->section(1, ServiceSectionType::Prayer, 480.0, 820.0);
        $talk = $this->section(2, ServiceSectionType::ShortTalk, 820.0, 1198.0, 'Baptismal testimonies');
        $after = $this->section(3, ServiceSectionType::Other, 1200.0, 1255.0);

        $this->assertNull($this->action->execute($talk, 916.0, $this->admin->id));

        $talk->refresh();
        $this->assertSame(820.0, (float) $talk->start_time);
        $this->assertSame(916.0, (float) $talk->end_time);
        $this->assertSame(96.0, (float) $talk->duration);

        $second = ServiceSection::query()
            ->where('media_processing_log_id', $this->log->id)
            ->where('start_time', 916.0)
            ->sole();
        $this->assertSame(ServiceSectionType::ShortTalk, $second->section_type);
        $this->assertSame(1198.0, (float) $second->end_time);
        $this->assertSame('Baptismal testimonies', $second->title);
        $this->assertTrue($second->needs_manual_review);
        $this->assertNull($second->church_service_item_id);

        $this->assertSame(
            [$before->id, $talk->id, $second->id, $after->id],
            ServiceSection::query()->where('media_processing_log_id', $this->log->id)->orderBy('section_order')->pluck('id')->all(),
        );

        $split = $talk->metadata?->toArray()['manually_split'] ?? null;
        $this->assertSame(916.0, (float) $split['at']);
        $this->assertSame($this->admin->id, $split['by_user_id']);
        $this->assertSame($second->id, $split['new_section_id']);
        $this->assertSame($talk->id, $second->metadata?->toArray()['split_from_section_id'] ?? null);
    }

    #[Test]
    public function a_split_of_the_last_section_needs_no_renumbering(): void
    {
        $talk = $this->section(1, ServiceSectionType::ShortTalk, 0.0, 400.0);

        $this->assertNull($this->action->execute($talk, 200.0, $this->admin->id));

        $this->assertSame([1, 2], ServiceSection::query()->where('media_processing_log_id', $this->log->id)->orderBy('section_order')->pluck('section_order')->all());
    }

    /**
     * Neither part can say which of them a hold's evidence lies in, so both keep it until a
     * person releases it.
     */
    #[Test]
    public function both_parts_keep_a_content_hold(): void
    {
        $talk = $this->section(1, ServiceSectionType::ShortTalk, 0.0, 400.0);
        app(HoldSectionForContentReview::class)($talk, 'Speech invented over silence', 'plan §4.0', ContentHoldCheck::SourceAudio);

        $this->assertNull($this->action->execute($talk->refresh(), 200.0, $this->admin->id));

        foreach (ServiceSection::query()->where('media_processing_log_id', $this->log->id)->get() as $part) {
            $this->assertTrue(HoldSectionForContentReview::isHeld($part->metadata?->toArray()['review_flags'] ?? []), "Section {$part->id} lost the hold.");
        }
    }

    #[Test]
    public function it_resets_the_original_sections_extracted_media(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('talks/talk.mp4', 'video');
        Storage::disk('public')->put('talks/talk.mp3', 'audio');

        $talk = $this->section(1, ServiceSectionType::ShortTalk, 0.0, 400.0);
        $talk->update([
            'extracted_video_path' => 'talks/talk.mp4',
            'extracted_audio_path' => 'talks/talk.mp3',
            'extracted_at' => now(),
            'publication_status' => ServiceSectionPublicationStatus::PendingApproval,
        ]);

        $this->assertNull($this->action->execute($talk, 200.0, $this->admin->id));

        $talk->refresh();
        $this->assertNull($talk->extracted_video_path);
        $this->assertNull($talk->extracted_audio_path);
        $this->assertSame(ServiceSectionPublicationStatus::NotApplicable, $talk->publication_status);
    }

    #[Test]
    #[TestWith([10.0], 'first part too short')]
    #[TestWith([390.0], 'second part too short')]
    #[TestWith([0.0], 'at the start')]
    #[TestWith([400.0], 'at the end')]
    #[TestWith([450.0], 'outside the section')]
    public function it_refuses_a_split_that_would_leave_a_part_under_fifteen_seconds(float $at): void
    {
        $talk = $this->section(1, ServiceSectionType::ShortTalk, 0.0, 400.0);

        $this->assertSame(
            'Split at least 15 seconds inside the section, so neither part is shorter than that.',
            $this->action->execute($talk, $at, $this->admin->id),
        );
        $this->assertSame(1, ServiceSection::query()->where('media_processing_log_id', $this->log->id)->count());
    }

    #[Test]
    public function it_refuses_a_published_section(): void
    {
        $talk = ServiceSection::factory()->create([
            'media_processing_log_id' => $this->log->id,
            'section_type' => ServiceSectionType::ShortTalk,
            'section_order' => 1,
            'start_time' => 0.0,
            'end_time' => 400.0,
            'published_at' => now(),
        ]);

        $this->assertSame('Published sections cannot be split.', $this->action->execute($talk, 200.0, $this->admin->id));
    }

    private function section(int $order, ServiceSectionType $type, float $start, float $end, ?string $title = null): ServiceSection
    {
        return ServiceSection::factory()->create([
            'media_processing_log_id' => $this->log->id,
            'section_type' => $type,
            'section_order' => $order,
            'title' => $title ?? $type->value,
            'start_time' => $start,
            'end_time' => $end,
            'duration' => $end - $start,
            'source_segment_ids' => [1, 2],
            'needs_manual_review' => true,
            'publication_status' => ServiceSectionPublicationStatus::NotApplicable,
            'metadata' => ['review_flags' => []],
        ]);
    }
}
