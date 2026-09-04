<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Data\SermonAnalysis;
use App\Enums\SermonTitleProvenance;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ApplyBankedAiSermonTitlesCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_reports_without_writing_by_default(): void
    {
        $sermon = $this->sermonWithBankedTitle('Sunday 7Th May 2023', 'The king who humbled himself');

        $this->artisan('sermons:apply-banked-ai-titles')
            ->expectsOutputToContain('DRY RUN')
            ->assertExitCode(0);

        $this->assertSame('Sunday 7Th May 2023', $sermon->fresh()?->title);
    }

    #[Test]
    public function it_applies_the_banked_title_and_records_its_provenance(): void
    {
        $sermon = $this->sermonWithBankedTitle('Sunday 7Th May 2023', 'The king who humbled himself');

        $this->artisan('sermons:apply-banked-ai-titles', ['--apply' => true])
            ->assertExitCode(0);

        $fresh = $sermon->fresh();

        $this->assertSame('The king who humbled himself', $fresh?->title);
        $this->assertSame(SermonTitleProvenance::AiAnalysis, $fresh?->title_provenance);
    }

    /**
     * The public URL is the reason this is opt-in: there is no slug-redirect table, so
     * rewriting a published sermon's slug 404s the address people already hold.
     */
    #[Test]
    public function it_leaves_the_slug_alone_unless_asked(): void
    {
        $sermon = $this->sermonWithBankedTitle('Sunday 7Th May 2023', 'The king who humbled himself');
        $sermon->forceFill(['slug' => 'sunday-7th-may-2023'])->save();

        $this->artisan('sermons:apply-banked-ai-titles', ['--apply' => true])
            ->assertExitCode(0);

        $this->assertSame('sunday-7th-may-2023', $sermon->fresh()?->slug);

        $this->artisan('sermons:apply-banked-ai-titles', ['--apply' => true, '--with-slug' => true])
            ->assertExitCode(0);
    }

    #[Test]
    public function it_rebuilds_the_slug_when_asked(): void
    {
        $sermon = $this->sermonWithBankedTitle('Sunday 7Th May 2023', 'The king who humbled himself');
        $sermon->forceFill(['slug' => 'sunday-7th-may-2023'])->save();

        $this->artisan('sermons:apply-banked-ai-titles', ['--apply' => true, '--with-slug' => true])
            ->assertExitCode(0);

        $this->assertSame('the-king-who-humbled-himself', $sermon->fresh()?->slug);
    }

    #[Test]
    public function it_refuses_to_overwrite_a_curated_title(): void
    {
        $sermon = $this->sermonWithBankedTitle('A title someone chose', 'The king who humbled himself');
        $sermon->forceFill(['title_provenance' => SermonTitleProvenance::Curated])->save();

        $this->artisan('sermons:apply-banked-ai-titles', ['--apply' => true])
            ->assertExitCode(0);

        $this->assertSame('A title someone chose', $sermon->fresh()?->title);
    }

    #[Test]
    public function it_leaves_a_title_that_already_came_from_analysis(): void
    {
        $sermon = $this->sermonWithBankedTitle('An earlier analysed title', 'The king who humbled himself');
        $sermon->forceFill(['title_provenance' => SermonTitleProvenance::AiAnalysis])->save();

        $this->artisan('sermons:apply-banked-ai-titles', ['--apply' => true])
            ->assertExitCode(0);

        $this->assertSame('An earlier analysed title', $sermon->fresh()?->title);
    }

    #[Test]
    public function it_can_be_limited_to_named_sermons(): void
    {
        $wanted = $this->sermonWithBankedTitle('Sunday 7Th May 2023', 'The king who humbled himself');
        $other = $this->sermonWithBankedTitle('Sunday 3Rd May 2026', 'Train yourself to be godly');

        $this->artisan('sermons:apply-banked-ai-titles', [
            '--apply' => true,
            '--sermon' => [(string) $wanted->id],
        ])->assertExitCode(0);

        $this->assertSame('The king who humbled himself', $wanted->fresh()?->title);
        $this->assertSame('Sunday 3Rd May 2026', $other->fresh()?->title);
    }

    private function sermonWithBankedTitle(string $currentTitle, string $bankedTitle): Sermon
    {
        $sermon = Sermon::factory()->create([
            'title' => $currentTitle,
            'title_provenance' => null,
        ]);

        MediaProcessingLog::factory()->create([
            'sermon_id' => $sermon->id,
            'ai_analysis' => new SermonAnalysis(
                title: $bankedTitle,
                series: null,
                reference: null,
                points: ['A point'],
                summary: null,
                transcript: 'A transcript.',
            ),
        ]);

        return $sermon;
    }
}
