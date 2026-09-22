<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Data\ChurchServiceTranscript;
use App\Data\SuspectTranscriptBlock;
use App\Services\Media\Audio\ServiceTranscriptPathologyDetector;
use App\Services\Media\Audio\ServiceTranscriptRepetitionScreen;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The cases are drawn from the 2026-09-09 correctness review's 222 located
 * repetition blocks and from the repetition the corpus shows is genuine, so the
 * screen is held to catching the first class without inventing the second.
 */
class ServiceTranscriptRepetitionScreenTest extends TestCase
{
    #[Test]
    public function it_holds_a_loop_far_shorter_than_the_recovery_detectors_floor(): void
    {
        // Sermon 921's shape: a long prose phrase repeated back to back well
        // inside two minutes. The recovery detector's 120-second floor excludes
        // every one of the 222 located blocks; this is the whole defect.
        $transcript = $this->loopingTranscript(
            phrase: 'God did what was necessary in order to make sure they won.',
            repeats: 12,
            start: 2715.0,
            secondsEach: 4.0,
        );

        $this->assertSame([], app(ServiceTranscriptPathologyDetector::class)->detect($transcript));

        $blocks = app(ServiceTranscriptRepetitionScreen::class)->screen($transcript);

        $this->assertCount(1, $blocks);
        $this->assertSame(SuspectTranscriptBlock::REASON_REPEATED_PHRASE, $blocks[0]->reason);
        $this->assertSame(12, $blocks[0]->repeats);
        $this->assertSame(48.0, $blocks[0]->seconds());
        $this->assertStringContainsString('god did what was necessary', $blocks[0]->phrase ?? '');
    }

    #[Test]
    public function it_holds_a_loop_whose_repeats_are_grouped_across_different_cue_boundaries(): void
    {
        // The same words, split so that no two cues are equal. Cue-string
        // equality — what the recovery detector tests — sees nothing here, which
        // is why the screen matches over the word stream instead.
        $words = [];

        for ($repeat = 0; $repeat < 10; $repeat++) {
            $words = [...$words, ...['and', 'will', 'bring', 'the', 'sorrow', 'on', 'me']];
        }

        $cues = [];
        $index = 0;

        // Cue lengths cycle 4, 5, 6 words against a seven-word phrase, so every
        // repeat straddles its boundaries differently and no cue text occurs
        // twice — the exact condition cue-string equality needs and never gets
        // across a retry.
        $sizes = [4, 5, 6];
        $size = 0;

        while ($index < count($words)) {
            $take = $sizes[$size % count($sizes)];
            $slice = array_slice($words, $index, $take);
            $cues[] = [
                'start' => $index * 0.5,
                'end' => ($index + count($slice)) * 0.5,
                'text' => ucfirst(implode(' ', $slice)).'.',
            ];
            $index += count($slice);
            $size++;
        }

        $texts = array_column($cues, 'text');
        $this->assertSame(count($texts), count(array_unique($texts)), 'The fixture must contain no two identical cues.');

        $transcript = ChurchServiceTranscript::fromCues($cues, 600.0, ChurchServiceTranscript::SOURCE_LOCAL_WHISPER);

        $this->assertSame([], app(ServiceTranscriptPathologyDetector::class)->detect($transcript));

        $blocks = app(ServiceTranscriptRepetitionScreen::class)->screen($transcript);

        $this->assertCount(1, $blocks);
        $this->assertSame(10, $blocks[0]->repeats);
        $this->assertSame('and will bring the sorrow on me', $blocks[0]->phrase);
    }

    #[Test]
    public function it_holds_an_extreme_two_word_loop_below_the_general_phrase_floor(): void
    {
        // Run 1347's reading loses part of Matthew 6 to "the Lord" repeated
        // sixteen times. The general three-word phrase floor deliberately
        // preserves ordinary rhetoric, but must not make this shorter loop
        // invisible.
        $transcript = $this->loopingTranscript(
            phrase: 'The Lord.',
            repeats: 16,
            start: 1583.52,
            secondsEach: 3.75,
        );

        $blocks = app(ServiceTranscriptRepetitionScreen::class)->screen($transcript);

        $this->assertCount(1, $blocks);
        $this->assertSame(SuspectTranscriptBlock::REASON_REPEATED_PHRASE, $blocks[0]->reason);
        $this->assertSame('the lord', $blocks[0]->phrase);
        $this->assertSame(16, $blocks[0]->repeats);
        $this->assertSame(32, $blocks[0]->words);
    }

