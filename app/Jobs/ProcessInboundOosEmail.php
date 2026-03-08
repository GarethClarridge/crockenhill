<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\InboundEmail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessInboundOosEmail implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        private InboundEmail $inboundEmail,
    ) {}

    public function handle(): void
    {
        // TODO: Phase 2.2 parses and imports the inbound email payload.
    }

    /**
     * @return list<string>
     */
    public function tags(): array
    {
        return ['inbound_email:'.$this->inboundEmail->getKey()];
    }
}
