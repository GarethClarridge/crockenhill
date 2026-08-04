<?php

declare(strict_types=1);

namespace Tests\Integration\Services\HistoricMedia;

use App\Data\HistoricProcessingResultImportPlan;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Services\HistoricMedia\HistoricMediaGraphPersister;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

class HistoricMediaGraphPersisterTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_allocates_and_applies_thumbnail_metadata_and_candidate_roles_without_collisions(): void
    {
        $sermon = Sermon::factory()->create([
            'thumbnail_metadata' => [
                'thumbnail_candidates' => [[
                    'id' => 'candidate-1',
                    'timestamp' => 12.0,
                    'score' => 0.9,
                    'plain_path' => 'staged/candidate-plain.webp',
                ]],
            ],
        ]);
        $run = MediaProcessingLog::factory()->livestream()->create([
            'processing_id' => 'thumbnail-role-run',
            'processing_metadata' => [],
        ]);
        $plan = new HistoricProcessingResultImportPlan(
            classification: 'create',
            reason: 'test',
            planHash: str_repeat('a', 64),
            bundleHash: str_repeat('b', 64),
            service: ['media_graph' => ['processing_key' => 'thumbnail-role-run']],
            assets: [
                [
                    'path' => 'staged/plain.webp',
                    'size' => 5,
                    'sha256' => hash('sha256', 'plain'),
                    'kind' => 'thumbnail',
                    'roles' => ['publication:thumbnail-role-run:main:sermon:thumbnail_metadata:plain_thumbnail_path'],
                ],
                [
                    'path' => 'staged/candidate-plain.webp',
                    'size' => 14,
                    'sha256' => hash('sha256', 'candidate plain'),
                    'kind' => 'thumbnail',
                    'roles' => ['publication:thumbnail-role-run:main:sermon:thumbnail_candidate:0:plain_path'],
                ],
            ],
        );
        $persister = app(HistoricMediaGraphPersister::class);
        $allocate = new ReflectionMethod($persister, 'assetDestinations');
        $apply = new ReflectionMethod($persister, 'applyAllocatedPaths');

        /** @var array<string, string> $destinations */
        $destinations = $allocate->invoke($persister, $plan, $run, [], ['main' => $sermon]);

        $this->assertSame("sermons/{$sermon->id}/thumbnail-plain.webp", $destinations['publication:thumbnail-role-run:main:sermon:thumbnail_metadata:plain_thumbnail_path']);
        $this->assertSame("sermons/{$sermon->id}/thumbnail-candidate-0-plain.webp", $destinations['publication:thumbnail-role-run:main:sermon:thumbnail_candidate:0:plain_path']);
        $this->assertNotSame(
            $destinations['publication:thumbnail-role-run:main:sermon:thumbnail_metadata:plain_thumbnail_path'],
            $destinations['publication:thumbnail-role-run:main:sermon:thumbnail_candidate:0:plain_path'],
        );

        $apply->invoke($persister, $plan, $run, [], ['main' => $sermon], $destinations);
        $metadata = Sermon::query()->findOrFail($sermon->id)->thumbnail_metadata;

        $this->assertSame('sermons/'.$sermon->id.'/thumbnail-plain.webp', $metadata?->plainThumbnailPath);
        $this->assertSame(
            'sermons/'.$sermon->id.'/thumbnail-candidate-0-plain.webp',
            $metadata?->thumbnailCandidates[0]['plain_path'],
        );
    }

    #[Test]
    public function it_remaps_a_service_artifact_that_records_no_kind(): void
    {
        $artifactHash = hash('sha256', 'rms log');
        $run = MediaProcessingLog::factory()->livestream()->create([
            'processing_id' => 'artifact-role-run',
            'processing_metadata' => [
                'service_artifacts' => [[
                    'path' => 'staged/rms.log',
                    'sha256' => $artifactHash,
                ]],
            ],
        ]);
        /** The literal role the exporter emits for an artifact with no kind. */
        $role = "service_artifact:artifact:{$artifactHash}";
        $plan = $this->plan('artifact-role-run', [[
            'path' => 'staged/rms.log',
            'size' => 7,
            'sha256' => $artifactHash,
            'kind' => 'artifact',
            'roles' => [$role],
        ]]);
        $persister = app(HistoricMediaGraphPersister::class);

        $destinations = $this->invoke($persister, 'assetDestinations', [$plan, $run, [], []]);
        $this->invoke($persister, 'applyAllocatedPaths', [$plan, $run, [], [], $destinations]);

        $this->assertSame('service-transcripts/artifact-role-run/rms.log', $destinations[$role]);
        $this->assertSame(
            'service-transcripts/artifact-role-run/rms.log',
            MediaProcessingLog::query()->findOrFail($run->id)->processing_metadata?->toArray()['service_artifacts'][0]['path'],
        );
    }

    #[Test]
    public function it_refuses_to_allocate_one_destination_to_two_different_contents(): void
    {
        $run = MediaProcessingLog::factory()->livestream()->create([
            'processing_id' => 'collision-run',
            'processing_metadata' => [],
        ]);
        $plan = $this->plan('collision-run', [
            [
                'path' => 'staged/first/notes.txt',
                'size' => 5,
                'sha256' => hash('sha256', 'first'),
                'kind' => 'transcript',
                'roles' => ['run_transcript_file_path'],
            ],
            [
                'path' => 'staged/second/notes.txt',
                'size' => 6,
                'sha256' => hash('sha256', 'second'),
                'kind' => 'artifact',
                'roles' => ['service_artifact:rms:'.hash('sha256', 'second')],
            ],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already allocated to different content');

        $this->invoke(app(HistoricMediaGraphPersister::class), 'assetDestinations', [$plan, $run, [], []]);
    }

    #[Test]
    public function it_allows_shared_content_to_reuse_one_destination(): void
    {
        $run = MediaProcessingLog::factory()->livestream()->create([
            'processing_id' => 'shared-run',
            'processing_metadata' => [],
        ]);
        $sharedHash = hash('sha256', 'shared');
        $plan = $this->plan('shared-run', [[
            'path' => 'staged/full-service.mp3',
            'size' => 6,
            'sha256' => $sharedHash,
            'kind' => 'audio',
            'roles' => ['run_audio_file_path', 'service_artifact:audio:'.$sharedHash],
        ]]);

        $destinations = $this->invoke(
            app(HistoricMediaGraphPersister::class),
            'assetDestinations',
            [$plan, $run, [], []],
        );

        $this->assertSame(
            $destinations['run_audio_file_path'],
            $destinations['service_artifact:audio:'.$sharedHash],
        );
    }

    /**
     * @param  list<array<string, mixed>>  $assets
     */
    private function plan(string $processingKey, array $assets): HistoricProcessingResultImportPlan
    {
        return new HistoricProcessingResultImportPlan(
            classification: 'create',
            reason: 'test',
            planHash: str_repeat('a', 64),
            bundleHash: str_repeat('b', 64),
            service: ['media_graph' => ['processing_key' => $processingKey]],
            assets: $assets,
        );
    }

    /** @param list<mixed> $arguments */
    private function invoke(object $target, string $method, array $arguments): mixed
    {
        return (new ReflectionMethod($target, $method))->invokeArgs($target, $arguments);
    }
}
