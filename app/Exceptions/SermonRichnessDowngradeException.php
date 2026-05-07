<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\MediaType;
use App\Enums\SermonService;
use Illuminate\Support\Carbon;

class SermonRichnessDowngradeException extends ProcessingException
{
    public function __construct(
        Carbon $date,
        SermonService $service,
        MediaType $existingType,
        MediaType $incomingType,
    ) {
        $dateStr = $date->toDateString();
        $serviceStr = $service->value;

        parent::__construct(
            "Refusing to overwrite richer sermon. Existing sermon for {$dateStr} {$serviceStr} is a {$existingType->value}; incoming is {$incomingType->value}."
        );
    }
}
