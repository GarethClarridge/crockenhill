<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\MediaProcessingLog;
use App\Models\Preacher;
use App\Models\PreacherAlias;
use App\Models\Sermon;
use Database\Seeders\PreacherSeeder;
use Database\Seeders\SermonSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class ProductionCensusSeedSignatureTest extends TestCase
{
    use RefreshDatabase;

    private string $folder;

    private BufferedOutput $output;

    private string $printed = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->folder = storage_path('scratch/seeded-residue-20261115-wp47/census-test-'.bin2hex(random_bytes(8)));
        mkdir($this->folder, 0700, true);
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), glob($this->folder.'/*') ?: []);
        @rmdir($this->folder);
        parent::tearDown();
    }

    #[Test]
    public function a_database_with_no_seeded_rows_reports_zero_of_every_kind(): void
    {
        Sermon::factory()->create(['date' => '2024-12-15', 'service' => 'morning', 'content_type' => 'sermon', 'title' => 'God’s promises to Mary', 'slug' => 'gods-promises-to-mary', 'series' => null]);
        Preacher::factory()->create(['name' => 'Mark Drury', 'slug' => 'mark-drury']);

        $footer = $this->footer();

        foreach (['sermons', 'preachers', 'preacher_aliases', 'media_processing_logs', 'church_service_items'] as $kind) {
            $this->assertSame(['count' => 0, 'ids' => []], $footer['seed_signature'][$kind], $kind);
        }
        $this->assertStringContainsString('Seed signature sermons: 0', $this->printed);
        $this->assertStringNotContainsString('production should have none', $this->printed);
    }

    #[Test]
    public function seeded_rows_are_counted_with_their_ids_per_kind(): void
    {
        $this->seed(PreacherSeeder::class);
        $this->seed(SermonSeeder::class);

        $footer = $this->footer();
        $signature = $footer['seed_signature'];

        $this->assertSame(7, $signature['sermons']['count']);
        $this->assertEqualsCanonicalizing(
            Sermon::query()->whereIn('slug', ['the-birth-of-our-saviour', 'walking-in-the-light', 'special-service-example', 'childrens-talk-who-is-jesus', 'childrens-talk-god-made-the-world', 'childrens-talk-the-good-shepherd', 'the-prodigal-son'])->pluck('id')->all(),
            $signature['sermons']['ids'],
        );
        $this->assertSame(4, $signature['preachers']['count']);
        $this->assertSame(
            Preacher::query()->whereIn('name', ['John Smith', 'David Johnson', 'Michael Brown', 'Paul Wilson'])->pluck('id')->sort()->values()->all(),
            collect($signature['preachers']['ids'])->sort()->values()->all(),
        );
        $this->assertSame([PreacherAlias::query()->where('alias', 'guest speaker')->value('id')], $signature['preacher_aliases']['ids']);
        $this->assertSame([MediaProcessingLog::query()->where('processing_id', 'seed-prodigal-son-processing')->value('id')], $signature['media_processing_logs']['ids']);
        $this->assertSame([ChurchServiceItem::query()->where('title', 'Luke 15:1-10')->value('id')], $signature['church_service_items']['ids']);
        $this->assertStringContainsString('Seed signature sermons: 7', $this->printed);
        $this->assertStringContainsString('production should have none', $this->printed);
    }

    #[Test]
    public function a_real_reading_with_the_same_title_in_another_service_is_not_a_match(): void
    {
        $service = ChurchService::factory()->create(['date' => '2024-12-01', 'service' => 'morning']);
        ChurchServiceItem::factory()->create(['church_service_id' => $service->id, 'position' => 2, 'type' => 'bibles', 'title' => 'Luke 15:1-10']);

        $this->assertSame(0, $this->footer()['seed_signature']['church_service_items']['count']);
    }

    /** @return array<string, mixed> */
    private function footer(): array
    {
        $path = $this->folder.'/census.jsonl';
        $this->output = new BufferedOutput;
        $status = Artisan::call('historic-import:production-census', ['--output' => $path, '--skip-hashes' => true], $this->output);
        $this->printed = $this->output->fetch();
        $this->assertSame(0, $status, $this->printed);
        $lines = file($path);

        return json_decode((string) end($lines), true, flags: JSON_THROW_ON_ERROR);
    }
}
