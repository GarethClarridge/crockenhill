<?php

declare(strict_types=1);

namespace App\Enums;

enum OosSemanticUncertainty: string
{
    case AmbiguousBoundary = 'ambiguous_boundary';
    case AmbiguousService = 'ambiguous_service';
    case AmbiguousItemKind = 'ambiguous_item_kind';
    case AmbiguousContinuation = 'ambiguous_continuation';
    case ForwardedCurrentAmbiguity = 'forwarded_current_ambiguity';
    case IncompleteService = 'incomplete_service';
    case UnresolvedDate = 'unresolved_date';
    case Other = 'other';
}
