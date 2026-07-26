<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\ChurchService\ServiceItemMergeOrderResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ServiceItemMergeOrderResolverTest extends TestCase
{
    private ServiceItemMergeOrderResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = new ServiceItemMergeOrderResolver;
    }

    #[Test]
    public function test_incoming_spine_splices_preserved_items_between_their_anchors(): void
    {
        // Run: song A (anchors to position 1), prayer, song B (anchors to position 3).
        // Existing position 2 was never detected and belongs between the anchors.
        $ordered = $this->resolver->resolve(
            [
                $this->anchor(1),
                $this->create(),
                $this->anchor(3),
            ],
            [2],
            spineIsIncoming: true,
        );

        $this->assertSame([
            ['source' => 'plan', 'index' => 0],
            ['source' => 'preserved', 'index' => 0],
            ['source' => 'plan', 'index' => 1],
            ['source' => 'plan', 'index' => 2],
        ], $ordered);
    }

    #[Test]
    public function test_incoming_spine_places_preserved_items_before_the_first_anchor(): void
    {
        $ordered = $this->resolver->resolve(
            [
                $this->create(),
                $this->anchor(5),
            ],
            [2],
            spineIsIncoming: true,
        );

        // The preserved item preceded the anchor in the old list, so it precedes
        // it here too — but still follows the detected item that opened the run.
        $this->assertSame([
            ['source' => 'plan', 'index' => 0],
            ['source' => 'preserved', 'index' => 0],
            ['source' => 'plan', 'index' => 1],
        ], $ordered);
    }

    #[Test]
    public function test_incoming_spine_leaves_the_existing_list_first_when_nothing_anchors(): void
    {
        $ordered = $this->resolver->resolve(
            [$this->create(), $this->create()],
            [1, 2],
            spineIsIncoming: true,
        );

        // No anchors means no evidence about how the lists interleave, so the
        // existing list is left intact rather than reordered around a guess.
        $this->assertSame([
            ['source' => 'preserved', 'index' => 0],
            ['source' => 'preserved', 'index' => 1],
            ['source' => 'plan', 'index' => 0],
            ['source' => 'plan', 'index' => 1],
        ], $ordered);
    }

    #[Test]
    public function test_incoming_spine_keeps_detected_order_when_the_plan_disagrees(): void
    {
        // The run saw position 3 before position 1. The run is the record of what
        // happened, so its order wins over the plan's.
        $ordered = $this->resolver->resolve(
            [$this->anchor(3), $this->anchor(1)],
            [],
            spineIsIncoming: true,
        );

        $this->assertSame([
            ['source' => 'plan', 'index' => 0],
            ['source' => 'plan', 'index' => 1],
        ], $ordered);
    }

    #[Test]
    public function test_existing_spine_splices_incoming_items_after_their_anchor(): void
    {
        $ordered = $this->resolver->resolve(
            [
                $this->anchor(1),
                $this->create(),
            ],
            [2],
            spineIsIncoming: false,
        );

        $this->assertSame([
            ['source' => 'plan', 'index' => 0],
            ['source' => 'plan', 'index' => 1],
            ['source' => 'preserved', 'index' => 0],
        ], $ordered);
    }

    #[Test]
    public function test_existing_spine_appends_incoming_items_when_nothing_anchors(): void
    {
        $ordered = $this->resolver->resolve(
            [$this->create()],
            [1, 2],
            spineIsIncoming: false,
        );

        $this->assertSame([
            ['source' => 'preserved', 'index' => 0],
            ['source' => 'preserved', 'index' => 1],
            ['source' => 'plan', 'index' => 0],
        ], $ordered);
    }

    /**
     * @return array{kind: string, existing_position: int|null}
     */
    private function anchor(int $existingPosition): array
    {
        return ['kind' => 'update', 'existing_position' => $existingPosition];
    }

    /**
     * @return array{kind: string, existing_position: int|null}
     */
    private function create(): array
    {
        return ['kind' => 'create', 'existing_position' => null];
    }
}
