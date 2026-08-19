<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Email;

use App\Data\OosSemanticAnnotationPatch;
use App\Data\OosSemanticAnnotationResult;
use App\Data\OosSemanticFinding;
use App\Data\OosSemanticLineAnnotation;
use App\Enums\OosSemanticItemKind;
use App\Enums\OosSemanticRole;
use App\Services\Email\ApplyOosSemanticAnnotationPatch;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class ApplyOosSemanticAnnotationPatchTest extends TestCase
{
    #[Test]
    public function it_applies_only_allowlisted_lines_and_fields(): void
    {
        $original = new OosSemanticAnnotationResult([], [
            1 => $this->annotation(1, OosSemanticRole::OtherContext),
            2 => $this->annotation(2, OosSemanticRole::Item, 'morning', OosSemanticItemKind::Song),
        ]);
        $patch = new OosSemanticAnnotationPatch([
            1 => $this->annotation(1, OosSemanticRole::Item, 'morning', OosSemanticItemKind::Prayer),
        ]);
        $findings = [new OosSemanticFinding(
            'item_semantics_incomplete',
            'Line one is an item.',
            [1],
            ['role', 'service_group_id', 'item_kind'],
        )];

        $patched = (new ApplyOosSemanticAnnotationPatch)->apply($original, $patch, $findings);

        $this->assertSame(OosSemanticRole::Item, $patched->annotations[1]->role);
        $this->assertSame($original->annotations[2], $patched->annotations[2]);
    }

    #[Test]
    public function it_rejects_an_unrelated_mutation(): void
    {
        $original = new OosSemanticAnnotationResult([], [
            1 => $this->annotation(1, OosSemanticRole::OtherContext),
            2 => $this->annotation(2, OosSemanticRole::OtherContext),
        ]);
        $patch = new OosSemanticAnnotationPatch([
            2 => $this->annotation(2, OosSemanticRole::Item, 'morning', OosSemanticItemKind::Song),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unrelated line 2');

        (new ApplyOosSemanticAnnotationPatch)->apply(
            $original,
            $patch,
            [new OosSemanticFinding('fault', 'Fault', [1], ['role'])],
        );
    }

    private function annotation(
        int $lineId,
        OosSemanticRole $role,
        ?string $group = null,
        ?OosSemanticItemKind $kind = null,
    ): OosSemanticLineAnnotation {
        return new OosSemanticLineAnnotation($lineId, $role, $group, $kind, null, null);
    }
}
