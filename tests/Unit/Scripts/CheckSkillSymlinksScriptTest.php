<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class CheckSkillSymlinksScriptTest extends TestCase
{
    private string $projectRoot;

    private string $scriptPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->projectRoot = dirname(__DIR__, 3);
        $this->scriptPath = $this->projectRoot.'/scripts/check-skill-symlinks.sh';
    }

    #[Test]
    public function it_passes_for_the_repository_skill_symlinks(): void
    {
        $process = $this->runScript($this->projectRoot);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput().$process->getOutput());
        $this->assertStringContainsString('Skill symlink check passed.', $process->getOutput());
    }

    #[Test]
    public function it_fails_when_a_tracked_skill_symlink_is_dangling(): void
    {
        $repository = sys_get_temp_dir().'/skill-symlink-test-'.bin2hex(random_bytes(8));
        mkdir($repository.'/skills/example', 0777, true);
        file_put_contents($repository.'/skills/source.md', '# Skill');
        symlink('../source.md', $repository.'/skills/example/SKILL.md');

        $this->runGit($repository, ['init']);
        $this->runGit($repository, ['add', '.']);
        unlink($repository.'/skills/source.md');

        $process = $this->runScript($repository);

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString(
            'Tracked skill symlink does not resolve to a readable file: skills/example/SKILL.md',
            $process->getOutput(),
        );
    }

    private function runScript(string $repository): Process
    {
        $process = new Process(['bash', $this->scriptPath, $repository], $this->projectRoot);
        $process->run();

        return $process;
    }

    /** @param array<int, string> $arguments */
    private function runGit(string $repository, array $arguments): void
    {
        $process = new Process(['git', ...$arguments], $repository);
        $process->mustRun();
    }
}
