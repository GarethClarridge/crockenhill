<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ServiceSection;
use App\Services\ChurchService\ServiceSectionSyncService;
use App\Support\TranscriptPromptEchoDetector;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Removes historical service sections created solely from Whisper prompt echoes.
 *
 * Deletion trigger: remove this one-shot command after all production prompt-echo
 * sections have been scrubbed and the 2026 livestream repair audit is closed.
 */
class ScrubPromptEchoSectionsCommand extends Command
{
    protected $signature = 'service:scrub-prompt-echo-sections
                            {--service=* : Limit to these church service IDs}
                            {--include-superseded : Also scrub superseded runs}
                            {--apply : Write changes (default: dry-run)}';

    protected $description = 'Remove service sections created from leaked Whisper prompt text';

    public function handle(
        TranscriptPromptEchoDetector $detector,
        ServiceSectionSyncService $syncService,
    ): int {
        $apply = (bool) $this->option('apply');
        $sections = $this->sections($detector);

        if ($sections->isEmpty()) {
            $this->info('No prompt-echo sections found for the given scope.');

            return self::SUCCESS;
        }

        $this->line($apply ? '<fg=yellow>APPLYING</> — removing prompt-echo sections:' : '<fg=cyan>DRY RUN</> — prompt-echo sections that would be removed:');
        $rows = [];

        foreach ($sections as $section) {
            $rows[] = [
                $section->processingLog->church_service_id,
                $section->media_processing_log_id,
                $section->id,
                $section->section_order,
                $section->metadata?->transcript,
            ];

            if ($apply) {
                $syncService->removeSection($section);
            }
        }

        $this->table(['svc', 'run', 'section', 'order', 'prompt echo'], $rows);
        $this->info(sprintf('%s %d section(s).', $apply ? 'Removed' : 'Would remove', $sections->count()));
        $this->dryRunNotice($apply);

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, ServiceSection>
     */
    private function sections(TranscriptPromptEchoDetector $detector): Collection
    {
        /** @var list<string> $serviceIds */
        $serviceIds = (array) $this->option('service');
        $includeSuperseded = (bool) $this->option('include-superseded');

        return ServiceSection::query()
            ->with('processingLog')
            ->whereNotNull('metadata->transcript')
            ->whereHas('processingLog', function (Builder $query) use ($serviceIds, $includeSuperseded): void {
                $query->when($serviceIds !== [], fn (Builder $query): Builder => $query->whereIn('church_service_id', $serviceIds));
                if (! $includeSuperseded) {
                    $query->whereNull('superseded_at');
                }
            })
            ->orderBy('media_processing_log_id')
            ->orderBy('section_order')
            ->get()
            ->filter(fn (ServiceSection $section): bool => $detector->isPromptEcho((string) $section->metadata?->transcript))
            ->values();
    }

    private function dryRunNotice(bool $apply): void
    {
        if (! $apply) {
            $this->comment('No changes written. Re-run with --apply to persist.');
        }
    }
}
