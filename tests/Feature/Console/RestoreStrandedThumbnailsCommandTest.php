<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Sermon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RestoreStrandedThumbnailsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        Storage::fake('public');
        Storage::fake('local');
        Storage::fake('do_spaces');

        // The production shape: the thumbnail disk was repointed at Spaces,
        // leaving the existing objects behind on `public`.
        config([
            'media-processing.storage.sermon_disk' => 'do_spaces',
            'media-processing.storage.transcript_disk' => 'do_spaces',
            'thumbnail-generation.storage.disk' => 'do_spaces',
        ]);
    }

    #[Test]
    public function it_copies_a_stranded_object_and_verifies_it_landed(): void
    {
        Sermon::factory()->create(['thumbnail_file_path' => 'sermons/thumbnails/stranded.webp']);
        Storage::disk('public')->put('sermons/thumbnails/stranded.webp', 'thumbnail bytes');

        // `restored` counts a transition, so it is only observable on the run
        // that performs it — hence asserting on this run's report rather than a
        // second invocation's.
        $report = $this->report('--apply');

        $this->assertSame(1, $report['objects']['restored']);
        Storage::disk('do_spaces')->assertExists('sermons/thumbnails/stranded.webp');
        $this->assertSame('thumbnail bytes', Storage::disk('do_spaces')->get('sermons/thumbnails/stranded.webp'));
    }

    /**
     * The whole point of the design: the stored path was always correct, so no
     * column is rewritten. A restore that touched the database would be a second
     * migration to verify rather than a file copy.
     */
    #[Test]
    public function it_never_rewrites_a_stored_path(): void
    {
        $sermon = Sermon::factory()->create(['thumbnail_file_path' => 'sermons/thumbnails/stranded.webp']);
        Storage::disk('public')->put('sermons/thumbnails/stranded.webp', 'thumbnail bytes');

        $this->artisan('media:restore-stranded-thumbnails --apply')->assertSuccessful();

        $this->assertSame('sermons/thumbnails/stranded.webp', $sermon->fresh()?->thumbnail_file_path);
    }

    #[Test]
    public function it_leaves_every_source_in_place(): void
    {
        Sermon::factory()->create(['thumbnail_file_path' => 'sermons/thumbnails/stranded.webp']);
        Storage::disk('public')->put('sermons/thumbnails/stranded.webp', 'thumbnail bytes');

        $this->artisan('media:restore-stranded-thumbnails --apply')->assertSuccessful();

        // Retaining the source is what makes the run trivially reversible.
        Storage::disk('public')->assertExists('sermons/thumbnails/stranded.webp');
    }

    #[Test]
    public function it_writes_nothing_without_the_apply_flag(): void
    {
        Sermon::factory()->create(['thumbnail_file_path' => 'sermons/thumbnails/stranded.webp']);
        Storage::disk('public')->put('sermons/thumbnails/stranded.webp', 'thumbnail bytes');

        $this->artisan('media:restore-stranded-thumbnails')
            ->expectsOutputToContain('DRY RUN')
            ->assertSuccessful();

        Storage::disk('do_spaces')->assertMissing('sermons/thumbnails/stranded.webp');

        $report = $this->report();

        $this->assertSame(1, $report['objects']['restorable']);
        $this->assertSame(0, $report['objects']['restored']);
    }

    #[Test]
    public function it_leaves_an_object_already_on_the_target_disk_untouched(): void
    {
        Sermon::factory()->create(['thumbnail_file_path' => 'sermons/thumbnails/already-there.webp']);
        Storage::disk('do_spaces')->put('sermons/thumbnails/already-there.webp', 'live bytes');
        Storage::disk('public')->put('sermons/thumbnails/already-there.webp', 'live bytes');

        $this->artisan('media:restore-stranded-thumbnails --apply')->assertSuccessful();

        $this->assertSame('live bytes', Storage::disk('do_spaces')->get('sermons/thumbnails/already-there.webp'));

        $report = $this->report('--apply');

        $this->assertSame(1, $report['objects']['already_present']);
        $this->assertSame(0, $report['objects']['restored']);
    }

    /**
     * Idempotence is what lets an interrupted run simply be re-run rather than
     * reasoned about.
     */
    #[Test]
    public function it_is_a_no_op_on_a_second_run(): void
    {
        Sermon::factory()->create(['thumbnail_file_path' => 'sermons/thumbnails/stranded.webp']);
        Storage::disk('public')->put('sermons/thumbnails/stranded.webp', 'thumbnail bytes');

        $this->artisan('media:restore-stranded-thumbnails --apply')->assertSuccessful();

        $second = $this->report('--apply');

        $this->assertSame(0, $second['objects']['restored']);
        $this->assertSame(1, $second['objects']['already_present']);
        $this->assertSame('thumbnail bytes', Storage::disk('do_spaces')->get('sermons/thumbnails/stranded.webp'));
    }

    /**
     * These are the genuine losses. Reporting them separately from the
     * recoverable ones is what turns the dry run into WP1's measurement.
     */
    #[Test]
    public function it_reports_an_object_missing_from_both_disks_rather_than_skipping_it(): void
    {
        Sermon::factory()->create(['thumbnail_file_path' => 'sermons/thumbnails/destroyed.webp']);

        $this->artisan('media:restore-stranded-thumbnails --apply')
            ->expectsOutputToContain('Unrecoverable objects are genuinely gone')
            ->assertSuccessful();

        $report = $this->report('--apply');

        $this->assertSame(1, $report['objects']['unrecoverable']);
        $this->assertSame(1, $report['references']['thumbnail']['unrecoverable']);
    }

    /**
     * A live key is never overwritten, even when the source disagrees with it.
     * A size disagreement is the signature of a truncated upload and needs a
     * human, but guessing which copy is right is not this command's job.
     */
    #[Test]
    public function it_refuses_to_overwrite_a_target_that_disagrees_with_its_source(): void
    {
        Sermon::factory()->create(['thumbnail_file_path' => 'sermons/thumbnails/disputed.webp']);
        Storage::disk('do_spaces')->put('sermons/thumbnails/disputed.webp', 'truncated');
        Storage::disk('public')->put('sermons/thumbnails/disputed.webp', 'the full original bytes');

        $this->artisan('media:restore-stranded-thumbnails --apply')
            ->expectsOutputToContain('exist on both disks at different sizes')
            ->assertFailed();

        $this->assertSame('truncated', Storage::disk('do_spaces')->get('sermons/thumbnails/disputed.webp'));
        $this->assertSame(1, $this->report('--apply')['objects']['size_mismatch']);
    }

    #[Test]
    public function it_restores_the_whole_thumbnail_family_including_candidates(): void
    {
        Sermon::factory()->create([
            'thumbnail_file_path' => 'sermons/thumbnails/main.webp',
            'thumbnail_metadata' => [
                'plain_thumbnail_path' => 'sermons/thumbnails/plain.webp',
                'card_thumbnail_path' => 'sermons/thumbnails/card.webp',
                'overlay_thumbnail_path' => 'sermons/thumbnails/overlay.webp',
                'thumbnail_candidates' => [
                    [
                        'id' => 'candidate-1',
                        'timestamp' => 120.0,
                        'score' => 0.9,
                        'plain_path' => 'sermons/thumbnails/candidates/1-plain.webp',
                        'card_path' => 'sermons/thumbnails/candidates/1-card.webp',
                        'overlay_path' => 'sermons/thumbnails/candidates/1-overlay.webp',
                    ],
                ],
            ],
        ]);

        $paths = [
            'sermons/thumbnails/main.webp',
            'sermons/thumbnails/plain.webp',
            'sermons/thumbnails/card.webp',
            'sermons/thumbnails/overlay.webp',
            'sermons/thumbnails/candidates/1-plain.webp',
            'sermons/thumbnails/candidates/1-card.webp',
            'sermons/thumbnails/candidates/1-overlay.webp',
        ];

        foreach ($paths as $path) {
            Storage::disk('public')->put($path, 'asset');
        }

        $this->assertSame(7, $this->report('--apply')['objects']['restored']);

        foreach ($paths as $path) {
            Storage::disk('do_spaces')->assertExists($path);
        }
    }

    /**
     * Sermon audio and video are intact on Spaces and are not this command's
     * business. Copying them would move bytes nobody asked to move.
     */
    #[Test]
    public function it_ignores_assets_outside_the_thumbnail_family(): void
    {
        Sermon::factory()->create([
            'audio_file_path' => 'sermons/audio/talk.mp3',
            'transcript_file_path' => 'transcripts/sermon_39.md',
        ]);

        Storage::disk('public')->put('sermons/audio/talk.mp3', 'audio');
        Storage::disk('public')->put('transcripts/sermon_39.md', 'transcript');

        $this->artisan('media:restore-stranded-thumbnails --apply')->assertSuccessful();

        Storage::disk('do_spaces')->assertMissing('sermons/audio/talk.mp3');
        Storage::disk('do_spaces')->assertMissing('transcripts/sermon_39.md');
        $this->assertSame(0, $this->report('--apply')['objects']['restored']);
    }

    /**
     * A legacy `private/` row resolves to the local disk, so the key this
     * command would write is not the key that row references. Guessing would
     * scatter objects under a prefix nothing reads.
     */
    #[Test]
    public function it_skips_a_legacy_private_path_instead_of_guessing_its_key(): void
    {
        Sermon::factory()->create(['thumbnail_file_path' => 'private/sermons/thumbnails/legacy.webp']);
        Storage::disk('public')->put('private/sermons/thumbnails/legacy.webp', 'asset');

        $this->artisan('media:restore-stranded-thumbnails --apply')->assertSuccessful();

        Storage::disk('do_spaces')->assertMissing('private/sermons/thumbnails/legacy.webp');
        $this->assertSame(1, $this->report('--apply')['objects']['skipped_private']);
    }

    #[Test]
    public function it_copies_one_shared_object_once(): void
    {
        // The selected candidate is also the sermon's thumbnail, so the same key
        // is reachable twice through the same row.
        Sermon::factory()->create([
            'thumbnail_file_path' => 'sermons/thumbnails/shared.webp',
            'thumbnail_metadata' => [
                'plain_thumbnail_path' => 'sermons/thumbnails/shared.webp',
            ],
        ]);

        Storage::disk('public')->put('sermons/thumbnails/shared.webp', 'asset');

        $report = $this->report('--apply');

        // One object copied, but both references accounted for — the second is
        // not silently dropped, or the per-kind table would stop lining up with
        // the audit's and the restore could never be verified against it.
        $this->assertSame(1, $report['objects']['restored']);
        $this->assertSame(0, $report['objects']['already_present']);
        $this->assertSame(1, $report['references']['thumbnail']['restored']);
        $this->assertSame(1, $report['references']['plain_thumbnail']['restored']);
    }

    #[Test]
    public function it_can_be_limited_to_a_single_sermon_for_a_cautious_first_run(): void
    {
        $first = Sermon::factory()->create(['thumbnail_file_path' => 'sermons/thumbnails/first.webp']);
        Sermon::factory()->create(['thumbnail_file_path' => 'sermons/thumbnails/second.webp']);

        Storage::disk('public')->put('sermons/thumbnails/first.webp', 'asset');
        Storage::disk('public')->put('sermons/thumbnails/second.webp', 'asset');

        $this->artisan("media:restore-stranded-thumbnails --apply --sermon={$first->id}")->assertSuccessful();

        Storage::disk('do_spaces')->assertExists('sermons/thumbnails/first.webp');
        Storage::disk('do_spaces')->assertMissing('sermons/thumbnails/second.webp');
    }

    #[Test]
    public function it_lists_sermon_ids_but_never_paths_with_the_details_option(): void
    {
        $sermon = Sermon::factory()->create(['thumbnail_file_path' => 'sermons/thumbnails/stranded.webp']);
        Storage::disk('public')->put('sermons/thumbnails/stranded.webp', 'asset');

        $this->artisan('media:restore-stranded-thumbnails --details')
            ->doesntExpectOutputToContain('sermons/thumbnails/stranded.webp')
            ->expectsOutputToContain((string) $sermon->id)
            ->assertSuccessful();

        $this->assertSame(
            [['sermon_id' => $sermon->id, 'kind' => 'thumbnail', 'outcome' => 'restorable']],
            $this->report('--details')['findings'],
        );
    }

    #[Test]
    public function it_refuses_a_source_disk_that_is_also_the_target(): void
    {
        config(['thumbnail-generation.storage.disk' => 'public']);

        $this->artisan('media:restore-stranded-thumbnails --source-disk=public')
            ->expectsOutputToContain('Nothing can be stranded relative to itself')
            ->assertFailed();
    }

    #[Test]
    public function it_refuses_an_unknown_source_disk(): void
    {
        $this->artisan('media:restore-stranded-thumbnails --source-disk=nowhere')
            ->expectsOutputToContain('Unknown source disk')
            ->assertFailed();
    }

    /**
     * The pair that closes the loop: the audit reports stranded, this restores
     * them, and the audit then comes back clean. If the two ever enumerate the
     * thumbnail family differently, this is the test that notices.
     */
    #[Test]
    public function a_restored_archive_audits_clean(): void
    {
        Sermon::factory()->create([
            'thumbnail_file_path' => 'sermons/thumbnails/main.webp',
            'thumbnail_metadata' => [
                'plain_thumbnail_path' => 'sermons/thumbnails/plain.webp',
                'thumbnail_candidates' => [
                    [
                        'id' => 'candidate-1',
                        'timestamp' => 120.0,
                        'score' => 0.9,
                        'plain_path' => 'sermons/thumbnails/candidates/1-plain.webp',
                    ],
                ],
            ],
        ]);

        foreach ([
            'sermons/thumbnails/main.webp',
            'sermons/thumbnails/plain.webp',
            'sermons/thumbnails/candidates/1-plain.webp',
        ] as $path) {
            Storage::disk('public')->put($path, 'asset');
        }

        $this->artisan('audit:sermon-assets')->assertFailed();

        $this->artisan('media:restore-stranded-thumbnails --apply')->assertSuccessful();

        $this->artisan('audit:sermon-assets')
            ->expectsOutputToContain('Sermon asset audit is clean')
            ->assertSuccessful();
    }

    /**
     * @return array{
     *     apply: bool,
     *     source_disk: string,
     *     target_disk: string,
     *     objects: array<string, int>,
     *     references: array<string, array<string, int>>,
     *     findings?: list<array{sermon_id: int, kind: string, outcome: string}>
     * }
     */
    private function report(string ...$options): array
    {
        Artisan::call(implode(' ', ['media:restore-stranded-thumbnails --json', ...$options]));

        $report = json_decode(Artisan::output(), true);

        $this->assertIsArray($report);

        return $report;
    }
}
