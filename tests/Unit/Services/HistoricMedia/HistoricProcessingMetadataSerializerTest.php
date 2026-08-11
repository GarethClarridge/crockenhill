<?php

declare(strict_types=1);

namespace Tests\Unit\Services\HistoricMedia;

use App\Services\HistoricMedia\HistoricProcessingMetadataSerializer;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class HistoricProcessingMetadataSerializerTest extends TestCase
{
    #[Test]
    public function it_preserves_supported_durable_blocks_and_removes_runtime_state(): void
    {
        $result = (new HistoricProcessingMetadataSerializer)->serialize([
            'service_artifacts' => [
                ['kind' => 'rms', 'path' => 'historic/batch/run/rms.json', 'sha256' => str_repeat('a', 64)],
            ],
            'historic_import' => [
                'concatenation' => 'lossless',
                'sources' => [[
                    'path' => '/Volumes/archive/service.mkv',
                    'mtime' => 123456,
                    'sha256' => str_repeat('b', 64),
                    'size' => 123,
                ]],
                'imported_at' => '2026-07-30T12:00:00Z',
            ],
            'rms_log_path' => 'historic/batch/run/rms.json',
            'queue_name' => 'media',
            'job_id' => 'local-job',
            'attempt_count' => 2,
        ]);

        $this->assertSame([
            'historic_import' => [
                'concatenation' => 'lossless',
                'sources' => [['sha256' => str_repeat('b', 64), 'size' => 123]],
            ],
            'rms_log_path' => 'historic/batch/run/rms.json',
            'service_artifacts' => [
                ['kind' => 'rms', 'path' => 'historic/batch/run/rms.json', 'sha256' => str_repeat('a', 64)],
            ],
        ], $result);
    }

    #[Test]
    public function it_rejects_unknown_id_bearing_metadata(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('oos_item_id');

        (new HistoricProcessingMetadataSerializer)->serialize([
            'oos_item_id' => 42,
        ]);
    }

    #[Test]
    public function it_rejects_nested_local_identities(): void
    {
        $this->expectException(RuntimeException::class);

        (new HistoricProcessingMetadataSerializer)->serialize([
            'service_structure' => [
                'sections' => [['owner_id' => 42]],
            ],
        ]);
    }

    #[Test]
    public function it_rejects_absolute_paths(): void
    {
        $this->expectException(RuntimeException::class);

        (new HistoricProcessingMetadataSerializer)->serialize([
            'service_transcript_path' => '/tmp/local-transcript.json',
        ]);
    }

    /**
     * F44: the curated facts must survive the bundle boundary. `occasion` has no
     * column on any model, so this block is the only thing that carries it to the
     * destination at all.
     */
    #[Test]
    public function it_carries_curated_editorial_facts_into_the_bundle(): void
    {
        $result = (new HistoricProcessingMetadataSerializer)->serialize([
            'historic_import' => [
                'concatenation' => 'none',
                'sources' => [['sha256' => str_repeat('c', 64), 'size' => 99]],
                'editorial_facts' => [
                    'occasion' => 'Harvest Thanksgiving',
                    'title' => 'The God Who Provides',
                    'speaker' => 'Rev. Alan Brown',
                    'scripture_reference' => 'Ruth 2:1-23',
                    'series' => 'Ruth',
                ],
            ],
        ]);

        $this->assertSame([
            'occasion' => 'Harvest Thanksgiving',
            'title' => 'The God Who Provides',
            'speaker' => 'Rev. Alan Brown',
            'scripture_reference' => 'Ruth 2:1-23',
            'series' => 'Ruth',
        ], $result['historic_import']['editorial_facts']);
    }

    #[Test]
    public function it_omits_editorial_facts_that_were_left_undecided(): void
    {
        $result = (new HistoricProcessingMetadataSerializer)->serialize([
            'historic_import' => [
                'concatenation' => 'none',
                'sources' => [['sha256' => str_repeat('d', 64), 'size' => 42]],
                'editorial_facts' => [
                    'occasion' => null,
                    'title' => null,
                    'speaker' => null,
                    'scripture_reference' => null,
                    'series' => null,
                ],
            ],
        ]);

        $this->assertArrayNotHasKey('editorial_facts', $result['historic_import']);
    }

    #[Test]
    public function it_still_rejects_an_editorial_fact_carrying_an_absolute_path(): void
    {
        $this->expectException(RuntimeException::class);

        (new HistoricProcessingMetadataSerializer)->serialize([
            'historic_import' => [
                'editorial_facts' => ['title' => '/Volumes/CBC Drive/leaked.mkv'],
            ],
        ]);
    }
}
