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

    /**
     * Plans applied to a service that already existed, as opposed to one this import created.
     *
     * @return list<OosEmailImportPlanOutcome>
     */
    public function merged(): array
    {
        return array_values(array_filter(
            $this->plans,
            static fn (OosEmailImportPlanOutcome $plan): bool => $plan->outcome === OosEmailImportOutcome::Merged,
        ));
    }

    /** @return list<OosEmailImportPlanOutcome> */
    public function evidenceRetained(): array
    {
        return array_values(array_filter(
            $this->plans,
            static fn (OosEmailImportPlanOutcome $plan): bool => $plan->outcome === OosEmailImportOutcome::EvidenceRetained,
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
     * first plan that resolved to any service (merged or staged), so redirects still land somewhere.
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
     * Plans whose import threw (a DB or sync error) rather than reaching a clean outcome.
     * A caller must treat these as errors — never as a silent hold — so the queue retry/failed
     * path runs and admins see a failure instead of a false "processed".
     *
     * @return list<OosEmailImportPlanOutcome>
     */
    public function failed(): array
    {
        return array_values(array_filter(
            $this->plans,
            static fn (OosEmailImportPlanOutcome $plan): bool => $plan->outcome === OosEmailImportOutcome::Failed,
        ));
    }

    public function hasFailures(): bool
    {
        return $this->failed() !== [];
    }

    /**
     * Every plan reached a terminal outcome (created/merged) and there was at least one.
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
     * The email is resolved when every plan either reached a terminal outcome or was attached
     * to a service whose canonical merge is awaiting separate service-level review.
     *
     * A held plan without a service still needs email-level attention and must remain pending.
     */
    public function isResolvedForEmail(): bool
    {
        return $this->plans !== [] && array_reduce(
            $this->plans,
            static fn (bool $carry, OosEmailImportPlanOutcome $plan): bool => $carry
                && ($plan->outcome->isTerminal() || $plan->churchService instanceof ChurchService),
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
