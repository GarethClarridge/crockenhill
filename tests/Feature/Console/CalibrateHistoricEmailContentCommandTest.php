<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Services\ChurchService\HistoricItemGroundTruth;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CalibrateHistoricEmailContentCommandTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/email-content-calibration-'.bin2hex(random_bytes(6));
        mkdir($this->root);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob("{$this->root}/*") ?: []);
        rmdir($this->root);

        parent::tearDown();
    }

    #[Test]
    public function it_writes_an_immutable_report_only_calibration_artifact(): void
    {
        $report = "{$this->root}/report.json";
        $truth = "{$this->root}/truth.json";
        $output = "{$this->root}/calibration.json";
        file_put_contents($report, json_encode(['entries' => [[
            'content_scope' => 'full',
            'plans' => [['date' => '2023-01-01', 'service' => 'morning', 'confidence' => 0.95]],
        ]]], JSON_THROW_ON_ERROR));
        file_put_contents($truth, json_encode([
            'format' => HistoricItemGroundTruth::Format,
            'identities' => [[
                'key' => '2023-01-01 morning',
                'verdicts' => ['song_membership' => 'match'],
            ]],
        ], JSON_THROW_ON_ERROR));

        $this->artisan('service-tracking:calibrate-historic-email-content', [
            '--archive-report' => $report,
            '--ground-truth' => $truth,
            '--output' => $output,
        ])->expectsOutputToContain('Live auto-import gate: unchanged')->assertSuccessful();

        $artifact = json_decode((string) file_get_contents($output), true, flags: JSON_THROW_ON_ERROR);
        $this->assertFalse($artifact['policy']['live_gate_changed']);

        $this->artisan('service-tracking:calibrate-historic-email-content', [
            '--archive-report' => $report,
            '--ground-truth' => $truth,
            '--output' => $output,
        ])->assertFailed();
    }
}
