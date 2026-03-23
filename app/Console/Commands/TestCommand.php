<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Dotenv\Exception\InvalidPathException;
use Dotenv\Parser\Parser;
use Dotenv\Store\StoreBuilder;
use Illuminate\Console\Command;
use Illuminate\Support\Env;
use Illuminate\Support\Str;
use NunoMaduro\Collision\Adapters\Laravel\Exceptions\RequirementsException;
use NunoMaduro\Collision\Coverage;
use ParaTest\Options;
use RuntimeException;
use SebastianBergmann\Environment\Console as EnvironmentConsole;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Process;

class TestCommand extends Command
{
    private const DEFAULT_PARALLEL_PROCESSES = 4;

    protected $signature = 'test
        {--without-tty : Disable output to TTY}
        {--compact : Indicates whether the compact printer should be used}
        {--coverage : Indicates whether code coverage information should be collected}
        {--min= : Indicates the minimum threshold enforcement for code coverage}
        {--p|parallel : Indicates if the tests should run in parallel}
        {--profile : Lists top 10 slowest tests}
        {--recreate-databases : Indicates if the test databases should be re-created}
        {--drop-databases : Indicates if the test databases should be dropped}
        {--without-databases : Indicates if database configuration should be performed}
    ';

    protected $description = 'Run the application tests';

    public function __construct()
    {
        parent::__construct();

        $this->ignoreValidationErrors();
    }

    public function handle(): int
    {
        if ($this->option('coverage') && ! Coverage::isAvailable()) {
            $this->output->writeln(sprintf(
                "\n  <fg=white;bg=red;options=bold> ERROR </> Code coverage driver not available.%s</>",
                Coverage::usingXdebug()
                    ? " Did you set <href=https://xdebug.org/docs/code_coverage#mode>Xdebug's coverage mode</>?"
                    : ' Did you install <href=https://xdebug.org/>Xdebug</> or <href=https://github.com/krakjoe/pcov>PCOV</>?'
            ));

            $this->newLine();

            return 1;
        }

        $usesParallel = (bool) $this->option('parallel');

        if ($usesParallel && ! $this->isParallelDependenciesInstalled()) {
            throw new RequirementsException('Running Collision 8.x artisan test command in parallel requires at least ParaTest (brianium/paratest) 7.x.');
        }

        $options = array_slice($_SERVER['argv'], $this->option('without-tty') ? 3 : 2);

        $this->clearEnv();

        $process = (new Process(array_merge(
            $this->binary(),
            $usesParallel ? $this->paratestArguments($options) : $this->phpunitArguments($options)
        ),
            null,
            $usesParallel ? $this->paratestEnvironmentVariables() : $this->phpunitEnvironmentVariables(),
        ))->setTimeout(null);

        try {
            $process->setTty(! $this->option('without-tty'));
        } catch (RuntimeException) {
        }

        $exitCode = 1;

        try {
            $exitCode = $process->run(function (string $type, string $line): void {
                $this->output->write($line);
            });
        } catch (ProcessSignaledException $e) {
            if (extension_loaded('pcntl') && $e->getSignal() !== SIGINT) {
                throw $e;
            }
        }

        if ($exitCode === 0 && $this->option('coverage')) {
            if (! $this->usingPest() && $usesParallel) {
                $this->newLine();
            }

            $hideFullCoverage = (bool) $this->option('compact');
            $coverage = Coverage::report($this->output, $hideFullCoverage);

            $exitCode = (int) ($coverage < $this->option('min'));

            if ($exitCode === 1) {
                $this->output->writeln(sprintf(
                    "\n  <fg=white;bg=red;options=bold> FAIL </> Code coverage below expected:<fg=red;options=bold> %s %%</>. Minimum:<fg=white;options=bold> %s %%</>.",
                    number_format($coverage, 1),
                    number_format((float) $this->option('min'), 1)
                ));
            }
        }

        return $exitCode;
    }

    /**
     * @param  array<int, string>  $options
     * @return array<int, string>
     */
    protected function paratestArguments(array $options): array
    {
        $options = array_values(array_filter($this->withDefaultProcessLimit($options), function (string $option): bool {
            return ! Str::startsWith($option, '--env=')
                && $option !== '--coverage'
                && $option !== '-q'
                && $option !== '--quiet'
                && $option !== '--ansi'
                && $option !== '--no-ansi'
                && ! Str::startsWith($option, '--min')
                && ! Str::startsWith($option, '-p')
                && ! Str::startsWith($option, '--compact')
                && ! Str::startsWith($option, '--parallel')
                && ! Str::startsWith($option, '--recreate-databases')
                && ! Str::startsWith($option, '--drop-databases')
                && ! Str::startsWith($option, '--without-databases');
        }));

        $options = array_merge($this->commonArguments(), [
            '--configuration='.$this->getConfigurationFile(),
            '--runner=\Illuminate\Testing\ParallelRunner',
        ], $options);

        $inputDefinition = new InputDefinition();
        Options::setInputDefinition($inputDefinition);
        $input = new ArgvInput($options, $inputDefinition);

        $paraTestOptions = Options::fromConsoleInput($input, base_path());

        if (! $paraTestOptions->configuration->hasCoverageCacheDirectory()) {
            $cacheDirectory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'__laravel_test_cache_directory';
            $options[] = '--cache-directory';
            $options[] = $cacheDirectory;
        }

        return $options;
    }

