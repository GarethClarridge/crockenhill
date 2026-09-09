<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Enums\ServiceSectionType;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\ChurchService\Structure\SermonContinuationScreen;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonContinuationScreenTest extends TestCase
{
    use RefreshDatabase;

    private SermonContinuationScreen $screen;

    protected function setUp(): void
    {
        parent::setUp();

        $this->screen = app(SermonContinuationScreen::class);
    }

    /**
     * Every sentence here is verbatim from the historic corpus, so the screen is
     * pinned to the prose it actually has to read rather than to a paraphrase.
     *
     * @return array<string, array{0: string}>
     */
    public static function continuationNotes(): array
    {
        return [
            'run 943 §773' => ['This is the continuation and conclusion of the primary sermon after the Revelation reading and song.'],
            'run 1073 §1711' => ['This is the first part of the main sermon, which resumes after the intervening hymn.'],
            'run 1116 §1951' => ['This is the continuation and conclusion of the primary sermon, followed by a closing prayer led by the preacher.'],
            'run 1148 §2207' => ['This continues the sermon’s application after the Revelation reading, but is not labelled as a second sermon.'],
            'run 1268 §4736' => ['This is a continuation of the single sermon, separated by a congregational song.'],
            'run 1268 §4738' => ['This is the concluding continuation of the single sermon.'],
        ];
    }

    /**
     * The notes that must NOT admit a section. These are the near misses: every
     * one mentions a sermon, and several are longer than the parts that qualify,
     * so nothing but the sentence separates them.
     *
     * @return array<string, array{0: string}>
     */
    public static function nonContinuationNotes(): array
    {
        return [
            'rival candidate' => ['This is a substantial biblical address, but the later Joshua exposition is the clearest primary sermon.'],
            'later exposition preferred' => ['The content is sermon-like, but the later extended exposition is treated as the primary sermon.'],
            'planned presentation' => ['This is treated as the planned presentation rather than a second sermon.'],
            'mission presentation' => ['This is a mission presentation rather than a sermon.'],
            'introduction to the sermon' => ['This is an introduction to the sermon rather than a separate sermon.'],
            'opening before the reading' => ['This is the opening of the sermon before the passage is read.'],
            'exposition kept as other' => ['This is sermon-like exposition before the main passage reading, but it is treated as other to preserve one primary sermon.'],
            'song before the sermon' => ['Congregational song: \'Lord Jesus, build your church today\' before the sermon proper.'],
            'prayer after the sermon' => ['Congregational prayer responding to the sermon themes; concludes with Amen and an invitation to sing.'],
        ];
    }

    #[Test]
    #[DataProvider('continuationNotes')]
    public function it_admits_a_section_the_detector_named_a_part_of_the_sermon(string $note): void
    {
        $run = $this->runWithSermon();
        $section = $this->sectionWithNotes($run, [$note]);

        $candidates = $this->screen->screen($run->serviceSections()->orderBy('start_time')->get());

        $this->assertCount(1, $candidates);
        $this->assertSame($section->id, $candidates[0]['section']->id);
        $this->assertSame($note, $candidates[0]['evidence']);
    }

    #[Test]
    #[DataProvider('nonContinuationNotes')]
    public function it_refuses_a_section_that_merely_mentions_the_sermon(string $note): void
    {
        $run = $this->runWithSermon();
        $this->sectionWithNotes($run, [$note]);

        $this->assertSame([], $this->screen->screen($run->serviceSections()->orderBy('start_time')->get()));
    }

    #[Test]
    public function it_keeps_the_genuine_part_and_drops_the_presentation_on_the_same_run(): void
    {
        // Run 1148 holds both shapes, and the presentation is the longer of the two.
        // Length, type and position are all useless here; only the sentence decides.
        $run = $this->runWithSermon();
        $this->sectionWithNotes($run, ['This is treated as the planned presentation rather than a second sermon.'], 688.0, 1725.0);
        $part = $this->sectionWithNotes($run, ['This continues the sermon’s application after the Revelation reading, but is not labelled as a second sermon.'], 3379.0, 3552.0);

        $candidates = $this->screen->screen($run->serviceSections()->orderBy('start_time')->get());

        $this->assertCount(1, $candidates);
        $this->assertSame($part->id, $candidates[0]['section']->id);
    }

    #[Test]
    public function it_declines_a_run_with_more_than_one_sermon_section(): void
    {
        // Which sermon does this continue? Nothing in the note says, and proximity
        // is a guess — that adjudication belongs to P8-Q7/Q9, not to this screen.
        $run = $this->runWithSermon();

        ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::Sermon->value,
            'start_time' => 4000.0,
            'end_time' => 4500.0,
            'needs_manual_review' => false,
            'metadata' => ['confidence_level' => 'high'],
        ]);

        $this->sectionWithNotes($run, ['This is the concluding continuation of the single sermon.']);

        $this->assertSame([], $this->screen->screen($run->serviceSections()->orderBy('start_time')->get()));
    }

    #[Test]
    public function it_declines_a_run_with_no_sermon_section_at_all(): void
    {
        $run = MediaProcessingLog::factory()->livestream()->create();
        $this->sectionWithNotes($run, ['This is the concluding continuation of the single sermon.']);

        $this->assertSame([], $this->screen->screen($run->serviceSections()->orderBy('start_time')->get()));
    }

    #[Test]
    public function it_requires_a_sermon_to_be_in_view_before_continuation_wording_counts(): void
    {
        // "Continuation" with no sermon in the sentence describes a song's second half.
        $run = $this->runWithSermon();
        $this->sectionWithNotes($run, ['This is a continuation of the earlier worship set.']);

        $this->assertSame([], $this->screen->screen($run->serviceSections()->orderBy('start_time')->get()));
    }

    private function runWithSermon(): MediaProcessingLog
    {
        $run = MediaProcessingLog::factory()->livestream()->create();

        ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::Sermon->value,
            'start_time' => 500.0,
            'end_time' => 1200.0,
            'needs_manual_review' => false,
            'metadata' => ['confidence_level' => 'high'],
        ]);

        return $run;
    }

    /**
     * @param  list<string>  $notes
     */
    private function sectionWithNotes(MediaProcessingLog $run, array $notes, float $start = 1450.0, float $end = 1900.0): ServiceSection
    {
        return ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::Other->value,
            'start_time' => $start,
            'end_time' => $end,
            'needs_manual_review' => false,
            'metadata' => [
                'confidence_level' => 'high',
                'ai_notes' => $notes,
            ],
        ]);
    }
}
