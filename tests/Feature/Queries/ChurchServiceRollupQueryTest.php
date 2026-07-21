<?php

declare(strict_types=1);

namespace Tests\Feature\Queries;

use App\Enums\ChurchServiceRollupStatus;
use App\Enums\SermonService;
use App\Enums\ServiceSectionPublicationStatus;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Models\ServiceSection;
use App\Queries\ChurchServiceRollupQuery;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ChurchServiceRollupQueryTest extends TestCase
{
    use RefreshDatabase;

    private ChurchServiceRollupQuery $query;

    protected function setUp(): void
    {
        parent::setUp();

        $this->query = app(ChurchServiceRollupQuery::class);
    }

    /**
     * @return Collection<int, ChurchService>
     */
    private function freshServices(): Collection
    {
        return ChurchService::query()->get();
    }

    #[Test]
    public function it_returns_plan_only_for_a_future_service_without_runs(): void
    {
        $service = ChurchService::factory()->create([
            'date' => now()->addDays(4)->toDateString(),
            'service' => SermonService::Morning,
            'needs_review' => false,
        ]);

        $rollups = $this->query->forServices($this->freshServices());

        $this->assertSame(ChurchServiceRollupStatus::PlanOnly, $rollups[$service->id]['status']);
        $this->assertSame(0, $rollups[$service->id]['attention_count']);
    }

    #[Test]
    public function it_returns_awaiting_recording_for_a_past_service_without_runs(): void
    {
        $service = ChurchService::factory()->create([
            'date' => now()->subDays(4)->toDateString(),
            'service' => SermonService::Morning,
            'needs_review' => false,
        ]);

        $rollups = $this->query->forServices($this->freshServices());

        $this->assertSame(ChurchServiceRollupStatus::AwaitingRecording, $rollups[$service->id]['status']);
    }

    #[Test]
    public function it_returns_processing_while_any_matching_run_is_in_flight(): void
    {
        $service = ChurchService::factory()->create([
            'date' => '2026-06-07',
            'service' => SermonService::Morning,
            'needs_review' => false,
        ]);

        MediaProcessingLog::factory()->livestream()->processing()->create([
            'extracted_date' => '2026-06-07',
            'extracted_service' => SermonService::Morning->value,
        ]);

        $rollups = $this->query->forServices($this->freshServices());

        $this->assertSame(ChurchServiceRollupStatus::Processing, $rollups[$service->id]['status']);
    }

    #[Test]
    public function it_rolls_up_auto_trim_video_runs_but_not_plain_video_runs(): void
    {
        $service = ChurchService::factory()->create([
            'date' => '2026-06-07',
            'service' => SermonService::Morning,
            'needs_review' => false,
        ]);

        // A plain (full_video) run is not part of the segmentation pipeline
        // and must not affect the rollup …
        MediaProcessingLog::factory()->video()->processing()->create([
            'extracted_date' => '2026-06-07',
            'extracted_service' => SermonService::Morning->value,
        ]);

        $rollups = $this->query->forServices($this->freshServices());
        $this->assertSame(ChurchServiceRollupStatus::AwaitingRecording, $rollups[$service->id]['status']);

        // … but an auto-trim video run produces service sections and counts
        // exactly like a livestream run.
        MediaProcessingLog::factory()->video()->processing()->create([
            'extracted_date' => '2026-06-07',
            'extracted_service' => SermonService::Morning->value,
            'processing_metadata' => [
                'video_processing_mode' => MediaProcessingLog::VIDEO_PROCESSING_MODE_AUTO_TRIM,
            ],
        ]);

        $rollups = $this->query->forServices($this->freshServices());
        $this->assertSame(ChurchServiceRollupStatus::Processing, $rollups[$service->id]['status']);
    }

    #[Test]
    public function it_returns_needs_review_with_a_count_covering_every_attention_source(): void
    {
        $service = ChurchService::factory()->create([
            'date' => '2026-06-07',
            'service' => SermonService::Morning,
            'needs_review' => true,
            'pending_structure_merge_source' => 'openlp',
        ]);

        $completedRun = MediaProcessingLog::factory()->livestream()->completed()->create([
            'extracted_date' => '2026-06-07',
            'extracted_service' => SermonService::Morning->value,
        ]);

        MediaProcessingLog::factory()->livestream()->manualReviewRequired()->create([
            'extracted_date' => '2026-06-07',
            'extracted_service' => SermonService::Morning->value,
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $completedRun->id,
            'needs_manual_review' => true,
        ]);

        $rollups = $this->query->forServices($this->freshServices());

        $this->assertSame(ChurchServiceRollupStatus::NeedsReview, $rollups[$service->id]['status']);
        // flagged section + awaiting-segment run + needs_review + pending merge
        $this->assertSame(4, $rollups[$service->id]['attention_count']);
    }

    #[Test]
    public function it_returns_needs_review_for_a_flagged_service_even_without_runs(): void
    {
        $service = ChurchService::factory()->create([
            'date' => now()->subDays(10)->toDateString(),
            'service' => SermonService::Morning,
            'needs_review' => true,
        ]);

        $rollups = $this->query->forServices($this->freshServices());

        $this->assertSame(ChurchServiceRollupStatus::NeedsReview, $rollups[$service->id]['status']);
        $this->assertSame(1, $rollups[$service->id]['attention_count']);
    }

    #[Test]
    public function it_returns_published_when_sections_are_resolved_and_a_sermon_exists(): void
    {
        $service = ChurchService::factory()->create([
            'date' => '2026-06-07',
            'service' => SermonService::Morning,
            'needs_review' => false,
        ]);

        $sermon = Sermon::factory()->create();

        $run = MediaProcessingLog::factory()->livestream()->completed()->create([
            'extracted_date' => '2026-06-07',
            'extracted_service' => SermonService::Morning->value,
            'sermon_id' => $sermon->id,
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'needs_manual_review' => false,
            'confidence' => 1.0,
            'publication_status' => ServiceSectionPublicationStatus::Published->value,
            'published_sermon_id' => $sermon->id,
        ]);

        $rollups = $this->query->forServices($this->freshServices());

        $this->assertSame(ChurchServiceRollupStatus::Published, $rollups[$service->id]['status']);
    }

    #[Test]
    public function it_returns_ready_for_a_completed_run_without_published_output(): void
    {
        $service = ChurchService::factory()->create([
            'date' => '2026-06-07',
            'service' => SermonService::Morning,
            'needs_review' => false,
        ]);

        MediaProcessingLog::factory()->livestream()->completed()->create([
            'extracted_date' => '2026-06-07',
            'extracted_service' => SermonService::Morning->value,
        ]);

        $rollups = $this->query->forServices($this->freshServices());

        $this->assertSame(ChurchServiceRollupStatus::Ready, $rollups[$service->id]['status']);
    }

    #[Test]
    public function it_rolls_up_repaired_runs_matched_only_via_item_projection_columns(): void
    {
        $processingId = 'repaired-run-uuid-123';

        $service = ChurchService::factory()->create([
            'date' => now()->subDays(3)->toDateString(),
            'service' => SermonService::Morning,
            'needs_review' => false,
        ]);

        ChurchServiceItem::factory()->create([
            'church_service_id' => $service->id,
            'livestream_processing_id' => $processingId,
        ]);

        // Identity deliberately mismatched: only fallback path (c) can match.
        MediaProcessingLog::factory()->livestream()->completed()->create([
            'processing_id' => $processingId,
            'extracted_date' => '1990-01-01',
            'extracted_service' => SermonService::Evening->value,
        ]);

        $rollups = $this->query->forServices($this->freshServices());

        $this->assertSame(ChurchServiceRollupStatus::Ready, $rollups[$service->id]['status']);
        $this->assertSame(1, $rollups[$service->id]['run_count']);
    }

    #[Test]
    public function it_rolls_up_runs_matched_via_import_metadata_projection(): void
    {
        $processingId = 'projection-run-uuid-456';

        $service = ChurchService::factory()->create([
            'date' => now()->subDays(3)->toDateString(),
            'service' => SermonService::Morning,
            'needs_review' => false,
            'import_metadata' => [
                'livestream_projection' => ['processing_id' => $processingId],
            ],
        ]);

        MediaProcessingLog::factory()->livestream()->completed()->create([
            'processing_id' => $processingId,
            'extracted_date' => '1990-01-01',
            'extracted_service' => SermonService::Evening->value,
        ]);

        $rollups = $this->query->forServices($this->freshServices());

        $this->assertSame(1, $rollups[$service->id]['run_count']);
    }

    #[Test]
    public function it_computes_a_page_of_rollups_with_a_constant_number_of_queries(): void
    {
        foreach (range(1, 5) as $week) {
            $service = ChurchService::factory()->create([
                'date' => now()->subWeeks($week)->toDateString(),
                'service' => SermonService::Morning,
                'needs_review' => false,
            ]);

            ChurchServiceItem::factory()->create(['church_service_id' => $service->id]);

            MediaProcessingLog::factory()->livestream()->completed()->create([
                'extracted_date' => $service->date->toDateString(),
                'extracted_service' => SermonService::Morning->value,
            ]);
        }

        $services = $this->freshServices();

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $this->query->forServices($services);

        // items eager-load + run query + serviceSections eager-load
        $this->assertSame(3, $queryCount);
    }

    #[Test]
    public function it_never_selects_blob_columns_for_rollup_runs(): void
    {
        $service = ChurchService::factory()->create([
            'date' => '2026-06-07',
            'service' => SermonService::Morning,
        ]);

        MediaProcessingLog::factory()->livestream()->completed()->create([
            'extracted_date' => '2026-06-07',
            'extracted_service' => SermonService::Morning->value,
        ]);

        $selectQueries = [];
        DB::listen(function ($query) use (&$selectQueries): void {
            if (str_contains($query->sql, 'media_processing_logs') && str_starts_with(ltrim($query->sql), 'select')) {
                $selectQueries[] = $query->sql;
            }
        });

        $this->query->forServices($this->freshServices());

        $this->assertNotEmpty($selectQueries);

        foreach ($selectQueries as $sql) {
            $this->assertStringNotContainsString('select *', strtolower($sql));
            $this->assertStringNotContainsString('"media_processing_logs".*', $sql);

            foreach (['rms_stats', 'ai_analysis', 'rms_log_path'] as $column) {
                $this->assertStringNotContainsString("`{$column}`", $sql);
            }
        }

        // The matcher must keep the rollup honest for all three paths in the
        // same query (sanity check that we used the shared clause builder).
        $this->assertStringContainsString('church_service_id', $selectQueries[1] ?? $selectQueries[0]);
    }

    #[Test]
    public function flagged_sections_do_not_trigger_needs_review_when_section_publishing_is_disabled(): void
    {
        config(['media-processing.section_publishing.enabled' => false]);

        $service = ChurchService::factory()->create([
            'date' => '2026-06-07',
            'service' => SermonService::Morning,
            'needs_review' => false,
        ]);

        $run = MediaProcessingLog::factory()->livestream()->completed()->create([
            'extracted_date' => '2026-06-07',
            'extracted_service' => SermonService::Morning->value,
            'sermon_id' => null,
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'needs_manual_review' => true,
            'publication_status' => ServiceSectionPublicationStatus::PendingApproval->value,
        ]);

        $rollups = $this->query->forServices($this->freshServices());

        $this->assertSame(ChurchServiceRollupStatus::Ready, $rollups[$service->id]['status']);
        $this->assertSame(0, $rollups[$service->id]['attention_count']);
    }

    #[Test]
    public function it_returns_an_empty_array_for_an_empty_collection(): void
    {
        $this->assertSame([], $this->query->forServices(new Collection));
    }
}
