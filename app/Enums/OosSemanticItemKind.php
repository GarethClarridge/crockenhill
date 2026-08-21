<?php

declare(strict_types=1);

namespace App\Enums;

enum OosSemanticItemKind: string
{
    case Welcome = 'welcome';
    case Prayer = 'prayer';
    case Notices = 'notices';
    case Song = 'song';
    case ChildrensTalk = 'childrens_talk';
    case BibleReading = 'bible_reading';
    case Sermon = 'sermon';
    case Other = 'other';
    case CallToWorship = 'call_to_worship';
    case Communion = 'communion';
    case Benediction = 'benediction';
    case Interview = 'interview';
    case MissionaryFocus = 'missionary_focus';
    case Transition = 'transition';

    /**
     * A frame element of a running order rather than its content: the parts a service has because
     * it is a service at all, not because of what was sung, read or preached.
     *
     * A source that lists only content — a "here are the hymns for tomorrow" email, or the
     * second-service stub of a two-service email — carries none of these. That is what
     * the semantic compiler's content-scope rule uses to refuse a `full` content scope.
     */
    public function structuralFrame(): bool
    {
        return match ($this) {
            self::Welcome, self::Notices, self::Prayer, self::ChildrensTalk,
            self::CallToWorship, self::Communion, self::Benediction, self::Transition => true,
            self::Song, self::BibleReading, self::Sermon, self::Other,
            self::Interview, self::MissionaryFocus => false,
        };
    }
}
