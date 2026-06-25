<?php

declare(strict_types=1);

namespace Tests\Unit\Data;

use App\Data\SongCluster;
use App\Data\SongClusterCollection;
use App\Data\SongClusterCollectionCast;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SongClusterDataTest extends TestCase
{
    /** @return array<string, mixed> */
    private function validClusterArray(): array
    {
        return [
            'start_estimate' => 120.0,
            'end_estimate' => 360.0,
            'samples' => [0.1, 0.2, 0.3],
            'sample_count' => 3,
            'confidence' => 0.87,
        ];
    }

    // ── SongCluster::fromArray ─────────────────────────────────────────────────

    #[Test]
    public function it_creates_from_valid_array(): void
    {
        $cluster = SongCluster::fromArray($this->validClusterArray());

        $this->assertInstanceOf(SongCluster::class, $cluster);
        $this->assertSame(120.0, $cluster->startEstimate);
        $this->assertSame(360.0, $cluster->endEstimate);
        $this->assertSame([0.1, 0.2, 0.3], $cluster->samples);
        $this->assertSame(3, $cluster->sampleCount);
        $this->assertSame(0.87, $cluster->confidence);
    }

    #[Test]
    public function it_returns_null_when_required_fields_missing(): void
    {
        $this->assertNull(SongCluster::fromArray(['start_estimate' => 1.0, 'end_estimate' => 2.0])); // no samples
        $this->assertNull(SongCluster::fromArray(['start_estimate' => 1.0, 'samples' => [0.1]])); // no end_estimate
        $this->assertNull(SongCluster::fromArray(null));
        $this->assertNull(SongCluster::fromArray('string'));
    }

    #[Test]
    public function it_casts_samples_to_floats(): void
    {
        $cluster = SongCluster::fromArray([
            'start_estimate' => 0.0,
            'end_estimate' => 10.0,
            'samples' => ['0.5', '1', 2],
        ]);

        $this->assertSame([0.5, 1.0, 2.0], $cluster->samples);
    }

    #[Test]
    public function it_round_trips_via_to_array(): void
    {
        $input = $this->validClusterArray();
        $output = SongCluster::fromArray($input)->toArray();

        $this->assertSame(120.0, $output['start_estimate']);
        $this->assertSame(360.0, $output['end_estimate']);
        $this->assertSame(0.87, $output['confidence']);
    }

    #[Test]
    public function it_derives_sample_count_from_samples_when_not_provided(): void
    {
        $cluster = SongCluster::fromArray([
            'start_estimate' => 0.0,
            'end_estimate' => 5.0,
            'samples' => [0.1, 0.2],
        ]);

        $this->assertSame(2, $cluster->toArray()['sample_count']);
    }

    #[Test]
    public function it_includes_optional_refined_fields_in_to_array(): void
    {
        $cluster = SongCluster::fromArray([
            'start_estimate' => 0.0,
            'end_estimate' => 10.0,
            'samples' => [0.1],
            'refined_visual_start' => 1.5,
            'refined_visual_end' => 9.0,
            'dense_sample_count' => 50,
        ]);

        $arr = $cluster->toArray();
        $this->assertSame(1.5, $arr['refined_visual_start']);
        $this->assertSame(9.0, $arr['refined_visual_end']);
        $this->assertSame(50, $arr['dense_sample_count']);
    }

    // ── SongClusterCollection ─────────────────────────────────────────────────

    #[Test]
    public function collection_creates_from_valid_array(): void
    {
        $collection = SongClusterCollection::fromArray([
            $this->validClusterArray(),
            array_merge($this->validClusterArray(), ['start_estimate' => 500.0, 'end_estimate' => 700.0]),
        ]);

        $this->assertInstanceOf(SongClusterCollection::class, $collection);
        $this->assertCount(2, $collection);
    }

    #[Test]
    public function collection_skips_invalid_cluster_entries(): void
    {
        $collection = SongClusterCollection::fromArray([
            $this->validClusterArray(),
            ['start_estimate' => 1.0], // missing end_estimate and samples
            'not_an_array',
        ]);

        $this->assertCount(1, $collection);
    }

    #[Test]
    public function collection_returns_null_for_non_array(): void
    {
        $this->assertNull(SongClusterCollection::fromArray(null));
        $this->assertNull(SongClusterCollection::fromArray('string'));
    }

    #[Test]
    public function collection_is_iterable(): void
    {
        $collection = SongClusterCollection::fromArray([$this->validClusterArray()]);

        $count = 0;
        foreach ($collection as $cluster) {
            $this->assertInstanceOf(SongCluster::class, $cluster);
            $count++;
        }

        $this->assertSame(1, $count);
    }

    #[Test]
    public function collection_to_array_serialises_all_clusters(): void
    {
        $collection = SongClusterCollection::fromArray([
            $this->validClusterArray(),
        ]);

        $arr = $collection->toArray();
        $this->assertIsArray($arr);
        $this->assertCount(1, $arr);
        $this->assertSame(120.0, $arr[0]['start_estimate']);
    }

    #[Test]
    public function collection_throws_on_array_write(): void
    {
        $collection = SongClusterCollection::fromArray([$this->validClusterArray()]);

        $this->expectException(LogicException::class);
        $collection['key'] = 'value'; // @phpstan-ignore-line
    }

    // ── SongClusterCollectionCast ─────────────────────────────────────────────

    #[Test]
    public function cast_get_deserialises_json_to_collection(): void
    {
        $cast = new SongClusterCollectionCast;
        $model = $this->createStub(Model::class);

        $json = json_encode([$this->validClusterArray()]);
        $result = $cast->get($model, 'clusters', $json, []);

        $this->assertInstanceOf(SongClusterCollection::class, $result);
        $this->assertCount(1, $result);
    }

    #[Test]
    public function cast_get_returns_null_for_empty_value(): void
    {
        $cast = new SongClusterCollectionCast;
        $model = $this->createStub(Model::class);

        $this->assertNull($cast->get($model, 'clusters', '', []));
        $this->assertNull($cast->get($model, 'clusters', null, []));
    }

    #[Test]
    public function cast_set_serialises_collection_to_json(): void
    {
        $cast = new SongClusterCollectionCast;
        $model = $this->createStub(Model::class);

        $collection = SongClusterCollection::fromArray([$this->validClusterArray()]);
        $result = $cast->set($model, 'clusters', $collection, []);

        $this->assertIsString($result);
        $decoded = json_decode($result, true);
        $this->assertIsArray($decoded);
        $this->assertCount(1, $decoded);
    }

    #[Test]
    public function cast_set_returns_null_for_null(): void
    {
        $cast = new SongClusterCollectionCast;
        $model = $this->createStub(Model::class);

        $this->assertNull($cast->set($model, 'clusters', null, []));
    }

    #[Test]
    public function cast_round_trips_collection(): void
    {
        $cast = new SongClusterCollectionCast;
        $model = $this->createStub(Model::class);

        $original = SongClusterCollection::fromArray([$this->validClusterArray()]);
        $json = $cast->set($model, 'clusters', $original, []);
        $restored = $cast->get($model, 'clusters', $json, []);

        $this->assertCount(1, $restored);
        $this->assertSame(120.0, $restored->items[0]->startEstimate);
    }
}
