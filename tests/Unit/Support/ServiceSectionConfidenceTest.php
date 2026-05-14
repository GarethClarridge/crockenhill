<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\ServiceSectionConfidence;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ServiceSectionConfidenceTest extends TestCase
{
    #[Test]
    public function it_uses_the_numeric_column_when_present(): void
    {
        $this->assertSame(0.75, ServiceSectionConfidence::resolve(0.75, ['confidence_level' => 'high']));
    }

    #[Test]
    public function it_falls_back_to_numeric_metadata_score_when_column_is_null(): void
    {
        $this->assertSame(0.92, ServiceSectionConfidence::resolve(null, ['confidence_score' => 0.92]));
    }

    #[Test]
    public function it_ignores_metadata_confidence_level_for_runtime_decisions(): void
    {
        $withLevel = ServiceSectionConfidence::resolve(null, ['confidence_level' => 'high']);
        $withoutLevel = ServiceSectionConfidence::resolve(null, []);

        $this->assertSame($withoutLevel, $withLevel);
    }

    #[Test]
    public function it_returns_the_same_value_when_metadata_level_is_removed_but_column_remains(): void
    {
        $withLevel = ServiceSectionConfidence::resolve(0.88, ['confidence_level' => 'low']);
        $withoutLevel = ServiceSectionConfidence::resolve(0.88, []);

        $this->assertSame($withoutLevel, $withLevel);
    }

    #[Test]
    public function it_clamps_values_into_the_unit_interval(): void
    {
        $this->assertSame(1.0, ServiceSectionConfidence::resolve(1.5));
        $this->assertSame(0.0, ServiceSectionConfidence::resolve(-0.5));
    }
}
