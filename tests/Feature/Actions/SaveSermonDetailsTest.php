<?php

declare(strict_types=1);

namespace Tests\Feature\Actions;

use App\Actions\QueueScriptureEnrichment;
use App\Actions\SaveSermonDetails;
use App\Enums\PreacherSource;
use App\Enums\SermonService;
use App\Models\Preacher;
use App\Models\ScripturePassage;
use App\Models\Sermon;
use App\Services\PreacherResolutionService;
use App\Services\SermonIdentitySyncService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SaveSermonDetailsTest extends TestCase
{
    use DatabaseTransactions;

    private SaveSermonDetails $action;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->forgetInstance(SaveSermonDetails::class);
        $this->action = app(SaveSermonDetails::class);
    }

    #[Test]
    public function it_updates_basic_sermon_fields(): void
    {
        $sermon = Sermon::factory()->create([
            'title' => 'Old Title',
            'slug' => 'old-slug',
            'date' => '2025-01-01',
            'service' => SermonService::Morning,
            'duration' => 1800,
        ]);

        $data = $this->validData([
            'title' => 'New Title',
            'slug' => 'new-slug',
            'date' => '2025-02-01',
            'service' => SermonService::Evening->value,
            'duration' => 2000,
        ]);

        $this->action->execute($sermon, $data);

        $sermon->refresh();
        $this->assertEquals('New Title', $sermon->title);
        $this->assertEquals('new-slug', $sermon->slug);
        $this->assertEquals('2025-02-01', $sermon->date->format('Y-m-d'));
        $this->assertEquals(SermonService::Evening, $sermon->service);
        $this->assertEquals(2000, $sermon->duration);
    }

    #[Test]
    public function it_resolves_preacher_by_id(): void
    {
        $sermon = Sermon::factory()->create(['preacher' => 'Old Preacher', 'preacher_id' => null]);
        $preacher = Preacher::factory()->create(['name' => 'Dr. Martyn Lloyd-Jones']);

        $data = $this->validData([
            'preacherId' => $preacher->id,
            'preacher' => 'Ignored Name',
        ]);

        $this->action->execute($sermon, $data);

        $sermon->refresh();
        $this->assertEquals('Dr. Martyn Lloyd-Jones', $sermon->preacher);
        $this->assertEquals($preacher->id, $sermon->preacher_id);
        $this->assertEquals(PreacherSource::Manual, $sermon->preacher_source);
    }

    #[Test]
    public function it_resolves_preacher_by_name_when_id_is_null(): void
    {
        $sermon = Sermon::factory()->create(['preacher' => 'Old Preacher']);

        $resolvedPreacher = Preacher::factory()->create(['name' => 'Charles Spurgeon']);

        // The issue is that the PreacherAliasObserver is firing and it resolves SermonIdentitySyncService
        // from the container. If it's a mock without the specific method expected, it fails.
        // Let's use a real instance for this test to avoid the observer complication,
        // OR mock the observer's dependency.

        $this->mock(PreacherResolutionService::class, function ($mock) use ($resolvedPreacher) {
            $mock->shouldReceive('resolve')->with('Charles Spurgeon')->once()->andReturn($resolvedPreacher);
        });

        $this->action = app(SaveSermonDetails::class);

        $data = $this->validData([
            'preacherId' => null,
            'preacher' => 'Charles Spurgeon',
        ]);

        $this->action->execute($sermon, $data);

        $sermon->refresh();
        $this->assertEquals('Charles Spurgeon', $sermon->preacher);
        $this->assertEquals($resolvedPreacher->id, $sermon->preacher_id);
    }

    #[Test]
    public function it_associates_existing_scripture_passage(): void
    {
        $sermon = Sermon::factory()->create(['reference' => 'Old Ref', 'scripture_passage_id' => null]);
        $passage = ScripturePassage::factory()->create(['normalized_reference' => 'John 3:16']);

        $mockSync = $this->mock(SermonIdentitySyncService::class);
        $mockSync->shouldReceive('findExistingScripturePassage')->with('John 3:16')->andReturn($passage);
        $mockSync->shouldReceive('syncForPersistence')->byDefault();
        $mockSync->shouldReceive('backfillSermonsForAlias')->byDefault();

        $this->action = app(SaveSermonDetails::class);

        $data = $this->validData(['reference' => 'John 3:16']);

        $this->action->execute($sermon, $data);

        $sermon->refresh();
        $this->assertEquals('John 3:16', $sermon->reference);
        $this->assertEquals($passage->id, $sermon->scripture_passage_id);
    }

    #[Test]
    public function it_triggers_scripture_enrichment_when_reference_changes(): void
    {
        $sermon = Sermon::factory()->create(['reference' => 'John 3:16']);

        $this->mock(QueueScriptureEnrichment::class, function ($mock) {
            $mock->shouldReceive('dispatch')->once();
        });

        $this->action = app(SaveSermonDetails::class);

        $data = $this->validData(['reference' => 'Romans 8:28']);

        $this->action->execute($sermon, $data);
    }

    #[Test]
    public function it_does_not_trigger_enrichment_when_reference_is_unchanged(): void
    {
        $sermon = Sermon::factory()->create(['reference' => 'John 3:16']);

        $this->mock(QueueScriptureEnrichment::class, function ($mock) {
            $mock->shouldReceive('dispatch')->never();
        });

        $this->action = app(SaveSermonDetails::class);

        $data = $this->validData(['reference' => 'John 3:16']);

        $this->action->execute($sermon, $data);
    }

    #[Test]
    public function it_filters_empty_sermon_points(): void
    {
        $sermon = Sermon::factory()->create();

        $data = $this->validData([
            'points' => ['Point 1', '', 'Point 2', null, '  '],
        ]);

        $this->action->execute($sermon, $data);

        $sermon->refresh();
        $this->assertEquals(['Point 1', 'Point 2', '  '], array_values($sermon->points));
    }

    #[Test]
    public function it_clears_needs_preacher_review_flag(): void
    {
        $sermon = Sermon::factory()->create(['needs_preacher_review' => true]);

        $data = $this->validData();

        $this->action->execute($sermon, $data);

        $sermon->refresh();
        $this->assertFalse($sermon->needs_preacher_review);
    }

    #[Test]
    public function it_falls_back_to_manual_preacher_name_when_id_is_invalid(): void
    {
        $sermon = Sermon::factory()->create(['preacher' => 'Old Preacher']);

        $data = $this->validData([
            'preacherId' => 99999,
            'preacher' => 'Manual Name',
            'preacherSource' => PreacherSource::Id3->value,
        ]);

        $this->action->execute($sermon, $data);

        $sermon->refresh();
        $this->assertEquals('Manual Name', $sermon->preacher);
        $this->assertNull($sermon->preacher_id);
        $this->assertEquals(PreacherSource::Id3, $sermon->preacher_source);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array{
     *   title: string, slug: string, date: string, service: string,
     *   preacher: string, preacherId: ?int, preacherSource: ?string,
     *   preacherConfidence: ?float, duration: ?float,
     *   segmentStartTime: ?float, segmentEndTime: ?float,
     *   downloadCount: ?int, reference: ?string, series: ?string,
     *   summary: ?string, points: array<int,string>,
     *   showSummary: bool, showPoints: bool
     * }
     */
    private function validData(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Test Sermon',
            'slug' => 'test-sermon',
            'date' => '2025-01-01',
            'service' => SermonService::Morning->value,
            'preacher' => 'John Doe',
            'preacherId' => null,
            'preacherSource' => PreacherSource::Manual->value,
            'preacherConfidence' => 1.0,
            'duration' => 1800.0,
            'segmentStartTime' => 0.0,
            'segmentEndTime' => 1800.0,
            'downloadCount' => 0,
            'reference' => 'John 1:1',
            'series' => 'Test Series',
            'summary' => 'Test Summary',
            'points' => ['Point 1'],
            'showSummary' => true,
            'showPoints' => true,
        ], $overrides);
    }
}
