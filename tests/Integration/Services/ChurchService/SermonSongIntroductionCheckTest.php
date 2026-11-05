<?php

declare(strict_types=1);

namespace Tests\Integration\Services\ChurchService;

use App\Data\ChurchServiceTranscript;
use App\Enums\ServiceSectionType;
use App\Exceptions\OutputEdgeTimingsMissing;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\ChurchService\CueSafeExtractionPlan;
use App\Services\ChurchService\SermonSongIntroductionCheck;
use App\Services\Media\Audio\ServiceArtifactStorage;
use App\Support\MediaProcessingVersion;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use OpenAI\Exceptions\RateLimitException;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Resources\Chat;
use OpenAI\Responses\Chat\CreateResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AudioTimelineFixture;
use Tests\TestCase;

/**
 * Register K16 (operator, 2026-10-16): a sermon followed by a song ends at the join, never after
 * the music starts, and takes in the preacher's introduction to the song only when it ties the
 * song to the sermon. "It's not really about the length, it's about the relevance of the song to
 * the sermon." 1111's sermon was cut after "We're going to sing.", leaving the rest of its
 * introduction, the hymn's last verse read as the sermon's conclusion, in a section of its own.
 */
class SermonSongIntroductionCheckTest extends TestCase
{
    use DatabaseTransactions;

    private const SERMON = [[0.0, 3024.83]];

    /** 1111: the introduction is a section of its own; the sermon runs on through it to the song. */
    #[Test]
    public function a_tied_introduction_in_a_section_of_its_own_is_left_out_of_the_sermon(): void
    {
        $log = $this->service();
        $this->answers([2, true, 6], [2, true, 6]);

        $result = app(SermonSongIntroductionCheck::class)->prepare($log, $this->spans());

        $this->assertTrue($result['asked']);
        $this->assertTrue($result['ties_to_sermon']);
        $this->assertSame(3019.1, $result['end'], 'the sermon ends before the tied introduction');
        $this->assertSame(3019.1, app(SermonSongIntroductionCheck::class)->read($log->fresh(), $this->spans())['end']);
    }

    /** A bare announcement goes: the sermon ends at its own last line, even inside its section. */
    #[Test]
    public function a_bare_announcement_ends_the_sermon_at_its_own_last_line(): void
    {
        $log = $this->service();
        $this->answers([2, false, 2], [2, false, 2]);

        $result = app(SermonSongIntroductionCheck::class)->prepare($log, $this->spans());

        $this->assertFalse($result['ties_to_sermon']);
        $this->assertSame(3019.1, $result['end'], 'at "…all his people.", before "We\'re going to sing."');
    }

    /**
     * 1160 (operator, 2026-10-16): both answers judged the introduction tied, one stopping at its
     * last line and one running on into "Maybe we can have the words on the screen". A third
     * answer decides the line: one named twice stands.
     */
    #[Test]
    public function answers_naming_different_lines_are_settled_by_a_third(): void
    {
        $log = $this->service();
        $this->answers([5, true, 5], [6, true, 6], [5, true, 5]);

        $result = app(SermonSongIntroductionCheck::class)->prepare($log, $this->spans());

        $this->assertSame(3057.1, $result['end']);
        OpenAI::assertSent(Chat::class, 3);
    }

    /**
     * The sermon ends at `sermon_last_line` (operator, 2026-10-28), so two answers agreeing on the
     * judgement and the video's last line but naming different sermon lines are settled by a third,
     * not by taking the earlier: 14 of 420 banked answers on 10-29 were two of this shape.
     */
    #[Test]
    public function answers_naming_different_sermon_lines_are_settled_by_a_third(): void
    {
        $log = $this->service();
        $this->answers([2, true, 6], [5, true, 6], [5, true, 6]);

        $result = app(SermonSongIntroductionCheck::class)->prepare($log, $this->spans());

        $this->assertSame(3057.1, $result['end'], 'the sermon line two answers name');
        OpenAI::assertSent(Chat::class, 3);
    }

    /**
     * 1001 (heard 10-28 and 10-29): a third answer moved the end to "And celebrate his mercy to you,",
     * splitting the sentence that runs on into the hymn invitation. Either whole sentence was heard
     * as right and the shorter wins on a sermon end, so a line left open on a clause steps back.
     */
    #[Test]
    #[DataProvider('openClauseEndings')]
    public function a_line_left_open_on_a_clause_steps_back_to_the_line_before(string $fifth, float $expected): void
    {
        $log = $this->service();
        $cues = $this->cues();
        $cues[4]['text'] = $fifth;
        Storage::disk('local')->put('temp/introduction.json', json_encode(ChurchServiceTranscript::fromCues($cues, 5000, ChurchServiceTranscript::SOURCE_MOCK)->toArray(), JSON_THROW_ON_ERROR));
        $this->answers([5, true, 6], [5, true, 6]);

        $this->assertSame($expected, app(SermonSongIntroductionCheck::class)->prepare($log, $this->spans())['end']);
    }

    /** 1077: inside the song's recorded section a comma ends a transcribed lyric line, not a sentence. */
    #[Test]
    public function a_line_from_the_songs_recorded_start_is_not_taken_as_open(): void
    {
        $log = $this->service(sections: [['sermon', 0.0, 3024.83], ['other', 3024.83, 3050.0], ['song', 3050.0, 3300.0]]);
        $cues = $this->cues();
        $cues[4]['text'] = 'Because you died for me,';
        Storage::disk('local')->put('temp/introduction.json', json_encode(ChurchServiceTranscript::fromCues($cues, 5000, ChurchServiceTranscript::SOURCE_MOCK)->toArray(), JSON_THROW_ON_ERROR));
        $this->answers([5, true, 5], [5, true, 5]);

        $this->assertSame(3057.1, app(SermonSongIntroductionCheck::class)->prepare($log, $this->spans())['end']);
    }

