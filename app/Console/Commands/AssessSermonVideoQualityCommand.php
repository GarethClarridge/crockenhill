<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\SermonVideoQualityStatus;
use App\Jobs\AssessSermonVideoQuality;
use App\Models\Sermon;
use App\Services\Media\MediaDiskReachability;
use Illuminate\Console\Command;

class AssessSermonVideoQualityCommand extends Command
{
    protected $signature = 'sermons:assess-video-quality
        {sermon? : Assess one sermon by id}
        {--from= : Only assess sermons on or after this YYYY-MM-DD date}
        {--to= : Only assess sermons on or before this YYYY-MM-DD date}
        {--all : Include sermons that already have an assessment verdict}
        {--reason= : Only assess sermons whose recorded assessment reason matches, e.g. missing_video_file}
        {--limit=0 : Maximum number of sermons to assess; 0 means no limit}
        {--queue : Queue assessments instead of running them sequentially}
        {--dry-run : Show matching sermons without assessing or queueing them}';

    protected $description = 'Assess sermon video quality for one sermon or a tightly controlled backfill batch';

    public function __construct(
        private readonly MediaDiskReachability $reachability,
    ) {
        parent::__construct();
    }

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

        /**
         * Bounded targeted recovery. `missing_video_file` is an evidence-access
         * failure, not an editorial decision, and it is the only class worth
         * replaying wholesale after a disk-resolution fix. Filtering on the
         * recorded reason keeps that replay off the genuinely unassessed
         * backlog and off the rejections, whose evidence stands on its own.
         */
        $reason = $this->option('reason');
        if (is_string($reason) && $reason !== '') {
            $query->where('video_quality_reason', $reason);
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

        $deferred = 0;

        foreach ($query->lazyById(25) as $sermon) {
            $count++;

            if ($dryRun) {
                $this->line("Would assess sermon #{$sermon->id} on disk [{$sermon->assetDisk()}]: {$sermon->title}");

                continue;
            }

            /**
             * The job holds its own state when the owning disk is unreachable,
             * but it does so silently. Reporting it here stops a batch run over
             * a detached volume from reading as {n} successful assessments.
             */
            $unreachable = $this->reachability->unreachableReason($sermon->assetDisk());
            if ($unreachable !== null) {
                $deferred++;
                $this->warn("Deferred sermon #{$sermon->id}: {$unreachable}");

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
        $this->info('Sermon video quality backfill '.$action.' '.($count - $deferred).' sermon(s).');

        if ($deferred > 0) {
            $this->warn("{$deferred} sermon(s) were deferred because their owning disk is unreachable; their existing state is unchanged.");
        }

        return self::SUCCESS;
    }
}
