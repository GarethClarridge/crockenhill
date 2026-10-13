<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\ServiceSectionType;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\ChurchService\TalkReadingPairing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AnswerTalkReadingCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_reports_the_answers_without_writing_anything_by_default(): void
    {
        $talk = $this->talkAfter(ServiceSectionType::BibleReading);

        $this->artisan('service:answer-talk-reading', ['--include' => [$talk->id], '--evidence' => 'talk page'])
            ->expectsOutputToContain('DRY RUN')
            ->assertSuccessful();

        $this->assertArrayNotHasKey(TalkReadingPairing::METADATA_KEY, $talk->fresh()->metadata->raw);
    }

    #[Test]
    public function it_records_each_talks_answer(): void
    {
        $with = $this->talkAfter(ServiceSectionType::BibleReading);
        $alone = $this->talkAfter(ServiceSectionType::BibleReading);

        $this->artisan('service:answer-talk-reading', ['--include' => [$with->id], '--alone' => [$alone->id], '--evidence' => 'talk page', '--execute' => true])
            ->expectsOutputToContain('Recorded 2 answer(s).')
            ->assertSuccessful();

        $this->assertNotNull(app(TalkReadingPairing::class)->includedReading($with->fresh()));
        $this->assertNull(app(TalkReadingPairing::class)->includedReading($alone->fresh()));
        $this->assertSame(['include' => false, 'answered_by' => 'talk page'], array_intersect_key($alone->fresh()->metadata->raw[TalkReadingPairing::METADATA_KEY], ['include' => 0, 'answered_by' => 0]));
    }

    #[Test]
    public function it_records_nothing_when_one_named_talk_has_no_reading_before_it(): void
    {
        $talk = $this->talkAfter(ServiceSectionType::BibleReading);
        $afterSong = $this->talkAfter(ServiceSectionType::Song);

        $this->artisan('service:answer-talk-reading', ['--include' => [$talk->id, $afterSong->id], '--evidence' => 'talk page', '--execute' => true])
            ->expectsOutputToContain("Section {$afterSong->id} is not a short talk directly after a reading.")
            ->expectsOutputToContain('Nothing was recorded')
            ->assertFailed();

        $this->assertArrayNotHasKey(TalkReadingPairing::METADATA_KEY, $talk->fresh()->metadata->raw);
    }

    #[Test]
    public function it_refuses_an_untraceable_or_contradictory_answer(): void
    {
        $talk = $this->talkAfter(ServiceSectionType::BibleReading);

        $this->artisan('service:answer-talk-reading', ['--include' => [$talk->id], '--execute' => true])
            ->expectsOutputToContain('--evidence is required')
            ->assertFailed();
        $this->artisan('service:answer-talk-reading', ['--include' => [$talk->id], '--alone' => [$talk->id], '--evidence' => 'talk page'])
            ->expectsOutputToContain('Named both')
            ->assertFailed();
    }

    private function talkAfter(ServiceSectionType $type): ServiceSection
    {
        $log = MediaProcessingLog::factory()->livestream()->create();
        ServiceSection::factory()->create(['media_processing_log_id' => $log->id, 'section_type' => $type, 'start_time' => 100.0, 'end_time' => 200.0]);

        return ServiceSection::factory()->create(['media_processing_log_id' => $log->id, 'section_type' => ServiceSectionType::ShortTalk, 'start_time' => 201.0, 'end_time' => 400.0]);
    }
}
