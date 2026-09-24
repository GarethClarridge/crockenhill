<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Enums\ServiceSectionPublicationStatus;
use App\Enums\ServiceSectionType;
use App\Enums\TalkType;
use App\Models\ServiceSection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Talks plan §4.5 steps 2–3: `childrens_talk` sections become `short_talk`
 * carrying an honest `childrens_talk` proposal, and the speaker, boundary and
 * flag names drop "children's".
 */
class ShortTalkSectionMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const TYPES = "enum('welcome','prayer','notices','song','short_talk','bible_reading','sermon','other')";

    #[Test]
    public function both_section_type_columns_name_short_talk_and_not_childrens_talk(): void
    {
        $this->assertSame(self::TYPES, $this->columnType('service_sections', 'section_type'));
        $this->assertSame(self::TYPES, $this->columnType('church_service_items', 'section_type'));
    }

    #[Test]
    public function the_publication_media_check_still_names_only_songs(): void
    {
        /** @var string $clause */
        $clause = DB::table('information_schema.check_constraints')
            ->whereRaw('constraint_schema = database()')
            ->where('constraint_name', 'service_sections_publication_media_check')
            ->value('check_clause');

        $this->assertStringContainsString('`section_type` = _utf8mb4', $clause);
        $this->assertStringContainsString('song', $clause);
        $this->assertStringNotContainsString('talk', $clause);
    }

    #[Test]
    public function the_metadata_migration_moves_keys_and_flags_and_proposes_childrens_talk(): void
    {
        $talk = ServiceSection::factory()->create([
            'section_type' => ServiceSectionType::ShortTalk->value,
            'metadata' => [
                'childrens_talk_speaker' => ['predicted' => ['preacher_name' => 'Jane Doe']],
                'childrens_talk_boundary' => ['candidate' => ['start_time' => 1.0]],
                'review_flags' => ['childrens_talk_speaker_review', 'structure_low_confidence'],
                'review_reason' => 'childrens_talk_speaker_no_match',
            ],
        ]);
        $prayer = ServiceSection::factory()->create([
            'section_type' => ServiceSectionType::Prayer->value,
            'metadata' => [
                'review_flags' => ['ambiguous_childrens_talk'],
                'previous_section' => ['section_type' => 'childrens_talk'],
            ],
        ]);

        $this->migration()->up();

        $talkMetadata = $this->rawMetadata($talk);
        $this->assertSame(['predicted' => ['preacher_name' => 'Jane Doe']], $talkMetadata['talk_speaker']);
        $this->assertSame(['candidate' => ['start_time' => 1]], $talkMetadata['short_talk_boundary']);
        $this->assertArrayNotHasKey('childrens_talk_speaker', $talkMetadata);
        $this->assertSame(['talk_speaker_review', 'structure_low_confidence'], $talkMetadata['review_flags']);
        $this->assertSame('talk_speaker_no_match', $talkMetadata['review_reason']);
        $this->assertSame(['proposed' => 'childrens_talk'], $talkMetadata['talk_type']);

        $prayerMetadata = $this->rawMetadata($prayer);
        $this->assertSame(['ambiguous_short_talk'], $prayerMetadata['review_flags']);
        $this->assertSame(['section_type' => 'childrens_talk'], $prayerMetadata['previous_section']);
        $this->assertArrayNotHasKey('talk_type', $prayerMetadata);
    }

    #[Test]
    public function the_metadata_migration_keeps_a_proposal_already_present(): void
    {
        $talk = ServiceSection::factory()->create([
            'section_type' => ServiceSectionType::ShortTalk->value,
            'metadata' => ['talk_type' => ['proposed' => 'testimony']],
        ]);

        $this->migration()->up();

        $this->assertSame(['proposed' => 'testimony'], $this->rawMetadata($talk)['talk_type']);
    }

    #[Test]
    public function the_restamp_renews_only_signatures_stale_by_the_rename_alone(): void
    {
        $renamed = ServiceSection::factory()->create(['section_type' => ServiceSectionType::ShortTalk->value]);
        $payload = $renamed->classificationSignaturePayload();
        $payload['section_type'] = 'childrens_talk';
        $oldSignature = hash('sha256', (string) json_encode($payload));
        $this->putMetadata($renamed, [
            'publication_candidate_extraction' => ['classification_signature' => $oldSignature],
            'publication' => ['approved_signature' => $oldSignature],
        ]);
        $reallyStale = ServiceSection::factory()->create(['section_type' => ServiceSectionType::ShortTalk->value]);
        $this->putMetadata($reallyStale, ['publication_candidate_extraction' => ['classification_signature' => 'moved-boundary']]);

        (require database_path('migrations/2026_09_24_061346_restamp_short_talk_classification_signatures.php'))->up();

        $renamedMetadata = $this->rawMetadata($renamed);
        $this->assertSame($renamed->refresh()->classificationSignature(), $renamedMetadata['publication_candidate_extraction']['classification_signature']);
        $this->assertSame($renamed->classificationSignature(), $renamedMetadata['publication']['approved_signature']);
        $this->assertSame('moved-boundary', $this->rawMetadata($reallyStale)['publication_candidate_extraction']['classification_signature']);
    }

    #[Test]
    public function the_media_stamp_conversion_keeps_every_candidate_whose_cut_is_unchanged(): void
    {
        $reviewedSpeaker = ['reviewed' => ['preacher_id' => null, 'preacher_name' => 'Jane Doe', 'source' => 'manual']];

        $stampedBeforeSpeakerReview = ServiceSection::factory()->create(['section_type' => ServiceSectionType::ShortTalk->value]);
        $payload = $stampedBeforeSpeakerReview->classificationSignaturePayload();
        $payload['publication_speaker'] = null;
        $this->putMetadata($stampedBeforeSpeakerReview, [
            'talk_speaker' => $reviewedSpeaker,
            'publication_candidate_extraction' => ['processing_id' => 'p1', 'classification_signature' => hash('sha256', (string) json_encode($payload))],
        ]);

        $song = ServiceSection::factory()->create(['section_type' => ServiceSectionType::Song->value]);
        $this->putMetadata($song, [
            'publication_candidate_extraction' => ['processing_id' => 'p2', 'classification_signature' => $song->classificationSignature()],
        ]);

        $movedBoundary = ServiceSection::factory()->create(['section_type' => ServiceSectionType::Song->value]);
        $this->putMetadata($movedBoundary, [
            'publication_candidate_extraction' => ['processing_id' => 'p3', 'classification_signature' => 'moved-boundary'],
        ]);

        (require $this->migrationPath('stamp_candidate_media_signatures'))->up();

        $converted = $this->rawMetadata($stampedBeforeSpeakerReview)['publication_candidate_extraction'];
        $this->assertSame(['processing_id' => 'p1', 'media_signature' => $stampedBeforeSpeakerReview->refresh()->mediaSignature()], $converted);
        $this->assertSame($song->refresh()->mediaSignature(), $this->rawMetadata($song)['publication_candidate_extraction']['media_signature']);
        $this->assertSame(
            ['processing_id' => 'p3', 'classification_signature' => 'moved-boundary'],
            $this->rawMetadata($movedBoundary)['publication_candidate_extraction'],
        );
    }

    #[Test]
    public function approved_and_published_short_talks_keep_the_childrens_talk_type_they_were_approved_as(): void
    {
        $approved = ServiceSection::factory()->create([
            'section_type' => ServiceSectionType::ShortTalk->value,
            'publication_status' => ServiceSectionPublicationStatus::Approved->value,
        ]);
        $this->putMetadata($approved, [
            'talk_type' => ['proposed' => 'childrens_talk'],
            'publication' => ['approved_signature' => $approved->classificationSignature(), 'approved_at' => '2026-09-01T10:00:00+00:00'],
        ]);
        $published = ServiceSection::factory()->create([
            'section_type' => ServiceSectionType::ShortTalk->value,
            'publication_status' => ServiceSectionPublicationStatus::Published->value,
        ]);
        $this->putMetadata($published, ['talk_type' => ['proposed' => 'childrens_talk']]);
        $pending = ServiceSection::factory()->create([
            'section_type' => ServiceSectionType::ShortTalk->value,
            'publication_status' => ServiceSectionPublicationStatus::PendingApproval->value,
        ]);
        $this->putMetadata($pending, ['talk_type' => ['proposed' => 'childrens_talk']]);

        (require $this->migrationPath('confirm_talk_type_of_approved_short_talks'))->up();

        $approved->refresh();
        $this->assertSame(TalkType::ChildrensTalk, $approved->publicationTalkType());
        $this->assertNull($this->rawMetadata($approved)['talk_type']['reviewed']['user_id']);
        $this->assertSame('2026-09-01T10:00:00+00:00', $this->rawMetadata($approved)['talk_type']['reviewed']['at']);
        $this->assertSame($approved->classificationSignature(), $this->rawMetadata($approved)['publication']['approved_signature']);
        $this->assertSame(TalkType::ChildrensTalk, $published->refresh()->publicationTalkType());
        $this->assertNull($pending->refresh()->publicationTalkType());
    }

    private function migrationPath(string $name): string
    {
        $paths = glob(database_path("migrations/*_{$name}.php")) ?: [];
        $this->assertCount(1, $paths);

        return $paths[0];
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function putMetadata(ServiceSection $section, array $metadata): void
    {
        DB::table('service_sections')->where('id', $section->id)->update(['metadata' => json_encode($metadata)]);
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_09_23_215704_move_short_talk_section_metadata_keys.php');
    }

    /**
     * @return array<string, mixed>
     */
    private function rawMetadata(ServiceSection $section): array
    {
        return json_decode((string) DB::table('service_sections')->where('id', $section->id)->value('metadata'), true);
    }

    private function columnType(string $table, string $column): string
    {
        /** @var string $columnType */
        $columnType = DB::table('information_schema.columns')
            ->whereRaw('table_schema = database()')
            ->where('table_name', $table)
            ->where('column_name', $column)
            ->value('column_type');

        return $columnType;
    }
}
