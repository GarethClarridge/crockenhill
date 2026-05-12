<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\SermonContentType;
use App\Enums\SermonService;
use App\Enums\SermonSourceType;
use App\Models\Sermon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GenerateProdSermonPatchCommandTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $temporaryFiles = [];

    #[Test]
    public function it_generates_prod_safe_insert_slugs_and_reads_every_insert_block(): void
    {
        Sermon::withoutEvents(fn (): Sermon => Sermon::factory()->create([
            'date' => '2024-01-14',
            'service' => SermonService::Morning,
            'title' => 'Existing Overlap',
            'slug' => 'existing-overlap-local',
            'duration' => 1234,
            'source_type' => SermonSourceType::AudioUpload,
        ]));

        Sermon::withoutEvents(fn (): Sermon => Sermon::factory()->create([
            'date' => '2024-01-21',
            'service' => SermonService::Evening,
            'title' => 'The Rich Young Ruler',
            'slug' => 'the-rich-young-ruler',
            'reference' => 'Matthew 19:16-26',
            'duration' => 2498,
            'source_type' => SermonSourceType::AudioUpload,
        ]));

        Sermon::withoutEvents(fn (): Sermon => Sermon::factory()->create([
            'date' => '2024-01-28',
            'service' => SermonService::Evening,
            'title' => 'The Rich Young Ruler',
            'slug' => 'the-rich-young-ruler-1',
            'reference' => 'Matthew 19:16-26',
            'duration' => 2601,
            'source_type' => SermonSourceType::AudioUpload,
        ]));

        $dumpPath = $this->createProdDump([
            $this->prodRow([
                'id' => 547,
                'date' => '2019-08-04',
                'service' => 'morning',
                'title' => 'The rich young ruler',
                'slug' => 'the-rich-young-ruler',
                'preacher' => 'Existing Preacher',
                'summary' => 'Legacy summary; includes parentheses (for parser coverage).',
            ]),
        ], [
            $this->prodRow([
                'id' => 811,
                'date' => '2024-01-14',
                'service' => 'morning',
                'title' => 'Existing overlap',
                'slug' => 'existing-overlap',
                'duration' => null,
                'preacher' => 'Existing Preacher',
            ]),
        ]);
        $outputPath = $this->temporaryPath('sermon-patch-output.sql');

        $this->artisan('sermons:generate-prod-patch', [
            '--dump' => $dumpPath,
            '--output' => $outputPath,
        ])->assertSuccessful();

        $patch = file_get_contents($outputPath);

        $this->assertNotFalse($patch);
        $this->assertStringContainsString('WHERE `id` = 811;', $patch);
        $this->assertStringContainsString("`duration` = '1234", $patch);
        $this->assertStringContainsString("'the-rich-young-ruler-1'", $patch);
        $this->assertStringContainsString("'the-rich-young-ruler-2'", $patch);
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        parent::tearDown();
    }

    /**
     * @param  list<array<string, int|string|null>>  ...$insertGroups
     */
    private function createProdDump(array ...$insertGroups): string
    {
        $path = $this->temporaryPath('prod-sermons-dump.sql');
        $statements = [];

        foreach ($insertGroups as $rows) {
            $values = array_map(
                fn (array $row): string => '('.$this->serializeProdRow($row).')',
                $rows,
            );

            $statements[] = 'INSERT INTO `sermons` VALUES '.implode(', ', $values).';';
        }

        file_put_contents($path, implode(PHP_EOL, $statements));

        return $path;
    }

    /**
     * @param  array<string, int|string|null>  $overrides
     * @return array<string, int|string|null>
     */
    private function prodRow(array $overrides): array
    {
        return array_replace([
            'id' => 1,
            'livestream_processing_id' => null,
            'date' => '2024-01-01',
            'service' => 'morning',
            'content_type' => SermonContentType::Sermon->value,
            'audio_file_path' => 'sermons/audio/existing.mp3',
            'video_file_path' => null,
            'video_quality_status' => null,
            'video_quality_reason' => null,
            'video_visibility_override' => null,
            'video_quality_assessed_at' => null,
            'source_type' => SermonSourceType::AudioUpload->value,
            'segment_start_time' => null,
            'segment_end_time' => null,
            'duration' => 1800,
            'filetype' => 'mp3',
            'title' => 'Existing sermon',
            'slug' => 'existing-sermon',
            'reference' => null,
            'scripture_passage_id' => null,
            'download_count' => 0,
            'preacher' => 'Existing Preacher',
            'preacher_id' => null,
            'preacher_source' => 'manual',
            'preacher_confidence' => null,
            'needs_preacher_review' => 0,
            'series' => null,
            'created_at' => '2026-05-08 10:07:00',
            'updated_at' => '2026-05-08 10:07:00',
            'points' => null,
            'show_points' => 1,
            'transcript_file_path' => null,
            'thumbnail_file_path' => null,
            'thumbnail_generated_at' => null,
            'thumbnail_metadata' => null,
            'summary' => null,
            'meta_description' => null,
            'show_summary' => 1,
        ], $overrides);
    }

    /**
     * @param  array<string, int|string|null>  $row
     */
    private function serializeProdRow(array $row): string
    {
        $columns = [
            'id', 'livestream_processing_id', 'date', 'service', 'content_type',
            'audio_file_path', 'video_file_path', 'video_quality_status', 'video_quality_reason',
            'video_visibility_override', 'video_quality_assessed_at', 'source_type',
            'segment_start_time', 'segment_end_time', 'duration', 'filetype', 'title',
            'slug', 'reference', 'scripture_passage_id', 'download_count', 'preacher',
            'preacher_id', 'preacher_source', 'preacher_confidence', 'needs_preacher_review',
            'series', 'created_at', 'updated_at', 'points', 'show_points',
            'transcript_file_path', 'thumbnail_file_path', 'thumbnail_generated_at',
            'thumbnail_metadata', 'summary', 'meta_description', 'show_summary',
        ];

        return implode(', ', array_map(
            fn (string $column): string => $this->sqlValue($row[$column] ?? null),
            $columns,
        ));
    }

    private function sqlValue(int|string|null $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        $escaped = str_replace(['\\', "'"], ['\\\\', "\\'"], (string) $value);

        return "'{$escaped}'";
    }

    private function temporaryPath(string $filename): string
    {
        $path = sys_get_temp_dir().'/'.$filename.'-'.uniqid('', true);
        $this->temporaryFiles[] = $path;

        return $path;
    }
}
