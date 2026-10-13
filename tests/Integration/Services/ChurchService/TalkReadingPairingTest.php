<?php

declare(strict_types=1);

namespace Tests\Integration\Services\ChurchService;

use App\Actions\HoldSectionForContentReview;
use App\Data\ServiceSectionMetadata;
use App\Enums\ServiceSectionType;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\ChurchService\CueSafeExtractionPlan;
use App\Services\ChurchService\PublicationPlanValidator;
use App\Services\ChurchService\TalkReadingPairing;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Operator ruling 2026-10-13: a talk about the reading before it is cut with that reading. Nine of
 * the fifteen talks after a reading were answered so (962's "Jehovah-Nissi" after Exodus 17), six
 * stand alone (1315's Mary Slessor after Matthew 22).
 */
class TalkReadingPairingTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function an_unanswered_talk_is_cut_alone(): void
    {
        [, , $talk] = $this->service();

        $this->assertSame([['start_time' => 205.0, 'end_time' => 400.0]], app(CueSafeExtractionPlan::class)->forSection($talk)['segments']);
        $this->assertArrayNotHasKey('with_reading', $talk->mediaSignaturePayload());
    }

    #[Test]
    public function a_talk_about_its_reading_is_cut_with_it_and_its_signatures_say_so(): void
    {
        [$log, $reading, $talk] = $this->service();
        $unanswered = [$talk->mediaSignature(), $talk->classificationSignature()];

        app(TalkReadingPairing::class)->record($talk, true, 'talk page');
        $talk->refresh();
        $plan = app(CueSafeExtractionPlan::class)->forSection($talk);

        $this->assertSame([['start_time' => 100.0, 'end_time' => 205.0], ['start_time' => 205.0, 'end_time' => 400.0]], $plan['segments']);
        $this->assertSame([], app(PublicationPlanValidator::class)->validate($log, app(TalkReadingPairing::class)->sectionsCut($talk), $plan['segments'], $plan['cue_edge_widening']));
        // A new answer re-cuts the candidate and asks for approval again.
        $this->assertNotSame($unanswered[0], $talk->mediaSignature());
        $this->assertNotSame($unanswered[1], $talk->classificationSignature());
        $this->assertSame(['section_id' => $reading->id, 'start_time' => 100.0, 'end_time' => 200.0], $talk->mediaSignaturePayload()['with_reading']);
    }

    #[Test]
    public function a_talk_answered_to_stand_alone_is_cut_alone_with_its_signatures_unchanged(): void
    {
        [, , $talk] = $this->service();
        $unanswered = [$talk->mediaSignature(), $talk->classificationSignature()];

        app(TalkReadingPairing::class)->record($talk, false, 'talk page');
        $talk->refresh();

        $this->assertSame([['start_time' => 205.0, 'end_time' => 400.0]], app(CueSafeExtractionPlan::class)->forSection($talk)['segments']);
        $this->assertSame($unanswered, [$talk->mediaSignature(), $talk->classificationSignature()]);
        $this->assertFalse($talk->metadata->raw[TalkReadingPairing::METADATA_KEY]['include']);
    }

    /** The answer was about one reading: once another section comes between, the talk is cut alone. */
    #[Test]
    public function an_answer_lapses_when_its_reading_is_no_longer_directly_before_the_talk(): void
    {
        [$log, , $talk] = $this->service();
        app(TalkReadingPairing::class)->record($talk, true, 'talk page');
        ServiceSection::factory()->create(['media_processing_log_id' => $log->id, 'section_type' => ServiceSectionType::Prayer, 'start_time' => 200.0, 'end_time' => 204.0]);

        $this->assertSame([['start_time' => 205.0, 'end_time' => 400.0]], app(CueSafeExtractionPlan::class)->forSection($talk->fresh())['segments']);
    }

    #[Test]
    public function only_a_talk_close_after_a_reading_has_a_reading_to_go_with(): void
    {
        $pairing = app(TalkReadingPairing::class);
        [, $reading, $talk] = $this->service();
        [, , $farTalk] = $this->service(talkStart: 216.0);
        [, , $afterPrayer] = $this->service(before: ServiceSectionType::Prayer);

        $this->assertTrue($pairing->readingBefore($talk)?->is($reading));
        $this->assertNull($pairing->readingBefore($reading));
        $this->assertNull($pairing->readingBefore($farTalk));
        $this->assertNull($pairing->readingBefore($afterPrayer));
        $this->expectException(InvalidArgumentException::class);
        $pairing->record($afterPrayer, true, 'talk page');
    }

    /** The reading is the talk's own: its hold is answered by the talk's output, not crossed by it. */
    #[Test]
    public function a_held_reading_cut_with_its_talk_is_not_a_crossed_hold(): void
    {
        [$log, $reading, $talk] = $this->service();
        $reading->update(['metadata' => ServiceSectionMetadata::fromArray(['review_flags' => [HoldSectionForContentReview::FLAG]])]);
        app(TalkReadingPairing::class)->record($talk, true, 'talk page');
        $plan = app(CueSafeExtractionPlan::class)->forSection($talk->fresh());

        $this->assertSame([], app(PublicationPlanValidator::class)->validate($log, app(TalkReadingPairing::class)->sectionsCut($talk->fresh()), $plan['segments']));
        $this->assertSame('crosses_held_section', app(PublicationPlanValidator::class)->validate($log, [$talk->fresh()], $plan['segments'])[0]['kind'] ?? null);
    }

    /** @return array{0: MediaProcessingLog, 1: ServiceSection, 2: ServiceSection} */
    private function service(float $talkStart = 205.0, ServiceSectionType $before = ServiceSectionType::BibleReading): array
    {
        $log = MediaProcessingLog::factory()->livestream()->create(['duration' => 5000]);
        $reading = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id, 'section_type' => $before, 'start_time' => 100.0, 'end_time' => 200.0,
            'metadata' => ServiceSectionMetadata::fromArray(['reading_reference' => 'Exodus 17:8-16']),
        ]);
        $talk = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id, 'section_type' => ServiceSectionType::ShortTalk, 'start_time' => $talkStart, 'end_time' => 400.0,
        ]);

        return [$log->fresh(), $reading->fresh(), $talk->fresh()];
    }
}
