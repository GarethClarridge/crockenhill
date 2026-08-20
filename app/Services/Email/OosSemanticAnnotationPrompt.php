<?php

declare(strict_types=1);

namespace App\Services\Email;

class OosSemanticAnnotationPrompt
{
    public const int Version = 5;

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

Every source describes at least one service; always declare a group for its running order, even
when the body never uses the word "morning" or "evening" outright. Infer proposed_service from the
subject line, salutation and surrounding context rather than omitting the group because the label
itself is implicit — an omitted group is a worse answer than an uncertain one. A festival or themed
service — Christmas, Carols, Palm Sunday, Easter and the like — still keeps its ordinary morning or
evening slot label; the occasion changes what is sung and preached, not which slot the service fell
in. other is rare: it names a service that falls outside the normal Sunday morning/evening pattern
altogether (a weekday Good Friday service, a wedding, a funeral, an ordination), never a themed
Sunday service in its usual slot. An unlabelled single Sunday running order is morning unless there
is evening evidence (an evening time, "pm", "tonight" or similar). Record ambiguous_service, with
proposed_service left null, only when the subject, salutation and context genuinely give no basis
for a choice.

A shared date/boundary may name multiple groups only through shared_service_group_ids. A heading
that is itself performed as an item may set boundary_also_item; these are the only multi-role cases.

Some running orders never state a heading at all — the message simply starts listing items. boundary_line_ids empty is a last resort, not a default: when a service has items but no separate heading line, mark its first item's role service_boundary with boundary_also_item set instead, and cite that same line as the boundary — the running order begins where the first item does. Leave boundary_line_ids empty only when the source gives no line at all to point to, not merely because the heading and the first item coincide.

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
