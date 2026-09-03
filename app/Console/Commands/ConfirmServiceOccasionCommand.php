<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ServiceOccasion;
use App\Enums\SermonService;
use App\Models\ChurchService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use RuntimeException;
use Throwable;

/**
 * Record an operator's confirmation of what a service was, releasing the label
 * to the public service page.
 *
 * The detector may propose an occasion — it does so when it asserts that a
 * service genuinely held no sermon — but a proposal is not a finding. Run #935
 * read a whole morning service as "fragmentary opening audio" and the very next
 * run of the same recording completed with twenty sections, so an unconfirmed
 * verdict has to surface as something a person answers rather than something a
 * visitor reads (D1/D2, 2026-09-03).
 *
 * Confirming also releases the run's retained source: `sermon_absence_unconfirmed`
 * is a review obligation until this is run.
 *
 * Deletion trigger: never — occasions keep arriving. This is the operator's
 * standing instrument for answering one.
 */
class ConfirmServiceOccasionCommand extends Command
{
    protected $signature = 'services:confirm-occasion
                            {--date= : Service date as YYYY-MM-DD}
                            {--service= : Which service that day (morning, evening, ...)}
                            {--occasion= : The confirmed occasion, or "none" to confirm this service has no special occasion}
                            {--apply : Write the confirmation (default: dry-run)}
                            {--yes : Confirm the guarded --apply operation}';

    protected $description = 'Confirm what a service was, releasing its occasion label to the public service page';

    public function handle(): int
    {
        try {
            $churchService = $this->churchService();
            $occasion = $this->occasion($churchService);
            $apply = (bool) $this->option('apply');

            if ($apply && ! (bool) $this->option('yes')) {
                throw new RuntimeException('--apply requires --yes confirmation; no changes were written.');
            }

            $this->table(
                ['Service', 'Proposed', 'Confirmed now', 'Becomes'],
                [[
                    $churchService->date->format('Y-m-d').' '.$churchService->service->value,
                    $churchService->occasion?->label() ?? '(none)',
                    $churchService->occasion_confirmed_at?->toIso8601String() ?? '(unconfirmed)',
                    $occasion?->label() ?? '(no special occasion)',
                ]],
            );

            foreach ($this->absenceAssertions($churchService) as $line) {
                $this->line($line);
            }

            $this->line('Confirming renders the occasion on the public service page and releases any source');
            $this->line('this service\'s runs were retaining for the confirmation.');

            if (! $apply) {
                $this->warn('DRY RUN: nothing was written. Re-run with --apply --yes for this exact service.');

                return self::SUCCESS;
            }

            $churchService->forceFill([
                'occasion' => $occasion,
                'occasion_confirmed_at' => now(),
            ])->saveQuietly();

            $this->info('Confirmed.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function churchService(): ChurchService
    {
        $date = $this->option('date');
        $service = $this->option('service');

        if (! is_string($date) || trim($date) === '') {
            throw new RuntimeException('--date is required and must be a YYYY-MM-DD service date.');
        }

        if (! is_string($service) || SermonService::tryFrom(trim($service)) === null) {
            throw new RuntimeException(
                '--service is required and must be one of: '.implode(', ', SermonService::values()).'.'
            );
        }

        $dateValue = Carbon::createFromFormat('!Y-m-d', trim($date));

        if (! $dateValue instanceof Carbon || $dateValue->format('Y-m-d') !== trim($date)) {
            throw new RuntimeException("[{$date}] is not a YYYY-MM-DD date.");
        }

        $churchService = ChurchService::query()
            ->whereDate('date', $dateValue->toDateString())
            ->where('service', trim($service))
            ->first();

        if (! $churchService instanceof ChurchService) {
            throw new RuntimeException("No church service exists for [{$date} {$service}].");
        }

        return $churchService;
    }

    /**
     * The occasion to confirm. Defaults to whatever the detector proposed, so
     * agreeing with it needs no restatement; `none` is how an operator confirms
     * a service that simply has no special occasion, which is a real answer and
     * not the absence of one.
     */
    private function occasion(ChurchService $churchService): ?ServiceOccasion
    {
        $requested = $this->option('occasion');

        if (! is_string($requested) || trim($requested) === '') {
            return $churchService->occasion;
        }

        if (trim($requested) === 'none') {
            return null;
        }

        $occasion = ServiceOccasion::tryFrom(trim($requested));

        if ($occasion === null) {
            throw new RuntimeException(
                "[{$requested}] is not a known occasion. Use one of: ".implode(', ', ServiceOccasion::values()).', or "none".'
            );
        }

        return $occasion;
    }

    /**
     * What the detector said stood in the sermon's place, from every run of this
     * service that asserted one — the evidence the operator is confirming.
     *
     * @return list<string>
     */
    private function absenceAssertions(ChurchService $churchService): array
    {
        $lines = [];

        foreach ($churchService->mediaProcessingLogs()->whereNull('superseded_at')->get() as $run) {
            $absence = $run->assertedSermonAbsence();

            if ($absence === null) {
                continue;
            }

            $lines[] = "  {$run->processing_id}: {$absence->explanation}";
        }

        return $lines === [] ? ['  (no run of this service asserts a missing sermon)'] : $lines;
    }
}
