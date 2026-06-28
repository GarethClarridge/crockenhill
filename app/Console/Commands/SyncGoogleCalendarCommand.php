<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Calendar\GoogleCalendarSyncService;
use App\Traits\SanitizesLogData;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncGoogleCalendarCommand extends Command
{
    use SanitizesLogData;

    protected $signature = 'calendar:sync';

    protected $description = 'Syncs events from Google Calendar using the configured window';

    public function handle(GoogleCalendarSyncService $calendarService): int
    {
        $this->info('Starting Google Calendar sync...');

        try {
            $report = $calendarService->syncFromGoogleCalendar();

            $this->info('Sync completed successfully!');
            $this->info("Sync window: {$report['start_date']} to {$report['end_date']}");
            $this->info("Processed: {$report['processed_events']}, Deleted: {$report['deleted_events']}");
            $this->info("Uncategorized: {$report['uncategorized_events']}");

            if ($report['uncategorized_events'] > 0) {
                $this->warn('Review uncategorized events in the admin panel.');
            }

        } catch (Exception $e) {
            $this->error('Sync failed: '.$e->getMessage());
            Log::error('Calendar sync failed', $this->sanitizeArrayForLog([
                'error' => $e->getMessage(),
            ]));

            return 1;
        }

        return 0;
    }
}
