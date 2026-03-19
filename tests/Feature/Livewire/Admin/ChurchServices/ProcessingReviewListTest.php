<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Admin\ChurchServices;

use App\Livewire\Admin\ChurchServices\ProcessingReviewList;
use App\Models\MediaProcessingLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Regression tests for TD-004B: ProcessingReviewList must not load oversized
 * JSON blob columns (visual_samples, song_clusters, rms_stats, ai_analysis, etc.)
 * that are never rendered in the review queue view.
 */
class ProcessingReviewListTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['is_admin' => true]);
    }

    #[Test]
    public function it_renders_the_review_queue_without_loading_blob_columns(): void
    {
        MediaProcessingLog::factory()->livestream()->manualReviewRequired()->create();

        $selectQueries = [];

        DB::listen(function ($query) use (&$selectQueries): void {
            if (str_contains($query->sql, 'media_processing_logs') && str_starts_with(ltrim($query->sql), 'select')) {
                $selectQueries[] = $query->sql;
            }
        });

        Livewire::actingAs($this->admin)
            ->test(ProcessingReviewList::class)
            ->assertStatus(200);

        $this->assertNotEmpty($selectQueries, 'Expected at least one SELECT query against media_processing_logs.');

        $blobColumns = ['visual_samples', 'song_clusters', 'rms_stats', 'ai_analysis', 'rms_log_path'];

        foreach ($selectQueries as $sql) {
            $this->assertStringNotContainsString(
                'select *',
                strtolower($sql),
                'ProcessingReviewList must use an explicit column list, not SELECT *.'
            );
            $this->assertStringNotContainsString(
                '"media_processing_logs".*',
                $sql,
                'ProcessingReviewList must use an explicit column list, not SELECT *.'
            );

            foreach ($blobColumns as $column) {
                $this->assertStringNotContainsString(
                    "`{$column}`",
                    $sql,
                    "ProcessingReviewList should not select the '{$column}' blob column."
                );
            }
        }
    }

    #[Test]
    public function it_shows_pending_review_items_with_correct_data(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->manualReviewRequired()->create([
            'original_filename' => 'service-2026-01-05.mp4',
        ]);

        Livewire::actingAs($this->admin)
            ->test(ProcessingReviewList::class)
            ->assertSee($log->original_filename)
            ->assertSee($log->processing_id);
    }

    #[Test]
    public function it_shows_empty_state_when_no_reviews_are_pending(): void
    {
        Livewire::actingAs($this->admin)
            ->test(ProcessingReviewList::class)
            ->assertSee('No livestream runs are awaiting manual review.');
    }
}
