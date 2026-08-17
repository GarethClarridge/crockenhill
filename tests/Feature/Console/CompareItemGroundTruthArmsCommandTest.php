<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CompareItemGroundTruthArmsCommandTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $root = sys_get_temp_dir().'/arm-comparison-'.bin2hex(random_bytes(6));
        mkdir($root);
        $this->root = $root;
    }

    #[Test]
    public function it_reports_the_transitions_between_two_arms(): void
    {
        $baseline = $this->artifact([
            $this->identity('2023-01-01', 'morning', 'indeterminate', songItems: 0),
            $this->identity('2023-01-08', 'morning', 'mismatch'),
        ]);

        $candidate = $this->artifact([
            $this->identity('2023-01-01', 'morning', 'match'),
            $this->identity('2023-01-08', 'morning', 'match'),
        ]);

        $output = "{$this->root}/comparison.json";

        $this->artisan('service-tracking:compare-ground-truth-arms', [
            '--baseline' => $baseline,
            '--candidate' => $candidate,
            '--output' => $output,
        ])
            ->expectsOutputToContain('Shared identities: 2')
            ->expectsOutputToContain('Total extraction failures fixed: 1')
            ->assertExitCode(0);

        $report = json_decode((string) file_get_contents($output), true);
        $membership = $report['dimensions']['song_membership'];

        $this->assertSame(2, $membership['population']);
        $this->assertSame(1, $membership['transitions']['indeterminate']['match']);
        $this->assertSame(1, $membership['transitions']['mismatch']['match']);
        $this->assertSame(2, $membership['discordance']['only_candidate_correct']);
    }

    #[Test]
    public function it_refuses_to_overwrite_an_existing_report(): void
    {
        $artifact = $this->artifact([$this->identity('2023-01-01', 'morning', 'match')]);
        $output = "{$this->root}/comparison.json";
        file_put_contents($output, 'already here');

        $this->artisan('service-tracking:compare-ground-truth-arms', [
            '--baseline' => $artifact,
            '--candidate' => $artifact,
            '--output' => $output,
        ])
            ->expectsOutputToContain('Refusing to overwrite')
            ->assertExitCode(1);

        $this->assertSame('already here', file_get_contents($output));
    }

    #[Test]
    public function it_fails_when_an_arm_artifact_is_missing(): void
    {
        $this->artisan('service-tracking:compare-ground-truth-arms', [
            '--baseline' => "{$this->root}/absent.json",
            '--candidate' => $this->artifact([$this->identity('2023-01-01', 'morning', 'match')]),
        ])
            ->expectsOutputToContain('No baseline artifact at')
            ->assertExitCode(1);
    }

    /** @param list<array<string, mixed>> $identities */
    private function artifact(array $identities): string
    {
        $path = "{$this->root}/arm-".bin2hex(random_bytes(4)).'.json';
        file_put_contents($path, json_encode(['identities' => $identities], JSON_THROW_ON_ERROR));

        return $path;
    }

    /** @return array<string, mixed> */
    private function identity(string $date, string $service, string $verdict, int $songItems = 3): array
    {
        return [
            'date' => $date,
            'service' => $service,
            'staged' => ['song_item_count' => $songItems],
            'hymn_workbook' => ['statements' => 3],
            'openlp' => ['item_key' => "{$date}-{$service}"],
            'verdicts' => [
                'song_membership' => $verdict,
                'song_count' => $verdict,
                'song_order' => $verdict,
            ],
        ];
    }
}