    /** @return array<string, array{string, float}> */
    public static function openClauseEndings(): array
    {
        return [
            'comma' => ['And celebrate his mercy to you,', 3032.2],
            'colon' => ['now by singing our final hymn from your heart:', 3032.2],
            'closing quote after a comma' => ['“Make it today,”', 3032.2],
            'full stop stays' => ['Make it today.', 3057.1],
            'unpunctuated line stays' => ['make it today', 3057.1],
        ];
    }

    /** A pair banked before the sermon line drew a third answer gets that answer, and keeps its first two. */
    #[Test]
    public function a_banked_pair_split_on_the_sermon_line_is_settled_by_a_third(): void
    {
        $log = $this->service();
        $this->bankPair($log, [2, true, 6], [5, true, 6]);
        $check = app(SermonSongIntroductionCheck::class);
        $this->assertSame(3019.1, $check->read($log, $this->spans())['end'], 'the earlier line, before settling');
        $this->answers([5, true, 6]);

        $result = $check->settleBankedSplit($log, $this->spans());

        $this->assertSame(['asked' => true, 'end' => 3057.1, 'ties_to_sermon' => true], $result);
        $this->assertSame(3057.1, $check->read($log->fresh(), $this->spans())['end']);
        OpenAI::assertSent(Chat::class, 1);
    }

    #[Test]
    #[DataProvider('settledBankedAnswers')]
    public function a_banked_answer_that_needs_no_third_is_not_asked_again(array $first, array $second, ?array $third): void
    {
        $log = $this->service();
        $this->bankPair($log, $first, $second, $third);
        OpenAI::fake([]);

        $this->assertFalse(app(SermonSongIntroductionCheck::class)->settleBankedSplit($log, $this->spans())['asked']);
        OpenAI::assertNothingSent();
    }

    /** @return array<string, array{array{int, bool, int}, array{int, bool, int}, array{int, bool, int}|null}> */
    public static function settledBankedAnswers(): array
    {
        return [
            'agreeing pair' => [[5, true, 6], [5, true, 6], null],
            'already three' => [[2, true, 6], [5, true, 6], [5, true, 6]],
        ];
    }

    #[Test]
    public function nothing_banked_is_nothing_to_settle(): void
    {
        $log = $this->service();
        OpenAI::fake([]);

        $this->assertNull(app(SermonSongIntroductionCheck::class)->settleBankedSplit($log, $this->spans()));
        OpenAI::assertNothingSent();
    }

    #[Test]
    public function three_different_lines_take_the_middle_one(): void
    {
        $log = $this->service();
        $this->answers([4, true, 4], [6, true, 6], [5, true, 5]);

        $this->assertSame(3057.1, app(SermonSongIntroductionCheck::class)->prepare($log, $this->spans())['end']);
    }

    /** Two answers on the winning side naming different lines: the earlier, as the longer ran on. */
    #[Test]
    public function two_winning_answers_naming_different_lines_take_the_earlier(): void
    {
        $log = $this->service();
        $this->answers([5, true, 5], [2, false, 2], [6, true, 6]);

        $result = app(SermonSongIntroductionCheck::class)->prepare($log, $this->spans());

        $this->assertTrue($result['ties_to_sermon']);
        $this->assertSame(3057.1, $result['end']);
    }

    /** 1005: one answer missed a garbled introduction. A third answer decides by majority. */
    #[Test]
    public function answers_that_disagree_on_the_judgement_are_settled_by_a_third(): void
    {
        $log = $this->service();
        $this->answers([6, true, 6], [2, false, 2], [6, true, 6]);

        $result = app(SermonSongIntroductionCheck::class)->prepare($log, $this->spans());

        $this->assertTrue($result['ties_to_sermon']);
        $this->assertSame(3064.4, $result['end']);
        OpenAI::assertSent(Chat::class, 3);
    }

    /**
     * 1224: "…destruction, | and despair." was spoken at the start of a 5 s window the classifier
     * heard music in. A line starting in the first music window may finish a sentence; a line
     * after it is the song, and an answer naming one steps back to the line before.
     */
    #[Test]
    public function lines_after_the_first_music_window_are_never_the_sermons_end(): void
    {
        $log = $this->service(timeline: [[2950, 3065, 0.01, 0.8], [3065, 3070, 0.6, 0.5], [3070, 3200, 0.8, 0.02]]);
        $this->answers([9, true, 9], [9, true, 9]);

        $result = app(SermonSongIntroductionCheck::class)->prepare($log, $this->spans());

        $this->assertSame(3069.1, $result['end'], '"So let\'s stand to sing 861." starts in the music window: the latest line allowed');
        OpenAI::assertSent(Chat::class, static fn (string $method, array $parameters): bool => str_contains($parameters['messages'][1]['content'], "7. [music may start] So let's stand to sing 861.")
            && str_contains($parameters['messages'][1]['content'], '8. [music] I have heard the cross'));
    }

    #[Test]
    public function a_banked_answer_is_not_asked_again(): void
    {
        $log = $this->service();
        $this->answers([3, true, 6], [3, true, 6]);
        $check = app(SermonSongIntroductionCheck::class);
        $check->prepare($log, $this->spans());

        $again = $check->prepare($log->fresh(), $this->spans());

        $this->assertFalse($again['asked']);
        $this->assertSame(3024.8, $again['end']);
        OpenAI::assertSent(Chat::class, 2);
    }

