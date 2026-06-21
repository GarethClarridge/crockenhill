<?php

declare(strict_types=1);

namespace Tests\Feature\Operations;

use App\Enums\SermonService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SectionExtractionScriptsTest extends TestCase
{
    #[Test]
    public function section_extraction_runners_are_tracked_outside_scratch(): void
    {
        $trackedScripts = [
            'scenarios.php',
            'run-init.php',
            'run-step2.php',
            'run-downstream.php',
            'run-cleanup.php',
            'verify-classifier.php',
        ];

        foreach ($trackedScripts as $script) {
            $this->assertFileExists(base_path("scripts/section-extraction/{$script}"));
        }

        $this->assertFileDoesNotExist(base_path('storage/scratch/run_init.php'));
        $this->assertFileDoesNotExist(base_path('storage/scratch/run_step2.php'));
        $this->assertFileDoesNotExist(base_path('storage/scratch/run_downstream.php'));
        $this->assertFileDoesNotExist(base_path('storage/scratch/run_cleanup.php'));
        $this->assertFileDoesNotExist(base_path('storage/scratch/verify_classifier.php'));
    }

    #[Test]
    public function runners_share_the_tracked_scenario_configuration(): void
    {
        $runnerScripts = [
            'run-init.php',
            'run-step2.php',
            'run-downstream.php',
            'run-cleanup.php',
            'verify-classifier.php',
        ];

        foreach ($runnerScripts as $script) {
            $contents = file_get_contents(base_path("scripts/section-extraction/{$script}"));

            $this->assertIsString($contents);
            $this->assertStringContainsString(
                'scripts/section-extraction/scenarios.php',
                $contents,
                "{$script} must use the shared scenario configuration."
            );
        }
    }

    #[Test]
    public function scenario_configuration_contains_the_required_contract(): void
    {
        /** @var array<string, array{
         *     label: string,
         *     video: string,
         *     date: string,
         *     service: SermonService,
         *     pid_file: string,
         *     expected_service_id: int,
         *     expected_status: ?string,
         *     expected_step: ?string,
         *     expected_section_count: ?int,
         *     expected_confirmed_songs: ?int,
         *     expected_childrens_talks: ?int,
         *     expected_sermon_range: ?array{float, float},
         * }> $scenarios
         */
        $scenarios = require base_path('scripts/section-extraction/scenarios.php');

        $this->assertSame(
            ['may24', 'jun14', 'apr23', 'jan24', 'nov24', 'mar25', 'sep25', 'dec25', 'mar26'],
            array_keys($scenarios)
        );

        foreach ($scenarios as $scenario) {
            $this->assertIsString($scenario['label']);
            $this->assertIsString($scenario['video']);
            $this->assertIsString($scenario['date']);
            $this->assertInstanceOf(SermonService::class, $scenario['service']);
            $this->assertIsString($scenario['pid_file']);
            $this->assertIsInt($scenario['expected_service_id']);
            $this->assertArrayHasKey('expected_status', $scenario);
            $this->assertArrayHasKey('expected_step', $scenario);
            $this->assertArrayHasKey('expected_section_count', $scenario);
            $this->assertArrayHasKey('expected_confirmed_songs', $scenario);
            $this->assertArrayHasKey('expected_childrens_talks', $scenario);
            $this->assertArrayHasKey('expected_sermon_range', $scenario);
        }
    }
}
