<?php

declare(strict_types=1);

namespace Tests\Integration\Services\ChurchService;

use App\Data\ChurchServiceTranscript;
use App\Enums\ServiceSectionType;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\ChurchService\CueSafeExtractionPlan;
use App\Services\ChurchService\OutputEdgeWordTimings;
use App\Services\ChurchService\SpokenEdgeSentenceCheck;
use App\Services\Media\Audio\ServiceArtifactStorage;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Chat\CreateResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Canary 11 listening (operator, 2026-10-06): an edge must not split a sentence or a thought;
 * "you can tell that the sentences are joined by meaning, so presumably an LLM can". 1117's talk
 * ended on "…let's have our morning reading." and its reading opened on "It's quite a short one.":
 * one handover split by whisper's punctuation, which no punctuation rule can see. Read-only
 * evaluation: the model caught it on edges between two spoken items, but misread sung words at
 * song edges, and its answers moved with the prompt. So it is asked twice, and only an agreed
 * move between two spoken items is made.
 */
class SpokenEdgeSentenceCheckTest extends TestCase
{
    use DatabaseTransactions;

    private const WORDS = [
        ['start' => 1715.50, 'end' => 1716.00, 'word' => ' Speaking'],
        ['start' => 1716.00, 'end' => 1716.40, 'word' => ' of'],
        ['start' => 1716.40, 'end' => 1716.80, 'word' => ' which,'],
        ['start' => 1716.80, 'end' => 1717.00, 'word' => " let's"],
        ['start' => 1717.00, 'end' => 1717.20, 'word' => ' have'],
        ['start' => 1717.20, 'end' => 1717.30, 'word' => ' our'],
        ['start' => 1717.30, 'end' => 1717.50, 'word' => ' morning'],
        ['start' => 1717.50, 'end' => 1717.70, 'word' => ' reading.'],
        ['start' => 1717.80, 'end' => 1717.90, 'word' => " It's"],
        ['start' => 1717.90, 'end' => 1718.10, 'word' => ' quite'],
        ['start' => 1718.10, 'end' => 1718.20, 'word' => ' a'],
        ['start' => 1718.20, 'end' => 1718.40, 'word' => ' short'],
        ['start' => 1718.40, 'end' => 1718.60, 'word' => ' one.'],
        ['start' => 1719.00, 'end' => 1719.30, 'word' => ' Luke'],
        ['start' => 1719.30, 'end' => 1719.60, 'word' => ' chapter'],
        ['start' => 1719.60, 'end' => 1720.00, 'word' => ' 16.'],
    ];

    #[Test]
    public function an_agreed_move_keeps_a_split_thought_whole(): void
    {
        [$talk, $reading] = $this->handover(ServiceSectionType::BibleReading);
        $this->answers(['extend', "Speaking of which, let's have our morning reading."], ['extend', "Speaking of which, let's have our morning reading."]);

        $summary = app(SpokenEdgeSentenceCheck::class)->prepare($reading->processingLog->fresh());
        $plan = app(CueSafeExtractionPlan::class)->forSection($reading->fresh());

        // Both edges of the handover (the talk's end, the reading's start) got the same answers.
        $this->assertSame(2, $summary['agreed_moves']);
        $this->assertLessThanOrEqual(1715.50, $plan['segments'][0]['start_time']);
        $this->assertSame('sentence_check', $plan['cue_edge_widening'][0]['reason']);
    }

    #[Test]
    public function a_move_the_two_answers_disagree_on_is_not_made(): void
    {
        [, $reading] = $this->handover(ServiceSectionType::BibleReading);
        $this->answers(['extend', "Speaking of which, let's have our morning reading."], ['shrink', "It's quite a short one."]);

        app(SpokenEdgeSentenceCheck::class)->prepare($reading->processingLog->fresh());
        $plan = app(CueSafeExtractionPlan::class)->forSection($reading->fresh());

        $this->assertEqualsWithDelta(1717.75, $plan['segments'][0]['start_time'], 0.06);
        $this->assertSame('word_pause', $plan['cue_edge_widening'][0]['reason']);
        $this->assertFalse($plan['cue_edge_widening'][0]['sentence_check']['agreed']);
    }

    /** A move naming words the cut does not have next to it is not made. */
    #[Test]
    public function words_that_are_not_beside_the_cut_move_nothing(): void
    {
        [, $reading] = $this->handover(ServiceSectionType::BibleReading);
        $this->answers(['shrink', 'Matthew chapter 5.'], ['shrink', 'Matthew chapter 5.']);

        app(SpokenEdgeSentenceCheck::class)->prepare($reading->processingLog->fresh());
        $plan = app(CueSafeExtractionPlan::class)->forSection($reading->fresh());

        $this->assertEqualsWithDelta(1717.75, $plan['segments'][0]['start_time'], 0.06);
    }

