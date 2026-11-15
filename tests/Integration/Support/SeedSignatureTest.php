<?php

declare(strict_types=1);

namespace Tests\Integration\Support;

use App\Exceptions\SeedResidueException;
use App\Models\ChurchServiceItem;
use App\Models\MediaProcessingLog;
use App\Models\Preacher;
use App\Models\PreacherAlias;
use App\Models\Sermon;
use App\Support\SeedSignature;
use Database\Seeders\PreacherSeeder;
use Database\Seeders\SermonSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SeedSignatureTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_signature_equals_what_the_seeders_write(): void
    {
        $this->seed(PreacherSeeder::class);
        $this->seed(SermonSeeder::class);

        $expected = SeedSignature::sermons();

        foreach ($expected as $identity => $values) {
            $sermon = Sermon::query()->where('slug', $values['slug'])->firstOrFail();

            $this->assertSame(
                $identity,
                SeedSignature::identity($sermon->date->toDateString(), $sermon->service->value, $sermon->content_type->value),
            );
            $this->assertSame($values['title'], $sermon->title);
            $this->assertSame($values['reference'], $sermon->reference);
            $this->assertSame($values['series'] ?? null, $sermon->series);
            $this->assertSame($values['points'] ?? null, $sermon->points);
            $this->assertSame($values['preacher_source'], $sermon->preacher_source->value);
            $this->assertNotSame([], SeedSignature::matchesSermon($sermon), "seeded sermon {$values['slug']} not recognised");
        }

        $this->assertSame(
            count($expected),
            Sermon::query()->whereIn('slug', array_column($expected, 'slug'))->count(),
        );

        $seededOnly = Preacher::query()->whereNotIn('name', ['Mark Drury', 'Visiting Speaker'])->pluck('name')->sort()->values()->all();
        $expectedNames = SeedSignature::preacherNames();
        sort($expectedNames);
        $this->assertSame($expectedNames, $seededOnly);

        $aliasOwner = PreacherAlias::query()->where('alias', SeedSignature::ALIAS)->firstOrFail();
        $this->assertSame('Visiting Speaker', $aliasOwner->preacher->name);

        $run = MediaProcessingLog::query()->where('processing_id', SeedSignature::PROCESSING_ID)->firstOrFail();
        $this->assertSame(SeedSignature::PROCESSING_ID, $run->processing_id);

        $item = ChurchServiceItem::query()->where('type', SeedSignature::SERVICE_ITEM_TYPE)->sole();
        $this->assertSame(SeedSignature::SERVICE_ITEM_TITLE, $item->title);
        $this->assertSame(SeedSignature::SERVICE_ITEM_POSITION, $item->position);
        $this->assertSame(SeedSignature::SERVICE_DATE, $item->churchService->date->toDateString());
    }

    #[Test]
    public function real_names_and_real_values_are_not_seed_signatures(): void
    {
        $this->assertFalse(SeedSignature::isSeededPreacherName('Mark Drury'));
        $this->assertFalse(SeedSignature::isSeededPreacherName('Visiting Speaker'));
        $this->assertTrue(SeedSignature::isSeededPreacherName('Paul Wilson'));
        $this->assertTrue(SeedSignature::isSeededAlias('Guest Speaker'));
        $this->assertFalse(SeedSignature::isSeededAlias('visiting speaker'));
        $this->assertTrue(SeedSignature::isSeededProcessingId('seed-prodigal-son-processing'));
        $this->assertFalse(SeedSignature::isSeededProcessingId('00000000-0000-0000-0000-000000000011'));
    }

    #[Test]
    public function a_value_matches_only_for_its_seeded_identity(): void
    {
        $title = ['title' => 'The Prodigal Son', 'slug' => 'the-prodigal-son'];

        $this->assertSame(
            $title,
            SeedSignature::matches('2024-11-24', 'morning', 'sermon', $title),
        );
        $this->assertSame([], SeedSignature::matches('2025-03-02', 'morning', 'sermon', $title));
        $this->assertSame([], SeedSignature::matches('2024-11-24', 'evening', 'sermon', $title));
        $this->assertSame([], SeedSignature::matches('2024-11-24', 'morning', 'childrens_talk', $title));
    }

    #[Test]
    public function a_shared_reference_alone_is_not_seeded(): void
    {
        $this->assertSame([], SeedSignature::matches('2024-12-15', 'morning', 'sermon', [
            'title' => 'God’s promises to Mary', 'slug' => 'gods-promises-to-mary', 'reference' => 'Luke 2:1-20', 'series' => null,
        ]));
    }

    #[Test]
    public function a_row_with_a_seeded_slug_reports_its_seeded_reference_too(): void
    {
        $this->assertSame(
            ['slug' => 'walking-in-the-light', 'reference' => '1 John 1:5-10'],
            SeedSignature::matches('2024-12-08', 'morning', 'sermon', [
                'title' => 'The lost older son', 'slug' => 'walking-in-the-light', 'reference' => '1 John 1:5-10', 'series' => null,
            ]),
        );
    }

    #[Test]
    public function a_json_encoded_points_value_is_compared_decoded(): void
    {
        $points = '1. Praise\n2. Thanksgiving';

        $this->assertSame(
            ['points' => $points],
            SeedSignature::matches('2024-12-01', 'other', 'sermon', ['points' => json_encode($points)]),
        );
    }

    #[Test]
    public function a_publication_with_a_seeded_alias_is_refused_and_named(): void
    {
        $publication = [
            'date' => '2026-08-02', 'service' => 'morning', 'content_type' => 'sermon', 'slug' => 'real', 'title' => 'Real',
            'preacher' => ['name' => 'Visiting Speaker', 'slug' => 'visiting-speaker', 'aliases' => ['guest speaker', 'visiting speaker']],
        ];

        $this->expectException(SeedResidueException::class);
        $this->expectExceptionMessage("preacher alias 'guest speaker'");

        SeedSignature::assertPublicationClean($publication, 'Test');
    }
}
