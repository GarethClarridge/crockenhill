<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\ScripturePassage;
use App\Models\Sermon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ScripturePassageTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function it_has_expected_casts(): void
    {
        $passage = ScripturePassage::factory()->create([
            'fetched_at' => '2025-01-01 12:00:00',
        ]);

        $this->assertInstanceOf(Carbon::class, $passage->fetched_at);
        $this->assertTrue($passage->fetched_at->isSameDay(Carbon::parse('2025-01-01')));
    }

    #[Test]
    public function it_has_sermons_relationship(): void
    {
        $passage = ScripturePassage::factory()->create();
        $sermon = Sermon::factory()->create([
            'scripture_passage_id' => $passage->id,
        ]);

        $this->assertTrue($passage->sermons->contains($sermon));
        $this->assertCount(1, $passage->sermons);
        $this->assertInstanceOf(Sermon::class, $passage->sermons->first());
    }

    #[Test]
    public function it_returns_false_when_recently_fetched(): void
    {
        Config::set('services.api_bible.refresh_after_days', 28);

        $passage = ScripturePassage::factory()->create([
            'fetched_at' => now()->subDays(27),
        ]);

        $this->assertFalse($passage->isStale());
    }

    #[Test]
    public function it_returns_true_when_fetched_at_exceeds_threshold(): void
    {
        Config::set('services.api_bible.refresh_after_days', 28);

        // Using factory stale() state as suggested in PR feedback
        $passage = ScripturePassage::factory()->stale()->create();

        $this->assertTrue($passage->isStale());
    }

    #[Test]
    public function it_respects_custom_configuration_threshold_when_fresh(): void
    {
        Config::set('services.api_bible.refresh_after_days', 7);

        $passage = ScripturePassage::factory()->create([
            'fetched_at' => now()->subDays(6),
        ]);

        $this->assertFalse($passage->isStale());
    }

    #[Test]
    public function it_respects_custom_configuration_threshold_when_stale(): void
    {
        Config::set('services.api_bible.refresh_after_days', 7);

        $passage = ScripturePassage::factory()->create([
            'fetched_at' => now()->subDays(7),
        ]);

        $this->assertTrue($passage->isStale());
    }
}
