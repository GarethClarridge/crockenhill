<?php

declare(strict_types=1);

namespace App\Services\Email;

use RuntimeException;

/**
 * The system prompts the OoS extractor may run under, as named variants rather than an edit.
 *
 * The evaluation's whole provenance apparatus rests on one rule: everything except the arm's
 * *declared* intervention must be identical across arms. `OosParserSurfaceFingerprint` hashes the
 * extractor's bytes and `OosParserArmPrimaryComparison::assertNoManifestDrift()` refuses a pair
 * whose manifests differ anywhere else. Rewriting `systemPrompt()` in place to run a prompt arm
 * would therefore be precisely the undeclared change those guards were built to catch — the two
 * arms could not even exist in one code state, so the second would have to be run against a file
 * the first never saw.
 *
 * Naming the variants instead makes the prompt an arm dimension exactly like model and reasoning
 * effort: both texts are present at once, the arm selects one, the run manifest records which and
 * its hash, and the fingerprint still refuses a silent edit to either.
 *
 * The prompts live here rather than in the extractor for a second reason. The manifest needs the
 * variant's identity, and a public accessor on `OpenAiOosEmailItemExtractor` would put an
 * evaluation-shaped hole in a production class — the same objection that made the fingerprint hash
 * whole files rather than expose the prompt.
 *
 * This file is part of the parser surface, so it is listed in {@see OosParserSurfaceFingerprint}.
 */
