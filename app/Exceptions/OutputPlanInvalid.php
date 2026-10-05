<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Services\ChurchService\PublicationPlanValidator;
use RuntimeException;

/** A cut that {@see PublicationPlanValidator} will not let extraction execute. */
class OutputPlanInvalid extends RuntimeException
{
    /** @param  list<array{kind: string, section_ids: list<int>}>  $violations */
    public function __construct(public readonly array $violations, string $detail = '')
    {
        parent::__construct('cut_plan_invalid: '.($detail !== '' ? $detail : json_encode($violations)));
    }
}
