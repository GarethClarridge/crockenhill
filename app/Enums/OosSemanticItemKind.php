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
}
