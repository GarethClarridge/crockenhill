<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Enums\ServiceSectionType;
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