    /** Song edges stay with the song rule: whisper's words there are misheard lyrics. */
    #[Test]
    public function an_edge_beside_a_song_is_not_asked_about(): void
    {
        [, $song] = $this->handover(ServiceSectionType::Song);
        OpenAI::fake([]);

        $summary = app(SpokenEdgeSentenceCheck::class)->prepare($song->processingLog->fresh());

        $this->assertSame(0, $summary['asked']);
        OpenAI::assertNothingSent();
    }

    #[Test]
    public function an_edge_already_asked_about_is_not_asked_again(): void
    {
        [, $reading] = $this->handover(ServiceSectionType::BibleReading);
        $this->answers(['keep', ''], ['keep', '']);
        $check = app(SpokenEdgeSentenceCheck::class);
        $check->prepare($reading->processingLog->fresh());

        $this->assertSame(0, $check->prepare($reading->processingLog->fresh())['asked']);
    }

    /** Without a stored answer the cut is the word pause's: the check refines, it never blocks. */
    #[Test]
    public function an_edge_never_asked_about_keeps_its_word_pause_cut(): void
    {
        [, $reading] = $this->handover(ServiceSectionType::BibleReading);

        $plan = app(CueSafeExtractionPlan::class)->forSection($reading->fresh());

        $this->assertEqualsWithDelta(1717.75, $plan['segments'][0]['start_time'], 0.06);
        $this->assertSame('word_pause', $plan['cue_edge_widening'][0]['reason']);
    }

    /** @return array{0: ServiceSection, 1: ServiceSection} */
    private function handover(ServiceSectionType $next): array
    {
        Storage::fake('local');
        config(['media-processing.storage.service_artifact_disk' => 'local']);
        $log = MediaProcessingLog::factory()->livestream()->processing()->create(['duration' => 5000]);
        $log->putServiceTranscriptPath('temp/handover.json');
        $cues = [
            ['start' => 1710.86, 'end' => 1712.78, 'text' => 'and so on and so forth.'],
            ['start' => 1712.78, 'end' => 1715.50, 'text' => "But keep reading God's word."],
            ['start' => 1715.50, 'end' => 1717.70, 'text' => "Speaking of which, let's have our morning reading."],
            ['start' => 1717.78, 'end' => 1718.70, 'text' => "It's quite a short one."],
            ['start' => 1718.70, 'end' => 1720.00, 'text' => 'Luke chapter 16.'],
            ['start' => 1800.00, 'end' => 1810.00, 'text' => 'This is the word of the Lord.'],
        ];
        Storage::disk('local')->put('temp/handover.json', json_encode(ChurchServiceTranscript::fromCues($cues, 5000, ChurchServiceTranscript::SOURCE_MOCK)->toArray(), JSON_THROW_ON_ERROR));
        $talk = ServiceSection::factory()->create(['media_processing_log_id' => $log->id, 'section_type' => ServiceSectionType::ShortTalk, 'start_time' => 1532.22, 'end_time' => 1717.70]);
        $reading = ServiceSection::factory()->create(['media_processing_log_id' => $log->id, 'section_type' => $next, 'start_time' => 1717.78, 'end_time' => 1810.0]);
        $evidence = app(OutputEdgeWordTimings::class);
        foreach ([1717.70, 1717.78, 1532.22, 1810.0] as $edge) {
            $window = $evidence->window($evidence->cues($log->fresh()), $edge, 5000.0);
            if ($window !== null) {
                app(ServiceArtifactStorage::class)->putJson($log->processing_id, $evidence->kind($evidence->identity($log, $window)), [
                    'identity' => $evidence->identity($log, $window), 'words' => self::WORDS, 'compute_seconds' => 1.0,
                ]);
            }
        }

        return [$talk->fresh(), $reading->fresh()];
    }

    /** @param array{0: string, 1: string} ...$answers */
    private function answers(array ...$answers): void
    {
        $responses = [];

        foreach ($answers as [$decision, $words]) {
            $responses[] = CreateResponse::fake(['choices' => [['message' => ['content' => json_encode(['decision' => $decision, 'words_to_move' => $words, 'reason' => 'test'], JSON_THROW_ON_ERROR)]]]]);
        }

        // Every edge between two spoken items is asked about twice; repeat for the talk's other edges.
        OpenAI::fake([...$responses, ...$responses, ...$responses, ...$responses]);
    }
}
