<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Data\SermonAnalysis;
use App\Enums\PreacherSource;
use App\Models\ChurchServiceItem;
use App\Models\MediaProcessingLog;
use App\Models\Preacher;
use App\Models\PreacherAlias;
use App\Models\Sermon;
use App\Models\ServiceSection;
use App\Support\SeedSignature;
use Database\Seeders\PreacherSeeder;
use Database\Seeders\SermonSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class ClearSeededResidueCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $printed = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PreacherSeeder::class);
        $this->seed(SermonSeeder::class);
        Sermon::query()->whereNotIn('slug', array_column(SeedSignature::sermons(), 'slug'))->delete();
    }

    #[Test]
    public function a_dry_run_writes_nothing_and_lists_every_change(): void
    {
        $this->adopt('2024-12-15', 'sermon', $this->analysis('God’s promises to Mary', 'Christmas', 'Luke 1:26-56'));
        $before = $this->snapshot();

        $status = $this->clear(false);

        $this->assertSame(0, $status);
        $this->assertSame($before, $this->snapshot());
        $this->assertStringContainsString('DRY RUN', $this->printed);
        $this->assertStringContainsString('The Birth of Our Saviour', $this->printed);
        $this->assertStringContainsString('God’s promises to Mary', $this->printed);
        $this->assertStringContainsString('Luke 1:26-56', $this->printed);
        $this->assertStringContainsString('guest speaker', $this->printed);
    }

    #[Test]
    public function only_fields_still_equal_to_the_seeded_value_change(): void
    {
        $sermon = $this->adopt('2024-12-08', 'sermon', $this->analysis('Ignored new title', null, 'Luke 15:11-32'));
        $sermon->forceFill(['title' => 'The lost older son', 'reference' => 'Luke 15:11-32', 'points' => ['Real point']])->save();

        $this->assertSame(0, $this->clear(true));

        $sermon->refresh();
        $this->assertSame('The lost older son', $sermon->title);
        $this->assertSame('the-lost-older-son', $sermon->slug);
        $this->assertSame('Luke 15:11-32', $sermon->reference);
        $this->assertNull($sermon->series);
        $this->assertSame(['Real point'], $sermon->points);
        $this->assertSame(PreacherSource::Default, $sermon->preacher_source);
        $this->assertSame([], SeedSignature::matchesSermon($sermon));
    }

    #[Test]
    public function seeded_title_reference_and_series_come_from_the_stored_analysis(): void
    {
        $sermon = $this->adopt('2024-12-15', 'sermon', $this->analysis('God’s promises to Mary', 'Christmas', 'Luke 1:26-56', ['Mary believed']));

        $this->assertSame(0, $this->clear(true));

        $sermon->refresh();
        $this->assertSame('God’s promises to Mary', $sermon->title);
        $this->assertSame('gods-promises-to-mary', $sermon->slug);
        $this->assertSame('Luke 1:26-56', $sermon->reference);
        $this->assertNull($sermon->series, 'An uncorroborated series is dropped, as the pipeline holds it back.');
        $this->assertSame(['Mary believed'], $sermon->points);
        $this->assertSame('ai_analysis', $sermon->title_provenance?->value);
        $this->assertDatabaseHas('sermon_scripture_filters', ['sermon_id' => $sermon->id, 'bible_book' => 'Luke', 'bible_chapter' => 1]);
        $this->assertDatabaseMissing('sermon_scripture_filters', ['sermon_id' => $sermon->id, 'bible_chapter' => 2]);
    }

    #[Test]
    public function unattached_seeded_rows_and_their_satellites_are_deleted(): void
    {
        $this->adopt('2024-11-24', 'sermon', $this->analysis('Amazing grace', null, '1 Peter 5:6-14'));

        $this->assertSame(0, $this->clear(true));

        $this->assertSame(0, Sermon::query()->whereIn('slug', [
            'special-service-example', 'childrens-talk-who-is-jesus', 'childrens-talk-god-made-the-world', 'childrens-talk-the-good-shepherd',
        ])->count());
        $this->assertDatabaseMissing('media_processing_logs', ['processing_id' => SeedSignature::PROCESSING_ID]);
        $this->assertSame(0, ServiceSection::query()->count());
        $this->assertSame(0, ChurchServiceItem::withTrashed()->where('title', 'Luke 15:1-10')->count());
        $this->assertSame(['Mark Drury', 'Visiting Speaker'], Preacher::query()->orderBy('name')->pluck('name')->sort()->values()->all());
        $this->assertDatabaseMissing('preacher_aliases', ['alias' => 'guest speaker']);
        $this->assertDatabaseHas('preacher_aliases', ['alias' => 'visiting speaker']);
        $kept = Sermon::query()->where('date', '2024-11-24')->sole();
        $this->assertSame('amazing-grace', $kept->slug);
        $this->assertNotSame(SeedSignature::PROCESSING_ID, $kept->livestream_processing_id);
        $this->assertSame(1, MediaProcessingLog::query()->where('sermon_id', $kept->id)->count());
    }

    #[Test]
    public function a_seeded_preacher_used_by_a_real_sermon_refuses_everything(): void
    {
        $paul = Preacher::query()->where('name', 'Paul Wilson')->firstOrFail();
        Sermon::factory()->create(['preacher_id' => $paul->id, 'preacher' => 'Paul Wilson', 'title' => 'A real sermon', 'date' => '2025-03-02']);
        $before = $this->snapshot();

        $this->assertSame(1, $this->clear(true));

        $this->assertSame($before, $this->snapshot());
        $this->assertStringContainsString('preacher '.$paul->id.' (Paul Wilson) is used by 1 sermon', $this->printed);
    }

    #[Test]
    public function a_service_item_referenced_by_a_real_section_refuses_everything(): void
    {
        $item = ChurchServiceItem::query()->where('title', 'Luke 15:1-10')->firstOrFail();
        $run = MediaProcessingLog::factory()->livestream()->create();
        ServiceSection::factory()->create(['media_processing_log_id' => $run->id, 'church_service_item_id' => $item->id]);
        $before = $this->snapshot();

        $this->assertSame(1, $this->clear(true));

        $this->assertSame($before, $this->snapshot());
        $this->assertStringContainsString("service item {$item->id}", $this->printed);
        $this->assertStringContainsString('outside the seed run', $this->printed);
    }

    #[Test]
    public function a_second_run_has_nothing_to_do(): void
    {
        $this->adopt('2024-12-15', 'sermon', $this->analysis('God’s promises to Mary', null, 'Luke 1:26-56'));
        $this->adopt('2024-12-08', 'sermon', $this->analysis('The lost older son', null, 'Luke 15:11-32'));
        $this->adopt('2024-11-24', 'sermon', $this->analysis('Amazing grace', null, '1 Peter 5:6-14'));

        $this->assertSame(0, $this->clear(true));
        $after = $this->snapshot();

        $this->assertSame(0, $this->clear(true));
        $this->assertStringContainsString('Nothing to do', $this->printed);
        $this->assertSame($after, $this->snapshot());
    }

    #[Test]
    public function a_seeded_title_the_analysis_cannot_replace_is_reported_and_fails_in_execute_mode(): void
    {
        $sermon = $this->adopt('2024-12-15', 'sermon', null);

        $this->assertSame(0, $this->clear(false));
        $this->assertStringContainsString('Unresolved', $this->printed);

        $this->assertSame(1, $this->clear(true));

        $sermon->refresh();
        $this->assertSame('The Birth of Our Saviour', $sermon->title);
        $this->assertSame('the-birth-of-our-saviour', $sermon->slug);
        $this->assertSame('Luke 2:1-20', $sermon->reference);
        $this->assertNull($sermon->series);
        $this->assertStringContainsString("title 'The Birth of Our Saviour' is seeded", $this->printed);
        $this->assertStringContainsString("reference 'Luke 2:1-20' is seeded", $this->printed);
    }

    private function clear(bool $execute): int
    {
        $output = new BufferedOutput;
        $status = Artisan::call('sermons:clear-seeded-residue', $execute ? ['--execute' => true] : [], $output);
        $this->printed = $output->fetch();

        return $status;
    }

    private function adopt(string $date, string $contentType, ?SermonAnalysis $analysis): Sermon
    {
        $sermon = Sermon::query()->where('date', $date)->where('content_type', $contentType)->where('service', 'morning')->firstOrFail();
        MediaProcessingLog::factory()->livestream()->completed()->create([
            'sermon_id' => $sermon->id,
            'ai_analysis' => $analysis,
        ]);

        return $sermon;
    }

    /** @param list<string> $points */
    private function analysis(string $title, ?string $series, ?string $reference, array $points = ['Point']): SermonAnalysis
    {
        return SermonAnalysis::create($title, $series, $reference, $points, null, 'transcript');
    }

    /** @return array<string, mixed> */
    private function snapshot(): array
    {
        return [
            'sermons' => Sermon::query()->orderBy('id')->get()->map->getAttributes()->all(),
            'runs' => MediaProcessingLog::query()->orderBy('id')->pluck('processing_id')->all(),
            'sections' => ServiceSection::query()->count(),
            'items' => ChurchServiceItem::withTrashed()->count(),
            'preachers' => Preacher::query()->orderBy('id')->pluck('name')->all(),
            'aliases' => PreacherAlias::query()->orderBy('id')->pluck('alias')->all(),
        ];
    }
}
