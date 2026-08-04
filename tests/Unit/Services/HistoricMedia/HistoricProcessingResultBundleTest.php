<?php

declare(strict_types=1);

namespace Tests\Unit\Services\HistoricMedia;

use App\Services\HistoricMedia\HistoricProcessingResultBundle;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class HistoricProcessingResultBundleTest extends TestCase
{
    #[Test]
    public function it_builds_and_decodes_a_hash_verified_portable_bundle(): void
    {
        $bundles = new HistoricProcessingResultBundle;
        $bundle = $bundles->make(
            str_repeat('a', 64),
            ['git_commit' => str_repeat('b', 40), 'pipeline_version' => 1],
            [$this->service()],
        );

        $decoded = $bundles->decode(json_encode($bundle, JSON_THROW_ON_ERROR));

        $this->assertSame(HistoricProcessingResultBundle::FORMAT, $decoded['format']);
        $this->assertSame($bundle['bundle_hash'], $decoded['bundle_hash']);
    }

    #[Test]
    public function it_rejects_hash_drift(): void
    {
        $bundles = new HistoricProcessingResultBundle;
        $bundle = $bundles->make(str_repeat('a', 64), ['pipeline_version' => 1], [$this->service()]);
        $bundle['services'][0]['date'] = '2026-08-02';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('hash does not match');

        $bundles->validate($bundle);
    }

    #[Test]
    public function it_rejects_duplicate_natural_identities(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Duplicate natural service identity');

        (new HistoricProcessingResultBundle)->make(
            str_repeat('a', 64),
            ['pipeline_version' => 1],
            [$this->service(), $this->service('other-run')],
        );
    }

    #[Test]
    public function it_rejects_local_ids_paths_and_runtime_state(): void
    {
        $service = $this->service();
        $service['media_graph']['sections'][0]['owner_id'] = 42;
        $service['media_graph']['sections'][0]['path'] = '/tmp/output.mp4';
        $service['media_graph']['queue_name'] = 'media';

        $this->expectException(RuntimeException::class);

        (new HistoricProcessingResultBundle)->make(
            str_repeat('a', 64),
            ['pipeline_version' => 1],
            [$service],
        );
    }

    #[Test]
    public function it_rejects_an_unclassified_relative_path_field(): void
    {
        $service = $this->service();
        $service['media_graph']['sections'][0]['unexpected_path'] = 'historic/structure.json';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unclassified path field');

        (new HistoricProcessingResultBundle)->make(
            str_repeat('a', 64),
            ['pipeline_version' => 1],
            [$service],
        );
    }

    #[Test]
    public function it_represents_shared_physical_content_once_with_all_logical_roles(): void
    {
        $contentHash = hash('sha256', 'shared media');
        $bundle = (new HistoricProcessingResultBundle)->make(
            str_repeat('a', 64),
            ['pipeline_version' => 1],
            [[
                ...$this->service(),
                'assets' => [
                    [
                        'role' => 'run_audio_file_path',
                        'path' => 'historic/batch/full.mp3',
                        'size' => 12,
                        'sha256' => $contentHash,
                    ],
                    [
                        'role' => 'publication:main:audio_file_path',
                        'path' => 'historic/batch/sermon.mp3',
                        'size' => 12,
                        'sha256' => $contentHash,
                    ],
                ],
            ]],
        );

        $assets = $bundle['services'][0]['assets'];

        $this->assertCount(1, $assets);
        $this->assertSame([
            'publication:main:audio_file_path',
            'run_audio_file_path',
        ], $assets[0]['roles']);
        $this->assertSame('unknown', $assets[0]['kind']);
    }

    /** @return array<string, mixed> */
    private function service(string $processingKey = 'historic-run'): array
    {
        return [
            'date' => '2026-08-01',
            'service' => 'morning',
            'source_manifest_hash' => str_repeat('c', 64),
            'evidence_set_hash' => str_repeat('d', 64),
            'pre_review_hash' => str_repeat('e', 64),
            'media_graph' => [
                'processing_key' => $processingKey,
                'sections' => [['section_key' => "{$processingKey}:section:1"]],
            ],
            'livestream_source_revision' => [
                'source_key' => "{$processingKey}|v1",
                'revision_hash' => str_repeat('f', 64),
                'assertions' => [],
            ],
            'assets' => [
                [
                    'role' => 'main_video',
                    'path' => 'historic/batch/main.mp4',
                    'size' => 123,
                    'sha256' => str_repeat('1', 64),
                ],
            ],
        ];
    }
}
