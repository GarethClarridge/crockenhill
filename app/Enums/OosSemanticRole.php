<?php

declare(strict_types=1);

namespace App\Enums;

enum OosSemanticRole: string
{
    case ServiceBoundary = 'service_boundary';
    case Item = 'item';
    case TransitionMarker = 'transition_marker';
    case Continuation = 'continuation';
    case SupportingDetail = 'supporting_detail';
    case NoticeContext = 'notice_context';
    case ForwardedContext = 'forwarded_context';
    case GreetingOrSignature = 'greeting_or_signature';
    case OtherContext = 'other_context';
}
