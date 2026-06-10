<?php

declare(strict_types=1);

namespace Tests\Integration\Services\Song\Sync;

use App\Models\Song;
use App\Services\Song\Sync\LegacySongReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LegacySongReconcilerTest extends TestCase
{
    use RefreshDatabase;

    private LegacySongReconciler $reconciler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->reconciler = app(LegacySongReconciler::class);
    }

    #[Test]
    public function it_matches_a_legacy_song_by_title(): void
    {
        $legacySong = Song::factory()->create([
            'canonical_key' => 'legacy-song-1001',
            'title' => 'A New Commandment',
        ]);

        $map = $this->reconciler->buildReconciliationMap(
            ['a new commandment' => [['id' => 1, 'title' => 'A New Commandment', 'search_title' => 'a new commandment@']]],
            []
        );

        $this->assertSame(
            ['a new commandment' => ['song_id' => $legacySong->id, 'deleted' => false]],
            $map
        );
    }

    #[Test]
    public function it_skips_groups_that_already_match_an_existing_canonical_key(): void
    {
        Song::factory()->create([
            'canonical_key' => 'legacy-song-1001',
            'title' => 'A New Commandment',
        ]);

        $map = $this->reconciler->buildReconciliationMap(
            ['a new commandment' => [['id' => 1, 'title' => 'A New Commandment', 'search_title' => 'a new commandment@']]],
            ['a new commandment' => ['song_id' => 99, 'deleted' => false]]
        );

        $this->assertSame([], $map);
    }

    #[Test]
    public function it_rejects_an_ambiguous_title_match(): void
    {
        Song::factory()->create(['canonical_key' => 'legacy-song-1', 'title' => 'Amazing Grace']);
        Song::factory()->create(['canonical_key' => 'legacy-song-2', 'title' => 'Amazing Grace']);

        $map = $this->reconciler->buildReconciliationMap(
            ['amazing grace' => [['id' => 1, 'title' => 'Amazing Grace', 'search_title' => 'amazing grace@']]],
            []
        );

        $this->assertSame([], $map);
    }

    #[Test]
    public function it_marks_soft_deleted_legacy_matches_as_deleted(): void
    {
        $legacySong = Song::factory()->create([
            'canonical_key' => 'legacy-song-1001',
            'title' => 'Be Thou My Vision',
        ]);
        $legacySong->delete();

        $map = $this->reconciler->buildReconciliationMap(
            ['be thou my vision' => [['id' => 1, 'title' => 'Be Thou My Vision', 'search_title' => 'be thou my vision@']]],
            []
        );

        $this->assertTrue($map['be thou my vision']['deleted']);
        $this->assertSame($legacySong->id, $map['be thou my vision']['song_id']);
    }
}
