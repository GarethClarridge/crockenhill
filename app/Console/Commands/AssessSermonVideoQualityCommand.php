<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\SermonVideoQualityStatus;
use App\Jobs\AssessSermonVideoQuality;
use App\Models\Sermon;
use Illuminate\Console\Command;

class AssessSermonVideoQualityCommand extends Command
{
    protected $signature = 'sermons:assess-video-quality
        {sermon? : Assess one sermon by id}
        {--from= : Only assess sermons on or after this YYYY-MM-DD date}
        {--to= : Only assess sermons on or before this YYYY-MM-DD date}
        {--all : Include sermons that already have an assessment verdict}
        {--limit=0 : Maximum number of sermons to assess; 0 means no limit}
        {--queue : Queue assessments instead of running them sequentially}
        {--dry-run : Show matching sermons without assessing or queueing them}';

    protected $description = 'Assess sermon video quality for one sermon or a tightly controlled backfill batch';

    public function handle(): int
    {
        $query = Sermon::query()
            ->withVideo()
            ->orderBy('id');

        $sermonId = $this->argument('sermon');
        if (is_numeric($sermonId)) {
            $query->whereKey((int) $sermonId);
        }

        $from = $this->option('from');
        if (is_string($from) && $from !== '') {
            $query->whereDate('date', '>=', $from);
        }

        $to = $this->option('to');
        if (is_string($to) && $to !== '') {
            $query->whereDate('date', '<=', $to);
        }

        if (! (bool) $this->option('all')) {
            $query->where('video_quality_status', SermonVideoQualityStatus::Unassessed->value);
        }

        $limit = max(0, (int) $this->option('limit'));
        if ($limit > 0) {
            $query->limit($limit);
        }

        $dryRun = (bool) $this->option('dry-run');
        $queue = (bool) $this->option('queue');
        $count = 0;

        if ($dryRun) {
            $this->warn('DRY RUN enabled. No assessments will be changed or queued.');
        }

        $this->line('Assessments run sequentially by default to avoid filling temporary disk with downloaded remote videos.');

        foreach ($query->lazyById(25) as $sermon) {
            $count++;

            if ($dryRun) {
                $this->line("Would assess sermon #{$sermon->id}: {$sermon->title}");

                continue;
            }

            if ($queue) {
                dispatch((new AssessSermonVideoQuality(sermonId: $sermon->id))
                    ->onQueue(config('media-processing.queues.video', 'video-processing')));
                $this->line("Queued sermon #{$sermon->id}: {$sermon->title}");

                continue;
            }

            app()->call([new AssessSermonVideoQuality(sermonId: $sermon->id), 'handle']);
            $this->line("Assessed sermon #{$sermon->id}: {$sermon->title}");
        }

        if ($count === 0) {
            $this->info('No matching sermon videos require assessment.');

            return self::SUCCESS;
        }

        $action = $dryRun ? 'matched' : ($queue ? 'queued' : 'assessed');
        $this->info("Sermon video quality backfill {$action} {$count} sermon(s).");

        return self::SUCCESS;
    }
}
