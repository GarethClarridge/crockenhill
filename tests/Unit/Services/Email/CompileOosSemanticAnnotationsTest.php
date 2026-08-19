<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Email;

use App\Data\OosCandidateService;
use App\Data\OosEmailSourceDocument;
use App\Data\OosSemanticAnnotationResult;
use App\Data\OosSemanticLineAnnotation;
use App\Enums\OosSemanticItemKind;
use App\Enums\OosSemanticRole;
use App\Exceptions\OosSemanticCompilationException;
use App\Services\Email\CompileOosSemanticAnnotations;
use App\Services\Email\OosEmailExtractionValidator;
use App\Services\Email\OosSemanticAnnotationValidator;
use App\Services\Email\OosServiceDateResolver;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CompileOosSemanticAnnotationsTest extends TestCase
{
    #[Test]
    public function it_compiles_source_bound_titles_order_continuations_and_ignored_complement(): void
    {
        $source = OosEmailSourceDocument::fromContext(
            'Order for Sunday 23 August 2026',
            "Morning Service\nHow deep the Father's\nlove for us\nThanks,",
            '2026-08-19',
        );
        $annotations = new OosSemanticAnnotationResult(
            services: [new OosCandidateService('morning', 'morning', [1])],
            annotations: [
                1 => $this->annotation(1, OosSemanticRole::ServiceBoundary, 'morning'),
                2 => $this->annotation(2, OosSemanticRole::Item, 'morning', OosSemanticItemKind::Song),
                3 => $this->annotation(3, OosSemanticRole::Continuation, 'morning', continuationTarget: 2),
                4 => $this->annotation(4, OosSemanticRole::GreetingOrSignature),
            ],
        );

        $compiled = $this->compiler()->compile($source, $annotations);
        $service = $compiled->extraction->services[0];

        $this->assertSame('2026-08-23', $service['date']);
        $this->assertSame("How deep the Father's love for us", $service['items'][0]['title']);
        $this->assertSame([2, 3], $service['items'][0]['source_line_ids']);
        $this->assertTrue($service['items'][0]['continuation']);
        $this->assertSame([['line_id' => 4, 'reason' => 'signature']], $compiled->extraction->ignoredLines);
    }

    #[Test]
    public function it_maps_richer_semantics_without_widening_the_canonical_type(): void
    {
        $source = OosEmailSourceDocument::fromContext(
            'Sunday 23 August 2026',
            "Morning Service\nCommunion",
            '2026-08-19',
        );
        $annotations = new OosSemanticAnnotationResult(
            [new OosCandidateService('morning', 'morning', [1])],
            [
                1 => $this->annotation(1, OosSemanticRole::ServiceBoundary, 'morning'),
                2 => $this->annotation(2, OosSemanticRole::Item, 'morning', OosSemanticItemKind::Communion),
            ],
        );

        $item = $this->compiler()->compile($source, $annotations)->extraction->services[0]['items'][0];

        $this->assertSame('other', $item['type']);
        $this->assertSame('communion', $item['semantic_kind']);
    }

    #[Test]
    public function missing_annotation_membership_fails_before_compilation(): void
    {
        $source = OosEmailSourceDocument::fromBody("Morning Service\nSong");
        $annotations = new OosSemanticAnnotationResult(
            [new OosCandidateService('morning', 'morning', [1])],
            [1 => $this->annotation(1, OosSemanticRole::ServiceBoundary, 'morning')],
        );

        try {
            $this->compiler()->compile($source, $annotations);
            $this->fail('Expected missing annotation membership to fail.');
        } catch (OosSemanticCompilationException $exception) {
            $this->assertSame('annotation_missing', $exception->findings[0]->code);
            $this->assertSame([2], $exception->findings[0]->lineIds);
        }
    }

    #[Test]
    public function grouped_transition_and_supporting_lines_cannot_compile_as_ignored_inside_an_item_span(): void
    {
        $source = OosEmailSourceDocument::fromContext(
            'Sunday 23 August 2026',
            "Morning Service\nSong One\nAfter the Lord's Supper\nhttps://example.test/song\nSong Two",
            '2026-08-19',
        );
        $annotations = new OosSemanticAnnotationResult(
            [new OosCandidateService('morning', 'morning', [1])],
            [
                5 => $this->annotation(5, OosSemanticRole::Item, 'morning', OosSemanticItemKind::Song),
                4 => $this->annotation(4, OosSemanticRole::SupportingDetail, 'morning'),
                3 => $this->annotation(3, OosSemanticRole::TransitionMarker, 'morning'),
                2 => $this->annotation(2, OosSemanticRole::Item, 'morning', OosSemanticItemKind::Song),
                1 => $this->annotation(1, OosSemanticRole::ServiceBoundary, 'morning'),
            ],
        );

        $compiled = $this->compiler()->compile($source, $annotations)->extraction;
        $validation = (new OosEmailExtractionValidator)->validate($source, $compiled);

        $this->assertSame(['Song One', 'Song Two'], array_column($compiled->services[0]['items'], 'title'));
        $this->assertSame([1, 3, 4], $compiled->services[0]['service_evidence_line_ids']);
        $this->assertSame([], $compiled->ignoredLines);
        $this->assertSame([], $validation->allReasons());
    }

    private function annotation(
        int $lineId,
        OosSemanticRole $role,
        ?string $group = null,
        ?OosSemanticItemKind $kind = null,
        ?int $continuationTarget = null,
    ): OosSemanticLineAnnotation {
        return new OosSemanticLineAnnotation(
            $lineId,
            $role,
            $group,
            $kind,
            $continuationTarget,
            null,
        );
    }

    private function compiler(): CompileOosSemanticAnnotations
    {
        return new CompileOosSemanticAnnotations(
            new OosSemanticAnnotationValidator,
            new OosServiceDateResolver,
        );
    }
}
