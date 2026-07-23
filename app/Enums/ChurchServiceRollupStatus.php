<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Rolled-up pipeline status for a ChurchService, shown as a single chip on
 * the services hub. Derived by ChurchServiceRollupQuery; precedence (first
 * match wins): Processing, NeedsReview, Published, Ready. When no livestream
 * run matches the service and nothing needs attention, the date decides:
 * today or future = PlanOnly (nothing expected yet), past = AwaitingRecording
 * (a recording is overdue).
 *
 * Colour discipline: amber = needs a human, teal = done/ready, sky = machine
 * working, slate = inert (plan-only).
 */
enum ChurchServiceRollupStatus: string
{
    case PlanOnly = 'plan_only';
    case AwaitingRecording = 'awaiting_recording';
    case Processing = 'processing';
    case ProcessingFailed = 'processing_failed';
    case NeedsReview = 'needs_review';
    case Ready = 'ready';
    case Published = 'published';

    public function label(): string
    {
        return match ($this) {
            self::PlanOnly => 'Plan only',
            self::AwaitingRecording => 'Awaiting recording',
            self::Processing => 'Processing',
            self::ProcessingFailed => 'Processing failed',
            self::NeedsReview => 'Needs review',
            self::Ready => 'Ready',
            self::Published => 'Published',
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::PlanOnly => 'bg-slate-200 text-slate-800',
            self::AwaitingRecording => 'bg-slate-200 text-slate-700',
            self::Processing => 'bg-sky-100 text-sky-800',
            self::ProcessingFailed => 'bg-rose-100 text-rose-800',
            self::NeedsReview => 'bg-amber-100 text-amber-800',
            self::Ready => 'bg-cbc-teal-light/15 text-cbc-teal-dark',
            self::Published => 'bg-cbc-teal text-white',
        };
    }

    public function needsAttention(): bool
    {
        return in_array($this, [self::ProcessingFailed, self::NeedsReview], true);
    }
}
