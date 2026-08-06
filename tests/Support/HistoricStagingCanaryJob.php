<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

class HistoricStagingCanaryJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        Storage::disk((string) config('media-processing.storage.sermon_disk'))->put('canary/sermon.txt', 'sermon');
        Storage::disk((string) config('media-processing.storage.transcript_disk'))->put('canary/transcript.txt', 'transcript');
        Storage::disk((string) config('media-processing.storage.temp_disk'))->put('canary/temp.txt', 'temp');
        Storage::disk((string) config('thumbnail-generation.storage.disk'))->put('canary/thumbnail.txt', 'thumbnail');
        Storage::disk((string) config('thumbnail-generation.processing.temp_disk'))->put('canary/thumbnail-temp.txt', 'thumbnail-temp');
    }
}
