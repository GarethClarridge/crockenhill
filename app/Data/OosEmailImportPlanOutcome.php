<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\OosEmailImportOutcome;
use App\Enums\SermonService;
use App\Models\ChurchService;

/**
 * The result of importing one {@see OosEmailServicePlan}: what happened, and the service it
 * resolved to (when one exists).
 */
readonly class OosEmailImportPlanOutcome
{
    public function __construct(
        public string $planKey,
        public ?SermonService $service,
        public ?string $date,
        public OosEmailImportOutcome $outcome,
        public ?ChurchService $churchService = null,
        public ?string $message = null,
    ) {}

    /**
     * @return array{plan_key:string,service:?string,date:?string,outcome:string,church_service_id:?int,message:?string}
     */
    public function toArray(): array
    {
        return [
            'plan_key' => $this->planKey,
            'service' => $this->service?->value,
            'date' => $this->date,
            'outcome' => $this->outcome->value,
            'church_service_id' => $this->churchService?->id,
            'message' => $this->message,
        ];
    }
}
