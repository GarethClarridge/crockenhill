<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Process\Process;

class CheckTypingBaselineScriptTest extends TestCase
{
    private string $scriptPath;

    private string $projectRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->projectRoot = dirname(__DIR__, 3);
        $this->scriptPath = $this->projectRoot.'/scripts/check-typing-baseline.php';
    }

    #[Test]
    public function it_passes_against_every_current_app_file(): void
    {
        $process = $this->runScript(...$this->appPhpFiles());

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput().$process->getOutput());
    }

    #[Test]
    public function it_allows_the_untyped_attributes_property_inherited_from_eloquent(): void
    {
        $process = $this->runScript($this->fixturePath('eloquent-model-with-attributes.php'));

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }

    #[Test]
    public function it_allows_the_untyped_policies_property_inherited_from_the_auth_provider(): void
    {
        $process = $this->runScript($this->fixturePath('auth-provider-with-policies.php'));

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }

    #[Test]
    public function it_flags_an_untyped_property_that_laravel_does_not_declare(): void
    {
        $process = $this->runScript($this->fixturePath('untyped-custom-property.php'));

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('Class property is missing an explicit type.', $process->getErrorOutput());
    }

    #[Test]
    public function it_flags_a_file_without_a_strict_types_declaration(): void
    {
        $process = $this->runScript($this->fixturePath('missing-strict-types.php'));

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('Missing declare(strict_types=1);', $process->getErrorOutput());
    }

    private function runScript(string ...$paths): Process
    {
        $process = new Process(['php', $this->scriptPath, ...$paths], $this->projectRoot);
        $process->run();

        return $process;
    }

    private function fixturePath(string $fixture): string
    {
        return $this->projectRoot.'/tests/Fixtures/TypingBaseline/'.$fixture;
    }

    /**
     * @return list<string>
     */
    private function appPhpFiles(): array
    {
        $files = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->projectRoot.'/app')) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }
}
