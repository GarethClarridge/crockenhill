<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Services\Processing\MediaProcessingRunTransitionService;
use App\Services\Processing\ProcessingNotificationRouter;
use App\Traits\ChecksCancellation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendCompletionNotification implements ShouldQueue
{
    use ChecksCancellation, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The maximum number of seconds the job can run.
     */
    public int $timeout = 120; // 2 minutes for notification

    /**
     * Create a new job instance.
     */
    public function __construct(
        private MediaProcessingLog $processingLog
    ) {}

    /**
     * Execute the job.
     */
    public function handle(
        ?MediaProcessingRunTransitionService $processingRunTransitions = null,
        ?ProcessingNotificationRouter $notificationRouter = null,
    ): void {
        $processingRunTransitions ??= app(MediaProcessingRunTransitionService::class);
        $notificationRouter ??= app(ProcessingNotificationRouter::class);

        if ($this->abortIfCancelled('SendCompletionNotification')) {
            return;
        }

        if ($notificationRouter->suppressIfHistoric($this->processingLog, 'success', 'info', [
            'stage' => 'processing_complete',
            'sermon_id' => $this->processingLog->sermon_id,
        ])) {
            $processingRunTransitions->updateStep($this->processingLog, 'notification_skipped');

            return;
        }

        try {
            Log::info('Starting completion notification', [
                'processing_id' => $this->processingLog->processing_id,
            ]);

            if (! config('media-processing.email.send_success_notifications')) {
                Log::info('Success notifications disabled, skipping', [
                    'processing_id' => $this->processingLog->processing_id,
                ]);
                $processingRunTransitions->updateStep($this->processingLog, 'notification_skipped');

                return;
            }

            $adminEmail = $this->getAdminEmail();
            if ($adminEmail === null) {
                Log::warning('No admin email configured for notification, skipping', [
                    'processing_id' => $this->processingLog->processing_id,
                ]);
                $processingRunTransitions->updateStep($this->processingLog, 'notification_skipped');

                return;
            }

            // Update processing log to indicate notification started
            $processingRunTransitions->updateStep($this->processingLog, 'sending_notification');

            // Get the sermon record if it was created
            $sermon = null;
            if ($this->processingLog->sermon_id) {
                $sermon = Sermon::query()->find($this->processingLog->sermon_id);
            }

            if (! $sermon instanceof Sermon) {
                throw new \RuntimeException('Sermon context is missing for completion notification.');
            }

            // Prepare notification data
            $notificationData = $this->prepareNotificationData($sermon, $this->processingLog);

            // Send notifications to administrator
            $this->sendNotifications($notificationData, $adminEmail);

            // Update final processing log status
            $processingRunTransitions->updateStep($this->processingLog, 'notification_sent');

            Log::info('Completion notification sent successfully', [
                'processing_id' => $this->processingLog->processing_id,
                'sermon_id' => $this->processingLog->sermon_id,
                'sermon_title' => $sermon->title ?? 'N/A',
                'processing_status' => $this->processingLog->status->value,
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to send completion notification', [
                'processing_id' => $this->processingLog->processing_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Don't fail the entire processing chain for notification failures
            // Just log the error and mark as completed
            $processingRunTransitions->updateRunFields($this->processingLog, [
                'current_step' => 'notification_failed',
                'error_message' => 'Notification failed: '.$e->getMessage(),
            ]);

            // Don't re-throw the exception to avoid failing the job chain
            Log::warning('Continuing despite notification failure', [
                'processing_id' => $this->processingLog->processing_id,
            ]);
        }
    }

    /**
     * Prepare notification data
     *
     * @return array<string, mixed>
     */
    private function prepareNotificationData(?Sermon $sermon, MediaProcessingLog $processingLog): array
    {
        $sermonUrl = $sermon ? route('sermons.show', ['sermon' => $sermon->slug]) : null;
        $adminUrl = $sermon ? route('admin.sermons.edit', ['sermon' => $sermon->slug]) : null;

        $endTime = $processingLog->updated_at ?? now();
        $startTime = $processingLog->created_at ?? now();
        $processingDuration = $startTime->diffForHumans($endTime);

        $hasErrors = ! empty($processingLog->error_message);
        $requiresReview = $hasErrors ||
          ($sermon && (empty($sermon->series) ||
          empty($sermon->reference) ||
          str_contains(strtolower($sermon->title), 'untitled')));

        $sermonData = null;
        if ($sermon) {
            $sermonData = [
                'id' => $sermon->id,
                'title' => $sermon->title,
                'date' => $sermon->date->format('F j, Y'),
                'service' => $sermon->service?->label() ?? 'Unknown',
                'preacher' => $sermon->preacher,
                'series' => $sermon->series ?? 'None',
                'reference' => $sermon->reference ?? 'None',
                'points_count' => count($sermon->points ?? []),
                'has_transcript' => $sermon->hasTranscript(),
                'url' => $sermonUrl,
                'admin_url' => $adminUrl,
            ];
        }

        return [
            'sermon' => $sermonData,
            'processing' => [
                'id' => $processingLog->processing_id,
                'status' => $processingLog->status->label(),
                'duration' => $processingDuration,
                'original_filename' => $processingLog->original_filename,
                'has_errors' => $hasErrors,
                'error_message' => $processingLog->error_message,
                'requires_review' => $requiresReview,
            ],
            'review_items' => $sermon ? $this->getReviewItems($sermon, $processingLog) : [],
        ];
    }

    /**
     * Get items that may require manual review
     *
     * @return array<int, string>
     */
    private function getReviewItems(Sermon $sermon, MediaProcessingLog $processingLog): array
    {
        $reviewItems = [];

        if (! empty($processingLog->error_message)) {
            $reviewItems[] = 'Processing errors occurred: '.$processingLog->error_message;
        }

        if (empty($sermon->series)) {
            $reviewItems[] = 'No sermon series was identified';
        }

        if (empty($sermon->reference)) {
            $reviewItems[] = 'No Bible passage reference was found';
        }

        if (
            str_contains(strtolower($sermon->title), 'untitled') ||
            str_contains(strtolower($sermon->title), 'sermon -')
        ) {
            $reviewItems[] = 'Title may need manual refinement';
        }

        if (! $sermon->hasTranscript()) {
            $reviewItems[] = 'Transcript is missing or inaccessible';
        }

        if (empty($sermon->points) || count($sermon->points) === 1) {
            $reviewItems[] = 'Sermon points may need manual review';
        }

        return $reviewItems;
    }

    /**
     * Send notification to the given admin email.
     *
     * @param  array<string, mixed>  $data
     */
    private function sendNotifications(array $data, string $adminEmail): void
    {
        $this->sendEmailNotification($adminEmail, $data);

        Log::info('Notification sent to admin', [
            'processing_id' => $this->processingLog->processing_id,
            'admin_email' => $adminEmail,
        ]);
    }

    /**
     * Get the admin email address from configuration
     */
    private function getAdminEmail(): ?string
    {
        $email = config('media-processing.email.admin_email');

        if (empty($email)) {
            return null;
        }

        return $email;
    }

    /**
     * Send email notification to admin
     *
     * @param  array<string, mixed>  $data
     */
    private function sendEmailNotification(string $adminEmail, array $data): void
    {
        $subject = $data['processing']['has_errors']
          ? "Sermon Processing Completed with Issues - {$data['sermon']['title']}"
          : "Sermon Processing Completed - {$data['sermon']['title']}";

        $message = $this->buildEmailMessage($data);

        Log::info('Email notification content', [
            'to' => $adminEmail,
            'subject' => $subject,
            'message' => $message,
        ]);

        Mail::raw($message, function ($mail) use ($adminEmail, $subject) {
            $mail->to($adminEmail)
                ->subject($subject);
        });
    }

    /**
     * Build email message content
     *
     * @param  array<string, mixed>  $data
     */
    private function buildEmailMessage(array $data): string
    {
        $sermon = $data['sermon'];
        $processing = $data['processing'];
        $reviewItems = $data['review_items'];

        $message = "Automated sermon processing has completed.\n\n";

        $message .= "SERMON DETAILS:\n";
        $message .= "Title: {$sermon['title']}\n";
        $message .= "Date: {$sermon['date']}\n";
        $message .= "Service: {$sermon['service']}\n";
        $message .= "Preacher: {$sermon['preacher']}\n";
        $message .= "Series: {$sermon['series']}\n";
        $message .= "Bible Reference: {$sermon['reference']}\n";
        $message .= "Sermon Points: {$sermon['points_count']}\n";
        $message .= 'Has Transcript: '.($sermon['has_transcript'] ? 'Yes' : 'No')."\n\n";

        $message .= "PROCESSING DETAILS:\n";
        $message .= "Status: {$processing['status']}\n";
        $message .= "Duration: {$processing['duration']}\n";
        $message .= "Original File: {$processing['original_filename']}\n\n";

        if (! empty($reviewItems)) {
            $message .= "ITEMS FOR REVIEW:\n";
            foreach ($reviewItems as $item) {
                $message .= "- {$item}\n";
            }
            $message .= "\n";
        }

        $message .= "LINKS:\n";
        $message .= "View Sermon: {$sermon['url']}\n";
        $message .= "Admin Panel: {$sermon['admin_url']}\n\n";

        if ($processing['has_errors']) {
            $message .= "ERROR DETAILS:\n";
            $message .= $processing['error_message']."\n\n";
        }

        $message .= 'This is an automated notification from the sermon processing system.';

        return $message;
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('SendCompletionNotification job failed permanently', [
            'processing_id' => $this->processingLog->processing_id,
            'sermon_id' => $this->processingLog->sermon_id,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);

        // Don't fail the processing chain for notification failures.
        // Update the injected log directly rather than re-querying via the
        // sermon relationship, which could resolve to the wrong log when a
        // sermon has multiple processing runs or when sermon_id is null.
        app(MediaProcessingRunTransitionService::class)->updateRunFields($this->processingLog, [
            'current_step' => 'notification_failed_permanently',
            'error_message' => 'Notification failed permanently: '.$exception->getMessage(),
        ]);
    }

    /**
     * Calculate the number of seconds to wait before retrying the job.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        // Quick retries for notifications: 30 seconds, 2 minutes, 5 minutes
        return [30, 120, 300];
    }
}
