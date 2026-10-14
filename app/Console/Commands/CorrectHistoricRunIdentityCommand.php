<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ChurchService;
use App\Models\HistoricImportOperation;
use App\Models\Sermon;
use App\Services\HistoricMedia\HistoricRunIdentityCorrection;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Correct the service a historic run was imported under when its recording was
 * filed on the wrong date, setting aside a poorer copy already on the real service.
 *
 * Deletion trigger: Delete once the historic import operation is closed out and
 * no further identity corrections can be recorded against it.
 */
class CorrectHistoricRunIdentityCommand extends Command
{
    protected $signature = 'historic-import:correct-run-identity
                            {--operation= : Exact immutable historic operation that owns the runs}
                            {--processing-id= : The run to move: the recording that was filed on the wrong date}
                            {--date= : The real service date (Y-m-d)}
                            {--service= : The real service slot (morning, evening, ...)}
                            {--duplicate= : A poorer copy already on the real service, to exclude as a duplicate recording}
                            {--note= : The operator note that justifies the correction}
                            {--apply : Write the correction (default: dry-run)}
                            {--yes : Confirm the guarded --apply operation}';

    protected $description = 'Move a misdated historic run onto its real service and set aside a poorer copy of the same recording';

    public function handle(HistoricRunIdentityCorrection $correction): int
    {
        try {
            $operation = $this->operation();
            $note = (string) ($this->option('note') ?? '');
            $apply = (bool) $this->option('apply');

            if ($apply && ! (bool) $this->option('yes')) {
                throw new RuntimeException('--apply requires --yes confirmation; no changes were written.');
            }

            $entry = $correction->inspect(
                $operation,
                $this->requiredOption('processing-id'),
                $this->requiredOption('date'),
                $this->requiredOption('service'),
                $this->optionalOption('duplicate'),
            );

            $this->report($entry, $note);

            if ($entry['already_corrected']) {
                $this->info("Run {$entry['run']->processing_id} is already corrected to {$entry['to']['date']} {$entry['to']['service']}; nothing to do.");

                return self::SUCCESS;
            }

            if (! $apply) {
                $this->warn('DRY RUN: nothing was written and no file was moved. Re-run with --apply --yes for this exact selection.');

                return self::SUCCESS;
            }

            $result = $correction->apply($operation, $entry, $note);

            $this->info(sprintf(
                'Corrected run %s to %s %s; sermons withdrawn: %d; assets relocated: %d.',
                $entry['run']->processing_id,
                $entry['to']['date'],
                $entry['to']['service'],
                $result['sermons_withdrawn'],
                $result['assets_relocated'],
            ));
            $this->line('Next: re-detect this run so it projects onto the real service, then re-cut it. Its re-dated sermon row refreshes in place.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    /** @param  array<string, mixed>  $entry */
    private function report(array $entry, string $note): void
    {
        $describe = static fn (?ChurchService $service): string => $service === null
            ? '(none)'
            : "#{$service->id} {$service->date->toDateString()} {$service->service->value}";

        $this->table(['', ''], [
            ['Run', "{$entry['run']->processing_id} (run {$entry['run']->id}, manifest item {$entry['item_key']})"],
            ['From', "{$entry['from']['date']} {$entry['from']['service']}"],
            ['To', "{$entry['to']['date']} {$entry['to']['service']}"],
            ['Real service', $entry['target_service'] === null ? '(none yet: projection will create it)' : $describe($entry['target_service'])],
            ['Duplicate', $entry['duplicate'] === null
                ? '(none)'
                : "{$entry['duplicate']->processing_id} (run {$entry['duplicate']->id}) ".($entry['duplicate_exclusion'] === [] ? 'already excluded' : 'excluded as duplicate_recording')],
            ['Misdated service', $entry['removes_service'] !== null
                ? 'removes '.$describe($entry['removes_service'])
                : ($entry['keeps_service_because'] ?? 'none to remove')],
            ['Re-dated sermons', $entry['redated_sermons'] === []
                ? 'none'
                : implode(', ', array_map(static fn (Sermon $sermon): string => "#{$sermon->id} from {$sermon->date->toDateString()}", $entry['redated_sermons']))],
            ['Withdrawn sermons', $entry['withdrawn_sermons'] === []
                ? 'none'
                : implode(', ', array_map(static fn (array $withdrawn): string => sprintf(
                    '#%d (%d file(s) kept under superseded/)',
                    $withdrawn['sermon']->id,
                    count($withdrawn['assets']),
                ), $entry['withdrawn_sermons']))],
        ]);

        $this->line('Note: '.($note === '' ? '(none given)' : $note));
    }

    private function operation(): HistoricImportOperation
    {
        $operationId = $this->requiredOption('operation');
        $operation = HistoricImportOperation::query()->where('operation_id', $operationId)->first();

        if (! $operation instanceof HistoricImportOperation) {
            throw new RuntimeException("No historic import operation exists for [{$operationId}].");
        }

        return $operation;
    }

    private function requiredOption(string $name): string
    {
        return $this->optionalOption($name) ?? throw new RuntimeException("--{$name} is required.");
    }

    private function optionalOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
