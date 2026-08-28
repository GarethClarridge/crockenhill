<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Enums\ServiceSectionType;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\ChurchService\LivestreamSectionToServiceItemMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LivestreamSectionToServiceItemMapperTest extends TestCase
{
    use RefreshDatabase;

    private LivestreamSectionToServiceItemMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mapper = new LivestreamSectionToServiceItemMapper;
    }

    #[Test]
    public function test_maps_classified_sections_to_item_payloads(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->create([
            'processing_id' => 'test-projection-001',
        ]);

        $sections = ServiceSection::factory()
            ->count(3)
            ->sequence(
                [
                    'section_type' => ServiceSectionType::Song,
                    'section_order' => 1,
                    'title' => 'Amazing Grace',
                    'confidence' => 0.95,
                    'source_segment_ids' => [1, 2],
                ],
                [
                    'section_type' => ServiceSectionType::Sermon,
                    'section_order' => 2,
                    'title' => 'Sermon',
                    'confidence' => 0.85,
                    'source_segment_ids' => [3],
                ],
                [
                    'section_type' => ServiceSectionType::Prayer,
                    'section_order' => 3,
                    'title' => 'Closing Prayer',
                    'confidence' => 0.7,
                    'source_segment_ids' => [4],
                ],
            )
            ->create([
                'media_processing_log_id' => $log->id,
                'church_service_item_id' => null,
            ]);

        $result = $this->mapper->map($sections->fresh(), $log->processing_id);

        $this->assertCount(3, $result);

        $this->assertSame(1, $result[0]['position']);
        $this->assertSame('songs', $result[0]['type']);
        $this->assertSame('song', $result[0]['section_type']);
        $this->assertSame('Amazing Grace', $result[0]['title']);
        $this->assertNull($result[0]['openlp_search_title']);
        $this->assertNull($result[0]['song_id']);
        $this->assertSame('high', $result[0]['metadata']['livestream_projection']['confidence_level']);
        $this->assertSame($log->processing_id, $result[0]['livestream_processing_id']);

        $this->assertSame(2, $result[1]['position']);
        $this->assertSame('custom', $result[1]['type']);
        $this->assertSame('sermon', $result[1]['section_type']);

        $this->assertSame(3, $result[2]['position']);
        $this->assertSame('custom', $result[2]['type']);
        $this->assertSame('prayer', $result[2]['section_type']);
    }

    #[Test]
    public function test_excludes_other_section_type(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->create();

        ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::Other,
            'section_order' => 1,
            'confidence' => 0.9,
        ]);

        $sections = ServiceSection::query()
            ->where('media_processing_log_id', $log->id)
            ->orderBy('section_order')
            ->get();

        $result = $this->mapper->map($sections, $log->processing_id);

        $this->assertCount(0, $result);
    }

    #[Test]
    public function test_excludes_low_confidence_sections(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->create();

        ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::Prayer,
            'section_order' => 1,
            'confidence' => 0.2,
        ]);

        $sections = ServiceSection::query()
            ->where('media_processing_log_id', $log->id)
            ->orderBy('section_order')
            ->get();

        $result = $this->mapper->map($sections, $log->processing_id);

        $this->assertCount(0, $result);
    }

    #[Test]
    public function test_uses_section_type_label_when_title_is_null(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->create();

        ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::Prayer,
            'section_order' => 1,
            'title' => null,
            'confidence' => 0.9,
        ]);

        $sections = ServiceSection::query()
            ->where('media_processing_log_id', $log->id)
            ->orderBy('section_order')
            ->get();

        $result = $this->mapper->map($sections, $log->processing_id);

        $this->assertCount(1, $result);
        $this->assertSame('Prayer', $result[0]['title']);
    }

    #[Test]
    public function test_maps_bible_reading_to_bibles_type(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->create();

        ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::BibleReading,
            'section_order' => 1,
            'title' => 'Luke 15:1-32',
            'confidence' => 0.9,
        ]);

        $sections = ServiceSection::query()
            ->where('media_processing_log_id', $log->id)
            ->orderBy('section_order')
            ->get();

        $result = $this->mapper->map($sections, $log->processing_id);

        $this->assertCount(1, $result);
        $this->assertSame('bibles', $result[0]['type']);
        $this->assertSame('bible_reading', $result[0]['section_type']);
    }

    #[Test]
    public function test_assigns_correct_confidence_levels(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->create();

        $sections = ServiceSection::factory()
            ->count(3)
            ->sequence(
                ['section_type' => ServiceSectionType::Song, 'section_order' => 1, 'confidence' => 0.9],
                ['section_type' => ServiceSectionType::Prayer, 'section_order' => 2, 'confidence' => 0.6],
                ['section_type' => ServiceSectionType::Sermon, 'section_order' => 3, 'confidence' => 0.35],
            )
            ->create([
                'media_processing_log_id' => $log->id,
                'church_service_item_id' => null,
            ]);

        $result = $this->mapper->map($sections->fresh(), $log->processing_id);

        $this->assertSame('high', $result[0]['metadata']['livestream_projection']['confidence_level']);
        $this->assertSame('medium', $result[1]['metadata']['livestream_projection']['confidence_level']);
        $this->assertSame('low', $result[2]['metadata']['livestream_projection']['confidence_level']);
    }

    #[Test]
    public function test_preserves_projection_provenance_in_columns_only(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->create();

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::Song,
            'section_order' => 1,
            'confidence' => 0.9,
            'source_segment_ids' => [5, 6, 7],
        ]);

        $sections = ServiceSection::query()
            ->where('media_processing_log_id', $log->id)
            ->orderBy('section_order')
            ->get();

        $result = $this->mapper->map($sections, $log->processing_id);

        $this->assertSame($section->id, $result[0]['livestream_service_section_id']);
        $this->assertSame($log->processing_id, $result[0]['livestream_processing_id']);
        $this->assertArrayNotHasKey('service_section_id', $result[0]['metadata']['livestream_projection']);
        $this->assertArrayNotHasKey('processing_id', $result[0]['metadata']['livestream_projection']);
        $this->assertSame([5, 6, 7], $result[0]['metadata']['livestream_projection']['source_segment_ids']);
    }

    #[Test]
    public function test_uses_song_title_hint_as_title_when_section_title_is_null(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->create();

        ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::Song,
            'section_order' => 1,
            'title' => null,
            'confidence' => 0.9,
            'metadata' => [
                'classification_mode' => 'audio_only',
                'song_title_hint' => 'Though the Nations Rage',
            ],
        ]);

        $sections = ServiceSection::query()
            ->where('media_processing_log_id', $log->id)
            ->orderBy('section_order')
            ->get();

        $result = $this->mapper->map($sections, $log->processing_id);

        $this->assertCount(1, $result);
        $this->assertSame('Though the Nations Rage', $result[0]['title']);
    }

    #[Test]
    public function test_song_title_hint_does_not_override_an_explicit_section_title(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->create();

        ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::Song,
            'section_order' => 1,
            'title' => 'Amazing Grace',
            'confidence' => 0.9,
            'metadata' => [
                'classification_mode' => 'audio_only',
                'song_title_hint' => 'Though the Nations Rage',
            ],
        ]);

        $sections = ServiceSection::query()
            ->where('media_processing_log_id', $log->id)
            ->orderBy('section_order')
            ->get();

        $result = $this->mapper->map($sections, $log->processing_id);

        $this->assertCount(1, $result);
        $this->assertSame('Amazing Grace', $result[0]['title']);
    }

    #[Test]
    public function test_song_title_hint_is_not_used_for_non_song_section_types(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->create();

        ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::Prayer,
            'section_order' => 1,
            'title' => null,
            'confidence' => 0.9,
            'metadata' => [
                'song_title_hint' => 'Though the Nations Rage',
            ],
        ]);

        $sections = ServiceSection::query()
            ->where('media_processing_log_id', $log->id)
            ->orderBy('section_order')
            ->get();

        $result = $this->mapper->map($sections, $log->processing_id);

        $this->assertCount(1, $result);
        $this->assertSame('Prayer', $result[0]['title']);
    }

    #[Test]
    public function test_renumbers_positions_after_filtering(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->create();

        ServiceSection::factory()
            ->count(3)
            ->sequence(
                ['section_type' => ServiceSectionType::Song, 'section_order' => 1, 'confidence' => 0.9],
                ['section_type' => ServiceSectionType::Other, 'section_order' => 2, 'confidence' => 0.9],
                ['section_type' => ServiceSectionType::Sermon, 'section_order' => 3, 'confidence' => 0.9],
            )
            ->create([
                'media_processing_log_id' => $log->id,
                'church_service_item_id' => null,
            ]);

        $sections = ServiceSection::query()
            ->where('media_processing_log_id', $log->id)
            ->orderBy('section_order')
            ->get();

        $result = $this->mapper->map($sections, $log->processing_id);

        $this->assertCount(2, $result);
        $this->assertSame(1, $result[0]['position']);
        $this->assertSame(2, $result[1]['position']);
    }

    #[Test]
    public function test_carries_the_sections_reading_reference_onto_the_projected_item(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->create();

        ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::BibleReading,
            'section_order' => 1,
            'title' => 'Paul addresses the Areopagus',
            'confidence' => 0.9,
            'metadata' => [
                'reading_reference' => 'Acts 17:22-31',
            ],
        ]);

        $sections = ServiceSection::query()
            ->where('media_processing_log_id', $log->id)
            ->orderBy('section_order')
            ->get();

        $result = $this->mapper->map($sections, $log->processing_id);

        $this->assertCount(1, $result);
        $this->assertSame(
            'Paul addresses the Areopagus',
            $result[0]['title'],
            'The descriptive title is editorial output and must survive intact.',
        );
        $this->assertSame('Acts 17:22-31', $result[0]['metadata']['scripture_reference']);
    }

    #[Test]
    public function test_omits_the_scripture_reference_when_the_section_names_no_passage(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->create();

        ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::Song,
            'section_order' => 1,
            'title' => 'Amazing Grace',
            'confidence' => 0.9,
            'metadata' => [],
        ]);

        $sections = ServiceSection::query()
            ->where('media_processing_log_id', $log->id)
            ->orderBy('section_order')
            ->get();

        $result = $this->mapper->map($sections, $log->processing_id);

        $this->assertArrayNotHasKey('scripture_reference', $result[0]['metadata']);
    }
}
