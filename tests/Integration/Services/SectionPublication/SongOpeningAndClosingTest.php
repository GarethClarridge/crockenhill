<?php

declare(strict_types=1);

namespace Tests\Integration\Services\SectionPublication;

use App\Data\ChurchServiceTranscript;
use App\Enums\ServiceSectionSongMatchType;
use App\Enums\ServiceSectionType;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Models\Song;
use App\Services\ChurchService\SectionPublication\SongOpeningAndClosing;
use App\Services\Media\Audio\SustainedSound;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SongOpeningAndClosingTest extends TestCase
{
    use RefreshDatabase;

    private const BETHLEHEM = [
        'v1' => 'O little town of Bethlehem, how still we see thee lie. Above thy deep and dreamless sleep the silent stars go by',
        'v2' => 'For Christ is born of Mary, and gathered all above. While mortals sleep the angels keep their watch of wondering love',
        'v3' => 'How silently, how silently, the wondrous gift is given. So God imparts to human hearts the blessings of his heaven',
    ];

    /**
     * 1035 §4813: the clip opens on verse 2. Verse 1 was sung just before it, and whisper
     * transcribed none of it, so only the verse the clip starts on shows it.
     */
    #[Test]
    public function it_raises_a_clip_that_opens_on_a_later_verse_with_singing_before_it(): void
    {
        $section = $this->section(self::BETHLEHEM, null, 510.0, 624.0);

        $observations = $this->observe($section, [
            [510.5, 519.0, 'For Christ is born of Mary, and gathered all above.'],
            [548.6, 557.0, 'How silently, how silently, the wondrous gift is given.'],
            [610.0, 620.0, 'So God imparts to human hearts the blessings of his heaven.'],
        ], sung: [[470, 700]]);

        $this->assertCount(1, $observations);
        $this->assertSame('opening', $observations[0]['edge']);
        $this->assertSame('v2', $observations[0]['verse']);
        $this->assertSame('v1', $observations[0]['expected']);
        $this->assertSame([2, 3], [$observations[0]['position'], $observations[0]['of']]);
        $this->assertTrue($observations[0]['risk']);
    }

    /**
     * 982 §1062: the leader was still talking before the song, so a clip that seems to open on
     * verse 2 has only lost verse 1 to the transcript.
     */
    #[Test]
    public function it_records_but_does_not_raise_a_later_opening_with_speech_before_it(): void
    {
        $section = $this->section(self::BETHLEHEM, null, 510.0, 624.0);

        $observations = $this->observe($section, [
            [512.0, 519.0, 'For Christ is born of Mary, and gathered all above.'],
            [610.0, 620.0, 'So God imparts to human hearts the blessings of his heaven.'],
        ], sung: [[510, 700]]);

        $this->assertCount(1, $observations);
        $this->assertFalse($observations[0]['risk']);
    }

    /**
     * Whisper often misses a first verse. When the first verse it heard comes well into the
     * clip, the clip has room for the one it missed.
     */
    #[Test]
    public function it_ignores_a_later_first_verse_heard_well_into_the_clip(): void
    {
        $section = $this->section(self::BETHLEHEM, null, 510.0, 624.0);

        $this->assertSame([], $this->observe($section, [
            [545.0, 552.0, 'For Christ is born of Mary, and gathered all above.'],
            [610.0, 620.0, 'So God imparts to human hearts the blessings of his heaven.'],
        ], sung: [[470, 700]]));
    }

    /**
     * 947 §698: the clip closes on the last-but-one verse while the church is still singing.
     */
    #[Test]
    public function it_raises_a_clip_that_closes_before_the_last_verse_with_singing_after_it(): void
    {
        $section = $this->section(self::BETHLEHEM, null, 400.0, 520.0);

        $observations = $this->observe($section, [
            [402.0, 410.0, 'O little town of Bethlehem, how still we see thee lie.'],
            [505.0, 512.0, 'While mortals sleep the angels keep their watch of wondering love.'],
        ], sung: [[395, 560]]);

        $this->assertCount(1, $observations);
        $this->assertSame('closing', $observations[0]['edge']);
        $this->assertSame('v2', $observations[0]['verse']);
        $this->assertSame('v3', $observations[0]['expected']);
        $this->assertTrue($observations[0]['risk']);
    }

    /**
     * Churches often end by singing the first verse again.
     */
    #[Test]
    public function it_accepts_a_closing_reprise_of_the_first_verse(): void
    {
        $section = $this->section(self::BETHLEHEM, null, 400.0, 560.0);

        $this->assertSame([], $this->observe($section, [
            [402.0, 410.0, 'O little town of Bethlehem, how still we see thee lie.'],
            [500.0, 508.0, 'So God imparts to human hearts the blessings of his heaven.'],
            [545.0, 555.0, 'O little town of Bethlehem, how still we see thee lie.'],
        ], sung: [[395, 600]]));
    }

    /**
     * Without a recorded order a chorus appears once in the catalogue, however often it is sung,
     * so a clip may close on it.
     */
    #[Test]
    public function it_accepts_a_closing_chorus_when_no_verse_order_is_recorded(): void
    {
        $section = $this->section([
            'v1' => 'We have heard a joyful sound, spread the tidings all around',
            'c1' => 'Jesus saves, Jesus saves, bear the news to every land',
            'v2' => 'Waft it on the rolling tide, tell to sinners far and wide',
        ], null, 400.0, 560.0);

        $this->assertSame([], $this->observe($section, [
            [402.0, 410.0, 'We have heard a joyful sound, spread the tidings all around.'],
            [545.0, 555.0, 'Bear the news to every land, Jesus saves.'],
        ], sung: [[395, 600]]));
    }

    /**
     * A recorded order says what ends the song, chorus or not.
     */
    #[Test]
    public function it_follows_the_recorded_verse_order(): void
    {
        $section = $this->section([
            'v1' => 'We have heard a joyful sound, spread the tidings all around',
            'c1' => 'Jesus saves, Jesus saves, bear the news to every land',
            'v2' => 'Waft it on the rolling tide, tell to sinners far and wide',
        ], 'c1 v1 c1 v2 c1', 400.0, 560.0);

        $this->assertSame([], $this->observe($section, [
            [402.0, 410.0, 'Bear the news to every land, Jesus saves.'],
            [545.0, 555.0, 'Bear the news to every land, Jesus saves.'],
        ], sung: [[395, 600]]));
    }

    /**
     * 1250 §3128: "Number 871, I know not what lies ahead" is the leader naming the song.
     */
    #[Test]
    public function it_does_not_read_the_leader_naming_the_song_as_a_verse(): void
    {
        $section = $this->section(self::BETHLEHEM, null, 510.0, 624.0);

        $this->assertSame([], $this->observe($section, [
            [510.5, 515.0, 'It\'s number 368, for Christ is born of Mary.'],
        ], sung: [[470, 700]]));
    }

    /**
     * A line every verse shares cannot say which verse is being sung.
     */
    #[Test]
    public function it_does_not_place_a_line_that_every_verse_shares(): void
    {
        $section = $this->section([
            'v1' => 'Holy holy holy Lord God almighty early in the morning our song shall rise to thee',
            'v2' => 'Holy holy holy all the saints adore thee casting down their golden crowns',
            'v3' => 'Holy holy holy though the darkness hide thee though the eye of sinful man',
        ], null, 510.0, 624.0);

        $this->assertSame([], $this->observe($section, [
            [511.0, 516.0, 'Holy, holy, holy.'],
        ], sung: [[470, 700]]));
    }

    #[Test]
    public function it_passes_a_clip_that_opens_on_the_first_verse_and_closes_on_the_last(): void
    {
        $section = $this->section(self::BETHLEHEM, null, 400.0, 560.0);

        $this->assertSame([], $this->observe($section, [
            [402.0, 410.0, 'O little town of Bethlehem, how still we see thee lie.'],
            [545.0, 555.0, 'So God imparts to human hearts the blessings of his heaven.'],
        ], sung: [[395, 600]]));
    }

    /**
     * @param  array<string, string>  $verses  Keyed `v1`, `c1`…, in document order
     */
    private function section(array $verses, ?string $verseOrder, float $start, float $end): ServiceSection
    {
        $xml = '<song><lyrics>';

        foreach ($verses as $key => $text) {
            $xml .= sprintf('<verse type="%s" label="%s"><![CDATA[%s]]></verse>', $key[0], substr($key, 1), $text);
        }

        $song = Song::factory()->create([
            'lyrics_xml' => $xml.'</lyrics></song>',
            'lyrics_plain' => implode("\n\n", $verses),
            'verse_order' => $verseOrder,
        ]);
        $run = MediaProcessingLog::factory()->livestream()->create(['church_service_id' => ChurchService::factory()->create()->id]);

        return ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'church_service_item_id' => ChurchServiceItem::factory()->create([
                'church_service_id' => $run->church_service_id,
                'song_id' => $song->id,
            ])->id,
            'section_type' => ServiceSectionType::Song->value,
            'song_match_type' => ServiceSectionSongMatchType::Confirmed->value,
            'start_time' => $start,
            'end_time' => $end,
            'duration' => $end - $start,
            'metadata' => [],
        ])->fresh();
    }

    /**
     * @param  list<array{0: float, 1: float, 2: string}>  $cues
     * @param  list<array{0: int, 1: int}>  $sung  Spans of unbroken singing; elsewhere is speech
     * @return list<array<string, mixed>>
     */
    private function observe(ServiceSection $section, array $cues, array $sung): array
    {
        $samples = [];

        for ($tenth = 0; $tenth < 10000; $tenth++) {
            $time = $tenth / 10;
            $level = fmod($time, 3.0) < 2.5 ? -25.0 : -60.0;

            foreach ($sung as [$from, $to]) {
                if ($time >= $from && $time < $to) {
                    $level = -18.0;
                }
            }

            $samples[] = ['time' => $time, 'rms' => $level];
        }

        return app(SongOpeningAndClosing::class)->observe(
            $section,
            new ChurchServiceTranscript(
                array_map(static fn (array $cue): array => ['start' => $cue[0], 'end' => $cue[1], 'text' => $cue[2]], $cues),
                1000.0,
                'mock',
            ),
            SustainedSound::fromSamples($samples, -45.0, SustainedSound::EDGE_WINDOW_BINS),
        );
    }
}