readonly class OosEmailExtractionPrompt
{
    public const Baseline = 'baseline';

    public const Lean = 'lean';

    private function __construct(public string $variant, private string $text) {}

    /**
     * The variant this process is configured to run.
     *
     * Defaults to the baseline, so nothing outside an evaluation arm changes behaviour by omission.
     */
    public static function configured(): self
    {
        return self::forVariant((string) config('service-tracking.email_parsing.prompt_variant', self::Baseline));
    }

    public static function forVariant(string $variant): self
    {
        return match ($variant) {
            self::Baseline => new self(self::Baseline, self::baselineText()),
            self::Lean => new self(self::Lean, self::leanText()),
            default => throw new RuntimeException("Unknown OoS email extraction prompt variant '{$variant}'."),
        };
    }

    public function text(): string
    {
        return $this->text;
    }

    /**
     * Recorded in the run manifest beside the variant name, so an artifact proves which text ran
     * rather than which label was typed.
     */
    public function sha256(): string
    {
        return hash('sha256', $this->text);
    }

    /**
     * The prompt every banked arm ran under. Frozen: editing it would silently redefine the
     * baseline that a variant is measured against.
     */
    private static function baselineText(): string
    {
        return <<<'TEXT'
You extract church service orders from email text. One email often contains BOTH a morning
and an evening order (and occasionally a special service such as carols or Christmas).
Return valid JSON with this shape only:
{"service_count":1,"services":[{"service":"morning|evening|other|unknown","date":"YYYY-MM-DD or null","content_scope":"full|partial|unknown","service_evidence_line_ids":[1],"items":[{"type":"welcome|prayer|notices|song|childrens_talk|bible_reading|sermon|other","title":"exact source text","source_line_ids":[2],"continuation":false}],"confidence":0.0}],"ignored_lines":[{"line_id":3,"reason":"context|forwarded_header|greeting|signature"}],"notes":["string"]}
Rules:
- Count the distinct service orders first. service_count MUST equal the number of entries in services.
- Set content_scope to "full" only when the email presents the service's complete running order.
  Use "partial" for supporting material such as selected hymns, readings, sermon details or notices
  that does not claim to be the whole order. Use "unknown" when the email does not make completeness
  clear. Completeness is separate from confidence: a short hymn list can be a confident partial.
- A single order may have no heading. In that case service_evidence_line_ids may be empty and the
  subject or a time in the body may identify it. Multiple orders require distinct body-line evidence
  for each boundary, such as headings or standalone time markers.
- A sentence naming a person and a service occasion before a list of song titles (for example "X
  would like the following hymns tomorrow morning:") is boundary evidence for that service, equal
  to a heading. Like a heading, that introducing sentence itself belongs in
  service_evidence_line_ids, never as an item. Extract only the song titles that follow it as that
  service's items, even when the introducing sentence sits inside a personal note, and even though
  it frames the songs as one person's choice rather than a publicly confirmed running order.
- That intro-sentence rule does not relax the evening rules below, which still govern. A sentence in
  prose never establishes an evening plan by itself. When such an intro names an evening service and
  no standalone evening boundary appears anywhere in the email, do not open a second plan for it.
- Nor does it reach through forwarding into a different set of services. When the introducing
  sentence sits inside a forwarded older message describing services other than the ones this email
  is about, it is context: put those lines in ignored_lines rather than extracting songs that this
  email's subject date would then misdate.
- A service order does not need prayers, readings, notices or a sermon to be genuine. A bare,
  ordered list of songs for a named service is itself a valid order: extract each title as its own
  "song" item in listed order, and set that plan's content_scope to "partial" unless the email
  otherwise claims it is the complete order. Where this meets the Notices rule below, this one wins,
  but only for a list introduced for one named service: song numbers mentioned in passing inside a
  general Notices section stay context.
- A general Notices section is context, NOT a service order, even when its lines mention another
  service, time, date, sermon or Bible passage. Put those lines in ignored_lines.
- Determine the service slot separately from its occasion. A Sunday evening carol service is evening,
  and a Sunday morning Christmas service is morning. Use "other" only when a special service has no
  evidenced morning or evening slot. Never use it for notices, meetings, diary entries or ambiguous prose.
- Preserve running order. By default each non-blank item line is exactly one item. Never merge adjacent
  item lines. Use multiple source_line_ids only for a genuinely wrapped continuation on physically
  adjacent lines, and set continuation=true. Lines separated by a blank line are separate items.
- Every numbered body line must appear exactly once: as service evidence, in one item's source_line_ids,
  or in ignored_lines. Never reuse, omit, invent or reorder line IDs.
- title must copy the complete referenced source text exactly. Do not summarise, clean or rewrite it.
- Use "morning" for AM/10.30 services and "evening" for afternoon, tonight, PM or 5pm-and-later
  services. Use "unknown" only when the service slot is genuinely unclear.
- Do not infer an evening service from the word "evening" in a notice, diary entry, forwarded
  header or prose. An evening plan is valid only when its evidence lines contain a standalone
  evening/PM/18:00-style heading or a clearly separated evening order with items following it.
- Never create an evening plan merely because a morning email mentions that an evening service
  exists. If there is no distinct evening boundary and item sequence, keep one plan and put the
  mention in ignored_lines.
- Treat a relative or named date in the subject as service evidence. Subject-level dates apply to every service plan
  in the email unless a plan states a different date. When a subject says
  "tomorrow", "Sunday" or another relative day, resolve it using the supplied calendar and assign
  that date to each morning/evening plan belonging to that day. Use null only when neither the
  subject nor the plan's body lines identify its date.
- When a date is present but is not a Sunday, check whether it is a nearby weekday transcription
  of the Sunday service date. Do not copy the email receipt date as the service date.
- Resolve relative or yearless dates against the supplied email receipt date. These emails normally
  describe services from the receipt date through the following two weeks; do not use a training-data year.
- Use "song" for hymns/songs and "bible_reading" for readings, while keeping their complete source
  wording in title. Display-title cleanup happens after extraction.
- Confidence reflects how reliable that service's extracted order is.
TEXT;
    }

    /**
     * The baseline with its two known redundancies removed and one contradiction repaired.
     *
     * Three changes, and deliberately no others — the arm is a screen, so a prompt that also moved
     * the schema, the routing thresholds or the sample would return a number nobody could attribute:
     *
     * 1. **The JSON example is gone.** The request is `strict: true` against a `json_schema`, so the
     *    shape — including the line-id enums that make an invented id impossible — is enforced by
     *    the API. The example was a second copy of the schema, carrying the usual risk that the two
     *    drift apart, and costing input tokens on every call to restate what is already binding.
     * 2. **The line-accounting rule now matches the validator.** The baseline demanded each line
     *    "appear exactly once", which `OosEmailExtractionValidator::assignLine()` does not require
     *    and deliberately does not enforce: a heading that is also its order's first item, or a date
     *    line two plans both cite, names a line twice without extracting its content twice, and the
     *    2026-08-14 review found 68 such plans against 2 genuine double-counts. Only two *items*
     *    claiming one line, or a line both ignored and claimed, are actually faults. So the baseline
     *    was asking the model to avoid a shape the pipeline accepts, while leaving the shapes that
     *    do fail validation implicit.
     * 3. **Rules stated more than once are stated once.** The evening constraint appeared in three
     *    bullets, the Notices/song-list precedence in two, and the date rules in three. They are
     *    grouped under headings instead, which is the same instruction set in fewer tokens.
     */
    private static function leanText(): string
    {
        return <<<'TEXT'
You extract church service orders from email text. One email often contains BOTH a morning and an
evening order, and occasionally a special service such as carols or Christmas. The response schema
fixes the JSON shape exactly; these rules decide what goes into it.

SERVICE BOUNDARIES
- Count the distinct service orders first. service_count MUST equal the number of entries in services.
- A single order may have no heading: service_evidence_line_ids may then be empty, and the subject or
  a time in the body identifies it. Two or more orders require distinct body-line evidence for each
  boundary, such as a heading or a standalone time marker.
- A sentence naming a person and a service occasion before a list of song titles (for example "X
  would like the following hymns tomorrow morning:") is boundary evidence for that service, equal to
  a heading. Put that sentence in service_evidence_line_ids and extract only the titles that follow
  it as items; the sentence is not itself a song. This holds even when it sits inside a personal note
  and frames the songs as one person's choice rather than a confirmed running order. It does not
  apply inside a forwarded older message about services other than the ones this email is about:
  those lines are context, and extracting them would misdate them to this email's subject date.
- Decide the slot separately from the occasion. A Sunday evening carol service is evening; a Sunday
  morning Christmas service is morning. Use "morning" for AM and 10.30 services, "evening" for
  afternoon, tonight, PM and 5pm-and-later services, "other" only for a special service with no
  evidenced morning or evening slot, and "unknown" only when the slot is genuinely unclear. Never use
  "other" for notices, meetings, diary entries or ambiguous prose.
- An evening plan requires a standalone evening/PM/18:00-style boundary line, or a clearly separated
  evening order with items following it. The word "evening" in a notice, a diary entry, a forwarded
  header, ordinary prose or an introducing sentence does not open one. When a morning email only
  mentions that an evening service exists, keep one plan and put the mention in ignored_lines.

CONTENT
- A general Notices section is context, NOT a service order, even when its lines mention another
  service, time, date, sermon or Bible passage: put them in ignored_lines. A bare ordered list of
  songs introduced for one named service is the exception and IS a valid order — extract each title
  as its own "song" item in listed order. A service order needs no prayers, readings, notices or
  sermon to be genuine. Song numbers mentioned in passing inside a Notices section stay context.
- Set content_scope to "full" only when the email presents that service's complete running order. Use
  "partial" for supporting material such as selected hymns, readings, sermon details or notices that
  does not claim to be the whole order, including a bare song list, unless the email says otherwise.
  Use "unknown" when completeness is not made clear. Completeness is separate from confidence: a
  short hymn list can be a confident partial.
- Use "song" for hymns and songs and "bible_reading" for readings. title must copy the complete
  referenced source text exactly — do not summarise, clean or rewrite it. Display-title cleanup
  happens after extraction.
- Confidence reflects how reliable that service's extracted order is.

LINE ACCOUNTING
- Preserve running order. Each non-blank item line is one item by default; never merge adjacent item
  lines, and lines separated by a blank line are separate items. Give an item more than one source
  line only for a genuinely wrapped continuation on physically adjacent lines, listed in ascending
  order, with continuation=true.
- Account for every numbered body line at least once: as service evidence, in one item's
  source_line_ids, or in ignored_lines. Never omit or invent a line ID.
- A line may be claimed more than once when it genuinely serves more than one role — a heading that
  is also its order's first item, or a date line that two plans both cite as their boundary. What is
  never allowed is two items claiming the same line, or a line that is both ignored and claimed.

DATES
- Treat a relative or named date in the subject as service evidence, applying to every plan in the
  email unless a plan states a different date. Resolve "tomorrow", "Sunday" and other relative days
  against the supplied calendar, and resolve yearless dates against the supplied receipt date rather
  than a training-data year — these emails normally describe services from the receipt date through
  the following two weeks. Use null only when neither the subject nor the plan's body lines identify
  the date.
- When a date is present but is not a Sunday, check whether it is a nearby weekday transcription of
  the Sunday service date. Never copy the email receipt date as the service date.
TEXT;
    }
}
