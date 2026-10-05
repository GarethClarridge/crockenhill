<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Actions\HoldSectionForContentReview;
use App\Enums\ServiceSectionType;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\ChurchService\PublicationPlanValidator;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

/**
 * S6: the checks an output's final spans must pass after every adjustment, in one place,
 * before extraction may execute them.
 */
class PublicationPlanValidatorTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function final_spans_that_reach_every_selected_section_and_no_held_one_pass(): void
    {
        $log = $this->log();
        $reading = $this->section($log, ServiceSectionType::BibleReading, 100, 200);
        $sermon = $this->section($log, ServiceSectionType::Sermon, 220, 3200);

        $violations = app(PublicationPlanValidator::class)->validate($log, [$reading, $sermon], [
            ['start_time' => 100.0, 'end_time' => 200.0],
            ['start_time' => 221.5, 'end_time' => 3200.0],
        ]);

        $this->assertSame([], $violations);
    }

    /** A merge or an inward edge may move a section's cut, but never lose the section. */
    #[Test]
    public function a_selected_section_no_final_span_reaches_is_a_violation(): void
    {
        $log = $this->log();
        $reading = $this->section($log, ServiceSectionType::BibleReading, 100, 200);
        $sermon = $this->section($log, ServiceSectionType::Sermon, 220, 3200);

        $violations = app(PublicationPlanValidator::class)->validate($log, [$reading, $sermon], [
            ['start_time' => 220.0, 'end_time' => 3200.0],
        ]);

        $this->assertSame([['kind' => 'selected_section_not_cut', 'section_ids' => [$reading->id]]], $violations);
    }

    #[Test]
    public function a_final_span_reaching_an_unselected_held_section_is_a_violation(): void
    {
        $log = $this->log();
        $sermon = $this->section($log, ServiceSectionType::Sermon, 220, 3200);
        $held = $this->section($log, ServiceSectionType::ShortTalk, 3200, 3300, [HoldSectionForContentReview::FLAG]);

        $violations = app(PublicationPlanValidator::class)->validate($log, [$sermon], [
            ['start_time' => 220.0, 'end_time' => 3201.5],
        ]);

        $this->assertSame([['kind' => 'crosses_held_section', 'section_ids' => [$held->id]]], $violations);
    }

    /** @param  list<array{0: float, 1: float}>  $spans */
    #[Test]
    #[TestWith([[[300.0, 200.0]]], 'reversed')]
    #[TestWith([[[220.0, 3200.0], [3100.0, 3300.0]]], 'overlapping')]
    #[TestWith([[[1000.0, 3200.0], [220.0, 900.0]]], 'unordered')]
    #[TestWith([[[220.0, 5000.5]]], 'past the source')]
    #[TestWith([[]], 'empty')]
    public function impossible_final_spans_are_refused(array $spans): void
    {
        $log = $this->log();
        $sermon = $this->section($log, ServiceSectionType::Sermon, 220, 3200);

        $this->expectException(InvalidArgumentException::class);

        app(PublicationPlanValidator::class)->validate($log, [$sermon], array_map(
            static fn (array $span): array => ['start_time' => $span[0], 'end_time' => $span[1]],
            $spans,
        ));
    }

    private function log(): MediaProcessingLog
    {
        return MediaProcessingLog::factory()->livestream()->create(['duration' => 5000]);
    }

    /** @param  list<string>  $flags */
    private function section(MediaProcessingLog $log, ServiceSectionType $type, float $start, float $end, array $flags = []): ServiceSection
    {
        return ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => $type->value,
            'start_time' => $start,
            'end_time' => $end,
            'duration' => $end - $start,
            'needs_manual_review' => $flags !== [],
            'metadata' => ['confidence_level' => 'high', 'review_flags' => $flags],
        ]);
    }
}
