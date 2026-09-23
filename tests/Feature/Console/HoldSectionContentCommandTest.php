<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Actions\HoldSectionForContentReview;
use App\Enums\ServiceSectionType;
use App\Models\ChurchService;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HoldSectionContentCommandTest extends TestCase
{
    use RefreshDatabase;

    private const OPTIONS = [
        '--reason' => 'Saved text repeats a sentence the audio does not',
        '--evidence' => 'plan §4.1',
        '--found-by' => 'loop_screen',
    ];

    #[Test]
    public function it_reports_the_holds_without_writing_anything_by_default(): void
    {
        [, $section] = $this->sectionOnService(ServiceSectionType::Sermon);

        $this->artisan('service:hold-section-content', [...self::OPTIONS, '--section' => [$section->id]])
            ->expectsOutputToContain('DRY RUN')
            ->assertSuccessful();

        self::assertFalse($section->fresh()->needs_manual_review);
    }

    #[Test]
    public function it_holds_the_named_sections_and_opens_service_review(): void
    {
        [$service, $sermon] = $this->sectionOnService(ServiceSectionType::Sermon);
        [, $song] = $this->sectionOnService(ServiceSectionType::Song);

        $this->artisan('service:hold-section-content', [
            ...self::OPTIONS,
            '--section' => [$sermon->id, $song->id],
            '--execute' => true,
        ])
            ->expectsOutputToContain('Held 2 section(s); 0 already carried this hold.')
            ->assertSuccessful();

        self::assertTrue($sermon->fresh()->needs_manual_review);
        self::assertTrue($song->fresh()->needs_manual_review);
        self::assertContains(HoldSectionForContentReview::FLAG, $sermon->fresh()->metadata->reviewFlags);
        self::assertTrue($service->fresh()->needs_review);

        $this->artisan('service:hold-section-content', [
            ...self::OPTIONS,
            '--section' => [$sermon->id, $song->id],
            '--execute' => true,
        ])
            ->expectsOutputToContain('Held 0 section(s); 2 already carried this hold.')
            ->assertSuccessful();
    }

    #[Test]
    public function it_holds_nothing_when_one_named_section_cannot_be_refused_at_release(): void
    {
        [, $sermon] = $this->sectionOnService(ServiceSectionType::Sermon);
        [, $reading] = $this->sectionOnService(ServiceSectionType::BibleReading);

        $this->artisan('service:hold-section-content', [
            ...self::OPTIONS,
            '--section' => [$sermon->id, $reading->id],
            '--execute' => true,
        ])
            ->expectsOutputToContain("Section {$reading->id} is a bible_reading")
            ->expectsOutputToContain('Nothing was held')
            ->assertFailed();

        self::assertFalse($sermon->fresh()->needs_manual_review);
    }

    #[Test]
    public function it_holds_nothing_when_a_named_section_does_not_exist(): void
    {
        [, $sermon] = $this->sectionOnService(ServiceSectionType::Sermon);

        $this->artisan('service:hold-section-content', [
            ...self::OPTIONS,
            '--section' => [$sermon->id, 999999],
            '--execute' => true,
        ])
            ->expectsOutputToContain('Section 999999 does not exist.')
            ->assertFailed();

        self::assertFalse($sermon->fresh()->needs_manual_review);
    }

    #[Test]
    public function it_requires_a_reason_and_evidence(): void
    {
        [, $sermon] = $this->sectionOnService(ServiceSectionType::Sermon);

        $this->artisan('service:hold-section-content', [
            '--section' => [$sermon->id],
            '--reason' => 'Looping text',
            '--found-by' => 'loop_screen',
            '--execute' => true,
        ])
            ->expectsOutputToContain('Both --reason and --evidence are required')
            ->assertFailed();

        self::assertFalse($sermon->fresh()->needs_manual_review);
    }

    #[Test]
    public function it_records_which_check_found_the_hold(): void
    {
        [, $sermon] = $this->sectionOnService(ServiceSectionType::Sermon);

        $this->artisan('service:hold-section-content', [...self::OPTIONS, '--section' => [$sermon->id], '--execute' => true])
            ->assertSuccessful();

        self::assertSame(
            'loop_screen',
            $sermon->fresh()->metadata?->toArray()[HoldSectionForContentReview::METADATA_KEY][0]['found_by'] ?? null,
        );
    }

    #[Test]
    public function it_refuses_a_hold_without_a_known_check(): void
    {
        [, $sermon] = $this->sectionOnService(ServiceSectionType::Sermon);

        $this->artisan('service:hold-section-content', [
            ...self::OPTIONS,
            '--found-by' => 'a hunch',
            '--section' => [$sermon->id],
            '--execute' => true,
        ])
            ->expectsOutputToContain('--found-by must be one of')
            ->assertFailed();

        self::assertFalse($sermon->fresh()->needs_manual_review);
    }

    /**
     * @return array{0: ChurchService, 1: ServiceSection}
     */
    private function sectionOnService(ServiceSectionType $type): array
    {
        $service = ChurchService::factory()->create(['needs_review' => false]);

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => MediaProcessingLog::factory()->livestream()->completed()->create([
                'church_service_id' => $service->id,
            ])->id,
            'church_service_item_id' => null,
            'section_type' => $type,
            'needs_manual_review' => false,
            'metadata' => ['review_flags' => []],
        ]);

        return [$service, $section];
    }
}
