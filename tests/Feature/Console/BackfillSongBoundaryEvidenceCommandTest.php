<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Data\HistoricStagingContext;
use App\Enums\ServiceSectionPublicationStatus;
use App\Enums\ServiceSectionType;
use App\Models\ChurchServiceItem;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Models\Song;
use App\Services\HistoricMedia\HistoricStagingContextRegistry;
use App\Services\HistoricMedia\HistoricStagingGuard;
use App\Support\ServiceArtifactDisk;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BackfillSongBoundaryEvidenceCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_reports_an_empty_pass_when_every_section_already_holds_evidence(): void
    {
        $this->songSection(banked: true);

        $this->artisan('service:backfill-song-boundary-evidence')
            ->expectsOutputToContain('already holds banked boundary evidence')
            ->assertSuccessful();
    }

    #[Test]
    public function it_writes_nothing_by_default(): void
    {
        $section = $this->songSection(withArtifacts: true);

        $this->artisan('service:backfill-song-boundary-evidence')
            ->expectsOutputToContain('DRY RUN')
            ->assertSuccessful();

        self::assertNull($this->bankedEvidence($section->fresh()));
    }

    #[Test]
    public function it_banks_evidence_for_a_section_whose_inputs_are_readable(): void
    {
        $section = $this->songSection(withArtifacts: true);

        $this->artisan('service:backfill-song-boundary-evidence', ['--execute' => true])
            ->assertSuccessful();

        $evidence = $this->bankedEvidence($section->fresh());

        self::assertIsArray($evidence);
        self::assertSame('available', $evidence['inputs']['service_transcript']['status']);
    }

    /**
     * The defect this command exists for. An unreadable input is not evidence
     * that the boundary is fine, and banking it would make "we could not look"
     * indistinguishable from "we looked and it was clean" on the next pass.
     */
    #[Test]
    public function it_reports_but_never_banks_an_unreadable_input(): void
    {
        $section = $this->songSection(withArtifacts: false);

        $this->artisan('service:backfill-song-boundary-evidence', ['--execute' => true])
            ->expectsOutputToContain('evidence_unavailable')
            ->expectsOutputToContain('need their source restaged')
            ->assertSuccessful();

        self::assertNull($this->bankedEvidence($section->fresh()));
        self::assertFalse($section->fresh()->needs_manual_review);
    }

    /**
     * A historic run's artifact keys resolve only under its own batch root, so
     * assessing without reactivating reports every input missing — a corpus-wide
     * false negative. Both the read and the write must happen inside it.
     */
    #[Test]
    public function it_opens_the_runs_own_staging_context_before_reading_its_artifacts(): void
    {
        $context = new HistoricStagingContext(
            manifestHash: str_repeat('a', 64),
            planHash: str_repeat('b', 64),
            stagingDisk: 'historic_staging',
            batchRoot: 'historic-batches/song-evidence',
            storageIdentity: [
                'driver' => 'local',
                'bucket' => null,
                'root_fingerprint' => str_repeat('c', 64),
                'prefix_fingerprint' => str_repeat('d', 64),
            ],
        );

        $observed = [];
        $registry = new class(app(HistoricStagingGuard::class), $observed) extends HistoricStagingContextRegistry
        {
            /** @param array<int, string> $observed */
            public function __construct(HistoricStagingGuard $guard, public array &$observed)
            {
                parent::__construct($guard);
            }

            public function within(HistoricStagingContext $context, Closure $callback): mixed
            {
                $this->observed[] = $context->batchRoot;

                return $callback();
            }
        };
        app()->instance(HistoricStagingContextRegistry::class, $registry);

        $this->songSection(withArtifacts: true, stagingContext: $context);

        $this->artisan('service:backfill-song-boundary-evidence', ['--execute' => true])
            ->assertSuccessful();

        // Once to inspect, once to write.
        self::assertSame(
            ['historic-batches/song-evidence', 'historic-batches/song-evidence'],
            $registry->observed,
        );
    }

    #[Test]
    public function it_holds_a_section_whose_re_derived_evidence_names_a_doubt(): void
    {
        // Two seconds of clip is far under the length a whole sung item reaches,
        // so the policy names short_song_clip and the hold has to be recorded.
        $section = $this->songSection(withArtifacts: true, start: 10.0, end: 12.0);

        $this->artisan('service:backfill-song-boundary-evidence', ['--execute' => true])
            ->expectsOutputToContain('short_song_clip')
            ->assertSuccessful();

        $fresh = $section->fresh();
        self::assertTrue($fresh->needs_manual_review);
        self::assertNotNull($this->bankedEvidence($fresh));
    }

    /**
     * P8-Q17 clamped eighteen section bounds, which left their banked candidates
     * describing clips that no longer exist. Evidence that disagrees with its own
     * section is not evidence about that section, so it has to be re-derived
     * rather than trusted — the same rule as an unreadable input.
     */
    #[Test]
    public function it_re_derives_evidence_whose_banked_bounds_no_longer_match_the_section(): void
    {
        $section = $this->songSection(withArtifacts: true, banked: true);

        // The banked candidate claims an end the section no longer reaches.
        $metadata = $section->metadata->toArray();
        $metadata['song_publication_boundary'] = [
            'version' => 1,
            'decision' => 'release_eligible',
            'candidate' => [
                'kind' => 'inclusive',
                'start_time' => $section->start_time,
                'end_time' => $section->end_time + 28.55,
            ],
        ];
        $section->forceFill(['metadata' => $metadata])->save();

        $this->artisan('service:backfill-song-boundary-evidence', ['--execute' => true])
            ->assertSuccessful();

        $evidence = $this->bankedEvidence($section->fresh());

        self::assertSame(
            (float) $section->end_time,
            (float) $evidence['candidate']['end_time'],
            'the re-derived candidate should describe the section as it now stands',
        );
    }

    #[Test]
    public function it_is_a_no_op_when_run_a_second_time(): void
    {
        $section = $this->songSection(withArtifacts: true);

        $this->artisan('service:backfill-song-boundary-evidence', ['--execute' => true])->assertSuccessful();
        $bankedAt = $section->fresh()->updated_at;

        $this->artisan('service:backfill-song-boundary-evidence', ['--execute' => true])
            ->expectsOutputToContain('already holds banked boundary evidence')
            ->assertSuccessful();

        self::assertEquals($bankedAt, $section->fresh()->updated_at);
    }

    #[Test]
    public function it_leaves_not_applicable_sections_out_of_the_default_pass(): void
    {
        $section = $this->songSection(
            withArtifacts: true,
            status: ServiceSectionPublicationStatus::NotApplicable,
        );

        $this->artisan('service:backfill-song-boundary-evidence', ['--execute' => true])
            ->expectsOutputToContain('already holds banked boundary evidence')
            ->assertSuccessful();

        self::assertNull($this->bankedEvidence($section->fresh()));

        $this->artisan('service:backfill-song-boundary-evidence', [
            '--execute' => true,
            '--include-not-applicable' => true,
        ])->assertSuccessful();

        self::assertNotNull($this->bankedEvidence($section->fresh()));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function bankedEvidence(ServiceSection $section): ?array
    {
        $evidence = ($section->metadata?->toArray() ?? [])['song_publication_boundary'] ?? null;

        return is_array($evidence) ? $evidence : null;
    }

    private function songSection(
        bool $withArtifacts = false,
        bool $banked = false,
        ?HistoricStagingContext $stagingContext = null,
        ServiceSectionPublicationStatus $status = ServiceSectionPublicationStatus::Published,
        float $start = 10.0,
        float $end = 250.0,
    ): ServiceSection {
        $song = Song::factory()->create();
        $item = ChurchServiceItem::factory()->create(['song_id' => $song->id]);

        $transcriptPath = 'service-transcripts/2024-01-07/morning-test.normalized.json';
        $rmsPath = 'service-transcripts/2024-01-07/morning-test.rms.json';

        // Whichever disk the estate resolves these durable keys to — the reader
        // goes through ServiceArtifactDisk, so the fake has to as well.
        $disk = ServiceArtifactDisk::for($transcriptPath);
        Storage::fake($disk);

        if ($withArtifacts) {
            Storage::disk($disk)->put($transcriptPath, json_encode([
                'duration' => 3600.0,
                'source' => 'test',
                'cues' => [
                    ['start' => $start + 1.0, 'end' => $start + 5.0, 'text' => 'Amazing grace how sweet the sound'],
                    ['start' => $end - 5.0, 'end' => $end - 1.0, 'text' => 'That saved a wretch like me'],
                ],
            ]));
            // FFmpeg astats text output, not JSON — the parser reads pts_time
            // and lavfi.astats lines, and a JSON body yields zero samples,
            // which reads back as an unavailable input.
            $rms = '';

            for ($t = 0; $t < 300; $t++) {
                $level = ($t >= (int) $start && $t <= (int) $end) ? '-18.5' : '-62.0';
                $rms .= "frame:{$t} pts:{$t} pts_time:{$t}\n";
                $rms .= "lavfi.astats.Overall.RMS_level={$level}\n";
            }

            Storage::disk($disk)->put($rmsPath, $rms);
        }

        $metadata = ['confidence_level' => 'high'];

        if ($banked) {
            $metadata['song_publication_boundary'] = ['version' => 1, 'decision' => 'release_eligible'];
        }

        $processingMetadata = ['service_transcript_path' => $transcriptPath];

        if ($stagingContext instanceof HistoricStagingContext) {
            $processingMetadata['historic_import'] = ['staging_context' => $stagingContext->toArray()];
        }

        $log = MediaProcessingLog::factory()->livestream()->create([
            'processing_metadata' => $processingMetadata,
            'rms_log_path' => $rmsPath,
        ]);

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'church_service_item_id' => $item->id,
            'section_type' => ServiceSectionType::Song->value,
            'song_match_type' => 'confirmed',
            'start_time' => $start,
            'end_time' => $end,
            'duration' => $end - $start,
            'publication_status' => ServiceSectionPublicationStatus::NotApplicable,
            'needs_manual_review' => false,
            'metadata' => $metadata,
        ]);

        $published = $status === ServiceSectionPublicationStatus::Published;

        /*
         * The schema enforces publication as an invariant, not just the app:
         * publication_link_check ties published_at to publication_status, and
         * publication_media_check requires the extracted clip to exist alongside
         * it. A published section has to gain or lose all of them together.
         */
        $section->forceFill([
            'publication_status' => $status,
            'published_at' => $published ? now() : null,
            'extracted_video_path' => $published ? 'sermons/songs/'.$song->id.'/'.$section->id.'.mp4' : null,
            'extracted_at' => $published ? now() : null,
        ])->save();

        return $section->fresh();
    }
}
