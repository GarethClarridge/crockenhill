<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Email;

use App\Contracts\OosSemanticAnnotator;
use App\Contracts\OosSemanticRepairer;
use App\Data\OosCandidateService;
use App\Data\OosEmailSourceDocument;
use App\Data\OosSemanticAnnotationPatch;
use App\Data\OosSemanticAnnotationResult;
use App\Data\OosSemanticLineAnnotation;
use App\Enums\OosSemanticItemKind;
use App\Enums\OosSemanticRole;
use App\Services\Email\ApplyOosSemanticAnnotationPatch;
use App\Services\Email\CompileOosSemanticAnnotations;
use App\Services\Email\OosParserSurfaceFingerprint;
use App\Services\Email\OosSemanticAnnotationPrompt;
use App\Services\Email\OosSemanticAnnotationSchema;
use App\Services\Email\OosSemanticAnnotationValidator;
use App\Services\Email\OosSemanticParserCandidate;
use App\Services\Email\OosServiceDateResolver;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OosSemanticParserCandidateTest extends TestCase
{
    #[Test]
    public function one_local_repair_changes_only_the_named_fields_and_retains_attempt_evidence(): void
    {
        $source = OosEmailSourceDocument::fromContext(
            'Sunday 23 August 2026',
            "Morning Service\nAmazing Grace",
            '2026-08-19',
        );
        $initial = new OosSemanticAnnotationResult(
            [new OosCandidateService('morning', 'morning', [1])],
            [
                1 => new OosSemanticLineAnnotation(1, OosSemanticRole::ServiceBoundary, 'morning', null, null, null),
                2 => new OosSemanticLineAnnotation(2, OosSemanticRole::OtherContext, 'morning', OosSemanticItemKind::Song, null, null),
            ],
        );
        $annotator = new class($initial) implements OosSemanticAnnotator
        {
            public function __construct(private readonly OosSemanticAnnotationResult $result) {}

            public function annotate(OosEmailSourceDocument $source): OosSemanticAnnotationResult
            {
                return $this->result;
            }
        };
        $repairer = new class implements OosSemanticRepairer
        {
            public function repair(OosEmailSourceDocument $source, OosSemanticAnnotationResult $annotations, array $findings): OosSemanticAnnotationPatch
            {
                return new OosSemanticAnnotationPatch([
                    2 => new OosSemanticLineAnnotation(2, OosSemanticRole::Item, 'morning', OosSemanticItemKind::Song, null, null),
                ], ['returned_model' => 'gpt-5.6-terra-test']);
            }
        };
        $validator = new OosSemanticAnnotationValidator;
        $candidate = new OosSemanticParserCandidate(
            $annotator,
            new CompileOosSemanticAnnotations($validator, new OosServiceDateResolver),
            $validator,
            new ApplyOosSemanticAnnotationPatch,
            new OosParserSurfaceFingerprint,
            new OosSemanticAnnotationSchema,
            new OosSemanticAnnotationPrompt,
            $repairer,
        );

        $outcome = $candidate->parse($source);

        $this->assertSame('Amazing Grace', $outcome->extraction->items[0]['title']);
        $this->assertTrue($outcome->riskSignals['targeted_repair_required']);
        $this->assertFalse($outcome->riskSignals['targeted_repair_failed']);
        $this->assertSame(['non_item_has_kind'], $outcome->attempts[0]['initial_rule_codes']);
        $this->assertSame([], $outcome->attempts[0]['final_rule_codes']);
        $this->assertSame(['item_kind', 'role'], $outcome->attempts[0]['allowed_patch'][2]);
        $this->assertArrayHasKey('compilation_hash', $outcome->attempts[0]);
        $this->assertArrayHasKey('compilation', $outcome->attempts[0]);
        $this->assertArrayHasKey('prompt_hash', $outcome->attempts[0]);
        $this->assertArrayHasKey('schema_hash', $outcome->attempts[0]);
        $this->assertSame('gpt-5.6-terra-test', $outcome->attempts[0]['repair_telemetry']['returned_model']);
    }

    #[Test]
    public function an_unrepairable_empty_unanchored_sibling_group_is_salvaged_rather_than_failing_the_whole_document(): void
    {
        // Reproduces 2018-01-07: a `service_boundary_missing` finding carries no line IDs, so
        // `isRepairable()` never even offers it to the repairer — the candidate's own
        // all-or-nothing check is the only place this document could still be discarded.
        $source = OosEmailSourceDocument::fromContext(
            'Sunday 23 August 2026',
            "Morning Service\nWelcome and notices\n248 Immortal, invisible\nEvening: hymns to follow",
            '2026-08-19',
        );
        $annotations = new OosSemanticAnnotationResult(
            [
                new OosCandidateService('morning', 'morning', [1]),
                new OosCandidateService('evening', 'evening', []),
            ],
            [
                1 => new OosSemanticLineAnnotation(1, OosSemanticRole::ServiceBoundary, 'morning', null, null, null),
                2 => new OosSemanticLineAnnotation(2, OosSemanticRole::Item, 'morning', OosSemanticItemKind::Welcome, null, null),
                3 => new OosSemanticLineAnnotation(3, OosSemanticRole::Item, 'morning', OosSemanticItemKind::Song, null, null),
                4 => new OosSemanticLineAnnotation(4, OosSemanticRole::SupportingDetail, 'evening', null, null, null),
            ],
        );
        $annotator = new class($annotations) implements OosSemanticAnnotator
        {
            public function __construct(private readonly OosSemanticAnnotationResult $result) {}

            public function annotate(OosEmailSourceDocument $source): OosSemanticAnnotationResult
            {
                return $this->result;
            }
        };
        $validator = new OosSemanticAnnotationValidator;
        $candidate = new OosSemanticParserCandidate(
            $annotator,
            new CompileOosSemanticAnnotations($validator, new OosServiceDateResolver),
            $validator,
            new ApplyOosSemanticAnnotationPatch,
            new OosParserSurfaceFingerprint,
            new OosSemanticAnnotationSchema,
            new OosSemanticAnnotationPrompt,
        );

        $outcome = $candidate->parse($source);

        $this->assertCount(1, $outcome->extraction->services);
        $this->assertSame('morning', $outcome->extraction->services[0]['service']);
        $this->assertSame([], $outcome->attempts[0]['final_rule_codes']);
        $this->assertArrayHasKey('compilation', $outcome->attempts[0]);
    }
}
