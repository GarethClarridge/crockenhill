<?php

namespace App\Console\Commands;

use App\Services\CalendarService;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncGoogleCalendarCommand extends Command
{
    protected $signature = 'calendar:sync';

    protected $description = 'Syncs events from Google Calendar using the configured window';

    public function handle(CalendarService $calendarService): int
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
            Log::error('Calendar sync failed', ['error' => $e->getMessage()]);

            return 1;
        }

        return 0;
    }
}
