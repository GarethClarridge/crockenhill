<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Email;

use App\Data\OosCandidateService;
use App\Data\OosEmailSourceDocument;
use App\Data\OosSemanticAnnotationResult;
use App\Data\OosSemanticLineAnnotation;
use App\Enums\OosSemanticItemKind;
use App\Enums\OosSemanticRole;
use App\Services\Email\OosSemanticAnnotationValidator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Found 2026-08-22 investigating a class of held Email sources: a service boundary line that is
 * also the item ("Final hymn for the morning: 697 'Above the voices'") is a valid, model-correct
 * shape (`boundary_also_item`), but a continuation wrapping its title onto the next physical line
 * targeted that boundary line, and the plain role check rejected it — the only fix the repairer
 * found was stripping the boundary role, which then deleted the group's only boundary evidence.
 */
class OosSemanticAnnotationValidatorTest extends TestCase
{
    private OosSemanticAnnotationValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validator = new OosSemanticAnnotationValidator;
    }

    #[Test]
    public function a_continuation_may_target_a_boundary_line_that_is_also_the_item(): void
    {
        $source = OosEmailSourceDocument::fromBody("Final song for the morning \xe2\x80\x93 697\nAbove the voices");
        $result = new OosSemanticAnnotationResult(
            [new OosCandidateService('morning', 'morning', [1])],
            [
                1 => new OosSemanticLineAnnotation(1, OosSemanticRole::ServiceBoundary, 'morning', OosSemanticItemKind::Song, null, null, boundaryAlsoItem: true),
                2 => new OosSemanticLineAnnotation(2, OosSemanticRole::Continuation, 'morning', null, 1, null),
            ],
        );

        $findings = $this->validator->validate($source, $result);

        $this->assertSame([], array_map(static fn ($f) => $f->code, $findings));
    }

    /**
     * Characterisation: an *ordinary* service-boundary line (not also an item) still cannot be a
     * continuation target — a boundary heading has no title to wrap. Only `boundary_also_item`
     * changes the answer.
     */
    #[Test]
    public function a_continuation_may_not_target_a_boundary_line_that_is_not_also_an_item(): void
    {
        $source = OosEmailSourceDocument::fromBody("Morning Service\nWelcome and notices");
        $result = new OosSemanticAnnotationResult(
            [new OosCandidateService('morning', 'morning', [1])],
            [
                1 => new OosSemanticLineAnnotation(1, OosSemanticRole::ServiceBoundary, 'morning', null, null, null),
                2 => new OosSemanticLineAnnotation(2, OosSemanticRole::Continuation, 'morning', null, 1, null),
            ],
        );

        $findings = $this->validator->validate($source, $result);

        $this->assertSame(['continuation_target_invalid'], array_map(static fn ($f) => $f->code, $findings));
    }
}
