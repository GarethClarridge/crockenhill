<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Services\ChurchService\ServiceItemTitleCleaner;
use App\Services\Email\ExistingEmailImportLookup;
use App\Services\Email\OosSemanticSafetyFixtures;
use App\Services\Email\RunOosSemanticSafetyFixtures;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RunOosSemanticSafetyFixturesTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function every_safety_fixture_meets_its_expectation(): void
    {
        $artifact = $this->runner()->run();

        $this->assertSame([], $artifact['summary']['unsatisfied_names']);
        $this->assertSame(0, $artifact['summary']['unsatisfied']);
        $this->assertSame(0, $artifact['summary']['content_invalid_false_accepts']);
        $this->assertSame(count($artifact['results']), $artifact['summary']['satisfied']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $artifact['fixture_results_hash']);
    }

    #[Test]
    public function the_clean_control_is_auto_importable_so_the_harness_is_not_refusing_everything(): void
    {
        $results = $this->indexed($this->runner()->run());

        $this->assertSame(OosSemanticSafetyFixtures::ExpectAutoImportable, $results['clean_control']['expectation']);
        $this->assertNotSame([], $results['clean_control']['observed']['auto_importable_plan_keys']);
        $this->assertTrue($results['clean_control']['satisfied']);
    }

    #[Test]
    public function annotation_defects_are_refused_before_anything_is_compiled(): void
    {
        $results = $this->indexed($this->runner()->run());

        foreach (['annotation_missing_line', 'annotation_invented_line', 'continuation_not_adjacent', 'continuation_targets_non_item', 'unknown_service_group'] as $name) {
            $this->assertSame(0, $results[$name]['observed']['compiled_service_count'], $name);
            $this->assertSame([], $results[$name]['observed']['auto_importable_plan_keys'], $name);
            $this->assertSame([], $results[$name]['observed']['evidence_importable_plan_keys'], $name);
            $this->assertNotSame([], $results[$name]['observed']['semantic_rule_codes'], $name);
        }

        $this->assertContains('annotation_missing', $results['annotation_missing_line']['observed']['semantic_rule_codes']);
        $this->assertContains('annotation_invented', $results['annotation_invented_line']['observed']['semantic_rule_codes']);
        $this->assertContains('continuation_not_adjacent', $results['continuation_not_adjacent']['observed']['semantic_rule_codes']);
        $this->assertContains('continuation_target_invalid', $results['continuation_targets_non_item']['observed']['semantic_rule_codes']);
        $this->assertContains('service_group_unknown', $results['unknown_service_group']['observed']['semantic_rule_codes']);
    }

    #[Test]
    public function a_full_confidence_content_invalid_extraction_is_still_held(): void
    {
        $results = $this->indexed($this->runner()->run());

        foreach (['items_out_of_source_order', 'source_line_claimed_by_multiple_items', 'item_merges_non_continuation_lines', 'item_source_line_missing'] as $name) {
            $this->assertSame('invalid_extraction', $results[$name]['observed']['primary_disposition'], $name);
            $this->assertSame([], $results[$name]['observed']['auto_importable_plan_keys'], $name);
            $this->assertSame([], $results[$name]['observed']['evidence_importable_plan_keys'], $name);
            $this->assertContains($name === 'item_source_line_missing' ? 'item_source_line_missing' : $name, $results[$name]['observed']['content_rule_codes'], $name);
        }
    }

    #[Test]
    public function bookkeeping_defects_hold_the_plan_for_review_without_rejecting_it(): void
    {
        $results = $this->indexed($this->runner()->run());

        foreach (['line_ignored_and_claimed', 'line_ignored_inside_item_span', 'line_unclassified'] as $name) {
            $this->assertSame('review_required', $results[$name]['observed']['primary_disposition'], $name);
            $this->assertSame([], $results[$name]['observed']['auto_importable_plan_keys'], $name);
            $this->assertContains($name, $results[$name]['observed']['bookkeeping_rule_codes'], $name);
        }
    }

    #[Test]
    public function it_restores_the_configured_parser_implementation(): void
    {
        config()->set('service-tracking.email_parsing.implementation', 'legacy');

        $this->runner()->run();

        $this->assertSame('legacy', config('service-tracking.email_parsing.implementation'));
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @return array<string, array<string, mixed>>
     */
    private function indexed(array $artifact): array
    {
        $indexed = [];

        foreach ($artifact['results'] as $result) {
            $indexed[$result['name']] = $result;
        }

        return $indexed;
    }

    private function runner(): RunOosSemanticSafetyFixtures
    {
        return new RunOosSemanticSafetyFixtures(
            new OosSemanticSafetyFixtures,
            new ExistingEmailImportLookup,
            app(ServiceItemTitleCleaner::class),
        );
    }
}