    #[Test]
    public function recording_ending_during_a_bare_announcement_still_asks_k16(): void
    {
        $log = $this->service(sections: [['sermon', 0.0, 1580.5]], timeline: [[1500, 1580, 0.01, 0.8]]);
        $cues = [
            ['start' => 1568.0, 'end' => 1570.0, 'text' => 'Amen.'],
            ['start' => 1570.0, 'end' => 1573.0, 'text' => "Let's sing again."],
            ['start' => 1573.0, 'end' => 1579.72, 'text' => "And I've chosen you."],
        ];
        Storage::disk('local')->put('temp/introduction.json', json_encode(ChurchServiceTranscript::fromCues($cues, 1580, ChurchServiceTranscript::SOURCE_MOCK)->toArray(), JSON_THROW_ON_ERROR));
        $spans = [['start_time' => 0.0, 'end_time' => 1580.5]];
        $this->answers([1, false, 1], [1, false, 1]);
        $check = app(SermonSongIntroductionCheck::class);

        $question = $check->question($log, $spans);
        $this->assertNotNull($question);
        $this->assertNull($question['identity']['song_start']);
        $this->assertSame(1579.72, $question['identity']['recording_end']);
        $this->assertStringContainsString('Next song: not in the recording (it ends after the last line).', $question['prompt']);
        $this->assertNull($check->songStart($log, $spans));
        $this->assertSame(['asked' => true, 'end' => 1570.0, 'ties_to_sermon' => false], $check->prepare($log, $spans));
        $this->assertSame(['asked' => false, 'end' => 1570.0, 'ties_to_sermon' => false], $check->prepare($log->fresh(), $spans));
        OpenAI::assertSent(Chat::class, 2);
    }

    #[Test]
    public function a_tied_introduction_before_recording_end_stops_at_the_sermons_own_last_line(): void
    {
        $log = $this->service(sections: [['sermon', 0.0, 3024.83], ['other', 3024.83, 3076.1]], timeline: [[2950, 3080, 0.01, 0.8]]);
        $this->answers([2, true, 6], [2, true, 6]);
        $check = app(SermonSongIntroductionCheck::class);

        $this->assertSame(['asked' => true, 'end' => 3019.1, 'ties_to_sermon' => true], $check->prepare($log, $this->spans()));
        $this->assertSame(3019.1, $check->read($log->fresh(), $this->spans())['end']);
        $this->assertNull($check->songStart($log, $this->spans()));
    }

    #[Test]
    #[DataProvider('recordingEndLineVotes')]
    public function recording_end_reuses_banked_calls_to_vote_on_the_sermons_last_line(array $numbers, float $expected, bool $music): void
    {
        $log = $this->service(sections: [['sermon', 0.0, 3024.83]],
            timeline: $music ? [[2950, 3055, 0.01, 0.8], [3055, 3060, 0.8, 0.5], [3060, 3080, 0.8, 0.5]] : [[2950, 3080, 0.01, 0.8]]);
        $check = app(SermonSongIntroductionCheck::class);
        $question = $check->question($log, $this->spans());
        $identity = $question['identity'];
        $kind = 'sermon-song-introduction-'.hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
        $payload = ['identity' => $identity, 'calls_completed' => true, 'end' => 3064.4, 'ties_to_sermon' => true,
            'line' => $this->cues()[5]['text'], 'lines' => $question['lines'],
            'calls' => array_map(static fn (int $n): array => ['sermon_last_line' => $n, 'ties_to_sermon' => true, 'video_last_line' => 6, 'reason' => 'banked'], $numbers)];
        if ($numbers === []) {
            unset($payload['calls']);
        }
        app(ServiceArtifactStorage::class)->putJson($log->processing_id, $kind, $payload);
        OpenAI::fake([]);

        $this->assertSame($expected, $check->read($log->fresh(), $this->spans())['end']);
        $this->assertSame(['asked' => false, 'end' => $expected, 'ties_to_sermon' => true], $check->prepare($log->fresh(), $this->spans()));
        $this->assertSame($payload, json_decode(Storage::disk('local')->get(ServiceArtifactStorage::recordedFor($log->fresh())[0]['path']), true));
        OpenAI::assertNothingSent();
    }

    /** @return array<string, array{list<int>, float, bool}> */
    public static function recordingEndLineVotes(): array
    {
        return [
            'majority' => [[2, 4, 2], 3019.1, false],
            'median of three' => [[2, 4, 3], 3024.8, false],
            'earlier of two' => [[4, 2], 3019.1, false],
            'step back from music' => [[6, 6], 3057.1, true],
            'legacy payload without calls keeps stored end' => [[], 3064.4, false],
        ];
    }

    #[Test]
    #[DataProvider('closingPrayerLines')]
    public function a_recording_end_keeps_the_last_closing_amen_within_the_untied_video_vote(string $fifth, string $sixth, int $videoLine, bool $ties, bool $song, float $expected): void
    {
        $log = $song ? $this->service() : $this->service(sections: [['sermon', 0.0, 3024.83]], timeline: [[2950, 3080, 0.01, 0.8]]);
        $cues = $this->cues();
        $cues[4]['text'] = $fifth;
        $cues[5]['text'] = $sixth;
        Storage::disk('local')->put('temp/introduction.json', json_encode(ChurchServiceTranscript::fromCues($cues, 5000, ChurchServiceTranscript::SOURCE_MOCK)->toArray(), JSON_THROW_ON_ERROR));
        $this->answers([2, $ties, $videoLine], [2, $ties, $videoLine]);
        $check = app(SermonSongIntroductionCheck::class);

        $this->assertSame($expected, $check->prepare($log, $this->spans())['end']);
        $this->assertSame($expected, $check->read($log->fresh(), $this->spans())['end']);
        $this->assertSame(['asked' => false, 'end' => $expected, 'ties_to_sermon' => $ties], $check->prepare($log->fresh(), $this->spans()));
        OpenAI::assertSent(Chat::class, 2);
    }

    /** @return array<string, array{string, string, int, bool, bool, float}> */
    public static function closingPrayerLines(): array
    {
        return [
            'closing prayer before thanks' => ['In Jesus’ name. Amen.', 'Thank you.', 6, false, false, 3057.1],
            'congregational amen after preacher amen excluded' => ['Amen.', 'Amen.', 6, false, false, 3057.1],
            'double amen inside one line stays whole' => ['In Jesus’ name. Amen. Amen.', 'Thank you.', 6, false, false, 3057.1],
            'quoted capital amen' => ['“AMEN!”', 'Thank you.', 6, false, false, 3057.1],
            'amen beyond video vote excluded' => ['Amen.', 'Thank you.', 4, false, false, 3019.1],
            'amen followed by a singing call excluded' => ["Amen. Let's sing.", 'Thank you.', 6, false, false, 3019.1],
            'no later amen keeps sermon line' => ['A conclusion.', 'Thank you.', 6, false, false, 3019.1],
            'tied introduction never recovers an amen' => ['Amen.', 'Amen.', 6, true, false, 3019.1],
            'song joins keep the preacher prayer too' => ['Amen.', 'Thank you.', 6, false, true, 3057.1],
        ];
    }

