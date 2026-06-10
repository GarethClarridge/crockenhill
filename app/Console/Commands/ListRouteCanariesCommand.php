<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Monitoring\RouteCanaryRegistry;
use Illuminate\Console\Command;

/**
 * Emits a post-deploy canary manifest: one representative live URL per public
 * route type, so a deploy smoke check can hit each distinct rendering path and
 * fail the rollout before visitors see a regression.
 *
 * Output is one tab-separated record per line: `url`, expected HTTP status,
 * number of hits, and a body marker to grep for (empty marker = status only).
 * The canary set itself lives in {@see RouteCanaryRegistry}, shared with the
 * continuous checker (the laravel-health RouteCanariesCheck).
 */
class ListRouteCanariesCommand extends Command
{
    protected $signature = 'monitoring:canaries';

    protected $description = 'Emit a post-deploy canary manifest (url, expected status, hits, body marker) covering each public route type';

    public function handle(RouteCanaryRegistry $registry): int
    {
        foreach ($registry->all() as $canary) {
            $this->line($canary->toManifestLine());
        }

        return self::SUCCESS;
    }
}
