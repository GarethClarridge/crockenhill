<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\ParallelTestingProcessLimiter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ParallelTestingProcessLimiterTest extends TestCase
{
    #[Test]
    public function it_adds_a_default_process_limit_for_parallel_test_runs(): void
    {
        $this->assertSame(
            ['artisan', 'test', '--parallel', '--processes=4'],
            ParallelTestingProcessLimiter::apply(['artisan', 'test', '--parallel'])
        );
    }

    #[Test]
    public function it_supports_the_short_parallel_flag(): void
    {
        $this->assertSame(
            ['artisan', 'test', '-p', '--processes=4'],
            ParallelTestingProcessLimiter::apply(['artisan', 'test', '-p'])
        );
    }

    #[Test]
    public function it_preserves_an_explicit_inline_process_limit(): void
    {
        $this->assertSame(
            ['artisan', 'test', '--parallel', '--processes=6'],
            ParallelTestingProcessLimiter::apply(['artisan', 'test', '--parallel', '--processes=6'])
        );
    }

    #[Test]
    public function it_preserves_an_explicit_separate_process_limit(): void
    {
        $this->assertSame(
            ['artisan', 'test', '--parallel', '--processes', '6'],
            ParallelTestingProcessLimiter::apply(['artisan', 'test', '--parallel', '--processes', '6'])
        );
    }

    #[Test]
    public function it_does_not_change_non_parallel_test_runs(): void
    {
        $this->assertSame(
            ['artisan', 'test'],
            ParallelTestingProcessLimiter::apply(['artisan', 'test'])
        );
    }

    #[Test]
    public function it_does_not_change_other_commands(): void
    {
        $this->assertSame(
            ['artisan', 'migrate', '--parallel'],
            ParallelTestingProcessLimiter::apply(['artisan', 'migrate', '--parallel'])
        );
    }
}