    #[Test]
    public function a_congregational_amen_straight_after_the_sermons_amen_is_not_recovered(): void
    {
        $log = $this->service(sections: [['sermon', 0.0, 3024.83]], timeline: [[2950, 3080, 0.01, 0.8]]);
        $cues = $this->cues();
        $cues[1]['text'] = 'In Jesus’ name. Amen.';
        $cues[2]['text'] = '“Amen.”';
        Storage::disk('local')->put('temp/introduction.json', json_encode(ChurchServiceTranscript::fromCues($cues, 5000, ChurchServiceTranscript::SOURCE_MOCK)->toArray(), JSON_THROW_ON_ERROR));
        $this->answers([2, false, 3], [2, false, 3]);
        $check = app(SermonSongIntroductionCheck::class);

        $this->assertSame(3019.1, $check->prepare($log, $this->spans())['end']);
        $this->assertSame(3019.1, $check->read($log->fresh(), $this->spans())['end']);
        OpenAI::assertSent(Chat::class, 2);
    }

    /**
     * 1101 (sermon-own-last-line-20261028, kept at the preacher's Amen, 1411.2): asked with sentence
     * lines 11-04, two answers of three ended on "Thank you." after the congregation's "Amen.". The
     * answering Amen is not in the sermon and the video is one stretch, so neither is the line after it.
     */
    #[Test]
    public function a_line_straight_after_the_answering_amen_steps_back_to_the_preachers_amen(): void
    {
        $log = $this->service(sections: [['sermon', 0.0, 3024.83]], timeline: [[2950, 3080, 0.01, 0.8]], cues: [
            ['start' => 3010.0, 'end' => 3013.0, 'text' => "And we ask these things in Jesus' name and for his sake."],
            ['start' => 3013.0, 'end' => 3014.0, 'text' => 'Amen.'],
            ['start' => 3014.0, 'end' => 3016.0, 'text' => 'Amen.'],
            ['start' => 3016.0, 'end' => 3017.0, 'text' => 'Thank you.'],
        ]);
        $this->answers([1, false, 4], [4, false, 4], [4, false, 4]);

        $this->assertSame(3014.0, app(SermonSongIntroductionCheck::class)->prepare($log, $this->spans())['end']);
    }

    #[Test]
    #[DataProvider('chosenAmenLines')]
    public function a_chosen_answering_amen_steps_back_to_the_first_amen_line(string $previous, string $chosen, int $chosenLine, bool $ties, bool $song, float $expected): void
    {
        $log = $song ? $this->service() : $this->service(sections: [['sermon', 0.0, 3024.83]], timeline: [[2950, 3080, 0.01, 0.8]]);
        $cues = $this->cues();
        $cues[1]['text'] = $previous;
        $cues[2]['text'] = $chosen;
        $cues[3]['text'] = 'Amen.';
        Storage::disk('local')->put('temp/introduction.json', json_encode(ChurchServiceTranscript::fromCues($cues, 5000, ChurchServiceTranscript::SOURCE_MOCK)->toArray(), JSON_THROW_ON_ERROR));
        $this->answers([$chosenLine, $ties, $chosenLine], [$chosenLine, $ties, $chosenLine]);
        $check = app(SermonSongIntroductionCheck::class);

        $this->assertSame($expected, $check->prepare($log, $this->spans())['end']);
        $this->assertSame($expected, $check->read($log->fresh(), $this->spans())['end']);
        $this->assertSame(['asked' => false, 'end' => $expected, 'ties_to_sermon' => $ties], $check->prepare($log->fresh(), $this->spans()));
        OpenAI::assertSent(Chat::class, 2);
    }

    /** @return array<string, array{string, string, int, bool, bool, float}> */
    public static function chosenAmenLines(): array
    {
        return [
            '1204 lone amen after amen' => ['Amen.', 'Amen.', 3, false, false, 3019.1],
            '1352 two answering amens after prayer' => ['For evermore. Amen.', 'Amen.', 4, false, false, 3019.1],
            'punctuation and capitalisation' => ['In Jesus’ name. AMEN!', '“AMEN.”', 3, false, false, 3019.1],
            'song join' => ['Amen.', 'Amen.', 3, false, true, 3019.1],
            'tied vote' => ['Amen.', 'Amen.', 3, true, true, 3019.1],
            'first amen stays' => ['Let us pray.', 'Amen.', 3, false, false, 3024.8],
            'substantive chosen line stays' => ['Amen.', 'For evermore. Amen.', 3, false, false, 3024.8],
            'amen inside previous line is not its end' => ['Amen means so be it.', 'Amen.', 3, false, false, 3024.8],
        ];
    }

    #[Test]
    public function closing_prayer_recovery_uses_the_agreed_video_line_and_never_steps_into_music(): void
    {
        $log = $this->service(sections: [['sermon', 0.0, 3024.83]], timeline: [[2950, 3055, 0.01, 0.8], [3055, 3080, 0.8, 0.5]]);
        $cues = $this->cues();
        $cues[3]['text'] = 'Amen.';
        $cues[5]['text'] = 'Amen.';
        Storage::disk('local')->put('temp/introduction.json', json_encode(ChurchServiceTranscript::fromCues($cues, 5000, ChurchServiceTranscript::SOURCE_MOCK)->toArray(), JSON_THROW_ON_ERROR));
        $this->answers([2, false, 4], [2, false, 6], [2, false, 6]);
        $check = app(SermonSongIntroductionCheck::class);

        $this->assertSame(3032.2, $check->prepare($log, $this->spans())['end']);
        $this->assertSame(3032.2, $check->read($log->fresh(), $this->spans())['end']);
        OpenAI::assertSent(Chat::class, 3);
    }

