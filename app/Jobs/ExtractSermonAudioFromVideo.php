<?php

namespace Crockenhill\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Crockenhill\Service;

class ExtractSermonAudioFromVideo implements ShouldQueue
{
  use Queueable;

  /**
   * Create a new job instance.
   */
  public function __construct(Service $service)
  {
    $this->service = $service;
  }

  /**
   * Execute the job.
   */
  public function handle(): void
  {
            // open the uploaded video from the right disk...
            FFMpeg::fromDisk('local')
            ->open($this->video->path)
 
            $audio = FFMpeg::fromDisk('local')
            ->open($path)
            ->export()
            ->toDisk('local')
            ->inFormat(new \FFMpeg\Format\Audio\Mp3())
            ->save('services/audio/' . $name . '.mp3');

  }
}
