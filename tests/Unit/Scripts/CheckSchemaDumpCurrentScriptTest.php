<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class CheckSchemaDumpCurrentScriptTest extends TestCase
{
    private string $scriptPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scriptPath = dirname(__DIR__, 3).'/scripts/check-schema-dump-current.sh';
    }

    #[Test]
    public function it_preserves_new_migrations_in_its_stale_dump_guidance(): void
    {
        $project = $this->makeProject(
            migrations: ['2026_07_15_120000_add_example_column'],
            dumpedMigrations: [],
        );

        $process = $this->runScript($project);

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('2026_07_15_120000_add_example_column', $process->getOutput());
        $this->assertStringContainsString('vendor/bin/sail artisan migrate', $process->getOutput());
        $this->assertStringContainsString("vendor/bin/sail artisan schema:dump\n", $process->getOutput());
        $this->assertStringNotContainsString('schema:dump --prune', $process->getOutput());
        $this->assertStringContainsString('--prune option is reserved for a deliberate quarterly squash', $process->getOutput());
    }

    #[Test]
    public function it_passes_when_every_migration_is_in_the_dump(): void
    {
        $project = $this->makeProject(
            migrations: ['2026_07_15_120000_add_example_column'],
            dumpedMigrations: ['2026_07_15_120000_add_example_column'],
        );

        $process = $this->runScript($project);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput().$process->getOutput());
        $this->assertStringContainsString('Schema dump is current.', $process->getOutput());
    }

    #[Test]
    public function it_passes_when_the_migrations_directory_is_empty(): void
    {
        $project = $this->makeProject(migrations: [], dumpedMigrations: []);

        $process = $this->runScript($project);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput().$process->getOutput());
    }

    /**
     * @param  array<int, string>  $migrations
     * @param  array<int, string>  $dumpedMigrations
     */
    private function makeProject(array $migrations, array $dumpedMigrations): string
    {
        $project = sys_get_temp_dir().'/schema-dump-test-'.bin2hex(random_bytes(8));
        mkdir($project.'/database/migrations', 0777, true);
        mkdir($project.'/database/schema', 0777, true);

        foreach ($migrations as $migration) {
            file_put_contents($project."/database/migrations/{$migration}.php", '<?php');
        }

        $rows = array_map(
            static fn (string $migration): string => "('{$migration}', 1)",
            $dumpedMigrations,
        );

        file_put_contents($project.'/database/schema/mysql-schema.sql', implode("\n", $rows));

        return $project;
    }

    private function runScript(string $project): Process
    {
        $process = new Process(['bash', $this->scriptPath], $project);
        $process->run();

        return $process;
    }
}