    #[Test]
    public function a_song_join_ends_at_the_sermons_own_line_even_when_the_introduction_is_tied(): void
    {
        $log = $this->service();
        $this->answers([2, true, 6], [2, true, 6]);
        $check = app(SermonSongIntroductionCheck::class);

        $this->assertSame(3019.1, $check->prepare($log, $this->spans())['end']);
        $this->assertSame(3019.1, $check->read($log->fresh(), $this->spans())['end']);
        OpenAI::assertSent(Chat::class, 2);
    }

    #[Test]
    public function a_song_answer_banked_under_the_original_identity_is_reused(): void
    {
        $log = $this->service();
        $check = app(SermonSongIntroductionCheck::class);
        $log->serviceSections()->where('section_type', ServiceSectionType::Sermon)->update(['title' => 'Sermon']);
        $log->serviceSections()->where('section_type', ServiceSectionType::Other)->update(['title' => 'Introduction']);
        $log->serviceSections()->where('section_type', ServiceSectionType::Song)->update(['title' => 'Hymn']);
        $prompt = <<<'TXT'
Sermon: "Sermon".
Next song: "Hymn".
Detected between them: other "Introduction".

Transcript:
1. You'll meet your king to begin a life of eternal joy with
2. him and all his people.
3. We're going to sing.
--- (the detected sermon section ends here) ---
4. We're going to sing a hymn that I chose.
5. Oh, let me see the lightning flare that ends this world's long night.
6. I pray that all of us will be able to sing that.
7. So let's stand to sing 861.
8. [music may start] I have heard the cross

TXT;
        $this->assertSame($prompt, $check->question($log, $this->spans())['prompt']);
        $identity = [
            'processing_id' => $log->processing_id,
            'sermon_end' => 3024.83,
            'song_start' => 3072.7,
            'model' => (string) config('media-processing.service_structure.model'),
            'prompt_version' => 3,
            'transcript' => hash('sha256', $prompt),
            'media_processing' => MediaProcessingVersion::signature(),
        ];
        $this->assertSame($identity, $check->question($log, $this->spans())['identity']);
        $this->assertStringContainsString('Next song: "', $prompt);
        $kind = 'sermon-song-introduction-'.hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
        app(ServiceArtifactStorage::class)->putJson($log->processing_id, $kind, [
            'identity' => $identity, 'calls_completed' => true, 'end' => 3064.4, 'ties_to_sermon' => true, 'line' => $this->cues()[5]['text'],
        ]);
        OpenAI::fake([]);

        $this->assertSame(['asked' => false, 'end' => 3064.4, 'ties_to_sermon' => true], $check->prepare($log->fresh(), $this->spans()));
        OpenAI::assertNothingSent();
    }

    /**
     * The reworded prompt (operator 2026-10-30, adopted as measured) asks a different question, so an
     * answer banked under version 2 is not read: the round asks every end afresh.
     */
    #[Test]
    public function an_answer_banked_under_the_previous_prompt_version_is_not_read(): void
    {
        $log = $this->service();
        $check = app(SermonSongIntroductionCheck::class);
        $identity = [...$check->question($log, $this->spans())['identity'], 'prompt_version' => 2];
        $kind = 'sermon-song-introduction-'.hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
        app(ServiceArtifactStorage::class)->putJson($log->processing_id, $kind, [
            'identity' => $identity, 'calls_completed' => true, 'end' => 3064.4, 'ties_to_sermon' => true, 'line' => $this->cues()[5]['text'],
        ]);

        $this->assertNull($check->read($log->fresh(), $this->spans()));

        $this->answers([2, true, 6], [2, true, 6]);
        $this->assertTrue($check->prepare($log->fresh(), $this->spans())['asked']);
        OpenAI::assertSent(Chat::class, 2);
    }

    /**
     * Item 6 (11-04): lines joined into sentences are a different question, so an answer banked to
     * the raw cues on a run whose cues now join is not read, and the next round asks it afresh.
     * Runs whose lines do not change keep their answers: no prompt version is bumped.
     */
    #[Test]
    public function an_answer_banked_to_the_raw_cues_is_not_read_once_they_join_into_a_sentence(): void
    {
        $cues = $this->cues();
        $cues[0]['end'] = 3017.4;
        $log = $this->service(cues: $cues);
        $check = app(SermonSongIntroductionCheck::class);
        $question = $check->question($log, $this->spans());
        $rawPrompt = str_replace("1. You'll meet your king to begin a life of eternal joy with him and all his people.\n",
            "1. You'll meet your king to begin a life of eternal joy with\n2. him and all his people.\n", $question['prompt']);
        $this->assertNotSame($rawPrompt, $question['prompt']);
        $identity = [...$question['identity'], 'transcript' => hash('sha256', $rawPrompt)];
        $kind = 'sermon-song-introduction-'.hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
        app(ServiceArtifactStorage::class)->putJson($log->processing_id, $kind, [
            'identity' => $identity, 'calls_completed' => true, 'end' => 3019.1, 'ties_to_sermon' => false, 'line' => 'him and all his people.',
        ]);

        $this->assertNull($check->read($log->fresh(), $this->spans()));
        $this->answers([1, false, 1], [1, false, 1]);
        $this->assertSame(3019.1, $check->prepare($log->fresh(), $this->spans())['end']);
        OpenAI::assertSent(Chat::class, 2);
    }

    /**
     * K16 as reworded 2026-10-29: the sermon's own conclusion stays even when it mentions, quotes or
     * leads into the song (1131 and 1140 lost their last point under version 2).
     */
    #[Test]
    public function the_model_is_told_a_conclusion_that_names_the_song_is_the_sermons_own(): void
    {
        $log = $this->service();
        $this->answers([2, true, 6], [2, true, 6]);

        app(SermonSongIntroductionCheck::class)->prepare($log, $this->spans());

        OpenAI::assertSent(Chat::class, static fn (string $method, array $parameters): bool => str_contains($parameters['messages'][0]['content'],
            "- The sermon's own conclusion or application is part of the sermon even when it mentions, quotes or leads into the song: it stays when it is fundamentally the sermon that happens to mention the song.")
            && str_contains($parameters['messages'][0]['content'], '"sermon_last_line": <number of the last line of the sermon itself, including its own conclusion or application even when that names the song; before any line whose purpose is to introduce or announce the song>'));
    }

