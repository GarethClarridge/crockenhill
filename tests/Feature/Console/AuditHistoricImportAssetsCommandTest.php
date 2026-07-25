<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Sermon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AuditHistoricImportAssetsCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_fails_for_an_unreferenced_manifest_prefix(): void
    {
        $report = $this->writeReport('historic-imports/orphan/');

        $this->artisan('audit:historic-import-assets', ['report' => $report])
            ->assertFailed()
            ->expectsOutputToContain('Unreferenced');
    }

    #[Test]
    public function it_succeeds_when_a_sermon_references_the_manifest_prefix(): void
    {
        Sermon::factory()->create([
            'video_file_path' => 'historic-imports/processing-id/sermon/video.mp4',
        ]);
        $report = $this->writeReport('historic-imports/processing-id/');

        $this->artisan('audit:historic-import-assets', ['report' => $report])
            ->assertSuccessful()
            ->expectsOutputToContain('no unreferenced');
    }

    private function writeReport(string $assetPrefix): string
    {
        $path = storage_path('framework/testing/historic-import-report-'.uniqid().'.json');
        file_put_contents($path, json_encode([
            'format' => 'crockenhill.historic-import-report',
            'items' => [['assets' => [$assetPrefix]]],
        ], JSON_THROW_ON_ERROR));

        return $path;
    }
}
