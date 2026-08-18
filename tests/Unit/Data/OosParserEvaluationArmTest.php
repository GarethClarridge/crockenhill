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

    /**
     * The effort arms exist to answer a question the model arms could not reach, so the one thing
     * they must not do is move the model as well. If they did, a passing arm could not say whether
     * the effort or the model bought the stability — and nano is also the configured production
     * model, which is what makes a passing arm a config change rather than a migration.
     */
    #[Test]
    public function it_holds_the_model_fixed_across_the_effort_arms(): void
    {
        $low = OosParserEvaluationArm::fromName('nano-low');
        $medium = OosParserEvaluationArm::fromName('nano-medium');
        $baseline = OosParserEvaluationArm::fromName('baseline-nano-none');

        $this->assertSame($baseline->model, $low->model);
        $this->assertSame($baseline->model, $medium->model);

        // And the effort survives normalisation: `minimal` is sent as `none` on GPT-5.4+, so an arm
        // whose effort collapsed would be a silent re-run of the baseline reported as a new setting.
        $this->assertSame('low', $low->effectiveReasoningEffort);
        $this->assertSame('medium', $medium->effectiveReasoningEffort);
        $this->assertSame('none', $baseline->effectiveReasoningEffort);
    }

    /**
     * Hidden reasoning tokens are billed against the same ceiling as the visible JSON, so an effort
     * arm run against the ceiling measured at `none` truncates, retries and reports the stronger
     * setting as the worse one. The decision rule would read that as a defective configuration.
     */
    #[Test]
    public function it_gives_every_reasoning_arm_a_token_headroom_above_the_none_ceiling(): void
    {
        /** @var array<string, int> $headroom */
        $headroom = config('service-tracking.email_parsing.reasoning_token_headroom');

        foreach (['nano-low', 'nano-medium'] as $name) {
            $arm = OosParserEvaluationArm::fromName($name);

            $this->assertArrayHasKey($arm->effectiveReasoningEffort, $headroom, "Arm {$name} has no configured token headroom.");
            $this->assertGreaterThan(0, $headroom[$arm->effectiveReasoningEffort], "Arm {$name} would run against the effort=none ceiling.");
        }
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
