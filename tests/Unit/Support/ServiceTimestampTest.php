<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\ServiceTimestamp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ServiceTimestampTest extends TestCase
{
    /** @return array<string, array{string, float|null}> */
    public static function typedOffsets(): array
    {
        return [
            'hours' => ['1:02:03', 3723.0],
            'minutes' => ['45:06', 2706.0],
            'tenths' => ['45:06.4', 2706.4],
            'plain seconds' => ['2744.5', 2744.5],
            'seconds overflowing a minute' => ['1:75', null],
            'text' => ['soon', null],
            'empty' => ['', null],
        ];
    }

    #[Test]
    #[DataProvider('typedOffsets')]
    public function it_reads_the_forms_an_operator_types(string $typed, ?float $seconds): void
    {
        $this->assertSame($seconds, ServiceTimestamp::parse($typed));
    }

    #[Test]
    public function a_formatted_boundary_reads_back_unchanged(): void
    {
        foreach ([0.0, 59.9, 2706.4, 3723.0] as $seconds) {
            $this->assertSame($seconds, ServiceTimestamp::parse(ServiceTimestamp::format($seconds)));
        }

        $this->assertSame('1:02:03', ServiceTimestamp::format(3723.0));
        $this->assertSame('45:06.4', ServiceTimestamp::format(2706.4));
    }
}
