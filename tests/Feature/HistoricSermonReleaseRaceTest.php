<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\SermonPublicationState;
use App\Enums\SermonService;
use App\Models\HistoricImportOperation;
use App\Models\Sermon;
use App\Services\Import\HistoricSermonPublicationService;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FilesystemAdapter as FlysystemAdapter;
use League\Flysystem\FilesystemOperator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * HIR0 red test for review finding 1 (High), owned by package **HIR7**.
 *
 * `HistoricSermonPublicationService::copyVerified()` decides ownership of a
 * final public path with `exists()` followed by `writeStream()`, records the
 * path in a process-local `$created` list, and the outer catch deletes every
 * path in that list on any later failure. Neither half re-checks that this
 * attempt still exclusively owns the object.
 *
 * The interleaving below is the one the review names: two attempts both observe
 * the final path as absent, both write it, one commits, and the loser's
 * compensation deletes the winner's published asset.
 *
 * Concurrency is made deterministic rather than simulated with threads. The
 * quarantine disk is wrapped so that the winning attempt completes — bytes
 * published *and* row committed — at the exact point the losing attempt has
 * already decided the destination was absent and is opening its source stream.
 * That is the only window in which the defect is reachable, so a test that
 * pauses anywhere else would pass vacuously.
 *
 * @see docs/reviews/historic-import-commit-review-2026-08-12.md finding 1
 * @see docs/plans/HISTORIC-IMPORT-SAFETY-REMEDIATION-2026-08-12.md §13 (HIR7)
 */
#[Group('hir-red')]
class HistoricSermonReleaseRaceTest extends TestCase
{
    use RefreshDatabase;

    private const AudioPath = 'sermons/historic/audio.mp3';

    private const AudioBytes = 'released-sermon-audio-bytes';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'church.services.public_from' => '2000-01-01',
            'media-processing.storage.historic_quarantine_disk' => 'historic_quarantine',
            'media-processing.storage.sermon_disk' => 'public',
        ]);
        Storage::fake('historic_quarantine');
        Storage::fake('public');
    }

    #[Test]
    public function a_losing_release_attempt_does_not_delete_the_winners_published_asset(): void
    {
        $operation = $this->operation();
        $sermon = $this->quarantinedSermon($operation);
        Storage::disk('historic_quarantine')->put(self::AudioPath, self::AudioBytes);

        // The winner: identical bytes at the same final path, then a committed
        // row. Both happen while the loser is between its `exists()` check and
        // its own write, which is exactly when both attempts believe they are
        // the object's creator.
        $this->completeWinningAttemptOnSecondQuarantineRead(function () use ($sermon): void {
            Storage::disk('public')->put(self::AudioPath, self::AudioBytes);
            Sermon::query()->whereKey($sermon->id)->update([
                'publication_state' => SermonPublicationState::Published->value,
                'asset_disk' => 'public',
            ]);
        });

        try {
            app(HistoricSermonPublicationService::class)->releaseRecords($operation, [$sermon->id], []);
            $this->fail('The losing attempt should have failed its commit-time binding check.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('binding changed before commit', $exception->getMessage());
        }

        // The winner is published and advertises the asset, so the bytes must
        // still be there. A release that loses a race is allowed to fail; it is
        // not allowed to take the winner's published asset with it.
        $this->assertDatabaseHas('sermons', [
            'id' => $sermon->id,
            'publication_state' => SermonPublicationState::Published->value,
            'asset_disk' => 'public',
        ]);
        Storage::disk('public')->assertExists(self::AudioPath);
        $this->assertSame(self::AudioBytes, Storage::disk('public')->get(self::AudioPath));
    }

    /**
     * Run `$winner` when the release opens its source stream — the second read
     * of the quarantined asset, after the hash read and after the destination
     * has already been observed as absent.
     */
    private function completeWinningAttemptOnSecondQuarantineRead(callable $winner): void
    {
        $quarantine = Storage::disk('historic_quarantine');

        Storage::set('historic_quarantine', new class($quarantine->getDriver(), $quarantine->getAdapter(), $quarantine->getConfig(), $winner) extends FilesystemAdapter
        {
            private int $reads = 0;

            /** @var callable */
            private $winner;

            /** @param array<string, mixed> $config */
            public function __construct(
                FilesystemOperator $driver,
                FlysystemAdapter $adapter,
                array $config,
                callable $winner,
            ) {
                parent::__construct($driver, $adapter, $config);

                $this->winner = $winner;
            }

            public function readStream($path)
            {
                $this->reads++;

                if ($this->reads === 2) {
                    ($this->winner)();
                }

                return parent::readStream($path);
            }
        });
    }

    private function operation(): HistoricImportOperation
    {
        return HistoricImportOperation::query()->create([
            'operation_id' => 'historic-'.str_repeat('a', 32),
            'binding_hash' => str_repeat('b', 64),
            'batch_key' => 'historic-release-race',
            'manifest_hashes' => ['video' => str_repeat('c', 64)],
            'plan_hash' => str_repeat('d', 64),
            'target_fingerprint' => str_repeat('e', 64),
            'runtime_fingerprint' => str_repeat('f', 64),
            'notification_mode' => 'external_disabled',
            'max_cost_minor_units' => 100,
        ]);
    }

    private function quarantinedSermon(HistoricImportOperation $operation): Sermon
    {
        return Sermon::factory()->create([
            'date' => '2026-01-04',
            'service' => SermonService::Morning,
            'publication_state' => SermonPublicationState::Quarantined,
            'asset_disk' => 'historic_quarantine',
            'historic_import_operation_id' => $operation->id,
            'audio_file_path' => self::AudioPath,
        ]);
    }
}
