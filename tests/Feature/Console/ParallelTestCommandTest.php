<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Console\Commands\TestCommand as ProjectTestCommand;
use Illuminate\Contracts\Console\Kernel;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ParallelTestCommandTest extends TestCase
{
    #[Test]
    public function the_project_test_command_overrides_the_framework_test_command(): void
    {
        $command = $this->app->make(Kernel::class)->all()['test'];

        $this->assertInstanceOf(ProjectTestCommand::class, $command);
    }

    #[Test]
    public function it_adds_a_safe_default_process_limit_for_parallel_runs(): void
    {
        $command = new class extends ProjectTestCommand
        {
            /**
             * @param  array<int, string>  $options
             * @return array<int, string>
             */
            public function exposeWithDefaultProcessLimit(array $options): array
            {
                return $this->withDefaultProcessLimit($options);
            }
        };

        $this->assertSame(
            ['--parallel', '--processes=4'],
            $command->exposeWithDefaultProcessLimit(['--parallel'])
        );
    }

    #[Test]
    public function it_keeps_an_explicit_process_limit_intact(): void
    {
        $command = new class extends ProjectTestCommand
        {
            /**
             * @param  array<int, string>  $options
             * @return array<int, string>
             */
            public function exposeWithDefaultProcessLimit(array $options): array
            {
                return $this->withDefaultProcessLimit($options);
            }
        };

        $this->assertSame(
            ['--parallel', '--processes=6'],
            $command->exposeWithDefaultProcessLimit(['--parallel', '--processes=6'])
        );

        $this->assertSame(
            ['--parallel', '--processes', '3'],
            $command->exposeWithDefaultProcessLimit(['--parallel', '--processes', '3'])
        );
    }
}
