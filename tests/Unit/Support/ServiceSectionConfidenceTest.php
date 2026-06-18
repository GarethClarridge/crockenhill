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
        $this->assertSame(1.0, ServiceSectionConfidence::clamp(1.1));
        $this->assertSame(0.0, ServiceSectionConfidence::clamp(-0.1));
    }

    #[Test]
    public function it_increases_confidence_with_clamping(): void
    {
        $this->assertSame(0.8, ServiceSectionConfidence::increase(0.5, 0.3));
        $this->assertSame(1.0, ServiceSectionConfidence::increase(0.9, 0.2));
    }

    #[Test]
    public function it_decreases_confidence_with_clamping(): void
    {
        $this->assertSame(0.2, ServiceSectionConfidence::decrease(0.5, 0.3));
        $this->assertSame(0.0, ServiceSectionConfidence::decrease(0.1, 0.2));
    }

    #[Test]
    public function it_returns_correct_level_for_confidence_scores(): void
    {
        $this->assertSame('high', ServiceSectionConfidence::levelFor(0.9));
        $this->assertSame('high', ServiceSectionConfidence::levelFor(ServiceSectionConfidence::HIGH_THRESHOLD));

        $this->assertSame('low', ServiceSectionConfidence::levelFor(0.6));
        $this->assertSame('low', ServiceSectionConfidence::levelFor(ServiceSectionConfidence::LOW_THRESHOLD));

        $this->assertSame('none', ServiceSectionConfidence::levelFor(0.4));
        $this->assertSame('none', ServiceSectionConfidence::levelFor(0.0));
    }

    #[Test]
    public function it_returns_default_scores_for_levels(): void
    {
        $this->assertSame(0.90, ServiceSectionConfidence::scoreForLevel('high'));
        $this->assertSame(0.50, ServiceSectionConfidence::scoreForLevel('low'));
        $this->assertSame(0.10, ServiceSectionConfidence::scoreForLevel('none'));
        $this->assertSame(0.10, ServiceSectionConfidence::scoreForLevel('unknown'));
        $this->assertSame(0.10, ServiceSectionConfidence::scoreForLevel(null));
    }
}
