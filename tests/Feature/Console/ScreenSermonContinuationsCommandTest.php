<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Actions\FlagSermonPartsNotExtracted;
use App\Enums\ServiceSectionType;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ScreenSermonContinuationsCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_refuses_to_run_without_an_explicit_selection(): void
    {
        // P8-Q3's rule: a bulk pass meant for one operation is not recoverable by
        // re-running it, so the command never guesses at scope.
        $this->artisan('service:screen-sermon-continuations')
            ->expectsOutputToContain('Name what to screen')
            ->assertFailed();
    }

    #[Test]
    public function it_reports_without_writing_anything_by_default(): void
    {
        [$run, $continuation] = $this->runWithUnrecordedPart();

        $this->artisan('service:screen-sermon-continuations', ['--run' => [$run->id]])
            ->assertSuccessful();

        $this->assertNull($continuation->fresh()->metadata->sermonContinuation);

        $sermon = $run->serviceSections()->where('section_type', ServiceSectionType::Sermon->value)->sole();
        $this->assertFalse((bool) $sermon->needs_manual_review);
    }

    #[Test]
    public function it_records_the_marker_and_holds_the_sermon_when_applied(): void
    {
        [$run, $continuation] = $this->runWithUnrecordedPart();
        $sermonId = $run->serviceSections()->where('section_type', ServiceSectionType::Sermon->value)->value('id');

        $this->artisan('service:screen-sermon-continuations', ['--run' => [$run->id], '--apply' => true])
            ->assertSuccessful();

        $marker = $continuation->fresh()->metadata->sermonContinuation;
        $this->assertNotNull($marker);
        $this->assertSame($sermonId, $marker->ofSectionId);
        $this->assertSame('detector_notes', $marker->source);
        // The sentence that admitted it is kept, so a later reader can tell an
        // attested part from one somebody assumed.
        $this->assertStringContainsString('continuation of the single sermon', (string) $marker->evidence);

        $sermon = $run->serviceSections()->where('section_type', ServiceSectionType::Sermon->value)->sole();
        $this->assertContains(FlagSermonPartsNotExtracted::FLAG, $sermon->metadata->reviewFlags);
        $this->assertTrue((bool) $sermon->needs_manual_review);
    }

    #[Test]
    public function it_is_a_no_op_when_run_a_second_time(): void
    {
        [$run, $continuation] = $this->runWithUnrecordedPart();

        $this->artisan('service:screen-sermon-continuations', ['--run' => [$run->id], '--apply' => true])->assertSuccessful();
        $writtenAt = $continuation->fresh()->updated_at;

        $this->artisan('service:screen-sermon-continuations', ['--run' => [$run->id], '--apply' => true])
            ->expectsOutputToContain('1 already recorded')
            ->assertSuccessful();

        $this->assertEquals($writtenAt, $continuation->fresh()->updated_at);
    }

    #[Test]
    public function it_errors_rather_than_reporting_an_empty_pass_for_a_run_it_cannot_find(): void
    {
        // The shape a Dusk run creates: the app is pointed at another database and
        // every named run vanishes. Reporting that as "nothing to do" skips real work.
        $this->artisan('service:screen-sermon-continuations', ['--run' => [987654]])
            ->expectsOutputToContain('could not be found')
            ->assertFailed();
    }

    /**
     * @return array{0: MediaProcessingLog, 1: ServiceSection}
     */
    private function runWithUnrecordedPart(): array
    {
        $run = MediaProcessingLog::factory()->livestream()->create([
            'sermon_start_time' => 500.0,
            'sermon_end_time' => 1200.0,
            'processing_metadata' => [
                'sermon_extraction_plan' => ['segments' => [['start_time' => 500.0, 'end_time' => 1200.0]]],
            ],
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::Sermon->value,
            'section_order' => 1,
            'start_time' => 500.0,
            'end_time' => 1200.0,
            'needs_manual_review' => false,
            'metadata' => ['confidence_level' => 'high'],
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::Song->value,
            'section_order' => 2,
            'start_time' => 1200.0,
            'end_time' => 1450.0,
            'needs_manual_review' => false,
            'metadata' => ['confidence_level' => 'high'],
        ]);

        $continuation = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::Other->value,
            'section_order' => 3,
            'start_time' => 1450.0,
            'end_time' => 1900.0,
            'needs_manual_review' => false,
            'metadata' => [
                'confidence_level' => 'high',
                'ai_notes' => ['This is a continuation of the single sermon, separated by a congregational song.'],
            ],
        ]);

        return [$run, $continuation];
    }
}
