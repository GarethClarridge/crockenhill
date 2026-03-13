<?php

declare(strict_types=1);

namespace App\Actions;

use App\Jobs\FetchBibleTextForSermon;
use App\Models\Sermon;

/**
 * Shared dispatcher for scripture enrichment.
 *
 * Call this after any code path that persists sermons.reference to ensure
 * the enrichment job is always dispatched asynchronously, keeping the main
 * processing chain decoupled from external API calls.
 */
class QueueScriptureEnrichment
{
    public function dispatch(Sermon $sermon): void
    {
        if (! config('services.api_bible.enabled')) {
            return;
        }

        if (empty($sermon->reference)) {
            return;
        }

        FetchBibleTextForSermon::dispatch($sermon);
    }
}
