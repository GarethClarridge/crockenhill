<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Data\OosEmailSourceDocument;

/**
 * The single definition of a legal continuation target.
 *
 * §5.2 calls the continuation target "adjacent" and §5.3 step 4 joins "only explicitly declared,
 * physically adjacent continuation lines". Two surfaces need that rule: the request schema, which
 * decides what the model is *able* to say, and the validator, which decides what is accepted. They
 * must not each carry their own copy — a schema that permits more than the validator accepts spends
 * a paid call to produce a finding, and a schema that permits less silently truncates a legitimate
 * answer.
 *
 * "Adjacent" means the immediately preceding *physical* line, and line IDs are physical positions
 * with blank lines skipped, so a line whose predecessor was blank has no permitted target at all: a
 * wrapped title does not survive a blank line.
 */
class OosSemanticContinuationRule
{
    /**
     * The only line a continuation on `$lineId` may target, or null when it may not continue
     * anything (the first body line, or a line whose physical predecessor was blank).
     */
    public static function permittedTargetLineId(OosEmailSourceDocument $source, int $lineId): ?int
    {
        $candidate = $lineId - 1;

        return $source->hasLine($candidate) ? $candidate : null;
    }

    public static function isPermittedTarget(OosEmailSourceDocument $source, int $lineId, ?int $targetLineId): bool
    {
        $permitted = self::permittedTargetLineId($source, $lineId);

        return $permitted !== null && $targetLineId === $permitted;
    }
}
