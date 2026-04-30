<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class CheckBootstrapSafetyScriptTest extends TestCase
{
    private string $scriptPath;

    private string $projectRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->projectRoot = dirname(__DIR__, 3);
        $this->scriptPath = $this->projectRoot.'/scripts/check-bootstrap-safety.sh';
    }

    #[Test]
    public function it_passes_against_the_current_bootstrap_file(): void
    {
        $process = $this->runScript($this->projectRoot.'/bootstrap/app.php');

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput().$process->getOutput());
        $this->assertStringContainsString('Bootstrap middleware safety check passed.', $process->getOutput());
    }

    #[Test]
    public function it_fails_for_direct_config_usage_inside_with_middleware(): void
    {
        $process = $this->runScript($this->fixturePath('unsafe-direct-config-app.php'));

        $this->assertSame(1, $process->getExitCode(), $process->getErrorOutput().$process->getOutput());
        $this->assertStringContainsString('Unsafe direct config() usage detected', $process->getOutput());
        $this->assertStringContainsString("config('app.trusted_proxies')", $process->getOutput());
    }

    #[Test]
    public function it_allows_deferred_config_usage_inside_an_arrow_function(): void
    {
        $process = $this->runScript($this->fixturePath('safe-deferred-config-app.php'));

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput().$process->getOutput());
    }

    #[Test]
    public function it_allows_deferred_config_usage_inside_a_multiline_arrow_function(): void
    {
        $process = $this->runScript($this->fixturePath('safe-multiline-arrow-config-app.php'));

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput().$process->getOutput());
    }

    #[Test]
    public function it_allows_deferred_config_usage_inside_a_regular_closure(): void
    {
        $process = $this->runScript($this->fixturePath('safe-closure-config-app.php'));

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput().$process->getOutput());
    }

    #[Test]
    public function it_ignores_comment_only_mentions_of_config_inside_with_middleware(): void
    {
        $process = $this->runScript($this->fixturePath('safe-comment-app.php'));

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput().$process->getOutput());
    }

    #[Test]
    public function it_ignores_config_usage_outside_with_middleware(): void
    {
        $process = $this->runScript($this->fixturePath('safe-config-outside-middleware-app.php'));

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput().$process->getOutput());
    }

    private function runScript(string $path): Process
    {
        $process = new Process(['bash', $this->scriptPath, $path], $this->projectRoot);
        $process->run();

        return $process;
    }

    private function fixturePath(string $fixture): string
    {
        return $this->projectRoot.'/tests/Fixtures/Bootstrap/'.$fixture;
    }
}
