<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Contracts\Console\Kernel;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RunOosParserArmCommandTest extends TestCase
{
    #[Test]
    public function the_command_is_registered(): void
    {
        $this->assertArrayHasKey(
            'service-tracking:run-oos-parser-arm',
            $this->app[Kernel::class]->all(),
        );
    }

    #[Test]
    public function it_requires_the_declared_arm_before_it_can_inspect_a_manifest(): void
    {
        $this->artisan('service-tracking:run-oos-parser-arm', [
            '--manifest' => '/not-used.json',
            '--price-snapshot' => '/not-used-prices.json',
            '--output' => 'baseline-nano-none',
        ])
            ->expectsOutputToContain('--arm is required')
            ->assertExitCode(1);
    }

    #[Test]
    public function it_refuses_an_output_that_can_escape_its_private_run_root(): void
    {
        $this->artisan('service-tracking:run-oos-parser-arm', [
            '--arm' => 'baseline-nano-none',
            '--manifest' => '/not-used.json',
            '--price-snapshot' => '/not-used-prices.json',
            '--output' => '../outside',
        ])
            ->expectsOutputToContain('--output must be a new lowercase run-directory name')
            ->assertExitCode(1);
    }

    #[Test]
    public function it_requires_the_dated_price_snapshot_the_arms_are_frozen_against(): void
    {
        $this->artisan('service-tracking:run-oos-parser-arm', [
            '--arm' => 'baseline-nano-none',
            '--manifest' => '/not-used.json',
            '--output' => 'baseline-nano-none',
        ])
            ->expectsOutputToContain('--price-snapshot is required')
            ->assertExitCode(1);
    }

    /**
     * A stability-only diagnostic needs a private run directory just as a full arm does. Its whole
     * purpose is the field-by-field decomposition of the disagreeing replicate pairs, and printing
     * that to a console the run then discards would leave the open question — *which* fields drive
     * the self-disagreement rate — exactly as unanswerable as before the mode existed. An earlier
     * version of this mode wrote nothing, which is the defect this asserts against.
     */
    #[Test]
    public function stability_only_still_requires_a_private_run_directory_to_retain_its_diagnostic(): void
    {
        $this->artisan('service-tracking:run-oos-parser-arm', [
            '--arm' => 'baseline-nano-none',
            '--manifest' => '/not-used.json',
            '--price-snapshot' => '/not-used-prices.json',
            '--stability-only' => true,
        ])
            ->expectsOutputToContain('--output is required')
            ->assertExitCode(1);
    }
}
