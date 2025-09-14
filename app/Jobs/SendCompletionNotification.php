<?php

namespace App\Jobs;

use App\Models\Sermon;
use App\Models\SermonProcessingLog;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

class SendCompletionNotification implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

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
        private SermonProcessingLog $processingLog
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            Log::info('Starting completion notification', [
                'processing_id' => $this->processingLog->processing_id,
            ]);

            // Update processing log to indicate notification started
            $this->processingLog->updateStep('sending_notification');

            // Get the sermon record if it was created
            $sermon = null;
            if ($this->processingLog->sermon_id) {
                $sermon = Sermon::find($this->processingLog->sermon_id);
            }

            // Prepare notification data
            $notificationData = $this->prepareNotificationData($sermon, $this->processingLog);

            // Send notifications to administrators
            $this->sendNotifications($notificationData);

            // Update final processing log status
            $this->processingLog->updateStep('notification_sent');

            Log::info('Completion notification sent successfully', [
                'processing_id' => $this->processingLog->processing_id,
                'sermon_id' => $this->processingLog->sermon_id,
                'sermon_title' => $sermon?->title ?? 'N/A',
                'processing_status' => $this->processingLog->status->value,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send completion notification', [
                'processing_id' => $this->processingLog->processing_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Don't fail the entire processing chain for notification failures
            // Just log the error and mark as completed
            $this->processingLog->update([
                'current_step' => 'notification_failed',
                'error_message' => 'Notification failed: ' . $e->getMessage(),
            ]);

            // Don't re-throw the exception to avoid failing the job chain
            Log::warning('Continuing despite notification failure', [
                'processing_id' => $this->processingLog->processing_id,
            ]);
        }
    }

    /**
     * Prepare notification data
     */
    private function prepareNotificationData(?Sermon $sermon, SermonProcessingLog $processingLog): array
    {
        $sermonUrl = $sermon ? url("/christ/sermons/{$sermon->slug}") : null;
        $adminUrl = $sermon ? url("/admin/sermons/{$sermon->id}") : null;

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
     */
    private function getReviewItems(Sermon $sermon, SermonProcessingLog $processingLog): array
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
     * Send notifications to administrators
     */
    private function sendNotifications(array $data): void
    {
        // Get admin users (assuming they have a specific role or email)
        $adminUsers = $this->getAdminUsers();

        if (empty($adminUsers)) {
            Log::warning('No admin users found for notification', [
                'processing_id' => $this->processingLog->processing_id,
            ]);

            return;
        }

        foreach ($adminUsers as $admin) {
            try {
                $this->sendEmailNotification($admin, $data);

                Log::info('Notification sent to admin', [
                    'processing_id' => $this->processingLog->processing_id,
                    'admin_email' => $admin->email,
                ]);
            } catch (\Exception $e) {
                Log::error('Failed to send notification to admin', [
                    'processing_id' => $this->processingLog->processing_id,
                    'admin_email' => $admin->email,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Get admin users who should receive notifications
     */
    private function getAdminUsers(): array
    {
        try {
            // For now, get all users - in a real implementation, you'd filter by role
            // This could be enhanced with a proper role system
            $users = User::all();

            // If no users in database, create a fallback notification
            if ($users->isEmpty()) {
                Log::info('No users found in database for notification');

                return [];
            }

            return $users->all();
        } catch (\Exception $e) {
            Log::error('Failed to retrieve admin users', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Send email notification to admin user
     */
    private function sendEmailNotification(User $admin, array $data): void
    {
        $subject = $data['processing']['has_errors']
          ? "Sermon Processing Completed with Issues - {$data['sermon']['title']}"
          : "Sermon Processing Completed - {$data['sermon']['title']}";

        $message = $this->buildEmailMessage($data);

        // For now, just log the notification content
        // In a real implementation, you'd use Laravel's Mail facade
        Log::info('Email notification content', [
            'to' => $admin->email,
            'subject' => $subject,
            'message' => $message,
        ]);

        // Uncomment when email is properly configured:
        /*
            Mail::raw($message, function ($mail) use ($admin, $subject) {
                $mail->to($admin->email)
                     ->subject($subject);
            });
            */
    }

    /**
     * Build email message content
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
            'sermon_id' => $this->sermonId,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);

        // Don't fail the processing chain for notification failures
        // Just update the processing log
        $sermon = Sermon::find($this->sermonId);
        if ($sermon) {
            $processingLog = $sermon->processingLogs()->latest()->first();
            if ($processingLog) {
                $processingLog->update([
                    'current_step' => 'notification_failed_permanently',
                    'error_message' => 'Notification failed permanently: '.$exception->getMessage(),
                ]);
            }
        }
    }

    /**
     * Calculate the number of seconds to wait before retrying the job.
     */
    public function backoff(): array
    {
        // Quick retries for notifications: 30 seconds, 2 minutes, 5 minutes
        return [30, 120, 300];
    }
}
