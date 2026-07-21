<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Admin\ChurchServices;

use App\Enums\InboundEmailStatus;
use App\Enums\MediaType;
use App\Enums\SermonService;
use App\Enums\ServiceSectionPublicationStatus;
use App\Enums\ServiceSectionType;
use App\Livewire\Admin\ChurchServices\ReviewInbox;
use App\Models\ChurchService;
use App\Models\InboundEmail;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Models\User;
use App\Queries\ReviewInboxQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\WithInboundEmailTestHelpers;

class ReviewInboxTest extends TestCase
{
    use RefreshDatabase;
    use WithInboundEmailTestHelpers;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'is_admin' => true,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($this->admin);
    }

    #[Test]
    public function it_renders_every_item_kind_grouped_by_service(): void
    {
        InboundEmail::factory()->create([
            'subject' => 'Order of service 7 June',
            'status' => InboundEmailStatus::Pending->value,
            'processing_metadata' => $this->processingMetadata(
                '2026-06-07',
                'morning',
                [['title' => 'Welcome', 'type' => 'custom']],
            ),
        ]);

        $service = ChurchService::factory()->create([
            'date' => '2026-06-07',
            'service' => SermonService::Morning,
            'needs_review' => true,
            'pending_structure_merge_source' => 'openlp',
        ]);

        $run = MediaProcessingLog::factory()->livestream()->completed()->create([
            'extracted_date' => '2026-06-07',
            'extracted_service' => SermonService::Morning->value,
            'sermon_id' => null,
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'title' => 'Flagged Children Talk',
            'needs_manual_review' => true,
        ]);

        MediaProcessingLog::factory()->livestream()->manualReviewRequired()->create([
            'original_filename' => 'livestream-2026-06-07.mp4',
            'extracted_date' => '2026-06-07',
            'extracted_service' => SermonService::Morning->value,
        ]);

        Livewire::test(ReviewInbox::class)
            ->assertSee('7 Jun 2026 — Morning')
            ->assertSee('Order of service 7 June')
            ->assertSee('Flagged Children Talk')
            ->assertSee('livestream-2026-06-07.mp4')
            ->assertSee('Choose segment')
            ->assertSee('Pending structure merge')
            ->assertSee('Service flagged for review')
            ->assertSeeHtml(route('admin.services.show', $service));
    }

    #[Test]
    public function unparsed_emails_land_in_a_pinned_unattributed_group(): void
    {
        InboundEmail::factory()->create([
            'subject' => 'Mystery email',
            'status' => InboundEmailStatus::Failed->value,
            'processing_metadata' => null,
        ]);

        ChurchService::factory()->create([
            'date' => '2026-06-07',
            'service' => SermonService::Morning,
            'needs_review' => true,
        ]);

        Livewire::test(ReviewInbox::class)
            ->assertSeeInOrder(['Unattributed', 'Mystery email', '7 Jun 2026']);
    }

    #[Test]
    public function filter_chips_narrow_the_queue_and_zero_count_chips_are_hidden(): void
    {
        InboundEmail::factory()->create([
            'subject' => 'Filterable email',
            'status' => InboundEmailStatus::Pending->value,
            'processing_metadata' => $this->processingMetadata(
                '2026-06-07',
                'morning',
                [['title' => 'Welcome', 'type' => 'custom']],
            ),
        ]);

        ChurchService::factory()->create([
            'date' => '2026-06-14',
            'service' => SermonService::Morning,
            'needs_review' => true,
        ]);

        Livewire::test(ReviewInbox::class)
            ->assertSee('Emails')
            ->assertSee('Services')
            ->assertDontSee('Segments') // zero-count chip hidden
            ->set('filter', 'emails')
            ->assertSee('Filterable email')
            ->assertDontSee('Service flagged for review')
            ->set('filter', 'services')
            ->assertSee('Service flagged for review')
            ->assertDontSee('Filterable email');
    }

    #[Test]
    public function approve_email_imports_the_service_and_redirects_to_it(): void
    {
        $email = InboundEmail::factory()->create([
            'status' => InboundEmailStatus::Pending->value,
            'processing_metadata' => $this->processingMetadata(
                '2026-06-07',
                'morning',
                [
                    ['position' => 1, 'type' => 'custom', 'title' => 'Welcome', 'metadata' => ['email_type' => 'welcome']],
                    ['position' => 2, 'type' => 'custom', 'title' => 'Sermon', 'metadata' => ['email_type' => 'sermon']],
                ],
            ),
        ]);

        Livewire::test(ReviewInbox::class)
            ->call('approveEmail', $email->id);

        $service = ChurchService::query()
            ->whereDate('date', '2026-06-07')
            ->where('service', SermonService::Morning->value)
            ->first();

        $this->assertNotNull($service);
        $this->assertSame(InboundEmailStatus::Processed, $email->fresh()->status);
    }

    #[Test]
    public function edit_and_approve_redirects_to_the_manual_form_prefilled_with_the_email(): void
    {
        $email = InboundEmail::factory()->create([
            'status' => InboundEmailStatus::Pending->value,
            'processing_metadata' => $this->processingMetadata(
                '2026-06-07',
                'morning',
                [['title' => 'Welcome', 'type' => 'custom']],
            ),
        ]);

        Livewire::test(ReviewInbox::class)
            ->call('editAndApproveEmail', $email->id)
            ->assertRedirect(route('admin.services.create', ['inboundEmailId' => $email->id]));
    }

    #[Test]
    public function reject_email_marks_it_rejected_and_refreshes_the_list(): void
    {
        $email = InboundEmail::factory()->create([
            'subject' => 'Reject me',
            'status' => InboundEmailStatus::Pending->value,
            'processing_metadata' => null,
        ]);

        Livewire::test(ReviewInbox::class)
            ->assertSee('Reject me')
            ->call('rejectEmail', $email->id)
            ->assertDispatched('notify', type: 'success', message: 'Inbound email rejected.')
            ->assertDontSee('Reject me');

        $this->assertSame(InboundEmailStatus::Rejected, $email->fresh()->status);
    }

    #[Test]
    public function section_reject_and_requeue_delegate_to_the_publication_actions(): void
    {
        $run = MediaProcessingLog::factory()->livestream()->completed()->create([
            'extracted_date' => '2026-06-07',
            'extracted_service' => SermonService::Morning->value,
            'sermon_id' => null,
        ]);

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'title' => 'Pending Section',
            'publication_status' => ServiceSectionPublicationStatus::PendingApproval->value,
        ]);

        $component = Livewire::test(ReviewInbox::class)
            ->assertSee('Pending Section')
            ->call('reject', $section->id)
            ->assertDispatched('notify', type: 'success', message: 'Section rejected.');

        $this->assertSame(ServiceSectionPublicationStatus::Rejected, $section->fresh()->publication_status);

        $component
            ->call('requeue', $section->id)
            ->assertDispatched('notify', type: 'success', message: 'Section moved back to pending approval.');

        $this->assertSame(ServiceSectionPublicationStatus::PendingApproval, $section->fresh()->publication_status);
    }

    #[Test]
    public function mark_service_reviewed_clears_the_flag(): void
    {
        $service = ChurchService::factory()->create([
            'date' => '2026-06-07',
            'service' => SermonService::Morning,
            'needs_review' => true,
        ]);

        Livewire::test(ReviewInbox::class)
            ->assertSee('Service flagged for review')
            ->call('markServiceReviewed', $service->id)
            ->assertDispatched('notify', type: 'success', message: 'Service marked as reviewed.')
            ->assertDontSee('Service flagged for review');

        $this->assertFalse($service->fresh()->needs_review);
    }

    #[Test]
    public function every_mutating_action_rejects_non_admin_users(): void
    {
        $member = User::factory()->create([
            'is_admin' => false,
            'email_verified_at' => now(),
        ]);

        $email = InboundEmail::factory()->create(['status' => InboundEmailStatus::Pending->value]);
        $service = ChurchService::factory()->create(['needs_review' => true]);
        $run = MediaProcessingLog::factory()->livestream()->completed()->create(['sermon_id' => null]);
        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'publication_status' => ServiceSectionPublicationStatus::PendingApproval->value,
        ]);

        $this->actingAs($member);

        foreach ([
            ['approveEmail', $email->id],
            ['editAndApproveEmail', $email->id],
            ['reparseEmail', $email->id],
            ['rejectEmail', $email->id],
            ['approve', $section->id],
            ['reject', $section->id],
            ['requeue', $section->id],
            ['markServiceReviewed', $service->id],
        ] as [$action, $argument]) {
            Livewire::test(ReviewInbox::class)
                ->call($action, $argument)
                ->assertForbidden();
        }
    }

    #[Test]
    public function the_inbox_route_is_admin_only_and_respects_the_service_tracking_gate(): void
    {
        $this->get(route('admin.services.inbox'))->assertOk();

        config(['service-tracking.enabled' => false]);
        $this->get(route('admin.services.inbox'))->assertNotFound();

        config(['service-tracking.enabled' => true]);
        $member = User::factory()->create(['is_admin' => false, 'email_verified_at' => now()]);
        $this->actingAs($member);
        $this->get(route('admin.services.inbox'))->assertForbidden();
    }

    #[Test]
    public function sections_are_omitted_when_section_publishing_is_disabled(): void
    {
        config(['media-processing.section_publishing.enabled' => false]);

        $run = MediaProcessingLog::factory()->livestream()->completed()->create([
            'extracted_date' => '2026-06-07',
            'extracted_service' => SermonService::Morning->value,
            'sermon_id' => null,
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'title' => 'Hidden Section',
            'needs_manual_review' => true,
        ]);

        Livewire::test(ReviewInbox::class)
            ->assertDontSee('Hidden Section')
            ->assertDontSee('Sections')
            ->assertSee('All caught up');
    }

    #[Test]
    public function the_retired_queue_urls_redirect_to_the_inbox(): void
    {
        $this->get(route('admin.services.review'))
            ->assertRedirect(route('admin.services.inbox'));

        $this->get(route('admin.services.inbound-emails'))
            ->assertRedirect(route('admin.services.inbox', ['filter' => 'emails']));

        $this->get(route('admin.services.section-publications'))
            ->assertRedirect(route('admin.services.inbox', ['filter' => 'sections']));

        $this->get(route('admin.services.processing.review.index'))
            ->assertRedirect(route('admin.services.inbox', ['filter' => 'segments']));
    }

    #[Test]
    public function the_retired_per_run_review_url_redirects_to_the_segment_page(): void
    {
        $log = MediaProcessingLog::factory()->create([
            'processing_type' => MediaType::Livestream,
        ]);

        $this->get("/admin/services/processing/{$log->id}/review")
            ->assertRedirect(route('admin.recordings.sermon-segment', $log->processing_id));
    }

    #[Test]
    public function approve_blocks_when_extracted_media_is_missing(): void
    {
        Queue::fake();
        Storage::fake('public');

        config(['media-processing.storage.sermon_disk' => 'public']);

        $run = MediaProcessingLog::factory()->livestream()->create([
            'extracted_date' => '2026-05-18',
            'extracted_service' => SermonService::Morning->value,
        ]);

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'publication_status' => ServiceSectionPublicationStatus::PendingApproval->value,
            'extracted_video_path' => 'sermons/sections/'.$run->id.'/missing-video.mp4',
            'extracted_audio_path' => 'sermons/audio/section-'.$run->id.'-missing.mp3',
        ]);

        Livewire::test(ReviewInbox::class)
            ->call('approve', $section->id)
            ->assertDispatched(
                'notify',
                type: 'error',
                message: 'Section media is missing. Reclassify and prepare candidates again.'
            );

        $this->assertSame(ServiceSectionPublicationStatus::PendingApproval, $section->fresh()->publication_status);
        Queue::assertNothingPushed();
    }

    #[Test]
    public function approve_blocks_childrens_talks_without_a_confirmed_speaker(): void
    {
        Queue::fake();
        Storage::fake('public');

        config(['media-processing.storage.sermon_disk' => 'public']);

        $run = MediaProcessingLog::factory()->livestream()->create([
            'extracted_date' => '2026-05-19',
            'extracted_service' => SermonService::Morning->value,
        ]);

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::ChildrensTalk->value,
            'publication_status' => ServiceSectionPublicationStatus::PendingApproval->value,
            'extracted_video_path' => 'sermons/sections/'.$run->id.'/video.mp4',
            'extracted_audio_path' => 'sermons/audio/section-'.$run->id.'.mp3',
            'metadata' => [
                'childrens_talk_speaker' => [
                    'predicted' => [
                        'outcome' => 'ambiguous',
                        'preacher_name' => 'Detected Speaker',
                        'confidence' => 0.61,
                    ],
                ],
            ],
        ]);

        Storage::disk('public')->put('sermons/sections/'.$run->id.'/video.mp4', 'video');
        Storage::disk('public')->put('sermons/audio/section-'.$run->id.'.mp3', 'audio');

        Livewire::test(ReviewInbox::class)
            ->call('approve', $section->id)
            ->assertDispatched(
                'notify',
                type: 'error',
                message: "Choose a speaker for this children's talk before approving publication."
            );

        $this->assertSame(ServiceSectionPublicationStatus::PendingApproval, $section->fresh()->publication_status);
        Queue::assertNothingPushed();
    }

    #[Test]
    public function a_pending_merge_is_never_displaced_by_newer_review_flags(): void
    {
        ChurchService::factory()->create([
            'date' => '2025-01-05',
            'service' => SermonService::Morning,
            'needs_review' => false,
            'pending_structure_merge_source' => 'openlp',
        ]);

        // A full cap of newer review-flagged services must not push the
        // older pending merge out of the queue (the hub chip counts it).
        foreach (range(1, ReviewInboxQuery::SOURCE_CAP) as $week) {
            ChurchService::factory()->create([
                'date' => Carbon::parse('2025-02-01')->addWeeks($week)->toDateString(),
                'service' => SermonService::Morning,
                'needs_review' => true,
            ]);
        }

        Livewire::test(ReviewInbox::class)
            ->set('filter', 'services')
            ->assertSee('Pending structure merge');
    }

    #[Test]
    public function an_overflow_notice_reports_the_true_backlog_when_a_source_is_capped(): void
    {
        InboundEmail::factory()->count(ReviewInboxQuery::SOURCE_CAP + 1)->create([
            'status' => InboundEmailStatus::Pending->value,
            'processing_metadata' => null,
        ]);

        Livewire::test(ReviewInbox::class)
            ->assertSee(sprintf(
                'Showing the newest %d of %d emails.',
                ReviewInboxQuery::SOURCE_CAP,
                ReviewInboxQuery::SOURCE_CAP + 1,
            ));
    }

    #[Test]
    public function email_rows_expose_the_original_email_diagnostics(): void
    {
        // The retired inbound-emails page showed the sanitised original email
        // and raw parser data; the inbox keeps that for diagnosing failures.
        InboundEmail::factory()->create([
            'subject' => 'Broken email',
            'status' => InboundEmailStatus::Failed->value,
            'body_plain' => 'Sunday 7 June order of service body text',
            'body_html' => '<p>Sunday 7 June order of service rich text</p>',
            'processing_metadata' => [
                'failure' => ['message' => 'Could not parse this email'],
                'parsing' => ['warnings' => ['Unrecognised heading format']],
            ],
        ]);

        Livewire::test(ReviewInbox::class)
            ->assertSee('Original email')
            ->assertSee('Sunday 7 June order of service body text')
            ->assertSee('Sunday 7 June order of service rich text')
            ->assertSee('Unrecognised heading format')
            ->assertSee('Could not parse this email');
    }

    #[Test]
    public function orphan_groups_offer_a_create_service_link_with_the_resolved_slot(): void
    {
        // A flagged section whose run resolves a date/slot that matches no
        // ChurchService: the group offers to create the missing Sunday so
        // its sections gain a workbench to be edited on.
        $run = MediaProcessingLog::factory()->livestream()->completed()->create([
            'extracted_date' => '2026-06-07',
            'extracted_service' => SermonService::Morning->value,
            'sermon_id' => null,
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'church_service_item_id' => null,
            'title' => 'Orphan Flagged Section',
            'needs_manual_review' => true,
        ]);

        // A truly unattributed item (no resolved slot) must not offer one.
        InboundEmail::factory()->create([
            'subject' => 'Mystery email',
            'status' => InboundEmailStatus::Failed->value,
            'processing_metadata' => null,
        ]);

        $createUrl = str_replace('&', '&amp;', route('admin.services.create', ['date' => '2026-06-07', 'service' => 'morning']));

        Livewire::test(ReviewInbox::class)
            ->assertSee('Orphan Flagged Section')
            ->assertSeeHtml($createUrl)
            ->assertSee('Create this service');
    }

    #[Test]
    public function it_shows_the_empty_state_when_nothing_needs_review(): void
    {
        Livewire::test(ReviewInbox::class)
            ->assertSee('All caught up')
            ->assertSee('There are no items currently requiring manual review.')
            ->assertSeeHtml(route('admin.services.index'));
    }

    #[Test]
    public function segment_links_use_the_dedicated_review_when_the_run_matches_a_service(): void
    {
        $service = ChurchService::factory()->create([
            'date' => '2026-06-07',
            'service' => SermonService::Morning,
        ]);

        $run = MediaProcessingLog::factory()->livestream()->manualReviewRequired()->create([
            'extracted_date' => '2026-06-07',
            'extracted_service' => SermonService::Morning->value,
        ]);

        Livewire::test(ReviewInbox::class)
            ->assertSeeHtml(route('admin.recordings.sermon-segment', $run->processing_id));
    }

    #[Test]
    public function segment_links_use_the_dedicated_review_for_orphan_runs(): void
    {
        $run = MediaProcessingLog::factory()->livestream()->manualReviewRequired()->create([
            'extracted_date' => '2026-06-07',
            'extracted_service' => SermonService::Morning->value,
        ]);

        Livewire::test(ReviewInbox::class)
            ->assertSeeHtml(route('admin.recordings.sermon-segment', $run->processing_id))
            ->assertSee('Choose segment');
    }

    #[Test]
    public function segment_links_for_auto_trim_video_runs_use_the_dedicated_review(): void
    {
        $service = ChurchService::factory()->create([
            'date' => '2026-06-07',
            'service' => SermonService::Morning,
        ]);

        // The workbench renders every segmentation-pipeline run, so matched
        // auto-trim video runs anchor there just like livestream runs.
        $run = MediaProcessingLog::factory()->manualReviewRequired()->create([
            'processing_type' => MediaType::Video,
            'extracted_date' => '2026-06-07',
            'extracted_service' => SermonService::Morning->value,
            'processing_metadata' => [
                'video_processing_mode' => MediaProcessingLog::VIDEO_PROCESSING_MODE_AUTO_TRIM,
                'manual_review' => [
                    'status' => 'required',
                    'reason_code' => 'no_qualifying_speech_block',
                    'reason_message' => 'No speech block met the 20-minute sermon threshold.',
                    'flagged_at' => now()->toIso8601String(),
                    'speech_segments' => [],
                ],
            ],
        ]);

        Livewire::test(ReviewInbox::class)
            ->assertSeeHtml(route('admin.recordings.sermon-segment', $run->processing_id));
    }

    #[Test]
    public function segment_entries_never_hydrate_blob_columns(): void
    {
        MediaProcessingLog::factory()->livestream()->manualReviewRequired()->create([
            'extracted_date' => '2026-06-07',
            'extracted_service' => SermonService::Morning->value,
        ]);

        $selectQueries = [];

        DB::listen(function ($query) use (&$selectQueries): void {
            if (str_contains($query->sql, 'media_processing_logs') && str_starts_with(ltrim($query->sql), 'select')) {
                $selectQueries[] = $query->sql;
            }
        });

        Livewire::test(ReviewInbox::class)->assertStatus(200);

        $this->assertNotEmpty($selectQueries);

        foreach ($selectQueries as $sql) {
            $this->assertStringNotContainsString('select *', strtolower($sql));
            $this->assertStringNotContainsString('"media_processing_logs".*', $sql);

            foreach (['rms_stats', 'ai_analysis', 'rms_log_path'] as $column) {
                $this->assertStringNotContainsString("`{$column}`", $sql);
            }
        }
    }
}
