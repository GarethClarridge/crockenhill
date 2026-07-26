<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Enums\SermonContentType;
use App\Models\ChurchServiceItem;
use App\Models\Preacher;
use App\Models\ScripturePassage;
use App\Models\Sermon;
use App\Models\SermonScriptureFilter;
use App\Models\Song;
use App\Models\SpeakerProfile;
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
            // A clean state now means the title agrees with the catalogue as well as the
            // link does — the linker writes both, so either one drifting is drift.
            'title' => 'Amazing Grace #123',
            'openlp_search_title' => 'Amazing Grace@',
            'song_id' => $song->id,
            'metadata' => null,
        ]);

        $audit = app(BackfillAudit::class);
        $report = $audit->report();

        $this->assertSame(0, $report['sermons_missing_scripture_filters']);
        $this->assertSame(0, $report['songs_missing_praise_numbers']);
        $this->assertSame(0, $report['song_link_drift']);
        $this->assertFalse($report['songs_catalogue_missing']);
        $this->assertFalse($report['speaker_profiles_missing']);
        $this->assertSame(0, $report['sermons_missing_scripture_passage']);
        $this->assertFalse($audit->hasFailures($report));
    }

    #[Test]
    public function it_detects_missed_scripture_filter_and_praise_number_backfills(): void
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

        $audit = app(BackfillAudit::class);
        $report = $audit->report();

        $this->assertSame(1, $report['sermons_missing_scripture_filters'], 'Only the parseable reference should count.');
        $this->assertSame(1, $report['songs_missing_praise_numbers']);
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
        $preacher = Preacher::factory()->create();
        foreach (range(1, 5) as $index) {
            Sermon::factory()->create([
                'preacher_id' => $preacher->id,
                'audio_file_path' => "sermons/speaker-{$index}.mp3",
            ]);
        }

        $audit = app(BackfillAudit::class);

        $this->assertTrue($audit->songsCatalogueMissing());

        $this->assertFalse($audit->speakerProfilesMissing(), 'Disabled speaker identification must not fail the audit.');

        config()->set('media-processing.speaker_identification.enabled', true);
        $this->assertTrue($audit->speakerProfilesMissing());
    }

    #[Test]
    public function it_requires_an_active_speaker_profile_for_the_configured_provider_and_model(): void
    {
        $preacher = Preacher::factory()->create();
        foreach (range(1, 5) as $index) {
            Sermon::factory()->create([
                'preacher_id' => $preacher->id,
                'audio_file_path' => "sermons/profile-{$index}.mp3",
            ]);
        }
        config()->set('media-processing.speaker_identification.enabled', true);
        config()->set('media-processing.speaker_identification.provider', 'resemblyzer');
        config()->set('media-processing.speaker_identification.model_version', 'v2.0');

        $inactiveProfile = SpeakerProfile::factory()->inactive()->create([
            'preacher_id' => $preacher->id,
            'provider' => 'resemblyzer',
            'model_version' => 'v2.0',
        ]);
        SpeakerProfile::factory()->create([
            'preacher_id' => $preacher->id,
            'provider' => 'other-provider',
            'model_version' => 'v2.0',
        ]);
        SpeakerProfile::factory()->create([
            'preacher_id' => $preacher->id,
            'provider' => 'resemblyzer',
            'model_version' => 'v1.0',
        ]);

        $audit = app(BackfillAudit::class);
        $this->assertTrue($audit->speakerProfilesMissing());

        $inactiveProfile->update(['is_active' => true]);

        $this->assertFalse($audit->speakerProfilesMissing());
    }

    #[Test]
    public function it_flags_an_eligible_preacher_without_a_profile_even_when_another_preacher_has_one(): void
    {
        config()->set('media-processing.speaker_identification.enabled', true);
        config()->set('media-processing.speaker_identification.provider', 'resemblyzer');
        config()->set('media-processing.speaker_identification.model_version', 'v1.0');

        $profiledPreacher = Preacher::factory()->create();
        Sermon::factory()->count(5)->sequence(
            fn ($sequence) => ['audio_file_path' => "sermons/profiled-{$sequence->index}.mp3"],
        )->create(['preacher_id' => $profiledPreacher->id]);
        SpeakerProfile::factory()->create([
            'preacher_id' => $profiledPreacher->id,
            'provider' => 'resemblyzer',
            'model_version' => 'v1.0',
        ]);

        $unprofiledPreacher = Preacher::factory()->create();
        Sermon::factory()->count(4)->sequence(
            fn ($sequence) => ['audio_file_path' => "sermons/unprofiled-{$sequence->index}.mp3"],
        )->create(['preacher_id' => $unprofiledPreacher->id]);

        $audit = app(BackfillAudit::class);
        $this->assertFalse($audit->speakerProfilesMissing(), 'Four eligible sermons are below the bootstrap threshold of five.');

        Sermon::factory()->create([
            'preacher_id' => $unprofiledPreacher->id,
            'audio_file_path' => 'sermons/unprofiled-5.mp3',
        ]);

        $this->assertTrue($audit->speakerProfilesMissing(), 'A second eligible preacher without a profile must fail the audit.');
    }
}