    #[Test]
    public function it_holds_an_extreme_one_word_loop_that_is_physically_impossible_speech(): void
    {
        // Run 1151 emits "Amen" thirty-two times in 1.8 seconds. The ordinary
        // density backstop requires a thirty-second window, so the short-phrase
        // branch must retain this independently impossible block.
        $transcript = $this->loopingTranscript(
            phrase: 'Amen.',
            repeats: 32,
            start: 1904.82,
            secondsEach: 0.05625,
        );

        $blocks = app(ServiceTranscriptRepetitionScreen::class)->screen($transcript);

        $this->assertCount(1, $blocks);
        $this->assertSame('amen', $blocks[0]->phrase);
        $this->assertSame(32, $blocks[0]->repeats);
        $this->assertEqualsWithDelta(1066.7, $blocks[0]->wordsPerMinute ?? 0.0, 0.1);
    }

    #[Test]
    public function it_holds_a_four_repeat_loop_confirmed_absent_from_the_source(): void
    {
        // Run 1230's saved sermon repeats this three-word fragment four times,
        // while the source says it once and continues into the resurrection.
        $transcript = $this->loopingTranscript(
            phrase: 'And his death.',
            repeats: 4,
            start: 1188.52,
            secondsEach: 0.225,
        );

        $blocks = app(ServiceTranscriptRepetitionScreen::class)->screen($transcript);

        $this->assertCount(1, $blocks);
        $this->assertSame('and his death', $blocks[0]->phrase);
        $this->assertSame(4, $blocks[0]->repeats);
        $this->assertSame(12, $blocks[0]->words);
    }

    #[Test]
    public function it_holds_a_loop_with_fewer_than_forty_repeated_words(): void
    {
        // Run 969 loses continuing source speech to fifteen repeated words.
        $transcript = $this->loopingTranscript(
            phrase: 'Into the kingdom.',
            repeats: 5,
            start: 3180.64,
            secondsEach: 0.348,
        );

        $blocks = app(ServiceTranscriptRepetitionScreen::class)->screen($transcript);

        $this->assertCount(1, $blocks);
        $this->assertSame('into the kingdom', $blocks[0]->phrase);
        $this->assertSame(15, $blocks[0]->words);
    }

    #[Test]
    public function it_holds_a_loop_whose_numbers_change_between_repetitions(): void
    {
        // Run 1187 invents successive numbered Peter references. Treating each
        // digit as literal lets the same surrounding decode loop evade the
        // verbatim screen.
        $cues = [];

        foreach ([6, 7, 8, 10] as $index => $number) {
            $cues[] = [
                'start' => 1350.0 + $index * 2.0,
                'end' => 1352.0 + $index * 2.0,
                'text' => "{$number} Peter 5 13 16.",
            ];
        }

        $blocks = app(ServiceTranscriptRepetitionScreen::class)->screen(
            ChurchServiceTranscript::fromCues($cues, 1800.0, ChurchServiceTranscript::SOURCE_LOCAL_WHISPER),
        );

        $this->assertCount(1, $blocks);
        $this->assertSame(4, $blocks[0]->repeats);
        $this->assertSame(20, $blocks[0]->words);
    }

    #[Test]
    public function it_does_not_treat_composite_short_phrases_as_longer_phrases(): void
    {
        foreach ([['Amen', 12], ['Amen', 23], ['The Lord', 8], ['The Lord', 15]] as [$phrase, $repeats]) {
            $transcript = $this->loopingTranscript($phrase, $repeats, 0.0, 4.0);

            $this->assertSame([], app(ServiceTranscriptRepetitionScreen::class)->screen($transcript), "{$phrase} repeated {$repeats} times");
        }
    }

