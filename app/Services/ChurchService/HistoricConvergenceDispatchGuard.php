<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use RuntimeException;

/**
 * Fail a historic convergence apply the moment it queues a job, sends mail or
 * raises a notification.
 *
 * WP6 requires production apply to prove it caused no external fan-out, and
 * neither obvious shortcut works. Faking the event dispatcher suppresses every
 * model observer with it, so apply would persist differently from the rehearsal
 * it is supposed to reproduce. The framework's Bus/Queue/Mail/Notification fakes
 * assert through PHPUnit, which production images do not install, and their
 * assertions could only run once the caller's transaction had already
 * committed — leaving rows written and their assets cleaned up.
 *
 * Throwing from a listener instead keeps the failure at the point of violation,
 * inside the caller's transaction, so the whole service rolls back.
 */
class HistoricConvergenceDispatchGuard
{
    private bool $armed = false;

    private ?Dispatcher $listeningOn = null;

    /**
     * Run the callback with external dispatch forbidden.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    public function guard(Closure $callback): mixed
    {
        $this->listen();
        $wasArmed = $this->armed;
        $this->armed = true;

        try {
            return $callback();
        } finally {
            $this->armed = $wasArmed;
        }
    }

    public function reject(string $description): void
    {
        if (! $this->armed) {
            return;
        }

        throw new RuntimeException(
            "Historic convergence apply attempted to dispatch {$description}; no records were committed.",
        );
    }

    /**
     * Register once per dispatcher instance. The listeners are inert whenever
     * the guard is disarmed, so leaving them attached costs nothing and avoids
     * forgetting listeners this class does not own.
     */
    private function listen(): void
    {
        $events = app(Dispatcher::class);

        if ($this->listeningOn === $events) {
            return;
        }

        $this->listeningOn = $events;
        $events->listen(JobQueued::class, function (): void {
            $this->reject('a queued job');
        });

        /**
         * The sync connection never queues anything — it runs the job inline,
         * side effects and all. That is the worse outcome, not an exemption, so
         * it is rejected on the same terms.
         */
        $events->listen(JobProcessing::class, function (): void {
            $this->reject('a queued job');
        });
        $events->listen(MessageSending::class, function (): void {
            $this->reject('mail');
        });
        $events->listen(NotificationSending::class, function (): void {
            $this->reject('a notification');
        });
    }
}
