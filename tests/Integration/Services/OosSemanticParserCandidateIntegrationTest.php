<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Data\OosCandidateService;
use App\Data\OosSemanticAnnotationResult;
use App\Data\OosSemanticLineAnnotation;
use App\Enums\OosSemanticItemKind;
use App\Enums\OosSemanticRole;
use App\Models\InboundEmail;
use App\Services\ChurchService\ServiceItemTitleCleaner;
use App\Services\Email\ApplyOosSemanticAnnotationPatch;
use App\Services\Email\CompileOosSemanticAnnotations;
use App\Services\Email\ExistingEmailImportLookup;
use App\Services\Email\OosEmailParserService;
use App\Services\Email\OosParserSurfaceFingerprint;
use App\Services\Email\OosSemanticAnnotationPrompt;
use App\Services\Email\OosSemanticAnnotationSchema;
use App\Services\Email\OosSemanticAnnotationValidator;
use App\Services\Email\OosSemanticParserCandidate;
use App\Services\Email\OosServiceDateResolver;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeOosSemanticAnnotator;
use Tests\TestCase;

class OosSemanticParserCandidateIntegrationTest extends TestCase
{
    #[Test]
    public function the_candidate_path_compiles_through_existing_policy(): void
    {
        $annotator = new FakeOosSemanticAnnotator(new OosSemanticAnnotationResult(
            [new OosCandidateService('morning', 'morning', [1])],
            [
                1 => new OosSemanticLineAnnotation(1, OosSemanticRole::ServiceBoundary, 'morning', null, null, null),
                2 => new OosSemanticLineAnnotation(2, OosSemanticRole::Item, 'morning', OosSemanticItemKind::Communion, null, null),
            ],
        ));
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
        $parser = new OosEmailParserService(
            new ExistingEmailImportLookup,
            app(ServiceItemTitleCleaner::class),
            $candidate,
        );

        $result = $parser->parse(InboundEmail::factory()->make([
            'subject' => 'Order for Sunday 23 August 2026',
            'body_plain' => "Morning Service\nCommunion",
            'received_at' => '2026-08-19 09:00:00',
        ]));

        $this->assertSame('semantic_annotations', $result->importMetadata['parser_implementation']);
        $this->assertSame('2026-08-23', $result->date);
        $this->assertSame('Communion', $result->items[0]['source_title']);
        $this->assertSame('communion', $result->items[0]['metadata']['semantic_kind']);
        $this->assertFalse($result->shouldImport);
        $this->assertArrayHasKey('parser_surface_hash', $result->extractionAttempts[0]);
        $this->assertSame([], $result->importMetadata['risk_signals']['content_validator_findings']['content']);
        $this->assertSame(1, $annotator->calls);
    }
}
