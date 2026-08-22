<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Data\OosSemanticAnnotationResult;
use App\Enums\OosSemanticRole;

/**
 * Which source lines the annotation deliberately did not read.
 *
 * Extracted from {@see CompileOosSemanticAnnotations} so the archive backfill
 * ({@see BackfillOosArchiveIgnoredLines}) applies the *same* rule rather than a second copy of it.
 * A copy would be the worse failure available here: the two would agree on the corpus that was
 * checked and diverge later, and the divergence would surface as
 * `OosEmailExtractionValidator`'s "unclassified source line" — a complaint about the document
 * rather than about the two rules disagreeing.
 *
 * The rule needs no source document. It is a function of the annotation roles alone, which is what
 * makes replaying it over banked `final_annotations` exact rather than approximate.
 *
 * It is one half of a partition, and only reads correctly beside the other half:
 * {@see CompileOosSemanticAnnotations::evidenceLineIds()} claims every non-item line that *belongs
 * to a service group* as that service's evidence, and this claims every non-item line that belongs
 * to none. Together they account for every annotated line, which is exactly what the validator's
 * coverage rule requires. Narrowing either side without widening the other opens a hole in that
 * coverage.
 */
class OosSemanticIgnoredLines
{
    /**
     * @return list<array{line_id:int,reason:string}>
     */
    public function forResult(OosSemanticAnnotationResult $result): array
    {
        $ignored = [];

        foreach ($result->annotations as $annotation) {
            if (in_array($annotation->role, [OosSemanticRole::Item, OosSemanticRole::Continuation, OosSemanticRole::ServiceBoundary], true)) {
                continue;
            }

            if ($annotation->serviceGroupId !== null || $annotation->sharedServiceGroupIds !== []) {
                continue;
            }

            $ignored[] = [
                'line_id' => $annotation->lineId,
                'reason' => match ($annotation->role) {
                    OosSemanticRole::ForwardedContext => 'forwarded_header',
                    OosSemanticRole::GreetingOrSignature => 'signature',
                    default => 'context',
                },
            ];
        }

        return $ignored;
    }
}
