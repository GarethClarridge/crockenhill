<?php

declare(strict_types=1);

namespace Tests\Integration\Services\ChurchService;

use App\Models\ChurchService;
use App\Models\ChurchServiceMergeProposal;
use App\Services\ChurchService\ChurchServiceProposalCensus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A corroboration conflict is about a whole-service dimension and names no item, so it
 * carries neither a canonical identity nor an assertion key. The census used to fall
 * straight through to the first proposed item, labelling such classes with whatever sat
 * first in the order of service — in the historic corpus that filed 100 unrelated
 * services under `custom:welcome and any notices` and split one shape across six
 * spellings of "welcome".
 */
class ChurchServiceProposalCensusSubjectTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_dimension_conflict_is_named_by_its_dimension_not_by_the_first_item(): void
    {
        $proposal = $this->proposalWithDimensionConflict(
            ['song_membership', 'song_count'],
            firstItemTitle: 'Welcome and any notices',
        );

        $subject = app(ChurchServiceProposalCensus::class)->subject($proposal);

        $this->assertSame('dimension:song_count+song_membership', $subject);
        $this->assertStringNotContainsString('welcome', $subject);
    }

    /**
     * Two services disagreeing on the same dimensions are one judgement, whatever
     * unrelated item happens to lead each order of service.
     */
    #[Test]
    public function services_sharing_a_dimension_conflict_share_one_class_key(): void
    {
        $census = app(ChurchServiceProposalCensus::class);

        $welcome = $this->proposalWithDimensionConflict(['song_membership'], firstItemTitle: 'Welcome');
        $notices = $this->proposalWithDimensionConflict(['song_membership'], firstItemTitle: 'notices2024looped.pptx');

        $this->assertSame($census->classKey($welcome), $census->classKey($notices));
    }

    /** Dimension order must not split a class, so the names are sorted before joining. */
    #[Test]
    public function dimension_ordering_does_not_split_a_class(): void
    {
        $census = app(ChurchServiceProposalCensus::class);

        $forward = $this->proposalWithDimensionConflict(['song_membership', 'song_order']);
        $reversed = $this->proposalWithDimensionConflict(['song_order', 'song_membership']);

        $this->assertSame($census->classKey($forward), $census->classKey($reversed));
    }

    /**
     * The item fallback still applies where a conflict names neither an identity nor a
     * dimension — that is the case the fallback was written for.
     */
    #[Test]
    public function a_conflict_without_a_dimension_still_falls_back_to_the_proposed_item(): void
    {
        $service = ChurchService::factory()->create();

        $proposal = ChurchServiceMergeProposal::factory()->create([
            'church_service_id' => $service->id,
            'field_decisions' => [['match_tier' => 2]],
            'conflicts' => [['kind' => 'order_inversion']],
            'proposed_items' => [[
                'canonical_identity' => 'song:be thou my vision',
                'position' => 1,
                'type' => 'songs',
                'title' => 'Be Thou My Vision',
            ]],
        ]);

        $this->assertSame(
            'song:be thou my vision',
            app(ChurchServiceProposalCensus::class)->subject($proposal),
        );
    }

    /** A conflict that does name an identity keeps naming the class, unchanged. */
    #[Test]
    public function an_identified_conflict_still_names_the_class(): void
    {
        $service = ChurchService::factory()->create();

        $proposal = ChurchServiceMergeProposal::factory()->create([
            'church_service_id' => $service->id,
            'field_decisions' => [['match_tier' => 1]],
            'conflicts' => [[
                'kind' => 'ambiguous_repeat_match',
                'canonical_identity' => 'song:amazing grace',
                'dimension' => 'song_membership',
            ]],
            'proposed_items' => [[
                'canonical_identity' => 'custom:welcome',
                'position' => 1,
                'type' => 'custom',
                'title' => 'Welcome',
            ]],
        ]);

        $this->assertSame(
            'song:amazing grace',
            app(ChurchServiceProposalCensus::class)->subject($proposal),
        );
    }

    /** @param  list<string>  $dimensions */
    private function proposalWithDimensionConflict(
        array $dimensions,
        string $firstItemTitle = 'Welcome',
    ): ChurchServiceMergeProposal {
        $service = ChurchService::factory()->create();

        return ChurchServiceMergeProposal::factory()->create([
            'church_service_id' => $service->id,
            'field_decisions' => [['match_tier' => 5]],
            'conflicts' => array_map(static fn (string $dimension): array => [
                'kind' => 'uncorroborated_content_dimension',
                'dimension' => $dimension,
            ], $dimensions),
            'proposed_items' => [[
                'position' => 1,
                'type' => 'custom',
                'title' => $firstItemTitle,
            ]],
        ]);
    }
}
