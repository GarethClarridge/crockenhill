<?php

declare(strict_types=1);

namespace Tests\Integration\Models;

use App\Data\ChurchServiceImportMetadata;
use App\Data\ServiceSectionMetadata;
use App\Enums\ServiceSectionType;
use App\Models\ChurchService;
use App\Models\ServiceSection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StableJsonMetadataWrappersTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function church_service_import_metadata_round_trips_mixed_historical_payloads(): void
    {
        $payload = [
            'confidence_score' => 0.82,
            'parse_method' => 'openlp',
            'warnings' => ['Needs review'],
            'manual_review' => [
                'reviewed_at' => '2026-03-17T12:00:00+00:00',
                'reviewed_by_user_id' => 7,
                'reopened_at' => '2026-03-18T12:00:00+00:00',
                'reopened_by_source' => 'openlp',
            ],
            'canonical_conflict' => [
                'detected_at' => '2026-03-18T12:00:00+00:00',
                'incoming_source' => 'openlp',
                'review_reopened' => true,
                'reviewed_previously' => true,
                'canonical_changed' => true,
                'changes' => [['field' => 'title']],
                'conflicts' => [['type' => 'position']],
            ],
            'canonical_conflict_history' => [[
                'incoming_source' => 'email',
            ]],
            'review_triggers' => ['manual_review_sections'],
            'manual_edit' => [
                'saved_by_user_id' => 4,
            ],
        ];

        $service = ChurchService::factory()->create([
            'import_metadata' => $payload,
        ]);

        $this->assertInstanceOf(ChurchServiceImportMetadata::class, $service->import_metadata);

        $metadata = $service->import_metadata;

        $this->assertSame(0.82, $metadata->confidenceScore);
        $this->assertSame('openlp', $metadata->parseMethod);
        $this->assertSame('2026-03-17T12:00:00+00:00', $metadata->manualReview?->reviewedAt);
        $serialized = $metadata?->toArray();
        $this->assertArrayNotHasKey('canonical_conflict', $serialized);
        $this->assertSame($payload['canonical_conflict_history'], $serialized['canonical_conflict_history'] ?? null);
    }

    #[Test]
    public function service_section_metadata_round_trips_alignment_and_childrens_talk_metadata(): void
    {
        $payload = [
            'confidence_level' => 'medium',
            'classification_mode' => 'openlp_aligned',
            'confidence_source' => 'ai_transcript',
            'confidence_score' => 0.72,
            'review_reason' => 'childrens_talk_speaker_ambiguous',
            'review_flags' => ['childrens_talk_speaker_review'],
            'transcript' => 'Segment transcript',
            'song_id' => 12,
            'reading_reference' => 'Psalm 23',
            'oos_alignment' => [
                'song_match_type' => 'inferred',
                'song_match_strategy' => 'oos_order_inference',
                'matched_item_id' => 5,
                'presentation_inference' => [
                    'resolved_type' => 'presentation',
                ],
            ],
            'childrens_talk_speaker' => [
                'predicted' => [
                    'outcome' => 'ambiguous',
                ],
                'reviewed' => [
                    'preacher_id' => 4,
                    'preacher_name' => 'Mary Helper',
                    'source' => 'manual',
                    'confidence' => 0.88,
                ],
            ],
            'publication' => [
                'approved_signature' => 'abc123',
                'approved_at' => '2026-03-17T13:00:00+00:00',
                'batch_approvals' => [[
                    'batch_id' => 'batch-1',
                ]],
            ],
            'cleanup' => [
                'reason' => 'scheduler',
            ],
        ];

        $section = ServiceSection::factory()->create([
            'section_type' => ServiceSectionType::ChildrensTalk->value,
            'metadata' => $payload,
        ]);

        $this->assertInstanceOf(ServiceSectionMetadata::class, $section->metadata);

        $metadata = $section->metadata;

        $this->assertSame('medium', $metadata?->confidenceLevel);
        $this->assertSame('inferred', $metadata->oosAlignment?->raw['song_match_type'] ?? null);
        $this->assertSame('Mary Helper', $metadata->childrensTalkSpeaker?->reviewed['preacher_name'] ?? null);
        $this->assertSame('Mary Helper', $section->publicationChildrensTalkSpeaker()['preacher_name'] ?? null);
        $serialized = $metadata?->toArray();

        $this->assertArrayNotHasKey('song_match_type', $serialized['oos_alignment'] ?? []);
        $this->assertArrayNotHasKey('matched_item_id', $serialized['oos_alignment'] ?? []);
        $this->assertSame('oos_order_inference', $serialized['oos_alignment']['song_match_strategy'] ?? null);
        $this->assertSame('presentation', $serialized['oos_alignment']['presentation_inference']['resolved_type'] ?? null);
    }
}
