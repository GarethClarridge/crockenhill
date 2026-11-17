<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ServiceSectionType;
use App\Models\ServiceSection;
use App\Services\ChurchService\TalkTypeProposer;
use Illuminate\Console\Command;
use Throwable;

/** Standing existing-section route: proposal JSON only; no processing or publication dispatch. */
class ProposeTalkTypesCommand extends Command
{
    protected $signature = 'service:propose-talk-types
        {--section=* : Explicit section IDs (repeatable; mutually exclusive with --run)}
        {--run= : Select this run\'s settled short-talk sections}
        {--apply : Persist proposals; the default is a read-only preview}
        {--refresh-reason= : Changed-input explanation or named evaluation sample, included in input identity}';

    protected $description = 'Preview or propose talk types on existing settled short-talk sections';

    public function handle(TalkTypeProposer $proposer): int
    {
        $ids = $this->option('section');
        $run = $this->option('run');
        $refresh = $this->option('refresh-reason');
        if (($ids === []) === ($run === null)
            || collect([...$ids, ...($run === null ? [] : [$run])])->contains(fn (mixed $id): bool => ! ctype_digit((string) $id) || (int) $id < 1)
            || ($refresh !== null && trim((string) $refresh) === '')) {
            $this->error('Supply either explicit positive --section IDs or one --run; a refresh reason must be nonblank.');

            return self::FAILURE;
        }
        $refresh = $refresh === null ? null : trim((string) $refresh);
        $query = ServiceSection::query()->notSuperseded();
        if ($run !== null) {
            $query->where('media_processing_log_id', (int) $run)->where('section_type', ServiceSectionType::ShortTalk->value);
        } else {
            $query->whereKey(array_map(intval(...), $ids));
        }
        $failed = false;
        $count = 0;
        $seen = [];
        foreach ($query->orderBy('id')->lazyById() as $section) {
            $count++;
            $seen[] = $section->id;
            try {
                $input = $proposer->input($section, $refresh);
                $reasons = $proposer->ineligibleReasons($input);
                if ($reasons !== []) {
                    $this->line($section->id.' skipped: '.implode(', ', $reasons));

                    continue;
                }
                $old = $section->metadata?->talkType?->raw['input_hash'] ?? null;
                $change = $old === null ? 'new' : ($old === $input->inputHash() ? 'unchanged' : 'changed');
                $this->line($section->id.' eligible '.$change.' input_hash='.$input->inputHash().' previous_hash='.($old ?? 'none'));
                if ($this->option('apply')) {
                    $outcome = $proposer->propose($section, refreshReason: $refresh);
                    $this->line($section->id.' '.$outcome.' '.$this->proposalSummary($section->fresh() ?? $section));
                    $failed = $failed || in_array($outcome, ['failed', 'stale'], true);
                }
            } catch (Throwable $exception) {
                $failed = true;
                $this->error($section->id.' failed: '.$exception->getMessage());
            }
        }
        if ($run === null) {
            foreach (array_diff(array_unique(array_map(intval(...), $ids)), $seen) as $missingId) {
                $this->line($missingId.' skipped: missing_or_superseded');
            }
        }
        if ($count === 0) {
            $this->line('No selected sections; skipped missing or superseded selection.');
        }
        $this->info(($this->option('apply') ? 'Apply' : 'Preview').' finished: '.$count.' selected section(s).');

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function automaticOutcome(ServiceSection $section): string
    {
        $reviewed = $section->metadata?->talkType?->reviewed;

        return ($reviewed['source'] ?? null) === 'automatic' ? (string) ($reviewed['value'] ?? '-') : '-';
    }

    private function proposalSummary(ServiceSection $section): string
    {
        $proposal = $section->metadata?->talkType?->proposal();

        return 'proposed_type='.($proposal->proposed->value ?? 'none')
            .' disposition='.($proposal->disposition ?? 'none')
            .' proposal_status='.($proposal->status ?? 'none')
            .' testimony_risk='.(($proposal?->signals['testimony_risk'] ?? false) ? 'yes' : 'no')
            .' reviewed_type='.($section->publicationTalkType()->value ?? 'none')
            .' publication_status='.$section->publication_status->value
            .' confirmed_automatically='.$this->automaticOutcome($section);
    }
}
