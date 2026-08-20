<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Email;

use App\Contracts\OosSemanticAnnotator;
use App\Data\OosCandidateService;
use App\Data\OosSemanticAnnotationResult;
use App\Data\OosSemanticLineAnnotation;
use App\Enums\OosSemanticItemKind;
use App\Enums\OosSemanticRole;
use App\Models\InboundEmail;
use App\Services\Email\OosEmailParserService;
use App\Services\Email\OosSemanticParserCandidate;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\Support\FakeOosSemanticAnnotator;
use Tests\TestCase;

/**
 * The Delivery 7 wiring contract: the parser must be reachable from the container, not merely from
 * a hand-built object graph.
 *
 * This exists because a defect of exactly that shape survived six deliveries. Every other test of
 * the semantic path constructs {@see OosEmailParserService} directly and passes the candidate in by
 * name, which proves the candidate compiles correctly when it is supplied but cannot prove that
 * production supplies it — and production did not. `$semanticParser` was declared
 * `?OosSemanticParserCandidate $semanticParser = null`, and Laravel's container declines to build an
 * *unbound concrete class* for a nullable parameter carrying a default, so every container-resolved
 * parser service — the weekly job, the reparse and approve actions, the archive command — held
 * `null`. It was invisible while the semantic path was not the default and would have failed every
 * parse the moment it became one.
 *
 * The general lesson is worth more than the specific bug: an interface with an explicit binding
 * (`OosSemanticRepairer`) *does* resolve under the same declaration, so two dependencies of
 * identical shape behaved differently, and neither the type signature nor the suite showed it. The
 * dependency is now required, so the container has to satisfy it.
 */
class OosParserImplementationWiringTest extends TestCase
{
    #[Test]
    public function the_container_supplies_the_semantic_parser_to_the_shared_parser_service(): void
    {
        $property = (new ReflectionClass(OosEmailParserService::class))->getProperty('semanticParser');
        $property->setAccessible(true);

        $this->assertInstanceOf(
            OosSemanticParserCandidate::class,
            $property->getValue(app(OosEmailParserService::class)),
            'A container-resolved OosEmailParserService cannot parse, so every production entry point '
            .'would fail.',
        );
    }

    #[Test]
    public function a_container_resolved_parser_compiles_a_source_end_to_end(): void
    {
        $this->app->bind(OosSemanticAnnotator::class, fn () => new FakeOosSemanticAnnotator(
            new OosSemanticAnnotationResult(
                [new OosCandidateService('morning', 'morning', [1])],
                [
                    1 => new OosSemanticLineAnnotation(1, OosSemanticRole::ServiceBoundary, 'morning', null, null, null),
                    2 => new OosSemanticLineAnnotation(2, OosSemanticRole::Item, 'morning', OosSemanticItemKind::Communion, null, null),
                ],
            ),
        ));

        $result = app(OosEmailParserService::class)->parse(InboundEmail::factory()->make([
            'subject' => 'Order for Sunday 23 August 2026',
            'body_plain' => "Morning Service\nCommunion",
            'received_at' => '2026-08-19 09:00:00',
        ]));

        $this->assertSame('semantic_annotations', $result->importMetadata['parser_implementation']);
        $this->assertSame('Communion', $result->items[0]['source_title']);
    }

    /**
     * There is one parser, and no configuration selects another.
     *
     * Asserted rather than assumed because the deleted `email_parsing.implementation` key would, if
     * reintroduced by a stale `.env` or a revert, silently route parses somewhere that no longer
     * exists. A parse that ignores the key entirely is the guarantee worth pinning.
     */
    #[Test]
    public function no_configuration_can_select_a_different_implementation(): void
    {
        config()->set('service-tracking.email_parsing.implementation', 'legacy');

        $this->app->bind(OosSemanticAnnotator::class, fn () => new FakeOosSemanticAnnotator(
            new OosSemanticAnnotationResult(
                [new OosCandidateService('morning', 'morning', [1])],
                [
                    1 => new OosSemanticLineAnnotation(1, OosSemanticRole::ServiceBoundary, 'morning', null, null, null),
                    2 => new OosSemanticLineAnnotation(2, OosSemanticRole::Item, 'morning', OosSemanticItemKind::Communion, null, null),
                ],
            ),
        ));

        $result = app(OosEmailParserService::class)->parse(InboundEmail::factory()->make([
            'subject' => 'Order for Sunday 23 August 2026',
            'body_plain' => "Morning Service\nCommunion",
            'received_at' => '2026-08-19 09:00:00',
        ]));

        $this->assertSame('semantic_annotations', $result->importMetadata['parser_implementation']);
    }
}
