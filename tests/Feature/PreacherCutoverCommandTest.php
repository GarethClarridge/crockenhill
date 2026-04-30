<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PreacherSource;
use App\Models\Preacher;
use App\Models\PreacherAlias;
use App\Models\Sermon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PreacherCutoverCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_cutover_skips_blank_preacher_names_and_defaults_whitespace_sermons(): void
    {
        $blankNameSermon = Sermon::factory()->create([
            'preacher' => '   ',
            'preacher_id' => null,
            'preacher_source' => null,
            'needs_preacher_review' => false,
        ]);

        $namedSermon = Sermon::factory()->create([
            'preacher' => 'John   Smith',
            'preacher_id' => null,
            'preacher_source' => null,
            'needs_preacher_review' => false,
        ]);

        $this->artisan('preachers:cutover')->assertSuccessful();

        $blankNameSermon->refresh();
        $namedSermon->refresh();

        $this->assertEquals('Visiting Speaker', $blankNameSermon->preacher);
        $this->assertEquals(PreacherSource::Default, $blankNameSermon->preacher_source);
        $this->assertTrue($blankNameSermon->needs_preacher_review);
        $this->assertNotNull($blankNameSermon->preacher_id);

        $this->assertNotNull($namedSermon->preacher_id);
        $this->assertEquals(PreacherSource::Manual, $namedSermon->preacher_source);
        $this->assertFalse($namedSermon->needs_preacher_review);

        $canonicalPreacher = Preacher::where('slug', 'john-smith')->first();
        $this->assertNotNull($canonicalPreacher);
        $this->assertEquals('John Smith', $canonicalPreacher->name);

        $this->assertDatabaseHas('preacher_aliases', ['alias' => 'john smith']);
        $this->assertDatabaseMissing('preachers', ['name' => '']);
        $this->assertDatabaseMissing('preachers', ['slug' => '']);
        $this->assertDatabaseMissing('preacher_aliases', ['alias' => '']);
    }

    public function test_cutover_is_idempotent_for_preachers_and_aliases(): void
    {
        Sermon::factory()->create(['preacher' => 'Jane Doe', 'preacher_id' => null]);

        $this->artisan('preachers:cutover')->assertSuccessful();
        $preacherCountAfterFirstRun = Preacher::count();
        $aliasCountAfterFirstRun = PreacherAlias::count();

        $this->artisan('preachers:cutover')->assertSuccessful();

        $this->assertEquals($preacherCountAfterFirstRun, Preacher::count());
        $this->assertEquals($aliasCountAfterFirstRun, PreacherAlias::count());
    }
}