    #[Test]
    public function a_recording_end_answer_survives_the_planners_source_duration_cap(): void
    {
        $log = $this->service(sections: [['sermon', 0.0, 3076.5]], timeline: [[2950, 3080, 0.01, 0.8]]);
        $log->update(['duration' => 3076.1]);
        $check = app(SermonSongIntroductionCheck::class);
        $this->answers([2, false, 2], [2, false, 2]);
        $check->prepare($log, [['start_time' => 0.0, 'end_time' => 3076.5]]);

        $this->assertSame(3019.1, $check->read($log->fresh(), [['start_time' => 0.0, 'end_time' => 3076.1]])['end'] ?? null);
        OpenAI::assertSent(Chat::class, 2);
    }

    #[Test]
    public function a_recording_end_join_requires_a_banked_answer_before_planning(): void
    {
        $log = $this->service(sections: [['sermon', 0.0, 3024.83]], timeline: [[2950, 3080, 0.01, 0.8]]);
        OpenAI::fake([]);
        $this->expectException(OutputEdgeTimingsMissing::class);
        $this->expectExceptionMessage('has no answer on where its sermon ends');

        app(CueSafeExtractionPlan::class)->forSpans($log, $this->spans(), sermonEnd: true);
    }

    #[Test]
    #[DataProvider('blockedRecordingEnds')]
    public function a_recording_end_does_not_cross_a_published_item_or_exceed_reach(array $sections, float $end): void
    {
        $log = $this->service(sections: $sections);
        OpenAI::fake([]);

        $this->assertNull(app(SermonSongIntroductionCheck::class)->question($log, [['start_time' => 0.0, 'end_time' => $end]]));
        OpenAI::assertNothingSent();
    }

    /** @return array<string, array{list<array{string, float, float}>, float}> */
    public static function blockedRecordingEnds(): array
    {
        return [
            'published talk' => [[['sermon', 0.0, 3024.83], ['short_talk', 3025.0, 3076.1]], 3024.83],
            'another sermon' => [[['sermon', 0.0, 3024.83], ['sermon', 3025.0, 3076.1]], 3024.83],
            'recording end beyond reach' => [[['sermon', 0.0, 2900.0]], 2900.0],
        ];
    }

    /** An answer judged the words it read: a line re-transcribed is a new question. */
    #[Test]
    public function a_banked_answer_does_not_survive_a_change_to_the_text_it_judged(): void
    {
        $log = $this->service();
        $this->answers([3, true, 6], [3, true, 6]);
        app(SermonSongIntroductionCheck::class)->prepare($log, $this->spans());

        $cues = $this->cues();
        $cues[4]['text'] = 'Do take a notice sheet on your way out.';
        Storage::disk('local')->put('temp/introduction.json', json_encode(ChurchServiceTranscript::fromCues($cues, 5000, ChurchServiceTranscript::SOURCE_MOCK)->toArray(), JSON_THROW_ON_ERROR));

        $this->assertNull(app(SermonSongIntroductionCheck::class)->read($log->fresh(), $this->spans()));
    }

    /** A clip of its own between the sermon and the song keeps its edges: nothing is asked. */
    #[Test]
    public function a_published_item_before_the_song_is_not_crossed(): void
    {
        $log = $this->service(sections: [['sermon', 0.0, 3024.83], ['short_talk', 3024.83, 3072.0], ['song', 3072.7, 3300.0]]);
        OpenAI::fake([]);

        $this->assertSame(['asked' => false, 'end' => null, 'ties_to_sermon' => null], app(SermonSongIntroductionCheck::class)->prepare($log, $this->spans()));
        OpenAI::assertNothingSent();
    }

    /**
     * 1251 (heard 10-20): the song starts 0.11 s before the sermon's detected end, so it was never
     * the sermon's neighbour and the end split "Let's stand and sing, shall we".
     */
    #[Test]
    public function a_song_overlapping_the_sermons_end_is_its_neighbour(): void
    {
        $log = $this->service(sections: [['sermon', 0.0, 3024.83], ['song', 3024.72, 3300.0]]);

        $this->assertSame(3024.72, app(SermonSongIntroductionCheck::class)->songStart($log, $this->spans()));
    }

    #[Test]
    public function a_song_out_of_reach_is_not_the_sermons_neighbour(): void
    {
        $log = $this->service(sections: [['sermon', 0.0, 3024.83], ['song', 3200.0, 3400.0]]);

        $this->assertNull(app(SermonSongIntroductionCheck::class)->songStart($log, $this->spans()));
    }

    /** Nothing credibly said before the song: nothing to judge, and the detected end stands. */
    #[Test]
    public function with_nothing_said_near_the_join_the_detected_end_stands(): void
    {
        $log = $this->service(sections: [['sermon', 0.0, 2900.0], ['song', 2910.0, 3300.0]]);
        OpenAI::fake([]);

        $this->assertSame(['asked' => false, 'end' => 2900.0, 'ties_to_sermon' => null], app(SermonSongIntroductionCheck::class)->prepare($log, [['start_time' => 0.0, 'end_time' => 2900.0]]));
        $this->assertSame(2900.0, app(SermonSongIntroductionCheck::class)->read($log, [['start_time' => 0.0, 'end_time' => 2900.0]])['end']);
        OpenAI::assertNothingSent();
    }