    #[Test]
    public function it_preserves_a_genuine_sung_refrain(): void
    {
        // Real repetition in this corpus sits at three and four repeats and at a
        // speakable rate. Holding this would manufacture the false-positive
        // class the screen exists to avoid.
        $transcript = $this->loopingTranscript(
            phrase: 'Tell me the old, old story.',
            repeats: 3,
            start: 300.0,
            secondsEach: 3.0,
        );

        $this->assertSame([], app(ServiceTranscriptRepetitionScreen::class)->screen($transcript));
    }

    #[Test]
    public function it_preserves_ordinary_preaching_at_a_normal_word_rate(): void
    {
        $cues = [];
        $sentences = [
            'So Jesus asked them this question.',
            'Were the people who died worse sinners than the others?',
            'And what answer would you have given him that morning?',
            'Luke tells us he answered it himself.',
        ];

        foreach ($sentences as $index => $sentence) {
            $cues[] = ['start' => $index * 4.0, 'end' => ($index + 1) * 4.0, 'text' => $sentence];
        }

        $this->assertSame([], app(ServiceTranscriptRepetitionScreen::class)->screen(
            ChurchServiceTranscript::fromCues($cues, 60.0, ChurchServiceTranscript::SOURCE_LOCAL_WHISPER),
        ));
    }

    #[Test]
    public function it_holds_a_sustained_impossible_word_rate_that_is_not_a_verbatim_loop(): void
    {
        // Run 929's residual block: a doxology couplet the decoder emits over and
        // over with enough variation that no phrase repeats verbatim five times,
        // at a rate no speaker reaches. This is the one block corpus-wide that
        // the density backstop adds.
        $cues = [];
        $lines = [
            'The fount of joy and holiness, the spirit of all truth and peace.',
            'The fount of joy and holiness, to Father, Son and Spirit now.',
            'Our souls we lift, our wills we bow, the fount of joy and gladness.',
        ];

        for ($index = 0; $index < 24; $index++) {
            $cues[] = [
                'start' => $index * 1.5,
                'end' => ($index + 1) * 1.5,
                'text' => $lines[$index % count($lines)],
            ];
        }

        $blocks = app(ServiceTranscriptRepetitionScreen::class)->screen(
            ChurchServiceTranscript::fromCues($cues, 300.0, ChurchServiceTranscript::SOURCE_LOCAL_WHISPER),
        );

        $this->assertNotSame([], $blocks);
        $this->assertSame(SuspectTranscriptBlock::REASON_IMPLAUSIBLE_DENSITY, $blocks[0]->reason);
        $this->assertGreaterThanOrEqual(400.0, $blocks[0]->wordsPerMinute);
    }

    #[Test]
    public function it_holds_sparse_thirty_second_cadence_between_dense_speech(): void
    {
        // Runs 1112 and 1278 lose continuing sermon speech to one short cue per
        // Whisper chunk. The words themselves need not repeat, so neither the
        // phrase nor density screens can see the missing material.
        $denseBefore = implode(' ', array_map(static fn (int $index): string => "before{$index}", range(1, 70)));
        $denseAfter = implode(' ', array_map(static fn (int $index): string => "after{$index}", range(1, 80)));

        $cues = [
            ['start' => 0.0, 'end' => 30.0, 'text' => $denseBefore],
            ['start' => 30.0, 'end' => 31.0, 'text' => 'Another one I remember from Jesus.'],
            ['start' => 60.0, 'end' => 61.0, 'text' => 'The Lord and we heard his voice.'],
            ['start' => 90.0, 'end' => 91.0, 'text' => 'And both of those.'],
            ['start' => 120.0, 'end' => 121.0, 'text' => 'He says this.'],
            ['start' => 150.0, 'end' => 180.0, 'text' => $denseAfter],
        ];

        $blocks = app(ServiceTranscriptRepetitionScreen::class)->screen(
            ChurchServiceTranscript::fromCues($cues, 180.0, ChurchServiceTranscript::SOURCE_LOCAL_WHISPER),
        );

        $this->assertCount(1, $blocks);
        $this->assertSame(SuspectTranscriptBlock::REASON_SPARSE_CADENCE, $blocks[0]->reason);
        $this->assertSame(30.0, $blocks[0]->start);
        $this->assertSame(150.0, $blocks[0]->end);
        $this->assertSame(4, $blocks[0]->repeats);
    }

