<?php

declare(strict_types=1);

namespace Tests\Feature\Actions;

use App\Actions\FlagPublishedReferenceContradictsSermon;
use App\Enums\ServiceSectionType;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Models\Sermon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FlagPublishedReferenceContradictsSermonTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Sermon 899 (run 963) was published as Matthew 2:1-12, the carol reading before it; the
     * preacher gave his text as 2 Corinthians 9:15, which structure detection heard.
     */
    #[Test]
    public function it_holds_a_sermon_whose_published_reference_shares_no_verse_with_the_heard_one(): void
    {
        [$run, $section] = $this->sermonRun('Matthew 2:1-12', '2 Corinthians 9:15');

        $outcome = app(FlagPublishedReferenceContradictsSermon::class)($run);

        self::assertSame(['raised' => 1, 'withdrawn' => 0], $outcome);
        $section->refresh();
        self::assertContains(FlagPublishedReferenceContradictsSermon::FLAG, $section->metadata?->toArray()['review_flags'] ?? []);
        self::assertTrue($section->needs_manual_review);
    }

    /**
     * 954 named 3 John where the preacher read 2 John: the same verses, another letter.
     */
    #[Test]
    public function it_holds_a_reference_to_the_wrong_book_with_the_same_verses(): void
    {
        [$run] = $this->sermonRun('3 John 1:10-11', '2 John 10-11');

        self::assertSame(1, app(FlagPublishedReferenceContradictsSermon::class)($run)['raised']);
    }

    #[Test]
    public function it_accepts_a_published_reference_that_overlaps_the_heard_one(): void
    {
        [$run, $section] = $this->sermonRun('Luke 18:31-43', 'Luke 18:35-43');

        self::assertSame(['raised' => 0, 'withdrawn' => 0], app(FlagPublishedReferenceContradictsSermon::class)($run));
        self::assertNotContains(FlagPublishedReferenceContradictsSermon::FLAG, $section->fresh()?->metadata?->toArray()['review_flags'] ?? []);
    }

    #[Test]
    public function it_withdraws_the_hold_once_the_reference_is_corrected(): void
    {
        [$run, $section] = $this->sermonRun('Matthew 2:1-12', '2 Corinthians 9:15');
        app(FlagPublishedReferenceContradictsSermon::class)($run);

        $run->sermon?->update(['reference' => '2 Corinthians 9:15']);

        self::assertSame(['raised' => 0, 'withdrawn' => 1], app(FlagPublishedReferenceContradictsSermon::class)($run->fresh()));
        self::assertFalse($section->fresh()?->needs_manual_review);
    }

    /**
     * Nothing heard is no evidence: the check makes no claim either way.
     */
    #[Test]
    public function it_makes_no_claim_without_a_heard_or_published_reference(): void
    {
        [$unheard] = $this->sermonRun('Matthew 2:1-12', null);
        [$unpublished] = $this->sermonRun(null, '2 Corinthians 9:15');

        self::assertSame(['raised' => 0, 'withdrawn' => 0], app(FlagPublishedReferenceContradictsSermon::class)($unheard));
        self::assertSame(['raised' => 0, 'withdrawn' => 0], app(FlagPublishedReferenceContradictsSermon::class)($unpublished));
    }

    #[Test]
    public function it_makes_no_claim_on_a_reference_it_cannot_parse(): void
    {
        [$run] = $this->sermonRun('The Christmas story', '2 Corinthians 9:15');

        self::assertSame(['raised' => 0, 'withdrawn' => 0], app(FlagPublishedReferenceContradictsSermon::class)($run));
    }

    /**
     * Making no claim withdraws an earlier one: otherwise clearing a wrong reference leaves a
     * hold that nothing will ever lift.
     */
    #[Test]
    public function it_withdraws_the_hold_once_the_published_reference_is_cleared(): void
    {
        [$run, $section] = $this->sermonRun('Matthew 2:1-12', '2 Corinthians 9:15');
        app(FlagPublishedReferenceContradictsSermon::class)($run);

        $run->sermon?->update(['reference' => null]);

        self::assertSame(['raised' => 0, 'withdrawn' => 1], app(FlagPublishedReferenceContradictsSermon::class)($run->fresh()));
        self::assertNotContains(FlagPublishedReferenceContradictsSermon::FLAG, $section->fresh()?->metadata?->toArray()['review_flags'] ?? []);
        self::assertFalse($section->fresh()?->needs_manual_review);
    }

    #[Test]
    public function it_withdraws_the_hold_once_the_published_reference_cannot_be_parsed(): void
    {
        [$run, $section] = $this->sermonRun('Matthew 2:1-12', '2 Corinthians 9:15');
        app(FlagPublishedReferenceContradictsSermon::class)($run);

        $run->sermon?->update(['reference' => 'The Christmas story']);

        self::assertSame(['raised' => 0, 'withdrawn' => 1], app(FlagPublishedReferenceContradictsSermon::class)($run->fresh()));
        self::assertFalse($section->fresh()?->needs_manual_review);
    }

    #[Test]
    public function it_withdraws_the_hold_once_no_heard_reference_remains(): void
    {
        [$run, $section] = $this->sermonRun('Matthew 2:1-12', '2 Corinthians 9:15');
        app(FlagPublishedReferenceContradictsSermon::class)($run);

        $metadata = $section->fresh()?->metadata?->toArray() ?? [];
        $metadata['sermon_reference'] = null;
        $section->forceFill(['metadata' => $metadata])->save();

        self::assertSame(['raised' => 0, 'withdrawn' => 1], app(FlagPublishedReferenceContradictsSermon::class)($run->fresh()));
        self::assertFalse($section->fresh()?->needs_manual_review);
    }

    /**
     * @return array{0: MediaProcessingLog, 1: ServiceSection}
     */
    private function sermonRun(?string $published, ?string $heard): array
    {
        $sermon = Sermon::factory()->create(['reference' => $published, 'scripture_passage_id' => null]);
        $run = MediaProcessingLog::factory()->livestream()->completed()->create(['sermon_id' => $sermon->id]);
        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::Sermon,
            'start_time' => 600,
            'end_time' => 2400,
            'duration' => 1800,
            'needs_manual_review' => false,
            'metadata' => ['sermon_reference' => $heard, 'review_flags' => []],
        ]);

        return [$run, $section];
    }
}
