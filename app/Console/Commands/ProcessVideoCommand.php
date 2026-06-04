<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Sermon\LivestreamCreateSermonService;
use Illuminate\Console\Command;

class ProcessVideoCommand extends Command
{
    protected $signature = 'livestream:create-sermon {processing_id : The processing ID}';

    protected $description = 'Manually create a sermon from processed livestream segments';

    public function handle(LivestreamCreateSermonService $livestreamCreateSermonService): int
    {
        try {
            $result = $livestreamCreateSermonService->resumeUsingLongestSpeechSegment((string) $this->argument('processing_id'));

            $this->info(sprintf(
                'Confirmed sermon segment %d: %.1fs to %.1fs (duration: %.1fs)',
                $result['segment_id'],
                $result['start_time'],
                $result['end_time'],
                $result['duration'],
            ));
            $this->info('Resumed the canonical livestream sermon flow.');

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
