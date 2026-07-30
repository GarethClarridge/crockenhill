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
}