    #[Test]
    #[DataProvider('transportFailurePositions')]
    public function transport_failure_is_not_banked_and_a_retry_can_recover(int $position): void
    {
        $log = $this->service();
        $failure = new \RuntimeException('down');
        $responses = [
            CreateResponse::fake(['choices' => [['message' => ['content' => '{"sermon_last_line":3,"ties_to_sermon":true,"video_last_line":5}']]]]),
            CreateResponse::fake(['choices' => [['message' => ['content' => '{"sermon_last_line":3,"ties_to_sermon":true,"video_last_line":6}']]]]),
        ];
        OpenAI::fake([...array_slice($responses, 0, $position - 1), $failure]);

        try {
            app(SermonSongIntroductionCheck::class)->prepare($log, $this->spans());
            $this->fail('Transport failure must propagate for retry.');
        } catch (\RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }

        $this->assertNull(app(SermonSongIntroductionCheck::class)->read($log->fresh(), $this->spans()));
        $this->answers([3, true, 6], [3, true, 6]);
        $this->assertSame(3024.8, app(SermonSongIntroductionCheck::class)->prepare($log->fresh(), $this->spans())['end']);
    }

    /** @return array<string, array{int}> */
    public static function transportFailurePositions(): array
    {
        return ['first call' => [1], 'second call' => [2], 'tie-break call' => [3]];
    }

    #[Test]
    public function invalid_content_answers_are_banked_as_uncertain(): void
    {
        $log = $this->service();
        OpenAI::fake([CreateResponse::fake(['choices' => [['message' => ['content' => '{}']]]]), CreateResponse::fake(['choices' => [['message' => ['content' => '{}']]]])]);
        $this->assertNull(app(SermonSongIntroductionCheck::class)->prepare($log, $this->spans())['end']);
        $this->assertSame(['end' => null, 'ties_to_sermon' => null, 'line' => null], app(SermonSongIntroductionCheck::class)->read($log->fresh(), $this->spans()));
    }

    #[Test]
    public function flex_capacity_failure_uses_the_default_tier(): void
    {
        $log = $this->service();
        config(['openai.service_tier' => 'flex']);
        $answer = CreateResponse::fake(['choices' => [['message' => ['content' => '{"sermon_last_line":3,"ties_to_sermon":true,"video_last_line":6,"reason":"test"}']]]]);
        OpenAI::fake([
            new RateLimitException(new Response(429, [], '{"error":{"code":"flex_unavailable"}}')),
            $answer,
            $answer,
        ]);

        $this->assertSame(3024.8, app(SermonSongIntroductionCheck::class)->prepare($log, $this->spans())['end']);
        OpenAI::assertSent(Chat::class, static fn (string $method, array $parameters): bool => $method === 'create' && $parameters['service_tier'] === 'default');
        OpenAI::assertSent(Chat::class, 3);
    }

    #[Test]
    public function legacy_null_answers_without_completed_transport_are_retried(): void
    {
        $log = $this->service();
        OpenAI::fake([CreateResponse::fake(['choices' => [['message' => ['content' => '{}']]]]), CreateResponse::fake(['choices' => [['message' => ['content' => '{}']]]])]);
        app(SermonSongIntroductionCheck::class)->prepare($log, $this->spans());
        foreach (ServiceArtifactStorage::recordedFor($log->fresh()) as $entry) {
            if (! str_starts_with($entry['kind'], 'sermon-song-introduction-')) {
                continue;
            }
            $payload = json_decode(Storage::disk($entry['disk'])->get($entry['path']), true);
            unset($payload['calls_completed']);
            Storage::disk($entry['disk'])->put($entry['path'], json_encode($payload, JSON_THROW_ON_ERROR));
        }

        $this->answers([3, true, 6], [3, true, 6]);
        $this->assertSame(3024.8, app(SermonSongIntroductionCheck::class)->prepare($log->fresh(), $this->spans())['end']);
    }

    /**
     * 962 (item 6, operator 11-04): the cues are fragments, so the question read as "pray this" and
     * the model ended there, mid-sentence. Cues join into the sentence their text closes.
     */
    #[Test]
    public function fragment_cues_are_asked_as_the_sentences_their_text_closes(): void
    {
        $log = $this->service(cues: [
            ['start' => 3010.0, 'end' => 3011.2, 'text' => "But that said, let's just"],
            ['start' => 3011.2, 'end' => 3012.1, 'text' => 'pray this'],
            ['start' => 3012.1, 'end' => 3014.0, 'text' => 'prayer now, shall we?'],
            ['start' => 3014.4, 'end' => 3016.0, 'text' => 'Our Father in heaven,'],
            ['start' => 3016.0, 'end' => 3018.2, 'text' => 'hallowed be your name.'],
            ['start' => 3018.6, 'end' => 3019.1, 'text' => 'Amen.'],
            ['start' => 3023.5, 'end' => 3024.8, 'text' => "Well, let's sing our final hymn."],
        ]);

        $question = app(SermonSongIntroductionCheck::class)->question($log, $this->spans());

        $this->assertSame([
            "But that said, let's just pray this prayer now, shall we?",
            'Our Father in heaven, hallowed be your name.',
            'Amen.',
            "Well, let's sing our final hymn.",
        ], array_column($question['lines'], 'text'));
        $this->assertSame([3010.0, 3014.0], [$question['lines'][0]['start'], $question['lines'][0]['end']]);
        $this->assertStringContainsString("1. But that said, let's just pray this prayer now, shall we?", $question['prompt']);
    }

    /**
     * 1254 and 1204 (item 6 v1, 11-04): joined lines carried "Amen Let's sing together" as one line,
     * and an answering Amen onto the prayer's. An Amen closes its line and opens none.
     */
    #[Test]
    public function an_amen_is_never_joined_to_the_words_around_it(): void
    {
        $log = $this->service(cues: [
            ['start' => 3012.0, 'end' => 3014.0, 'text' => 'and we ask these things'],
            ['start' => 3014.0, 'end' => 3016.0, 'text' => 'in his name'],
            ['start' => 3016.0, 'end' => 3016.6, 'text' => 'Amen'],
            ['start' => 3016.6, 'end' => 3019.0, 'text' => "Let's sing together as we conclude our time."],
            ['start' => 3023.5, 'end' => 3024.8, 'text' => 'Amen Amen'],
        ]);

        $question = app(SermonSongIntroductionCheck::class)->question($log, $this->spans());

        $this->assertSame(['and we ask these things in his name', 'Amen', "Let's sing together as we conclude our time.", 'Amen Amen'], array_column($question['lines'], 'text'));
    }