    /**
     * @return array<int, string>
     */
    protected function binary(): array
    {
        if ($this->usingPest()) {
            $command = $this->option('parallel') ? ['vendor/pestphp/pest/bin/pest', '--parallel'] : ['vendor/pestphp/pest/bin/pest'];
        } else {
            $command = $this->option('parallel') ? ['vendor/brianium/paratest/bin/paratest'] : ['vendor/phpunit/phpunit/phpunit'];
        }

        if (PHP_SAPI === 'phpdbg') {
            return array_merge([PHP_BINARY, '-qrr'], $command);
        }

        return array_merge([PHP_BINARY], $command);
    }

    /**
     * @return array<int, string>
     */
    protected function commonArguments(): array
    {
        $arguments = [];

        if ($this->option('coverage')) {
            $arguments[] = '--coverage-php';
            $arguments[] = Coverage::getPath();
        }

        if ($this->option('ansi')) {
            $arguments[] = '--colors=always';
        } elseif ($this->option('no-ansi')) {
            $arguments[] = '--colors=never';
        } elseif ((new EnvironmentConsole())->hasColorSupport()) {
            $arguments[] = '--colors=always';
        }

        return $arguments;
    }

    protected function usingPest(): bool
    {
        return function_exists('\Pest\version');
    }

    /**
     * @param  array<int, string>  $options
     * @return array<int, string>
     */
    protected function phpunitArguments(array $options): array
    {
        $options = array_merge(['--no-output'], $options);

        $options = array_values(array_filter($options, function (string $option): bool {
            return ! Str::startsWith($option, '--env=')
                && $option !== '-q'
                && $option !== '--quiet'
                && $option !== '--coverage'
                && $option !== '--compact'
                && $option !== '--profile'
                && $option !== '--ansi'
                && $option !== '--no-ansi'
                && ! Str::startsWith($option, '--min');
        }));

        return array_merge($this->commonArguments(), ['--configuration='.$this->getConfigurationFile()], $options);
    }

    protected function getConfigurationFile(): string
    {
        if (! file_exists($file = base_path('phpunit.xml'))) {
            $file = base_path('phpunit.xml.dist');
        }

        return $file;
    }

    /**
     * @return array<string, bool|int|string>
     */
    protected function phpunitEnvironmentVariables(): array
    {
        $variables = [
            'COLLISION_PRINTER' => 'DefaultPrinter',
        ];

        if ($this->option('compact')) {
            $variables['COLLISION_PRINTER_COMPACT'] = 'true';
        }

        if ($this->option('profile')) {
            $variables['COLLISION_PRINTER_PROFILE'] = 'true';
        }

        return $variables;
    }

    /**
     * @return array<string, bool|int|string>
     */
    protected function paratestEnvironmentVariables(): array
    {
        return [
            'LARAVEL_PARALLEL_TESTING' => 1,
            'LARAVEL_PARALLEL_TESTING_RECREATE_DATABASES' => $this->option('recreate-databases'),
            'LARAVEL_PARALLEL_TESTING_DROP_DATABASES' => $this->option('drop-databases'),
            'LARAVEL_PARALLEL_TESTING_WITHOUT_DATABASES' => $this->option('without-databases'),
        ];
    }

    protected function clearEnv(): void
    {
        if (! $this->option('env')) {
            $vars = self::getEnvironmentVariables(
                $this->laravel->environmentPath(),
                $this->laravel->environmentFile()
            );

            $repository = Env::getRepository();

            foreach ($vars as $name) {
                $repository->clear($name);
            }
        }
    }

    /**
     * @return array<int, string>
     */
    protected static function getEnvironmentVariables(string $path, string $file): array
    {
        try {
            $content = StoreBuilder::createWithNoNames()
                ->addPath($path)
                ->addName($file)
                ->make()
                ->read();
        } catch (InvalidPathException) {
            return [];
        }

        $vars = [];

        foreach ((new Parser())->parse($content) as $entry) {
            $vars[] = $entry->getName();
        }

        return $vars;
    }

    protected function isParallelDependenciesInstalled(): bool
    {
        return class_exists(\ParaTest\ParaTestCommand::class);
    }

    /**
     * @param  array<int, string>  $options
     * @return array<int, string>
     */
    protected function withDefaultProcessLimit(array $options): array
    {
        if ($this->hasExplicitProcessLimit($options)) {
            return $options;
        }

        $options[] = '--processes='.self::DEFAULT_PARALLEL_PROCESSES;

        return $options;
    }

    /**
     * @param  array<int, string>  $options
     */
    private function hasExplicitProcessLimit(array $options): bool
    {
        foreach ($options as $index => $option) {
            if ($option === '--processes') {
                return isset($options[$index + 1]) && $options[$index + 1] !== '';
            }

            if (str_starts_with($option, '--processes=')) {
                return true;
            }
        }

        return false;
    }
}
