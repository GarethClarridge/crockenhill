<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SermonPublicationState;
use App\Models\Song;
use App\Models\SongUsageReport;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SongUsageReport> */
class SongUsageReportFactory extends Factory
{
    protected $model = SongUsageReport::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'song_id' => Song::factory(),
            'used_on' => $this->faker->date(),
            'reported_service' => null,
            'resolved_church_service_item_id' => null,
            'reported_title' => $this->faker->sentence(4),
            'reported_number' => null,
            'catalog_title' => null,
            'match_method' => 'title',
            'source_workbook' => 'Historic hymn usage.xlsx',
            'source_sheet' => '2007',
            'source_row' => $this->faker->unique()->numberBetween(1, 1000000),
            'source_fingerprint' => hash('sha256', $this->faker->unique()->uuid()),
            'metadata' => null,
            'publication_state' => SermonPublicationState::Published,
            'historic_import_operation_id' => null,
        ];
    }

    /**
     * Evidence as the historic hymn importer writes it: stored, admin-visible, and absent from
     * every public read until `historic-import:release-batch` publishes the batch.
     */
    public function quarantined(): static
    {
        return $this->state(fn (): array => [
            'publication_state' => SermonPublicationState::Quarantined,
        ]);
    }
}
