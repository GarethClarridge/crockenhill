<?php

declare(strict_types=1);

namespace Tests\Unit\Data;

use App\Data\OosParserEvaluationArm;
use App\Services\Email\OosEmailExtractionPrompt;
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
            'service-tracking.email_parsing.prompt_variant' => 'lean',
        ]);

        $arm = OosParserEvaluationArm::fromName('luna-none');
        $arm->apply();

        $this->assertSame([
            'model' => 'gpt-5.6-luna',
            'configured_reasoning_effort' => 'none',
            'effective_reasoning_effort' => 'none',
            'prompt_variant' => OosEmailExtractionPrompt::Baseline,
            'prompt_sha256' => OosEmailExtractionPrompt::forVariant(OosEmailExtractionPrompt::Baseline)->sha256(),
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

    /**
     * The prompt arm's whole claim is that the prompt is the only thing that moved. Reasoning effort
     * has already been answered — `low` traded `item_structure` disagreement for a *worse* routing
     * flip rate — so an arm that varied the effort as well could not say which change was
     * responsible, and routing is what decides unattended auto-import.
     */
    #[Test]
    public function the_lean_prompt_arm_moves_the_prompt_and_nothing_else(): void
    {
        $baseline = OosParserEvaluationArm::fromName('baseline-nano-none');
        $lean = OosParserEvaluationArm::fromName('lean-nano-none');

        $this->assertSame($baseline->model, $lean->model);
        $this->assertSame($baseline->configuredReasoningEffort, $lean->configuredReasoningEffort);
        $this->assertSame($baseline->effectiveReasoningEffort, $lean->effectiveReasoningEffort);
        $this->assertSame(OosEmailExtractionPrompt::Baseline, $baseline->promptVariant);
        $this->assertSame(OosEmailExtractionPrompt::Lean, $lean->promptVariant);
    }

    /**
     * The variant name is what an operator typed; the hash is what the API will be sent. They come
     * apart exactly when a variant's text is edited between two arms, so the manifest carries both.
     */
    #[Test]
    public function it_applies_the_prompt_variant_and_certifies_the_text_that_will_be_sent(): void
    {
        config(['service-tracking.email_parsing.prompt_variant' => 'baseline']);

        $arm = OosParserEvaluationArm::fromName('lean-nano-none');
        $arm->apply();
        $configuration = $arm->resolvedConfiguration();

        $this->assertSame('lean', config('service-tracking.email_parsing.prompt_variant'));
        $this->assertSame('lean', $configuration['prompt_variant']);
        $this->assertSame(
            OosEmailExtractionPrompt::forVariant(OosEmailExtractionPrompt::Lean)->sha256(),
            $configuration['prompt_sha256'],
        );
    }

    #[Test]
    public function it_refuses_a_prompt_variant_moved_out_from_under_the_arm(): void
    {
        $arm = OosParserEvaluationArm::fromName('lean-nano-none');
        $arm->apply();

        // Whatever moved it — a stale config cache, an env var, another arm in the same process —
        // the arm must not certify a run that would send the baseline prompt under the lean label.
        config(['service-tracking.email_parsing.prompt_variant' => OosEmailExtractionPrompt::Baseline]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("arm 'lean-nano-none' does not match its frozen prompt variant");

        $arm->resolvedConfiguration();
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