    #[Test]
    public function it_preserves_short_cadence_over_music_without_dense_speech_on_both_sides(): void
    {
        // "Amen" and "Thank you" on a 30-second grid account for almost all
        // 178 raw corpus candidates. Cadence alone is therefore not a hold.
        $cues = [
            ['start' => 30.0, 'end' => 31.0, 'text' => 'Amen.'],
            ['start' => 60.0, 'end' => 61.0, 'text' => 'Thank you.'],
            ['start' => 90.0, 'end' => 91.0, 'text' => 'Amen.'],
            ['start' => 120.0, 'end' => 121.0, 'text' => 'Thank you.'],
        ];

        $this->assertSame([], app(ServiceTranscriptRepetitionScreen::class)->screen(
            ChurchServiceTranscript::fromCues($cues, 180.0, ChurchServiceTranscript::SOURCE_LOCAL_WHISPER),
        ));
    }

    #[Test]
    public function it_holds_a_cadence_run_that_opens_the_recording(): void
    {
        // Run 1014: the recording begins with four "Thank you." cues exactly one
        // decoder window apart, and its sermon section starts at 0, so the
        // fabricated text sits inside published preaching. The leading flank
        // cannot be measured — there are no earlier cues — and requiring it made
        // the screen structurally blind at every recording's start.
        $denseAfter = implode(' ', array_map(static fn (int $index): string => "after{$index}", range(1, 80)));

        $cues = [
            ['start' => 0.0, 'end' => 30.0, 'text' => 'Thank you.'],
            ['start' => 30.0, 'end' => 60.0, 'text' => 'Thank you.'],
            ['start' => 60.0, 'end' => 90.0, 'text' => 'Thank you.'],
            ['start' => 90.0, 'end' => 120.0, 'text' => 'Thank you.'],
            ['start' => 120.0, 'end' => 150.0, 'text' => $denseAfter],
        ];

        $blocks = app(ServiceTranscriptRepetitionScreen::class)->screen(
            ChurchServiceTranscript::fromCues($cues, 150.0, ChurchServiceTranscript::SOURCE_LOCAL_WHISPER),
        );

        $this->assertCount(1, $blocks);
        $this->assertSame(SuspectTranscriptBlock::REASON_SPARSE_CADENCE, $blocks[0]->reason);
        $this->assertSame(0.0, $blocks[0]->start);
        $this->assertSame(4, $blocks[0]->repeats);
    }

    #[Test]
    public function it_holds_a_cadence_run_that_closes_the_recording(): void
    {
        // The same blindness at the other edge: no later cues, so the trailing
        // flank cannot be measured either.
        $denseBefore = implode(' ', array_map(static fn (int $index): string => "before{$index}", range(1, 70)));

        $cues = [
            ['start' => 0.0, 'end' => 30.0, 'text' => $denseBefore],
            ['start' => 30.0, 'end' => 60.0, 'text' => 'Thank you.'],
            ['start' => 60.0, 'end' => 90.0, 'text' => 'Thank you.'],
            ['start' => 90.0, 'end' => 120.0, 'text' => 'Thank you.'],
            ['start' => 120.0, 'end' => 150.0, 'text' => 'Thank you.'],
        ];

        $blocks = app(ServiceTranscriptRepetitionScreen::class)->screen(
            ChurchServiceTranscript::fromCues($cues, 150.0, ChurchServiceTranscript::SOURCE_LOCAL_WHISPER),
        );

        $this->assertCount(1, $blocks);
        $this->assertSame(SuspectTranscriptBlock::REASON_SPARSE_CADENCE, $blocks[0]->reason);
        $this->assertSame(30.0, $blocks[0]->start);
    }

