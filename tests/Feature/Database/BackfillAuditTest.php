<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Enums\SermonContentType;
use App\Models\ChurchServiceItem;
use App\Models\MediaProcessingLog;
use App\Models\Preacher;
use App\Models\ScripturePassage;
use App\Models\Sermon;
use App\Models\SermonScriptureFilter;
use App\Models\Song;
use App\Support\BackfillAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BackfillAuditTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_reports_a_clean_state_when_every_backfill_postcondition_holds(): void
    {
        $preacher = Preacher::factory()->create();
        $passage = ScripturePassage::factory()->create();

        // The SermonObserver indexes scripture filters on save, so this sermon
        // arrives with its derived filter rows already in place.
        Sermon::factory()->create([
            'content_type' => SermonContentType::Sermon,
            'reference' => 'John 3:16',
            'preacher_id' => $preacher->id,
            'scripture_passage_id' => $passage->id,
        ]);

        $song = Song::factory()->create([
            'title' => 'Amazing Grace #123',
            'praise_number' => '123',
            'canonical_key' => 'amazing grace',
        ]);
        ChurchServiceItem::factory()->create([
            'type' => 'songs',
            'openlp_search_title' => 'Amazing Grace@',
            'song_id' => $song->id,
            'metadata' => null,
        ]);

        MediaProcessingLog::factory()->create([
            'processing_metadata' => ['extracted_date' => '2026-01-04', 'extracted_service' => 'morning'],
            'extracted_date' => '2026-01-04',
            'extracted_service' => 'morning',
        ]);

        $audit = app(BackfillAudit::class);
        $report = $audit->report();

        $this->assertSame(0, $report['sermons_missing_scripture_filters']);
        $this->assertSame(0, $report['songs_missing_praise_numbers']);
        $this->assertFalse($report['preachers_table_empty']);
        $this->assertSame(0, $report['song_link_drift']);
        $this->assertSame(0, $report['media_logs_missing_extracted_identity']);
        $this->assertFalse($report['songs_catalogue_missing']);
        $this->assertFalse($report['speaker_profiles_missing']);
        $this->assertSame(0, $report['sermons_missing_preacher']);
        $this->assertSame(0, $report['sermons_missing_scripture_passage']);
        $this->assertFalse($audit->hasFailures($report));
    }

    #[Test]
    public function it_detects_missed_scripture_filter_praise_number_preacher_and_media_identity_backfills(): void
    {
        Sermon::factory()->create([
            'content_type' => SermonContentType::Sermon,
            'reference' => 'John 3:16',
            'preacher_id' => null,
            'scripture_passage_id' => null,
        ]);
        Sermon::factory()->create([
            'content_type' => SermonContentType::Sermon,
            'reference' => 'Church anniversary address',
            'preacher_id' => null,
            'scripture_passage_id' => null,
        ]);

        // Simulate the environment where the filter backfill never ran by
        // removing the rows the observer created on save.
        SermonScriptureFilter::query()->delete();

        Song::factory()->create([
            'title' => 'Blessed Assurance #47',
            'praise_number' => null,
        ]);

        MediaProcessingLog::factory()->create([
            'processing_metadata' => ['extracted_date' => '2026-01-04', 'extracted_service' => 'morning'],
            'extracted_date' => null,
            'extracted_service' => null,
        ]);

        $audit = app(BackfillAudit::class);
        $report = $audit->report();

        $this->assertSame(1, $report['sermons_missing_scripture_filters'], 'Only the parseable reference should count.');
        $this->assertSame(1, $report['songs_missing_praise_numbers']);
        $this->assertTrue($report['preachers_table_empty']);
        $this->assertSame(1, $report['media_logs_missing_extracted_identity']);
        $this->assertSame(2, $report['sermons_missing_preacher']);
        $this->assertSame(2, $report['sermons_missing_scripture_passage']);
        $this->assertTrue($audit->hasFailures($report));
    }

    #[Test]
    public function it_measures_song_link_drift_without_persisting_links(): void
    {
        Song::factory()->create([
            'canonical_key' => 'song one',
            'title' => 'Song One',
        ]);
        $item = ChurchServiceItem::factory()->create([
            'type' => 'songs',
            'openlp_search_title' => 'Song One@',
            'song_id' => null,
        ]);

        $this->assertSame(1, app(BackfillAudit::class)->songLinkDrift());

        $item->refresh();
        $this->assertNull($item->song_id, 'The audit must run the linker as a dry run.');
    }

    #[Test]
    public function it_detects_a_missing_songs_catalogue_and_missing_speaker_profiles(): void
    {
        ChurchServiceItem::factory()->create([
            'type' => 'songs',
            'song_id' => null,
        ]);
        Sermon::factory()->create();

        $audit = app(BackfillAudit::class);

        $this->assertTrue($audit->songsCatalogueMissing());

        $this->assertFalse($audit->speakerProfilesMissing(), 'Disabled speaker identification must not fail the audit.');

        config()->set('media-processing.speaker_identification.enabled', true);
        $this->assertTrue($audit->speakerProfilesMissing());
    }
}
