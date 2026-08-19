<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Email;

use App\Services\Email\OosEmailExtractionPrompt;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class OosEmailExtractionPromptTest extends TestCase
{
    /**
     * The baseline is the text four banked arms ran under, so it is data, not code: editing it
     * would redefine the baseline every future arm is measured against, silently and after the
     * fact. Pinning the hash is what makes such an edit a failing test rather than a number nobody
     * can reproduce.
     *
     * If this fails and the edit was intended, the banked arms are no longer a comparable baseline
     * and the honest move is a new variant, not a new expected hash.
     */
    #[Test]
    public function the_baseline_prompt_is_frozen_at_the_text_the_banked_arms_ran(): void
    {
        $this->assertSame(
            'e68600a95d46197a821066d6d9285aeba55376b232cade882cf2025e97f0e92b',
            OosEmailExtractionPrompt::forVariant(OosEmailExtractionPrompt::Baseline)->sha256(),
        );
    }

    #[Test]
    public function nothing_outside_an_evaluation_arm_changes_the_prompt_by_omission(): void
    {
        $this->assertSame(OosEmailExtractionPrompt::Baseline, OosEmailExtractionPrompt::configured()->variant);

        config(['service-tracking.email_parsing.prompt_variant' => OosEmailExtractionPrompt::Lean]);

        $this->assertSame(OosEmailExtractionPrompt::Lean, OosEmailExtractionPrompt::configured()->variant);
    }

    #[Test]
    public function it_refuses_a_variant_that_does_not_exist(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Unknown OoS email extraction prompt variant 'concise'");

        OosEmailExtractionPrompt::forVariant('concise');
    }

    /**
     * Normally a test asserting that a prompt contains a phrase proves only that the phrase is
     * still spelled the same way. Here the phrasing *is* the intervention: the lean arm exists to
     * screen two specific edits, and an arm that shipped without one of them would report a null
     * result for a change it never made.
     *
     * Both are asserted against the baseline as well, so this cannot pass by the edit having been
     * a no-op — the point is that the two texts differ in exactly these places.
     *
     * The line-accounting one is the substantive edit. The baseline demanded every line "appear
     * exactly once", which `OosEmailExtractionValidator::assignLine()` does not require and
     * deliberately does not enforce — see this suite's
     * `a_line_cited_as_both_service_evidence_and_an_item_is_not_a_finding` and
     * `one_line_shared_as_evidence_by_two_plans_is_not_a_finding`, which pin the permission the
     * lean text now states.
     */
    #[Test]
    public function the_lean_variant_drops_the_schema_copy_and_matches_the_validator_on_line_reuse(): void
    {
        $baseline = OosEmailExtractionPrompt::forVariant(OosEmailExtractionPrompt::Baseline)->text();
        $lean = OosEmailExtractionPrompt::forVariant(OosEmailExtractionPrompt::Lean)->text();

        // 1. The JSON example restated a schema the request already enforces with `strict: true`.
        $this->assertStringContainsString('{"service_count":1,"services"', $baseline);
        $this->assertStringNotContainsString('{"service_count":1,"services"', $lean);

        // 2. The line-accounting rule forbade a shape the pipeline accepts on 68 reviewed plans.
        $this->assertStringContainsString('must appear exactly once', $baseline);
        $this->assertStringNotContainsString('must appear exactly once', $lean);
        $this->assertStringContainsString('Account for every numbered body line at least once', $lean);
        $this->assertStringContainsString('may be claimed more than once', $lean);

        // And the two faults the validator does register are still forbidden explicitly.
        $this->assertStringContainsString('two items claiming the same line', $lean);
        $this->assertStringContainsString('both ignored and claimed', $lean);
    }

    /**
     * A screen that measured a prompt change without the arms differing would return a clean,
     * meaningless null result, so the two variants have to be distinguishable by their recorded
     * identity and not only by their name.
     */
    #[Test]
    public function the_variants_are_distinguishable_by_the_hash_the_manifest_records(): void
    {
        $baseline = OosEmailExtractionPrompt::forVariant(OosEmailExtractionPrompt::Baseline);
        $lean = OosEmailExtractionPrompt::forVariant(OosEmailExtractionPrompt::Lean);

        $this->assertNotSame($baseline->sha256(), $lean->sha256());
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $lean->sha256());
    }
}