    /**
     * 961 and 983 (item 6 v1, 11-04): an unpunctuated transcript has no sentence to close, and a
     * cap on the joined length split one mid-clause ("disappointments and | discouragements"). With
     * no sentence end in sight the cues stay as they are.
     */
    #[Test]
    public function cues_with_no_sentence_end_stay_as_they_are(): void
    {
        $cues = [];
        foreach (['to encourage us to keep us going', 'to prevent us from falling into the depths', 'of despair when life in this world gets tough',
            'or when we face disappointments and', 'discouragements we are going to sing', 'a final hymn written on these words'] as $index => $text) {
            $cues[] = ['start' => 3000.0 + 3 * $index, 'end' => 3003.0 + 3 * $index, 'text' => $text];
        }
        $log = $this->service(cues: $cues);

        $question = app(SermonSongIntroductionCheck::class)->question($log, $this->spans());

        $this->assertSame(array_column($cues, 'text'), array_column($question['lines'], 'text'));
    }

    /** @return list<array{start_time: float, end_time: float}> */
    private function spans(): array
    {
        return array_map(static fn (array $span): array => ['start_time' => $span[0], 'end_time' => $span[1]], self::SERMON);
    }

    /**
     * 1111's cues before its song. The first cue ends 1.6 s before the second starts, so the two stay
     * separate lines ({@see SermonSongIntroductionCheck} joins cues only up to a 1.5 s pause): these
     * tests are about the judgement, and their answers name lines as the raw cues number them.
     *
     * @return list<array{start: float, end: float, text: string}>
     */
    private function cues(): array
    {
        return [
            ['start' => 3012.7, 'end' => 3015.8, 'text' => "You'll meet your king to begin a life of eternal joy with"],
            ['start' => 3017.4, 'end' => 3019.1, 'text' => 'him and all his people.'],
            ['start' => 3023.5, 'end' => 3024.8, 'text' => "We're going to sing."],
            ['start' => 3029.6, 'end' => 3032.2, 'text' => "We're going to sing a hymn that I chose."],
            ['start' => 3053.3, 'end' => 3057.1, 'text' => "Oh, let me see the lightning flare that ends this world's long night."],
            ['start' => 3062.7, 'end' => 3064.4, 'text' => 'I pray that all of us will be able to sing that.'],
            ['start' => 3066.8, 'end' => 3069.1, 'text' => "So let's stand to sing 861."],
            ['start' => 3072.7, 'end' => 3076.1, 'text' => 'I have heard the cross'],
        ];
    }

    /**
     * @param  list<array{0: string, 1: float, 2: float}>  $sections
     * @param  list<array{0: float|int, 1: float|int, 2: float, 3: float}>  $timeline
     * @param  list<array{start: float, end: float, text: string}>|null  $cues  {@see self::cues()} when null
     */
    private function service(array $sections = [['sermon', 0.0, 3024.83], ['other', 3024.83, 3071.99], ['song', 3072.7, 3300.0]],
        array $timeline = [[2950, 3070, 0.01, 0.8], [3070, 3300, 0.8, 0.02]], ?array $cues = null): MediaProcessingLog
    {
        Storage::fake('local');
        config(['media-processing.storage.service_artifact_disk' => 'local']);
        $log = MediaProcessingLog::factory()->livestream()->create(['duration' => 5000, 'audio_timeline_path' => 'service-transcripts/introduction.classes.json']);
        Storage::disk('local')->put('service-transcripts/introduction.classes.json', AudioTimelineFixture::json($timeline, 5000.0));
        $log->putServiceTranscriptPath('temp/introduction.json');
        Storage::disk('local')->put('temp/introduction.json', json_encode(ChurchServiceTranscript::fromCues($cues ?? $this->cues(), 5000, ChurchServiceTranscript::SOURCE_MOCK)->toArray(), JSON_THROW_ON_ERROR));

        foreach ($sections as [$type, $start, $end]) {
            ServiceSection::factory()->create(['media_processing_log_id' => $log->id, 'section_type' => ServiceSectionType::from($type), 'start_time' => $start, 'end_time' => $end]);
        }

        return $log->fresh();
    }

    /**
     * Bank answers as a run asked before 10-29 did, without the third draw on the sermon line.
     *
     * @param  array{0: int, 1: bool, 2: int}  $first
     * @param  array{0: int, 1: bool, 2: int}  $second
     * @param  array{0: int, 1: bool, 2: int}|null  $third
     */
    private function bankPair(MediaProcessingLog $log, array $first, array $second, ?array $third = null): void
    {
        $question = app(SermonSongIntroductionCheck::class)->question($log, $this->spans());
        $calls = array_map(static fn (array $answer): array => [
            'sermon_last_line' => $answer[0], 'ties_to_sermon' => $answer[1], 'video_last_line' => $answer[2], 'reason' => 'test',
        ], array_values(array_filter([$first, $second, $third])));
        $kind = 'sermon-song-introduction-'.hash('sha256', json_encode($question['identity'], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
        app(ServiceArtifactStorage::class)->putJson($log->processing_id, $kind, [
            'calls_completed' => true, 'identity' => $question['identity'], 'prompt' => $question['prompt'], 'lines' => $question['lines'], 'calls' => $calls,
            'ties_to_sermon' => true, 'end' => null, 'line' => null,
        ]);
        $log->refresh();
    }

    /** @param array{0: int, 1: bool, 2: int} ...$answers sermon_last_line, ties_to_sermon, video_last_line */
    private function answers(array ...$answers): void
    {
        OpenAI::fake(array_map(static fn (array $answer): CreateResponse => CreateResponse::fake(['choices' => [['message' => ['content' => json_encode([
            'sermon_last_line' => $answer[0], 'ties_to_sermon' => $answer[1], 'video_last_line' => $answer[2], 'reason' => 'test',
        ], JSON_THROW_ON_ERROR)]]]]), $answers));
    }
}
