<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\OosEmailImportOutcome;
use App\Models\ChurchService;

/**
 * Aggregate result of importing an inbound OoS email that may contain several service plans.
 * The email is only fully resolved when every plan reached a terminal outcome.
 */
readonly class OosEmailImportResult
{
    /**
     * @param  list<OosEmailImportPlanOutcome>  $plans
     */
    public function __construct(
        public array $plans,
    ) {}

    /**
     * @return list<OosEmailImportPlanOutcome>
     */
    public function created(): array
    {
        return array_values(array_filter(
            $this->plans,
            static fn (OosEmailImportPlanOutcome $plan): bool => $plan->outcome === OosEmailImportOutcome::Created,
        ));
    }

    public function firstCreatedService(): ?ChurchService
    {
        foreach ($this->plans as $plan) {
            if ($plan->outcome === OosEmailImportOutcome::Created && $plan->churchService instanceof ChurchService) {
                return $plan->churchService;
            }
        }

        return null;
    }

    /**
     * The service a caller should surface after importing: the first created service, else the
     * first plan that resolved to any service (merged/skipped), so redirects still land somewhere.
     */
    public function primaryService(): ?ChurchService
    {
        return $this->firstCreatedService() ?? $this->firstResolvedService();
    }

    public function firstResolvedService(): ?ChurchService
    {
        foreach ($this->plans as $plan) {
            if ($plan->churchService instanceof ChurchService) {
                return $plan->churchService;
            }
        }

        return null;
    }

    /**
     * @return list<int>
     */
    public function importedServiceIds(): array
    {
        $ids = [];

        foreach ($this->plans as $plan) {
            if ($plan->churchService instanceof ChurchService && ! in_array($plan->churchService->id, $ids, true)) {
                $ids[] = $plan->churchService->id;
            }
        }

        return $ids;
    }

    public function hasImportedService(): bool
    {
        return $this->importedServiceIds() !== [];
    }

    /**
     * Every plan reached a terminal outcome (created/merged/skipped) and there was at least one.
     */
    public function isFullyResolved(): bool
    {
        return $this->plans !== [] && array_reduce(
            $this->plans,
            static fn (bool $carry, OosEmailImportPlanOutcome $plan): bool => $carry && $plan->outcome->isTerminal(),
            true,
        );
    }

    /**
     * @return list<array{plan_key:string,service:?string,date:?string,outcome:string,church_service_id:?int,message:?string}>
     */
    public function toArray(): array
    {
        return array_map(
            static fn (OosEmailImportPlanOutcome $plan): array => $plan->toArray(),
            $this->plans,
        );
    }
}
