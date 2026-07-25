<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Sermon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReslugPlaceholderSermonsCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_reports_without_writing_by_default(): void
    {
        $sermon = Sermon::factory()->create([
            'title' => 'Called to a godly life',
            'slug' => 'morning-sermon-january-18-2026',
        ]);

        $this->artisan('sermons:reslug-placeholders')
            ->expectsOutputToContain('DRY RUN')
            ->assertExitCode(0);

        $this->assertSame('morning-sermon-january-18-2026', $sermon->fresh()?->slug);
    }

    #[Test]
    public function it_rebuilds_placeholder_slugs_with_apply(): void
    {
        $sermon = Sermon::factory()->create([
            'title' => 'Called to a godly life',
            'slug' => 'morning-sermon-january-18-2026',
        ]);

        $this->artisan('sermons:reslug-placeholders', ['--apply' => true])
            ->assertExitCode(0);

        $this->assertSame('called-to-a-godly-life', $sermon->fresh()?->slug);
    }

    #[Test]
    public function it_leaves_real_slugs_alone(): void
    {
        $sermon = Sermon::factory()->create([
            'title' => 'The Good Shepherd',
            'slug' => 'the-good-shepherd',
        ]);

        $this->artisan('sermons:reslug-placeholders', ['--apply' => true])
            ->expectsOutputToContain('No sermons are carrying a placeholder slug.')
            ->assertExitCode(0);

        $this->assertSame('the-good-shepherd', $sermon->fresh()?->slug);
    }

    #[Test]
    public function it_skips_sermons_whose_title_is_still_the_placeholder(): void
    {
        $sermon = Sermon::factory()->create([
            'title' => 'Morning Sermon - January 18, 2026',
            'slug' => 'morning-sermon-january-18-2026',
        ]);

        $this->artisan('sermons:reslug-placeholders', ['--apply' => true])
            ->expectsOutputToContain('No sermons are carrying a placeholder slug.')
            ->assertExitCode(0);

        $this->assertSame('morning-sermon-january-18-2026', $sermon->fresh()?->slug);
    }

    #[Test]
    public function it_avoids_collisions_with_existing_slugs(): void
    {
        Sermon::factory()->create([
            'title' => 'Called to a godly life',
            'slug' => 'called-to-a-godly-life',
        ]);

        $sermon = Sermon::factory()->create([
            'title' => 'Called to a godly life',
            'slug' => 'morning-sermon-january-18-2026',
        ]);

        $this->artisan('sermons:reslug-placeholders', ['--apply' => true])
            ->assertExitCode(0);

        $this->assertSame('called-to-a-godly-life-1', $sermon->fresh()?->slug);
    }

    #[Test]
    public function it_can_be_limited_to_specific_sermons(): void
    {
        $targeted = Sermon::factory()->create([
            'title' => 'Called to a godly life',
            'slug' => 'morning-sermon-january-18-2026',
        ]);

        $untouched = Sermon::factory()->create([
            'title' => 'Draw near by the blood of Jesus',
            'slug' => 'evening-sermon-may-3-2026',
        ]);

        $this->artisan('sermons:reslug-placeholders', [
            '--sermon' => [(string) $targeted->id],
            '--apply' => true,
        ])->assertExitCode(0);

        $this->assertSame('called-to-a-godly-life', $targeted->fresh()?->slug);
        $this->assertSame('evening-sermon-may-3-2026', $untouched->fresh()?->slug);
    }
}