    #[Test]
    public function it_still_requires_a_measurable_flank_to_be_dense(): void
    {
        // An absent flank is excused, never passed. A cadence run that opens the
        // recording and is followed by more sparse speech has one measurable
        // flank and it is not dense, so the run stays unheld.
        $cues = [
            ['start' => 0.0, 'end' => 30.0, 'text' => 'Thank you.'],
            ['start' => 30.0, 'end' => 60.0, 'text' => 'Thank you.'],
            ['start' => 60.0, 'end' => 90.0, 'text' => 'Thank you.'],
            ['start' => 90.0, 'end' => 120.0, 'text' => 'Thank you.'],
            ['start' => 121.0, 'end' => 150.0, 'text' => 'Quiet words only.'],
        ];

        $this->assertSame([], app(ServiceTranscriptRepetitionScreen::class)->screen(
            ChurchServiceTranscript::fromCues($cues, 150.0, ChurchServiceTranscript::SOURCE_LOCAL_WHISPER),
        ));
    }

    #[Test]
    public function it_reports_one_block_per_loop_rather_than_one_per_phrase_length(): void
    {
        // An eight-word phrase repeated twelve times is also a sixteen-word
        // phrase repeated six times. Reporting both would double-count the same
        // seconds in every fraction derived from the screen.
        $transcript = $this->loopingTranscript(
            phrase: 'The magi s prayer is a great day.',
            repeats: 12,
            start: 0.0,
            secondsEach: 5.0,
        );

        $blocks = app(ServiceTranscriptRepetitionScreen::class)->screen($transcript);

        $this->assertCount(1, $blocks);
        $this->assertSame(0.0, $blocks[0]->start);
        $this->assertSame(60.0, $blocks[0]->end);
    }

    #[Test]
    public function it_records_an_unmeasurable_rate_as_null_rather_than_infinity(): void
    {
        // The corpus contains blocks whose cues all carry the same instant, so
        // the rate is unbounded rather than large. INF is not JSON-encodable and
        // would fail the metadata write that stores the finding.
        $cues = [];

        for ($index = 0; $index < 12; $index++) {
            $cues[] = ['start' => 900.0, 'end' => 900.0, 'text' => 'Joy and her soul is filled with inexpressible.'];
        }

        $blocks = app(ServiceTranscriptRepetitionScreen::class)->screen(
            ChurchServiceTranscript::fromCues($cues, 1800.0, ChurchServiceTranscript::SOURCE_LOCAL_WHISPER),
        );

        $this->assertCount(1, $blocks);
        $this->assertNull($blocks[0]->wordsPerMinute);
        $this->assertNull($blocks[0]->toArray()['words_per_minute']);
        $this->assertIsString(json_encode($blocks[0]->toArray(), JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function it_returns_only_the_blocks_overlapping_the_delivered_span(): void
    {
        $screen = app(ServiceTranscriptRepetitionScreen::class);

        $blocks = [
            new SuspectTranscriptBlock(100.0, 160.0, SuspectTranscriptBlock::REASON_REPEATED_PHRASE, 80, 80.0),
            new SuspectTranscriptBlock(900.0, 940.0, SuspectTranscriptBlock::REASON_REPEATED_PHRASE, 60, 90.0),
        ];

        $within = $screen->within($blocks, [['start' => 850.0, 'end' => 1800.0]]);

        $this->assertCount(1, $within);
        $this->assertSame(900.0, $within[0]->start);
    }

    #[Test]
    public function it_counts_shared_seconds_once(): void
    {
        $seconds = SuspectTranscriptBlock::coveredSeconds([
            new SuspectTranscriptBlock(0.0, 60.0, SuspectTranscriptBlock::REASON_REPEATED_PHRASE, 40, 40.0),
            new SuspectTranscriptBlock(30.0, 90.0, SuspectTranscriptBlock::REASON_REPEATED_PHRASE, 40, 40.0),
        ]);

        $this->assertSame(90.0, $seconds);
    }

    private function loopingTranscript(string $phrase, int $repeats, float $start, float $secondsEach): ChurchServiceTranscript
    {
        $cues = [];

        for ($index = 0; $index < $repeats; $index++) {
            $cues[] = [
                'start' => $start + $index * $secondsEach,
                'end' => $start + ($index + 1) * $secondsEach,
                'text' => $phrase,
            ];
        }

        return ChurchServiceTranscript::fromCues($cues, $start + $repeats * $secondsEach + 600.0, ChurchServiceTranscript::SOURCE_LOCAL_WHISPER);
    }
}
