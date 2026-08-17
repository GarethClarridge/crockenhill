<?php

declare(strict_types=1);

namespace Tests\Unit\Data;

use App\Data\OosParserEvaluationArm;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class OosParserEvaluationArmTest extends TestCase
{
    #[Test]
    public function it_applies_and_certifies_the_frozen_luna_arm_configuration(): void
    {
        config([
            'service-tracking.email_parsing.model' => 'wrong-model',
            'service-tracking.email_parsing.reasoning_effort' => 'low',
        ]);

        $arm = OosParserEvaluationArm::fromName('luna-none');
        $arm->apply();

        $this->assertSame([
            'model' => 'gpt-5.6-luna',
            'configured_reasoning_effort' => 'none',
            'effective_reasoning_effort' => 'none',
        ], $arm->resolvedConfiguration());
        $this->assertSame('luna-none', config('openai.evaluation_arm'));
    }

    #[Test]
    public function it_refuses_an_unknown_arm_before_any_configuration_is_changed(): void
    {
        config(['service-tracking.email_parsing.model' => 'gpt-5.4-nano']);

        try {
            OosParserEvaluationArm::fromName('not-an-arm');
            $this->fail('An unknown arm must be refused.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString("Unknown OoS parser evaluation arm 'not-an-arm'", $exception->getMessage());
        }

        $this->assertSame('gpt-5.4-nano', config('service-tracking.email_parsing.model'));
    }
}
