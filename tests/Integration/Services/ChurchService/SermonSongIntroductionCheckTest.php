<?php

declare(strict_types=1);

namespace Tests\Integration\Services\ChurchService;

use App\Data\ChurchServiceTranscript;
use App\Enums\ServiceSectionType;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\ChurchService\SermonSongIntroductionCheck;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Resources\Chat;
use OpenAI\Responses\Chat\CreateResponse;
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
    public function a_tied_introduction_in_a_section_of_its_own_ends_the_sermon_at_its_last_line(): void
    {
        $log = $this->service();
        $this->answers([3, true, 6], [3, true, 6]);

        $result = app(SermonSongIntroductionCheck::class)->prepare($log, $this->spans());

        $this->assertTrue($result['asked']);
        $this->assertTrue($result['ties_to_sermon']);
        $this->assertSame(3064.4, $result['end'], 'through "…that all of us will be able to sing that", before "So let\'s stand to sing 861."');
        $this->assertSame(3064.4, app(SermonSongIntroductionCheck::class)->read($log->fresh(), $this->spans())['end']);
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
        $this->answers([3, true, 5], [3, true, 6], [3, true, 5]);

        $result = app(SermonSongIntroductionCheck::class)->prepare($log, $this->spans());

        $this->assertSame(3057.1, $result['end']);
        OpenAI::assertSent(Chat::class, 3);
    }

    #[Test]
    public function three_different_lines_take_the_middle_one(): void
    {
        $log = $this->service();
        $this->answers([3, true, 4], [3, true, 6], [3, true, 5]);

        $this->assertSame(3057.1, app(SermonSongIntroductionCheck::class)->prepare($log, $this->spans())['end']);
    }

    /** Two answers on the winning side naming different lines: the earlier, as the longer ran on. */
    #[Test]
    public function two_winning_answers_naming_different_lines_take_the_earlier(): void
    {
        $log = $this->service();
        $this->answers([3, true, 5], [2, false, 2], [3, true, 6]);

        $result = app(SermonSongIntroductionCheck::class)->prepare($log, $this->spans());

        $this->assertTrue($result['ties_to_sermon']);
        $this->assertSame(3057.1, $result['end']);
    }

    /** 1005: one answer missed a garbled introduction. A third answer decides by majority. */
    #[Test]
    public function answers_that_disagree_on_the_judgement_are_settled_by_a_third(): void
    {
        $log = $this->service();
        $this->answers([3, true, 6], [2, false, 2], [3, true, 6]);

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
        $this->answers([3, true, 9], [3, true, 9]);

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
        $this->assertSame(3064.4, $again['end']);
        OpenAI::assertSent(Chat::class, 2);
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

    /** Failed answers name no end: the planner leaves it to the operator. */
    #[Test]
    public function failed_answers_bank_no_end(): void
    {
        $log = $this->service();
        OpenAI::fake([new \RuntimeException('down'), new \RuntimeException('down')]);

        $result = app(SermonSongIntroductionCheck::class)->prepare($log, $this->spans());

        $this->assertNull($result['end']);
        $this->assertSame(['end' => null, 'ties_to_sermon' => null, 'line' => null], app(SermonSongIntroductionCheck::class)->read($log->fresh(), $this->spans()));
    }

    /** @return list<array{start_time: float, end_time: float}> */
    private function spans(): array
    {
        return array_map(static fn (array $span): array => ['start_time' => $span[0], 'end_time' => $span[1]], self::SERMON);
    }

    /** @return list<array{start: float, end: float, text: string}> */
    private function cues(): array
    {
        return [
            ['start' => 3012.7, 'end' => 3017.4, 'text' => "You'll meet your king to begin a life of eternal joy with"],
            ['start' => 3017.4, 'end' => 3019.1, 'text' => 'him and all his people.'],
            ['start' => 3023.5, 'end' => 3024.8, 'text' => "We're going to sing."],
            ['start' => 3029.6, 'end' => 3032.2, 'text' => "We're going to sing a hymn that I chose."],
            ['start' => 3053.3, 'end' => 3057.1, 'text' => "Oh, let me see the lightning flare that ends this world's long night,"],
            ['start' => 3062.7, 'end' => 3064.4, 'text' => 'I pray that all of us will be able to sing that.'],
            ['start' => 3066.8, 'end' => 3069.1, 'text' => "So let's stand to sing 861."],
            ['start' => 3072.7, 'end' => 3076.1, 'text' => 'I have heard the cross'],
        ];
    }

    /**
     * @param  list<array{0: string, 1: float, 2: float}>  $sections
     * @param  list<array{0: float|int, 1: float|int, 2: float, 3: float}>  $timeline
     */
    private function service(array $sections = [['sermon', 0.0, 3024.83], ['other', 3024.83, 3071.99], ['song', 3072.7, 3300.0]],
        array $timeline = [[2950, 3070, 0.01, 0.8], [3070, 3300, 0.8, 0.02]]): MediaProcessingLog
    {
        Storage::fake('local');
        config(['media-processing.storage.service_artifact_disk' => 'local']);
        $log = MediaProcessingLog::factory()->livestream()->create(['duration' => 5000, 'audio_timeline_path' => 'service-transcripts/introduction.classes.json']);
        Storage::disk('local')->put('service-transcripts/introduction.classes.json', AudioTimelineFixture::json($timeline, 5000.0));
        $log->putServiceTranscriptPath('temp/introduction.json');
        Storage::disk('local')->put('temp/introduction.json', json_encode(ChurchServiceTranscript::fromCues($this->cues(), 5000, ChurchServiceTranscript::SOURCE_MOCK)->toArray(), JSON_THROW_ON_ERROR));

        foreach ($sections as [$type, $start, $end]) {
            ServiceSection::factory()->create(['media_processing_log_id' => $log->id, 'section_type' => ServiceSectionType::from($type), 'start_time' => $start, 'end_time' => $end]);
        }

        return $log->fresh();
    }

    /** @param array{0: int, 1: bool, 2: int} ...$answers sermon_last_line, ties_to_sermon, video_last_line */
    private function answers(array ...$answers): void
    {
        OpenAI::fake(array_map(static fn (array $answer): CreateResponse => CreateResponse::fake(['choices' => [['message' => ['content' => json_encode([
            'sermon_last_line' => $answer[0], 'ties_to_sermon' => $answer[1], 'video_last_line' => $answer[2], 'reason' => 'test',
        ], JSON_THROW_ON_ERROR)]]]]), $answers));
    }
}
