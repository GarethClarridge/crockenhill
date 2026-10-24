<?php

declare(strict_types=1);

namespace Tests\Integration\Services\ChurchService;

use App\Enums\ServiceSectionPublicationStatus;
use App\Enums\ServiceSectionSongMatchType;
use App\Enums\ServiceSectionType;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Models\Song;
use App\Services\ChurchService\Structure\SongLyricEdgeExtension;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SongLyricEdgeExtensionTest extends TestCase
{
    use RefreshDatabase;

    private MediaProcessingLog $run;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['media-processing.storage.transcript_disk' => 'local']);

        $this->run = MediaProcessingLog::factory()->livestream()->create([
            'church_service_id' => ChurchService::factory()->create()->id,
        ]);
    }

    /**
     * 1291 §3640: "God of the Ages" began inside the tail of the song before it.
     */
    #[Test]
    public function it_starts_a_song_at_its_own_opening_lines_sung_inside_the_previous_song(): void
    {
        $previous = $this->song(326.0, 427.0, 'We are here to praise you lift our hearts and sing');
        $section = $this->song(427.0, 560.0, 'God of the ages history\'s maker planning our pathway holding us fast shaping in mercy all that concerns us father we praise you lord of the past');
        $this->transcript([
            [385.0, 392.0, 'And we shall give you pleasure and delight.'],
            [392.4, 396.0, 'Father, I am in the same.'],
            [402.2, 406.0, 'God of the ages, history\'s maker,'],
            [406.4, 409.0, 'planning our pathway, holding us fast,'],
            [409.9, 412.0, 'shaping in mercy all that concerns us.'],
            [412.4, 420.0, 'Father, we praise you, Lord of the past.'],
            [430.0, 440.0, 'God of the ages, history\'s maker,'],
        ]);

        $this->assertSame(1, $this->extension()->extend($this->run));

        $this->assertEqualsWithDelta(402.2, (float) $section->fresh()->start_time, 0.01);
        $this->assertEqualsWithDelta(560.0 - 402.2, (float) $section->fresh()->duration, 0.01);
        $this->assertEqualsWithDelta(427.0, (float) $previous->fresh()->end_time, 0.01, 'The neighbour keeps its span.');
    }

    /**
     * 1108 §1896: the chorus ran on inside the prayer until the leader called the church to pray.
     */
    #[Test]
    public function it_ends_a_song_after_its_own_lines_sung_inside_the_following_prayer(): void
    {
        $section = $this->song(1177.0, 1315.0, 'Bless the Lord O my soul O my soul worship his holy name sing like never before still my soul sing your praise unending ten thousand years and then forevermore');
        $this->section(ServiceSectionType::Prayer, 1316.0, 1893.0);
        $this->transcript([
            [1297.8, 1306.0, 'Sing like never before, O my soul, I\'ll worship your holy name.'],
            [1322.1, 1328.0, 'Still my soul sing your praise unending.'],
            [1328.5, 1336.0, 'Ten thousand years and then forevermore.'],
            [1336.7, 1343.0, 'Bless the Lord, O my soul, O my soul, worship his holy name.'],
            [1345.7, 1355.9, 'Sing like never before, O my soul, I\'ll worship your holy name.'],
            [1356.8, 1360.0, 'Well, let\'s turn to God in prayer.'],
            [1375.5, 1380.0, 'Our Father in heaven, hallowed be your name.'],
        ]);

        $this->extension()->extend($this->run);

        $this->assertEqualsWithDelta(1355.9, (float) $section->fresh()->end_time, 0.01);
    }

    /**
     * 978 §1030: the leader read the first verse aloud, then called the church to stand.
     * The announcement stands between the quoted verse and the song.
     */
    #[Test]
    public function it_leaves_a_song_whose_lines_are_quoted_before_an_announcement(): void
    {
        $section = $this->song(109.0, 299.0, 'Amazing grace how sweet the sound that saved a wretch like me I once was lost but now am found was blind but now I see');
        $this->transcript([
            [89.0, 93.0, 'And the first verse reads as follows, amazing grace, how'],
            [93.7, 97.0, 'sweet the sound, that saved a wretch like me.'],
            [97.0, 103.0, 'I once was lost, but now am found, was blind, but now I'],
            [107.0, 109.0, 'So let\'s stand and sing this one through together.'],
            [110.0, 130.0, 'Amazing grace, how sweet the sound, that saved a wretch'],
        ]);

        $this->assertSame(0, $this->extension()->extend($this->run));
        $this->assertEqualsWithDelta(109.0, (float) $section->fresh()->start_time, 0.01);
    }

    /**
     * 1020 §1338: Psalm 148 read before "O Praise the Name" shares its words.
     */
    #[Test]
    public function it_leaves_a_song_whose_words_are_read_in_the_reading_before_it(): void
    {
        $section = $this->song(436.0, 654.0, 'let them praise the name of the lord praise the lord let everything that has breath praise the lord');
        $this->section(ServiceSectionType::BibleReading, 315.0, 436.0);
        $this->transcript([
            [392.2, 398.0, 'Let them praise the name of the Lord.'],
            [417.2, 420.0, 'Praise the Lord.'],
            [420.2, 426.0, 'Indeed, let everything praise the Lord.'],
            [426.2, 430.0, 'Let us who have breath praise the Lord.'],
            [431.2, 436.0, 'Let us stand, shall we, and sing our first song.'],
        ]);

        $this->assertSame(0, $this->extension()->extend($this->run));
    }

    /**
     * 1262 §3283: "praise him" is in both songs; a line the neighbouring song's own lyrics
     * explain as well is that song being sung.
     */
    #[Test]
    public function it_stops_at_a_line_the_neighbouring_song_explains_as_well(): void
    {
        $this->song(1094.0, 1256.0, 'Praise my soul the King of heaven praise him praise him angels help us to adore him');
        $section = $this->song(1256.0, 1490.0, 'All people that on earth do dwell sing to the lord with cheerful voice praise him');
        $this->transcript([
            [1235.7, 1240.0, 'Praise him, praise him, praise him, praise him.'],
            [1244.4, 1249.0, 'Praise him, praise him, angels help us to adore him.'],
            [1256.0, 1280.0, 'All people that on earth do dwell, sing to the Lord.'],
        ]);

        $this->assertSame(0, $this->extension()->extend($this->run));
        $this->assertEqualsWithDelta(1256.0, (float) $section->fresh()->start_time, 0.01);
    }

    /**
     * 1232 §2854: a transcription loop repeated the song's own line over the next song.
     * A looping transcript is no evidence of what was sung.
     */
    #[Test]
    public function it_does_not_count_a_looping_line_as_the_song_being_sung(): void
    {
        $section = $this->song(114.0, 206.0, 'who will proclaim the glory of the risen lord all heaven declares');
        $this->section(ServiceSectionType::Other, 206.0, 342.0);
        $this->transcript(array_map(
            static fn (int $second): array => [206.0 + $second, 207.0 + $second, 'Who will proclaim the glory of the risen Lord?'],
            range(1, 8),
        ));

        $this->assertSame(0, $this->extension()->extend($this->run));
        $this->assertEqualsWithDelta(206.0, (float) $section->fresh()->end_time, 0.01);
    }

    /**
     * 1243 §3028: one line of the song, well outside the edge, is not enough to move it.
     */
    #[Test]
    public function it_leaves_a_song_with_a_single_line_further_out_than_three_seconds(): void
    {
        $section = $this->song(1070.0, 1273.0, 'take time to be holy speak oft with thy lord');
        $this->transcript([
            [1062.3, 1066.0, 'Well, we need to take time to be holy.'],
            [1072.0, 1080.0, 'Take time to be holy, speak oft with thy Lord.'],
        ]);

        $this->assertSame(0, $this->extension()->extend($this->run));
    }

    /**
     * 1367 §4574: the first line began a moment before the section.
     */
    #[Test]
    public function it_nudges_a_song_to_a_single_own_line_within_three_seconds(): void
    {
        $section = $this->song(274.0, 520.0, 'O Lord my rock and my redeemer greatest treasure of my longing soul');
        $this->transcript([
            [272.2, 280.0, 'O Lord, my rock and my redeemer,'],
            [280.0, 288.0, 'Greatest treasure of my longing soul.'],
        ]);

        $this->assertSame(1, $this->extension()->extend($this->run));
        $this->assertEqualsWithDelta(272.2, (float) $section->fresh()->start_time, 0.01);
    }

    /**
     * Lines more than 15 s apart are not one run of singing.
     */
    #[Test]
    public function it_stops_at_a_silence_longer_than_fifteen_seconds(): void
    {
        $section = $this->song(600.0, 800.0, 'Great is thy faithfulness O God my father morning by morning new mercies I see');
        $this->transcript([
            [560.0, 565.0, 'Great is thy faithfulness, O God my Father.'],
            [566.0, 570.0, 'Morning by morning new mercies I see.'],
            [600.0, 620.0, 'Great is thy faithfulness.'],
        ]);

        $this->assertSame(0, $this->extension()->extend($this->run));
    }

    /**
     * Moving the bounds changes the section's media signature, which is what makes candidate
     * preparation cut the clip again; the step itself touches neither media nor publication.
     */
    #[Test]
    public function it_records_the_extension_and_leaves_the_recut_to_candidate_preparation(): void
    {
        $section = $this->song(1256.0, 1490.0, 'All people that on earth do dwell sing to the lord with cheerful voice');
        $section->forceFill([
            'extracted_video_path' => 'sections/old.mp4',
            'publication_status' => ServiceSectionPublicationStatus::PendingApproval,
        ])->save();
        $signature = $section->fresh()->mediaSignature();
        $this->transcript([
            [1238.0, 1243.0, 'All people that on earth do dwell.'],
            [1243.3, 1249.0, 'Sing to the Lord with cheerful voice.'],
        ]);

        $this->extension()->extend($this->run);

        $section = $section->fresh();
        $this->assertNotSame($signature, $section->mediaSignature());
        $this->assertSame('sections/old.mp4', $section->extracted_video_path);
        $this->assertSame(ServiceSectionPublicationStatus::PendingApproval, $section->publication_status);
        $this->assertEquals([
            'edge' => 'before',
            'from' => 1256.0,
            'to' => 1238.0,
            'lines' => 2,
        ], array_diff_key($section->metadata->toArray()['song_lyric_edge_extension'][0], ['extended_at' => true]));
    }

    /**
     * Only an edge the hold holds is corrected. 1337 §4268: a prayer quoting the hymn pauses
     * like speech, the hold releases it as a quotation, and the prayer keeps its words.
     */
    #[Test]
    public function it_leaves_lines_quoted_over_speech_that_the_hold_releases(): void
    {
        $section = $this->song(620.0, 840.0, 'Bless the Lord O my soul worship his holy name ten thousand years and then forevermore');
        $this->section(ServiceSectionType::Prayer, 840.0, 1000.0);
        $this->transcript([
            [850.0, 856.0, 'Ten thousand years and then forevermore.'],
            [860.0, 866.0, 'Bless the Lord, O my soul, worship his holy name.'],
        ], [[0, 840, 'sung'], [840, 2000, 'speech']]);

        $this->assertSame(0, $this->extension()->extend($this->run));
        $this->assertEqualsWithDelta(840.0, (float) $section->fresh()->end_time, 0.01);
    }

    /**
     * 1240 §175520: the leader read the first verse, called the church to stand, and the band
     * played in. The congregation's first lines break like speech, so the hold released them as a
     * quotation and the clip began 16 s into the song.
     */
    #[Test]
    public function it_starts_a_song_at_its_first_lines_sung_after_the_introduction(): void
    {
        $section = $this->song(3888.94, 4007.82, 'O Father you are sovereign in all the worlds you made your mighty word was spoken and light and life obeyed your voice commands the seasons');
        $this->transcript([
            [3846.7, 3850.56, 'so I can remember the hymn that I chose two weeks ago.'],
            [3852.5, 3854.02, 'O Father, you are sovereign.'],
            [3854.72, 3856.54, 'In all the worlds you made,'],
            [3857.08, 3860.78, 'your mighty word has spoken and light and life obeyed.'],
            [3860.78, 3862.10, 'Let\'s stand to sing.'],
            [3872.60, 3876.94, 'O Father, you are sovereign.'],
            [3877.46, 3881.02, 'In all the worlds you made,'],
            [3881.54, 3888.94, 'Your mighty word was spoken and lightened like the bait.'],
            [3888.94, 3896.86, 'Your voice commands the seasons'],
        ], [[0, 3863, 'speech'], [3863, 3872, 'sung'], [3872, 3889, 'faltering'], [3889, 4100, 'sung']], duration: 4100.0);

        $this->assertSame(1, $this->extension()->extend($this->run));
        $this->assertEqualsWithDelta(3872.60, (float) $section->fresh()->start_time, 0.01);
    }

    /**
     * 929 §520, heard 10-24: straight after "God of Glory", the musicians took "All Praise To Him"
     * through as an introduction before the church sang it from its first line. No leader spoke
     * between, so the lines are not the congregation coming in.
     */
    #[Test]
    public function it_leaves_an_introduction_sung_straight_after_the_previous_song(): void
    {
        $this->song(195.0, 325.0, 'God of glory we exalt your name your love is gracious you are my God');
        $section = $this->song(345.0, 497.72, 'All praise to him the God of light who formed the mountains by his might all praise to him who names the stars that sing his fame in skies afar');
        $this->transcript([
            [316.24, 320.52, 'Your love is gracious, you are my God.'],
            [331.52, 335.0, 'Who formed the mountains by his might.'],
            [335.66, 338.7, 'All praise to him who names the stars'],
            [338.7, 342.38, 'that sing his fame in skies afar.'],
            [353.72, 358.66, 'All praise to him, the God of light,'],
        ], [[0, 2000, 'faltering']]);

        $this->assertSame(0, $this->extension()->extend($this->run));
        $this->assertEqualsWithDelta(345.0, (float) $section->fresh()->start_time, 0.01);
    }

    /**
     * 1011 §1285: the lines were transcribed from a feed muted until the clip begins; there is
     * nothing to hear before it, so the clip keeps its start.
     */
    #[Test]
    public function it_leaves_a_song_whose_opening_lines_lie_under_silence(): void
    {
        $section = $this->song(513.85, 637.42, 'This is our God the servant King he calls us now to follow him to bring our lives as a daily offering of worship to the servant King');
        $this->transcript([
            [432.0, 436.0, 'Let\'s stand and sing. Number 396, the servant.'],
            [477.0, 485.08, 'This is our God, the servant King.'],
            [485.08, 493.58, 'He calls us now to follow Him, to bring our lives as a'],
            [493.58, 500.80, 'daily offering of worship to the servant King.'],
            [520.0, 527.0, 'This is our God, the servant King.'],
        ], [[0, 437, 'speech'], [437, 520, 'silent'], [520, 2000, 'sung']]);

        $this->assertSame(0, $this->extension()->extend($this->run));
        $this->assertEqualsWithDelta(513.85, (float) $section->fresh()->start_time, 0.01);
    }

    /**
     * 977 §1005: the leader read the first verse straight into the song, at speaking pace and
     * with no introduction between; the reading is not the song's opening.
     */
    #[Test]
    public function it_leaves_a_verse_read_straight_into_the_song(): void
    {
        $section = $this->song(589.99, 760.0, 'I\'ll praise my Maker while I\'ve breath and when my voice is lost in death praise shall employ my nobler powers my days of praise shall ne\'er be past');
        $this->transcript([
            [565.0, 569.5, 'The first verse goes like this.'],
            [569.56, 572.5, 'I\'ll praise my maker while I\'ve breath.'],
            [573.1, 577.4, 'And when my voice is lost in death, praise shall employ my'],
            [579.2, 581.6, 'my days of praise shall not be passed,'],
            [590.0, 600.0, 'I\'ll praise my maker while I\'ve breath.'],
        ], [[0, 589, 'faltering'], [589, 2000, 'sung']]);

        $this->assertSame(0, $this->extension()->extend($this->run));
        $this->assertEqualsWithDelta(589.99, (float) $section->fresh()->start_time, 0.01);
    }

    /**
     * Withdrawing public content is DemoteHeldPublicationsCommand's decision, never the
     * pipeline's; a published clip keeps its cut and its hold.
     */
    #[Test]
    public function it_leaves_a_published_section_alone(): void
    {
        $section = $this->song(1256.0, 1490.0, 'All people that on earth do dwell sing to the lord with cheerful voice');
        $section->forceFill([
            'publication_status' => ServiceSectionPublicationStatus::Published,
            'published_at' => now(),
            'extracted_video_path' => 'sections/published.mp4',
            'extracted_audio_path' => 'sections/published.mp3',
            'extracted_at' => now(),
        ])->save();
        $this->transcript([
            [1238.0, 1243.0, 'All people that on earth do dwell.'],
            [1243.3, 1249.0, 'Sing to the Lord with cheerful voice.'],
        ]);

        $this->assertSame(0, $this->extension()->extend($this->run));
        $this->assertEqualsWithDelta(1256.0, (float) $section->fresh()->start_time, 0.01);
    }

    #[Test]
    public function it_changes_nothing_when_run_twice(): void
    {
        $section = $this->song(1256.0, 1490.0, 'All people that on earth do dwell sing to the lord with cheerful voice');
        $this->transcript([
            [1238.0, 1243.0, 'All people that on earth do dwell.'],
            [1243.3, 1249.0, 'Sing to the Lord with cheerful voice.'],
        ]);

        $this->extension()->extend($this->run);

        $this->assertSame(0, $this->extension()->extend($this->run->fresh()));
        $this->assertEqualsWithDelta(1238.0, (float) $section->fresh()->start_time, 0.01);
    }

    #[Test]
    public function it_leaves_a_run_without_a_transcript_alone(): void
    {
        $section = $this->song(600.0, 800.0, 'Great is thy faithfulness');

        $this->assertSame(0, $this->extension()->extend($this->run));
        $this->assertEqualsWithDelta(600.0, (float) $section->fresh()->start_time, 0.01);
    }

    private function extension(): SongLyricEdgeExtension
    {
        return app(SongLyricEdgeExtension::class);
    }

    private function song(float $start, float $end, string $lyrics): ServiceSection
    {
        $item = ChurchServiceItem::factory()->create([
            'church_service_id' => $this->run->church_service_id,
            'song_id' => Song::factory()->create(['lyrics_plain' => $lyrics])->id,
        ]);

        return $this->section(ServiceSectionType::Song, $start, $end, $item->id);
    }

    private function section(ServiceSectionType $type, float $start, float $end, ?int $itemId = null): ServiceSection
    {
        return ServiceSection::factory()->create([
            'media_processing_log_id' => $this->run->id,
            'church_service_item_id' => $itemId,
            'section_type' => $type->value,
            'song_match_type' => $itemId !== null ? ServiceSectionSongMatchType::Confirmed->value : null,
            'start_time' => $start,
            'end_time' => $end,
            'duration' => $end - $start,
            'metadata' => [],
        ]);
    }

    /**
     * Transcript cues over a 2000 s recording, and an RMS log sampled every 0.1 s: singing holds
     * -18 dB without a break, speech pauses half a second every 3 s. Sung throughout unless the
     * test says otherwise.
     *
     * A congregation finding its feet breaks once every 5 s ("faltering"), which reads as speech
     * by its pauses though it is as loud as singing; a muted feed is "silent".
     *
     * @param  list<array{0: float, 1: float, 2: string}>  $cues
     * @param  list<array{0: int, 1: int, 2: 'sung'|'speech'|'faltering'|'silent'}>  $spans
     */
    private function transcript(array $cues, array $spans = [[0, 2000, 'sung']], float $duration = 2000.0): void
    {
        config([
            'media-processing.segmentation.adaptive_thresholds.enabled' => false,
            'media-processing.segmentation.rms_threshold' => -45.0,
        ]);
        $lines = [];

        for ($tenth = 0; $tenth < $duration * 10; $tenth++) {
            $time = $tenth / 10;
            $level = -60.0;

            foreach ($spans as [$from, $to, $kind]) {
                if ($time >= $from && $time < $to) {
                    $level = match ($kind) {
                        'sung' => -18.0,
                        'faltering' => fmod($time, 5.0) < 4.4 ? -18.0 : -60.0,
                        'silent' => -90.0,
                        default => fmod($time, 3.0) < 2.5 ? -25.0 : -60.0,
                    };
                }
            }

            $lines[] = sprintf('pts_time:%.3f', $time);
            $lines[] = sprintf('lavfi.astats.Overall.RMS_level=%.1f', $level);
        }

        $rmsPath = 'service-transcripts/test-'.$this->run->processing_id.'.rms.json';
        Storage::disk('local')->put($rmsPath, implode("\n", $lines));
        $this->run->forceFill(['rms_log_path' => $rmsPath])->save();

        $path = 'service-transcripts/test-'.$this->run->processing_id.'.normalized.json';
        Storage::disk('local')->put($path, json_encode([
            'cues' => array_map(static fn (array $cue): array => ['start' => $cue[0], 'end' => $cue[1], 'text' => $cue[2]], $cues),
            'duration' => $duration,
            'source' => 'mock',
        ], JSON_THROW_ON_ERROR));
        $this->run->putServiceTranscriptPath($path);
    }
}
