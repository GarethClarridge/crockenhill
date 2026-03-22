<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\MeetingPhotoMigrationService;
use Illuminate\Console\Command;

class MeetingMigratePhotosCommand extends Command
{
    protected $signature = 'meetings:migrate-photos
                            {--dry-run : Show what would be migrated without making changes}';

    protected $description = 'Migrate legacy meeting photos from public/images/meetings/ to Spatie Media Library';

    public function handle(MeetingPhotoMigrationService $meetingPhotoMigrationService): int
    {
        $result = $meetingPhotoMigrationService->migrate((bool) $this->option('dry-run'));
        $summary = $result['summary'];

        $this->info("Found {$summary['meetings_examined']} meetings to check.");
        $this->newLine();

        foreach ($result['items'] as $item) {
            $this->line($item['label']);
        }

        $this->newLine();
        $this->info('Migration complete:');
        $this->line("  Migrated: {$summary['migrated']}");
        $this->line("  Skipped: {$summary['skipped']}");
        $this->line("  Errors: {$summary['failed']}");

        return $summary['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
