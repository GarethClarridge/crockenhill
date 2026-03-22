<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\PreacherSource;
use App\Models\Preacher;
use App\Models\PreacherAlias;
use App\Models\Sermon;
use App\Services\PreacherCutoverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PreacherCutoverServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_links_sermons_and_defaults_blank_names_through_shared_service(): void
    {
        $blankSermon = Sermon::factory()->create([
            'preacher' => '   ',
            'preacher_id' => null,
            'preacher_source' => null,
            'needs_preacher_review' => false,
        ]);
        $namedSermon = Sermon::factory()->create([
            'preacher' => 'J Smith',
            'preacher_id' => null,
            'preacher_source' => null,
            'needs_preacher_review' => false,
        ]);

        $result = app(PreacherCutoverService::class)->run(aliasMap: [
            'j smith' => 'John Smith',
        ]);

        $blankSermon->refresh();
        $namedSermon->refresh();

        $this->assertSame(2, $result['summary']['sermons_linked']);
        $this->assertSame(1, $result['summary']['sermons_defaulted']);
        $this->assertSame('Visiting Speaker', $blankSermon->preacher);
        $this->assertSame(PreacherSource::DEFAULT, $blankSermon->preacher_source);
        $this->assertTrue($blankSermon->needs_preacher_review);
        $this->assertSame(PreacherSource::MANUAL, $namedSermon->preacher_source);
        $this->assertFalse($namedSermon->needs_preacher_review);
        $this->assertNotNull($namedSermon->preacher_id);

        $preacher = Preacher::query()->where('name', 'John Smith')->first();
        $this->assertNotNull($preacher);
        $this->assertDatabaseHas('preacher_aliases', ['alias' => 'j smith']);
        $this->assertInstanceOf(PreacherAlias::class, PreacherAlias::query()->where('alias', 'j smith')->first());
    }
}
