<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Backup\Config\Config;
use Spatie\Backup\Notifications\Notifiable;
use Spatie\Backup\Notifications\Notifications\BackupHasFailedNotification;
use Tests\TestCase;
use ZipArchive;

/**
 * Exercises the spatie/laravel-backup adoption (June 2026 review, Phase P2):
 * a real `backup:run --only-db` shells out to mysqldump against the test
 * database and must land an encrypted zip on the backups disk, and a failed
 * run must notify the admin mailbox.
 */
class BackupRunCommandTest extends TestCase
{
    #[Test]
    public function backup_run_writes_an_encrypted_database_backup_to_the_backups_disk(): void
    {
        Notification::fake();
        Storage::fake('do_spaces_backups');
        config(['backup.backup.password' => 'test-archive-password']);

        // The package resolves its scoped Config object during application
        // boot and the zip writer re-fetches it from the container, so the
        // runtime password override only applies after the stale instance is
        // forgotten; --config does the same for the command's own copy.
        $this->app->forgetInstance(Config::class);

        $this->artisan('backup:run', ['--only-db' => true, '--config' => 'backup'])
            ->assertSuccessful();

        $zips = array_values(array_filter(
            Storage::disk('do_spaces_backups')->allFiles(),
            fn (string $file): bool => str_ends_with($file, '.zip'),
        ));

        $this->assertCount(1, $zips, 'Expected exactly one backup zip on the backups disk.');

        $zip = new ZipArchive;
        $this->assertTrue($zip->open(Storage::disk('do_spaces_backups')->path($zips[0])));

        $dumpIndex = null;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            if (str_ends_with((string) $zip->getNameIndex($i), '.sql.gz')) {
                $dumpIndex = $i;

                break;
            }
        }

        $this->assertNotNull($dumpIndex, 'Backup zip should contain a gzipped database dump.');

        $stat = $zip->statIndex($dumpIndex);
        $this->assertIsArray($stat);
        $this->assertNotSame(
            ZipArchive::EM_NONE,
            $stat['encryption_method'],
            'The database dump entry should be encrypted inside the archive.',
        );

        $zip->setPassword('test-archive-password');
        $this->assertNotFalse($zip->getFromIndex($dumpIndex), 'The dump should extract with the archive password.');

        $zip->close();
    }

    #[Test]
    public function a_failed_backup_notifies_the_admin_mailbox(): void
    {
        Notification::fake();
        Storage::fake('do_spaces_backups');
        config(['backup.backup.source.databases' => ['nonexistent-connection']]);

        $this->artisan('backup:run', ['--only-db' => true, '--config' => 'backup'])->assertFailed();

        Notification::assertSentTo(new Notifiable, BackupHasFailedNotification::class);
    }
}
