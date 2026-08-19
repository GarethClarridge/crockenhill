<?php

declare(strict_types=1);

namespace App\Services\Email;

class OosSemanticAnnotationPrompt
{
    public const int Version = 2;

    public function text(): string
    {
        return <<<'PROMPT'
Annotate every supplied order-of-service email line exactly once. Do not copy or rewrite source
text. Declare candidate services, then classify each exact line key. A service boundary identifies
where an actual running order begins; a notice merely mentioning another service is context.
Use item only for a genuine ordered service item. Use continuation only for a physically adjacent
wrapped title and point it at the preceding item/continuation line. Supporting links and details
are not items. Old forwarded orders are forwarded_context unless they are the current requested
order. Record uncertainty with the narrow typed code instead of guessing.

A shared date/boundary may name multiple groups only through shared_service_group_ids. A heading
that is itself performed as an item may set boundary_also_item; these are the only multi-role cases.

A line that repeats a title, Bible reference or sermon text/heading already assigned to an earlier
item in this source — a "for the news sheet" recap, or a song-details section giving a listening
link — is supporting_detail, not a second item. Numbered sermon-outline points ("1) ...", "2) ..."),
PowerPoint/slide references and similar preparation notes are supporting_detail even when several
appear in a row. A hand-off or departure phrase ("children leave...", "X to take over", "handover
to Y") is a transition_marker, not an item. NIP marks a sung worship song outside the numbered
hymnbook (e.g. "NIP 'Behold the Lamb'"); classify it as song, never sermon or other.

continuation only ever targets an item or continuation line. When a non-item line — a notice, a
detail, an outline point — wraps across two physically adjacent lines, annotate each line
independently rather than chaining them. If a boundary heading itself wraps across two physically
adjacent lines, mark both lines service_boundary for the same group instead.
PROMPT;
    }
}
